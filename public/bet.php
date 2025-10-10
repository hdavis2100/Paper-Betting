<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();
include __DIR__ . '/partials/header.php';

/**
 * GET ?event_id=...&outcome=...  -> show form
 * POST                            -> place bet atomically
 */

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $eventId = $_POST['event_id'] ?? '';
  $outcome = $_POST['outcome'] ?? '';
  $stake   = isset($_POST['stake']) ? (float)$_POST['stake'] : 0.0;

  if ($eventId === '' || $outcome === '' || $stake <= 0) {
    $errors[] = 'Please provide event, outcome, and a positive stake.';
  }

  if (!$errors) {
    // latest H2H price for that outcome
    $stmt = $pdo->prepare("SELECT price FROM odds WHERE event_id = ? AND market = 'h2h' AND outcome = ? ORDER BY last_updated DESC LIMIT 1");
    $stmt->execute([$eventId, $outcome]);
    $od = $stmt->fetch();


    if (!$od) {
      $errors[] = 'Odds not found or outcome unavailable.';
    } else {
      $odds = (float)$od['price'];
      $potential = round($stake * $odds, 2);
      $userId = current_user()['id'];
    
      try {
        // verify event hasn't started yet
        $check = $pdo->prepare("SELECT commence_time FROM events WHERE event_id = ? LIMIT 1");
        $check->execute([$eventId]);
        $row = $check->fetch();
        if (!$row) {
          throw new RuntimeException('Invalid event.');
        }

        $now = new DateTime('now', new DateTimeZone('UTC'));
        $kickoff = new DateTime($row['commence_time'], new DateTimeZone('UTC'));
        if ($kickoff <= $now) {
          throw new RuntimeException('This event has already started. Betting is closed.');
        }

        $pdo->beginTransaction();

        // lock wallet row
        $w = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE");
        $w->execute([$userId]);
        $wallet = $w->fetch();
        if (!$wallet) throw new RuntimeException('Wallet not found.');
        if ((float)$wallet['balance'] < $stake) throw new RuntimeException('Insufficient funds.');

        // debit
        $newBal = (float)$wallet['balance'] - $stake;
        $pdo->prepare("UPDATE wallets SET balance = ? WHERE user_id = ?")->execute([$newBal, $userId]);

        // record bet
        $pdo->prepare("INSERT INTO bets (user_id, event_id, outcome, odds, stake, potential_return)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$userId, $eventId, $outcome, $odds, $stake, $potential]);

        // audit
        $pdo->prepare("INSERT INTO wallet_transactions (user_id, change_amt, balance_after, reason)
                       VALUES (?,?,?,'bet')")
            ->execute([$userId, -$stake, $newBal]);

        $pdo->commit();
        $success = "Bet placed! Potential return: " . number_format($potential, 2);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        $errors[] = $e->getMessage();
      }
    }
  }

  // preserve selection after POST
  $_GET['event_id'] = $_POST['event_id'] ?? ($_GET['event_id'] ?? '');
  $_GET['outcome']  = $_POST['outcome'] ?? ($_GET['outcome'] ?? '');
}

$eventId = $_GET['event_id'] ?? '';
$outcomeParam = $_GET['outcome'] ?? '';

$event = null; $homeBest = null; $awayBest = null;

if ($eventId !== '') {
  $e = $pdo->prepare("SELECT event_id, home_team, away_team, commence_time FROM events WHERE event_id = ? LIMIT 1");
  $e->execute([$eventId]);
  $event = $e->fetch();

  if ($event) {
    $best = $pdo->prepare("SELECT outcome, MAX(price) AS p FROM odds WHERE event_id = ? AND market='h2h' GROUP BY outcome");
        // Prevent betting on past or live events
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $kickoff = new DateTime($event['commence_time'], new DateTimeZone('UTC'));
    if ($kickoff <= $now) {
        echo "<div class='msg err'>Betting is closed for this event (already started).</div>";
        include __DIR__ . '/partials/footer.php';
        exit;
    }

    $best->execute([$eventId]);
    $m = [];
    foreach ($best->fetchAll() as $r) { $m[$r['outcome']] = $r['p']; }
    $homeBest = $m[$event['home_team']] ?? null;
    $awayBest = $m[$event['away_team']] ?? null;
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Place Bet</title>
  <style>
    label { display:block; margin: 6px 0; }
    .msg { padding:8px; margin: 8px 0; border:1px solid #ccc; }
    .err { color:#b00; }
    .ok  { color:#070; }
  </style>
</head>
<body>
  <nav>
    <a href="/sportsbet/public/index.php">Home</a> |
    <a href="/sportsbet/public/events.php">Events</a> |
    <a href="/sportsbet/public/my_bets.php">My Bets</a> |
    <a href="/sportsbet/public/logout.php">Logout</a>
  </nav>

  <h1>Place Bet</h1>

  <?php foreach ($errors as $err): ?>
    <div class="msg err"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>
  <?php if ($success): ?>
    <div class="msg ok"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <?php if (!$event): ?>
    <p>Select a bet from the <a href="/sportsbet/public/events.php">Events</a> list.</p>
  <?php else: ?>
    <p><strong><?= htmlspecialchars($event['home_team']) ?> vs <?= htmlspecialchars($event['away_team']) ?></strong></p>
    <p>Kickoff: <?= htmlspecialchars($event['commence_time']) ?></p>
    <p>Best H2H — Home: <?= $homeBest ? htmlspecialchars(format_american_odds((float)$homeBest)) : '—' ?>,
       Away: <?= $awayBest ? htmlspecialchars(format_american_odds((float)$awayBest)) : '—' ?></p>

    <form method="post" action="/sportsbet/public/bet.php">
      <input type="hidden" name="event_id" value="<?= htmlspecialchars($event['event_id']) ?>">
      <label>
        Outcome
        <select name="outcome" required>
          <?php if ($homeBest): ?>
            <option value="<?= htmlspecialchars($event['home_team']) ?>" <?php if ($outcomeParam===$event['home_team']) echo 'selected'; ?>>
              <?= htmlspecialchars($event['home_team']) ?> (<?= htmlspecialchars(format_american_odds((float)$homeBest)) ?>)
            </option>
          <?php endif; ?>
          <?php if ($awayBest): ?>
            <option value="<?= htmlspecialchars($event['away_team']) ?>" <?php if ($outcomeParam===$event['away_team']) echo 'selected'; ?>>
              <?= htmlspecialchars($event['away_team']) ?> (<?= htmlspecialchars(format_american_odds((float)$awayBest)) ?>)
            </option>
          <?php endif; ?>
        </select>
      </label>
      <label>
        Stake
        <input type="number" name="stake" min="1" step="1" required placeholder="e.g., 50">
      </label>
      <button type="submit">Place Bet</button>
    </form>
  <?php endif; ?>
</body>
</html>
<?php include __DIR__ . '/partials/footer.php'; ?>
