<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

/**
 * Net profit:
 *   won:  (actual_return - stake)
 *   lost: (-stake)
 *   void: (0)
 *   pending: (0 for now)
 */
$sql = "
SELECT
  u.id,
  u.username,
  w.balance,
  COALESCE(SUM(
    CASE
      WHEN b.status='won'  THEN b.actual_return - b.stake
      WHEN b.status='lost' THEN -b.stake
      ELSE 0
    END
  ), 0) AS net_profit,
  COUNT(b.id) AS bets_count
FROM users u
JOIN wallets w ON w.user_id = u.id
LEFT JOIN bets b ON b.user_id = u.id
GROUP BY u.id, u.username, w.balance
ORDER BY w.balance DESC, net_profit DESC, bets_count DESC
LIMIT 50
";
$leaders = $pdo->query($sql)->fetchAll();
include __DIR__ . '/partials/header.php';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Leaderboard</title>
  <style>
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ddd; padding: 8px; }
    th { background: #f7f7f7; text-align: left; }
  </style>
</head>
<body>
  <nav>
    <a href="/sportsbet/public/index.php">Home</a> |
    <a href="/sportsbet/public/events.php">Events</a> |
    <a href="/sportsbet/public/my_bets.php">My Bets</a> |
    <a href="/sportsbet/public/leaderboard.php">Leaderboard</a> |
    <a href="/sportsbet/public/logout.php">Logout</a>
  </nav>

  <h1>Leaderboard</h1>
  <?php if (!$leaders): ?>
    <p>No users yet.</p>
  <?php else: ?>
    <table>
      <tr>
        <th>#</th>
        <th>User</th>
        <th>Balance</th>
        <th>Net Profit</th>
        <th>Total Bets</th>
      </tr>
      <?php $i=1; foreach ($leaders as $row): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= htmlspecialchars($row['username']) ?></td>
          <td><?= htmlspecialchars(number_format((float)$row['balance'], 2)) ?></td>
          <td><?= htmlspecialchars(number_format((float)$row['net_profit'], 2)) ?></td>
          <td><?= (int)$row['bets_count'] ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</body>
</html>
