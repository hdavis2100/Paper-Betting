<?php
declare(strict_types=1);

/**
 * Usage:
 *   php /var/www/html/sportsbet/src/settle_bets.php
 *
 * Notes:
 * - Uses TheOddsAPI scores endpoint per sport over the last N days.
 * - Marks pending bets as won/lost/void and credits/refunds wallets.
 */

require __DIR__ . '/db.php';

$config = require '/var/www/secure_config/sportsbet_config.php';
$apiKey = $config['odds_api_key'] ?? '';
if ($apiKey === '') {
  fwrite(STDERR, "Missing odds_api_key in secure config.\n");
  exit(1);
}

/** How many days back to fetch scores for (covers late finishes). */
const DAYS_FROM = 7;

/** 1) Gather unsettled events grouped by sport */
$sql = "
  SELECT DISTINCT e.sport_key, e.event_id, e.home_team, e.away_team
  FROM bets b
  JOIN events e ON e.event_id = b.event_id
  WHERE b.status = 'pending'
    AND e.commence_time < NOW()
";
$rows = $pdo->query($sql)->fetchAll();
if (!$rows) {
  echo "Nothing to settle.\n";
  exit(0);
}

$bySport = [];
foreach ($rows as $r) {
  $bySport[$r['sport_key']][] = $r;
}

/** Prepared statements we'll reuse */
$lockWallet = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE");
$updateWallet = $pdo->prepare("UPDATE wallets SET balance = ? WHERE user_id = ?");
$logTxn = $pdo->prepare("INSERT INTO wallet_transactions (user_id, change_amt, balance_after, reason) VALUES (?,?,?,?)");
$updateBet = $pdo->prepare("UPDATE bets SET status = ?, settled_at = NOW(), actual_return = ? WHERE id = ?");

$totalSettled = 0;

foreach ($bySport as $sport => $events) {
  // 2) Fetch recent scores for this sport
  $scoresUrl = sprintf(
    'https://api.the-odds-api.com/v4/sports/%s/scores?daysFrom=%d&dateFormat=iso&apiKey=%s',
    rawurlencode($sport), DAYS_FROM, urlencode($apiKey)
  );

  $ch = curl_init($scoresUrl);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false || $code !== 200) {
    fwrite(STDERR, "[{$sport}] Scores fetch failed: HTTP {$code} {$err}\n");
    continue;
  }

  $scoreData = json_decode($resp, true);
  if (!is_array($scoreData)) {
    fwrite(STDERR, "[{$sport}] Invalid scores JSON\n");
    continue;
  }

  // Build a quick lookup: event_id -> { completed, scores[] }
  $scoreMap = [];
  foreach ($scoreData as $e) {
    if (!isset($e['id'])) continue;
    $scoreMap[$e['id']] = $e;
  }

  // 3) For each event in this sport, settle all pending bets
  foreach ($events as $ev) {
    $eventId = $ev['event_id'];
    $home = $ev['home_team'];
    $away = $ev['away_team'];

    if (!isset($scoreMap[$eventId])) {
      // No result yet; skip until next run
      continue;
    }

    $info = $scoreMap[$eventId];

    // Try to extract winner from scores
    $completed = (bool)($info['completed'] ?? false);
    $scoresArr = $info['scores'] ?? [];

    $winner = null;
    if ($completed) {
      $homeScore = null;
      $awayScore = null;

      if (is_array($scoresArr)) {
        foreach ($scoresArr as $row) {
          if (!is_array($row)) continue;
          $name = $row['name'] ?? '';
          if ($name === '') continue;
          $scoreVal = isset($row['score']) ? (float)$row['score'] : null;
          if ($scoreVal === null) continue;

          if (strcasecmp($name, $home) === 0) {
            $homeScore = $scoreVal;
          } elseif (strcasecmp($name, $away) === 0) {
            $awayScore = $scoreVal;
          }
        }
      }

      if ($homeScore === null && isset($info['home_score'])) {
        $homeScore = (float)$info['home_score'];
      }
      if ($awayScore === null && isset($info['away_score'])) {
        $awayScore = (float)$info['away_score'];
      }

      if ($homeScore !== null && $awayScore !== null) {
        if ($homeScore > $awayScore) {
          $winner = $home;
        } elseif ($awayScore > $homeScore) {
          $winner = $away;
        } else {
          $winner = 'TIE';
        }
      }
    }

    // Settle all pending bets for this event
    $getBets = $pdo->prepare("SELECT * FROM bets WHERE event_id = ? AND status = 'pending'");
    $getBets->execute([$eventId]);
    $pendingBets = $getBets->fetchAll();

    foreach ($pendingBets as $b) {
      $userId = (int)$b['user_id'];
      $stake  = (float)$b['stake'];
      $payout = 0.0;
      $newStatus = 'pending';
      $reason = '';

      if (!$completed || $winner === null) {
        // Not completed or no score info: skip for now
        continue;
      }

      if ($winner === 'TIE') {
        // Refund stake
        $newStatus = 'void';
        $payout = $stake;
        $reason = 'bet_void_refund';
      } else {
        if ($b['outcome'] === $winner) {
          $newStatus = 'won';
          $payout = (float)$b['stake'] * (float)$b['odds'];
          $reason = 'bet_payout';
        } else {
          $newStatus = 'lost';
          $payout = 0.0;
          $reason = 'bet_loss';
        }
      }

      try {
        $pdo->beginTransaction();

        // Update bet
        $updateBet->execute([$newStatus, $payout, $b['id']]);

        // If refund or win, credit wallet
        if ($payout > 0) {
          $lockWallet->execute([$userId]);
          $wallet = $lockWallet->fetch();
          if (!$wallet) throw new RuntimeException('Wallet not found for settlement');
          $newBal = (float)$wallet['balance'] + $payout;
          $updateWallet->execute([$newBal, $userId]);
          $logTxn->execute([$userId, $payout, $newBal, $reason]);
        }

        $pdo->commit();
        $totalSettled++;
      } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Settle failed for bet {$b['id']}: {$e->getMessage()}\n");
      }
    }
  }
}

echo "Settled {$totalSettled} bet(s).\n";
