<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$userId = current_user()['id'];

$stmt = $pdo->prepare(
  "SELECT b.id, b.event_id, b.market, b.outcome, b.line, b.odds, b.stake, b.potential_return, b.status, b.placed_at,
          e.home_team, e.away_team, e.commence_time
   FROM bets b
   JOIN events e ON b.event_id = e.event_id
   WHERE b.user_id = ?
   ORDER BY b.placed_at DESC
   LIMIT 200"
);
$stmt->execute([$userId]);
$bets = $stmt->fetchAll();
include __DIR__ . '/partials/header.php';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>My Bets</title>
  <style>
    table { border-collapse: collapse; width:100%; }
    th, td { border:1px solid #ccc; padding:6px 8px; }
    th { background:#f5f5f5; text-align:left; }
  </style>
</head>
<body>
  

  <h1>My Bets</h1>
  <?php if (!$bets): ?>
    <p>No bets yet.</p>
  <?php else: ?>
    <table>
      <tr>
        <th>Placed (ET)</th>
        <th>Match</th>
        <th>Outcome</th>
        <th>Odds</th>
        <th>Stake</th>
        <th>Potential</th>
        <th>Status</th>
      </tr>
      <?php foreach ($bets as $b): ?>
        <tr>
          <td><?= htmlspecialchars(format_est_datetime($b['placed_at'])) ?></td>
          <td><?= htmlspecialchars($b['home_team']) ?> vs <?= htmlspecialchars($b['away_team']) ?><br>
              <small><?= htmlspecialchars(format_est_datetime($b['commence_time'])) ?></small></td>
          <td><?= htmlspecialchars(format_market_label($b['market'])) ?> — <?= htmlspecialchars(format_market_outcome_label($b['market'], $b['outcome'], $b['line'] !== null ? (float)$b['line'] : null)) ?></td>
          <td><?= htmlspecialchars(format_american_odds((float)$b['odds'])) ?></td>
          <td><?= htmlspecialchars(number_format((float)$b['stake'], 2)) ?></td>
          <td><?= htmlspecialchars(number_format((float)$b['potential_return'], 2)) ?></td>
          <td><?= htmlspecialchars($b['status']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</body>
</html>
<?php include __DIR__ . '/partials/footer.php'; ?>