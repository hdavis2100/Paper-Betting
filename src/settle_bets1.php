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
const DAYS_FROM_DEFAULT = 7;

/**
 * Optional CLI flags:
 *   --sport=SPORT_KEY          Limit to a single sport.
 *   --event=EVENT_ID           Limit to one event.
 *   --days-from=N              Override the score lookback window.
 *   --include-upcoming         Inspect bets even if the event has not started.
 *   --dump-scores              Print the fetched API score summary for matched events.
 *   --debug                    Emit verbose settlement diagnostics to stdout.
 */
$cliOpts = getopt('', [
  'sport::',
  'event::',
  'days-from::',
  'include-upcoming',
  'dump-scores',
  'debug',
]);

$sportFilter = $cliOpts['sport'] ?? null;
$eventFilter = $cliOpts['event'] ?? null;
$includeUpcoming = array_key_exists('include-upcoming', $cliOpts);
$dumpScores = array_key_exists('dump-scores', $cliOpts);
$debugMode = array_key_exists('debug', $cliOpts);

function debug_log(string $message): void {
  global $debugMode;
  if ($debugMode) {
    echo $message . "\n";
  }
}

$daysFrom = DAYS_FROM_DEFAULT;
if (isset($cliOpts['days-from'])) {
  $override = (int)$cliOpts['days-from'];
  if ($override >= 0) {
    $daysFrom = $override;
  }
}

/** 1) Gather unsettled events grouped by sport */
function normalize_team_label(string $name): string {
  $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
  if ($converted === false) {
    $converted = $name;
  }
  $converted = strtolower($converted);
  return preg_replace('/[^a-z0-9]/', '', $converted);
}

function identify_competitor(string $slug, string $homeSlug, string $awaySlug): ?string {
  if ($slug !== '' && $slug === $homeSlug) {
    return 'home';
  }
  if ($slug !== '' && $slug === $awaySlug) {
    return 'away';
  }

  if ($slug !== '' && $homeSlug !== '' && levenshtein($slug, $homeSlug) <= 2) {
    return 'home';
  }
  if ($slug !== '' && $awaySlug !== '' && levenshtein($slug, $awaySlug) <= 2) {
    return 'away';
  }

  return null;
}

function parse_numeric_score($value): ?float {
  if (is_int($value) || is_float($value)) {
    return (float)$value;
  }
  if (is_string($value)) {
    $trimmed = trim($value);
    if ($trimmed === '') {
      return null;
    }
    if (is_numeric($trimmed)) {
      return (float)$trimmed;
    }
  }
  return null;
}

function interpret_result_keyword($value): ?string {
  if (!is_string($value)) {
    return null;
  }
  $norm = strtolower(trim($value));
  if ($norm === '') {
    return null;
  }
  if (in_array($norm, ['win', 'winner', 'won'], true)) {
    return 'win';
  }
  if (in_array($norm, ['loss', 'lose', 'lost', 'loser'], true)) {
    return 'loss';
  }
  if (in_array($norm, ['draw', 'tie', 'push', 'no contest', 'no-contest', 'no_contest', 'no result', 'no-result'], true)) {
    return 'tie';
  }
  return null;
}

$conditions = ["b.status = 'pending'"];
$params = [];
if (!$includeUpcoming) {
  $conditions[] = 'e.commence_time < UTC_TIMESTAMP()';
}
if ($sportFilter !== null && $sportFilter !== '') {
  $conditions[] = 'e.sport_key = ?';
  $params[] = $sportFilter;
}
if ($eventFilter !== null && $eventFilter !== '') {
  $conditions[] = 'e.event_id = ?';
  $params[] = $eventFilter;
}

$sql = "
  SELECT DISTINCT e.sport_key, e.event_id, e.home_team, e.away_team, e.commence_time
  FROM bets b
  JOIN events e ON e.event_id = b.event_id
  WHERE " . implode(' AND ', $conditions) . '
  ORDER BY e.commence_time ASC
