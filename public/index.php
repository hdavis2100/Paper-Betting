<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

// Fetch wallet balance
$stmt = $pdo->prepare('SELECT balance FROM wallets WHERE user_id = ? LIMIT 1');
$stmt->execute([current_user()['id']]);
$wallet = $stmt->fetch();
$balance = $wallet ? (float)$wallet['balance'] : 0.0;
include __DIR__ . '/partials/header.php';
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Dashboard</title></head>
<body>
  <p>Logged in as <strong><?= htmlspecialchars(current_user()['username']) ?></strong></p>
  <p>Wallet balance: <strong><?= htmlspecialchars(number_format($balance, 2)) ?></strong></p>

  

  <hr>
  <p>Next up: events & odds from TheOddsAPI, placing a bet, and a simple leaderboard.</p>
</body>
</html>
<?php include __DIR__ . '/partials/footer.php'; ?>