';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
if (!$rows) {
  $upcomingSql = "
    SELECT COUNT(*)
    FROM bets b
    JOIN events e ON e.event_id = b.event_id
    WHERE b.status = 'pending'
      AND e.commence_time >= UTC_TIMESTAMP()
  ";
  $upcomingParams = [];
  if ($sportFilter !== null && $sportFilter !== '') {
    $upcomingSql .= ' AND e.sport_key = ?';
    $upcomingParams[] = $sportFilter;
  }
  if ($eventFilter !== null && $eventFilter !== '') {
    $upcomingSql .= ' AND e.event_id = ?';
    $upcomingParams[] = $eventFilter;
  }

  $upcomingStmt = $pdo->prepare($upcomingSql);
  $upcomingStmt->execute($upcomingParams);
  $upcoming = (int)$upcomingStmt->fetchColumn();

  if ($upcoming > 0 && !$includeUpcoming) {
    echo "Nothing to settle (pending bets exist but their events have not started yet).\n";
    echo "Re-run with --include-upcoming to inspect them or verify commence_time values.\n";
  } else {
    echo "Nothing to settle for the provided filters.\n";
  }
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
  debug_log(sprintf('[sport] %s (%d event(s) pending)', $sport, count($events)));
  // 2) Fetch recent scores for this sport
  $effectiveDaysFrom = max(1, min($daysFrom, 3));
  if ($effectiveDaysFrom !== $daysFrom && !$dumpScores) {
    fwrite(STDERR, "[{$sport}] daysFrom clamped to {$effectiveDaysFrom} to satisfy API limits.\n");
  }

  $scoresUrl = sprintf(
    'https://api.the-odds-api.com/v4/sports/%s/scores?daysFrom=%d&dateFormat=iso&apiKey=%s',
    rawurlencode($sport), $effectiveDaysFrom, urlencode($apiKey)
  );

  $ch = curl_init($scoresUrl);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false || $code !== 200) {
    $bodySnippet = '';
    if (is_string($resp) && $resp !== '') {
      $bodySnippet = ' Body: ' . substr(trim($resp), 0, 300);
    }
    fwrite(STDERR, "[{$sport}] Scores fetch failed: HTTP {$code} {$err}{$bodySnippet}\n");
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
    $homeSlug = normalize_team_label($home);
    $awaySlug = normalize_team_label($away);

    if (!isset($scoreMap[$eventId])) {
      if ($dumpScores) {
        echo "[{$sport}] Event {$eventId} not returned by scores API.\n";
      }
      debug_log(sprintf('[event] %s missing from API response; skipping settlement for now.', $eventId));
      // No result yet; skip until next run
      continue;
    }

    $info = $scoreMap[$eventId];

    debug_log(sprintf('[event] %s | %s vs %s | commence=%s', $eventId, $home, $away, (string)$ev['commence_time']));

    if ($dumpScores) {
      $homePrint = $info['home_score'] ?? 'n/a';
      $awayPrint = $info['away_score'] ?? 'n/a';
      $state = ($info['completed'] ?? false) ? 'completed' : 'in-progress';
      printf(
        "[scores] %s | %s vs %s | %s | home=%s away=%s\n",
        $eventId,
        $home,
        $away,
        $state,
        (string)$homePrint,
        (string)$awayPrint
      );
    }

    // Try to extract winner from scores
    $completed = (bool)($info['completed'] ?? false);
    $scoresArr = $info['scores'] ?? [];

    $homeScore = null;
    $awayScore = null;
    $totalScore = null;
    $winner = null;
    $winnerNameHints = [];
    $orderedNumericScores = [];
    $orderedTargets = [];
    if ($completed) {
      if (is_array($scoresArr)) {
        foreach ($scoresArr as $row) {
          if (!is_array($row)) continue;
          $name = $row['name'] ?? '';
          if ($name === '') continue;
          $slug = normalize_team_label($name);
          $target = identify_competitor($slug, $homeSlug, $awaySlug);
          $orderedTargets[] = $target;

          $scoreRaw = $row['score'] ?? null;
          $scoreVal = parse_numeric_score($scoreRaw);
          $orderedNumericScores[] = $scoreVal;

          if ($scoreVal !== null) {
            if ($target === 'home' && $homeScore === null) {
              $homeScore = $scoreVal;
            } elseif ($target === 'away' && $awayScore === null) {
              $awayScore = $scoreVal;
            }
            continue;
          }

          $keyword = interpret_result_keyword($scoreRaw);
          if ($keyword === 'win') {
            if ($target === 'home') {
              $winner = $home;
            } elseif ($target === 'away') {
              $winner = $away;
            } else {
              $winnerNameHints[] = $name;
            }
          } elseif ($keyword === 'loss') {
            if ($target === 'home') {
              $winner = $away;
            } elseif ($target === 'away') {
              $winner = $home;
            }
          } elseif ($keyword === 'tie') {
            $winner = 'TIE';
          }
        }
      }

      if (($homeScore === null || $awayScore === null) && $orderedNumericScores) {
        foreach ($orderedNumericScores as $idx => $numeric) {
          if ($numeric === null) {
            continue;
          }
          $target = $orderedTargets[$idx] ?? null;
          if ($target === 'home' && $homeScore === null) {
            $homeScore = $numeric;
          } elseif ($target === 'away' && $awayScore === null) {
            $awayScore = $numeric;
          }
        }
      }

      if (($homeScore === null || $awayScore === null) && count($orderedNumericScores) === 2) {
        if ($homeScore === null && $orderedNumericScores[0] !== null) {
          $homeScore = $orderedNumericScores[0];
        }
        if ($awayScore === null && $orderedNumericScores[1] !== null) {
          $awayScore = $orderedNumericScores[1];
        }
      }

      if ($homeScore === null && isset($info['home_score'])) {
        $parsed = parse_numeric_score($info['home_score']);
        if ($parsed !== null) {
          $homeScore = $parsed;
        } else {
          $keyword = interpret_result_keyword($info['home_score']);
          if ($keyword === 'win') {
            $winner = $home;
          } elseif ($keyword === 'loss') {
            $winner = $away;
          } elseif ($keyword === 'tie') {
            $winner = 'TIE';
          }
        }
      }
      if ($awayScore === null && isset($info['away_score'])) {
        $parsed = parse_numeric_score($info['away_score']);
        if ($parsed !== null) {
          $awayScore = $parsed;
        } else {
          $keyword = interpret_result_keyword($info['away_score']);
          if ($keyword === 'win') {
            $winner = $away;
          } elseif ($keyword === 'loss') {
            $winner = $home;
          } elseif ($keyword === 'tie') {
            $winner = 'TIE';
          }
        }
      }

      if ($winner === null && $winnerNameHints) {
        foreach ($winnerNameHints as $hint) {
          $hintSlug = normalize_team_label($hint);
          $target = identify_competitor($hintSlug, $homeSlug, $awaySlug);
          if ($target === 'home') {
            $winner = $home;
            break;
          }
          if ($target === 'away') {
            $winner = $away;
            break;
          }
        }
      }

      if ($homeScore !== null && $awayScore !== null) {
        if ($winner === null) {
          if ($homeScore > $awayScore) {
            $winner = $home;
          } elseif ($awayScore > $homeScore) {
            $winner = $away;
          } else {
            $winner = 'TIE';
          }
        }
        $totalScore = $homeScore + $awayScore;
      }
    } else {
      debug_log(sprintf('[event] %s still marked in-progress; completed flag is false.', $eventId));
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

      if (!$completed) {
        debug_log(sprintf('[bet] %s (user %d) skipped: event not completed.', $b['id'], $userId));
        continue;
      }

      $marketType = strtolower($b['market'] ?? 'h2h');
      $lineValue = $b['line'] !== null ? (float)$b['line'] : null;

      if ($marketType === 'h2h') {
        if ($winner === null) {
          debug_log(sprintf('[bet] %s pending: no winner resolved yet (homeScore=%s, awayScore=%s).', $b['id'], $homeScore !== null ? (string)$homeScore : 'null', $awayScore !== null ? (string)$awayScore : 'null'));
          continue;
        }
        if ($winner === 'TIE') {
          $newStatus = 'void';
          $payout = $stake;
          $reason = 'bet_void_refund';
        } elseif ($b['outcome'] === $winner) {
          $newStatus = 'won';
          $payout = (float)$b['stake'] * (float)$b['odds'];
          $reason = 'bet_payout';
        } else {
          $newStatus = 'lost';
          $payout = 0.0;
          $reason = 'bet_loss';
        }
      } elseif ($marketType === 'spreads') {
        if ($homeScore === null || $awayScore === null || $lineValue === null) {
          debug_log(sprintf('[bet] %s pending: spread requires scores (%s/%s) and line (%s).', $b['id'], $homeScore !== null ? (string)$homeScore : 'null', $awayScore !== null ? (string)$awayScore : 'null', $lineValue !== null ? (string)$lineValue : 'null'));
          continue;
        }
        if (strcasecmp($b['outcome'], $home) === 0) {
          $teamScore = $homeScore;
          $oppScore  = $awayScore;
        } elseif (strcasecmp($b['outcome'], $away) === 0) {
          $teamScore = $awayScore;
          $oppScore  = $homeScore;
        } else {
          continue;
        }
        $adjusted = $teamScore + $lineValue;
        if ($adjusted > $oppScore + 1e-6) {
          $newStatus = 'won';
          $payout = $stake * (float)$b['odds'];
          $reason = 'bet_payout';
        } elseif (abs($adjusted - $oppScore) <= 1e-6) {
          $newStatus = 'void';
          $payout = $stake;
          $reason = 'bet_void_refund';
        } else {
          $newStatus = 'lost';
          $payout = 0.0;
          $reason = 'bet_loss';
        }
      } elseif ($marketType === 'totals') {
        if ($totalScore === null || $lineValue === null) {
          debug_log(sprintf('[bet] %s pending: total requires aggregate score (%s) and line (%s).', $b['id'], $totalScore !== null ? (string)$totalScore : 'null', $lineValue !== null ? (string)$lineValue : 'null'));
          continue;
        }
        $selection = strtolower($b['outcome']);
        if ($selection === 'over') {
          if ($totalScore > $lineValue + 1e-6) {
            $newStatus = 'won';
            $payout = $stake * (float)$b['odds'];
            $reason = 'bet_payout';
          } elseif (abs($totalScore - $lineValue) <= 1e-6) {
            $newStatus = 'void';
            $payout = $stake;
            $reason = 'bet_void_refund';
          } else {
            $newStatus = 'lost';
            $payout = 0.0;
            $reason = 'bet_loss';
          }
        } elseif ($selection === 'under') {
          if ($totalScore < $lineValue - 1e-6) {
            $newStatus = 'won';
            $payout = $stake * (float)$b['odds'];
            $reason = 'bet_payout';
          } elseif (abs($totalScore - $lineValue) <= 1e-6) {
            $newStatus = 'void';
            $payout = $stake;
            $reason = 'bet_void_refund';
          } else {
            $newStatus = 'lost';
            $payout = 0.0;
            $reason = 'bet_loss';
          }
        } else {
          continue;
        }
      } else {
        continue;
      }

      try {
        $pdo->beginTransaction();

        $updateBet->execute([$newStatus, $payout, $b['id']]);

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
        debug_log(sprintf('[bet] %s settled as %s (payout %.2f).', $b['id'], $newStatus, $payout));
      } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Settle failed for bet {$b['id']}: {$e->getMessage()}\n");
      }
    }
  }
}

echo "Settled {$totalSettled} bet(s).\n";
