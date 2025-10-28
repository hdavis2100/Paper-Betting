<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

$headerUser = current_user();
$headerBalance = null;
$headerUnreadNotifications = 0;
if ($headerUser) {
  $s = $pdo->prepare('SELECT balance FROM wallets WHERE user_id = ? LIMIT 1');
  $s->execute([$headerUser['id']]);
  $w = $s->fetch();
  $headerBalance = $w ? (float)$w['balance'] : 0.0;
  $headerUnreadNotifications = fetch_unread_notifications_count($pdo, (int)$headerUser['id']);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Betleague</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #f7f8fb; }
    .navbar-brand { font-weight: 700; letter-spacing: .2px; }
    .card { border-radius: 1rem; }
    .table thead th { background: #f0f2f6; }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom">
  <div class="container">
    <a class="navbar-brand" href="/sportsbet/public/index.php">Betleague</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
      <span class="navbar-toggler-icon"></span>
    </button>
      <div id="mainNav" class="collapse navbar-collapse">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <?php if ($headerUser): ?>
            <li class="nav-item"><a class="nav-link" href="/betleague/public/sports.php">Sports</a></li>
            <li class="nav-item"><a class="nav-link" href="/betleague/public/events.php">Events</a></li>
            <li class="nav-item"><a class="nav-link" href="/betleague/public/leaderboard.php">Leaderboard</a></li>
            <li class="nav-item"><a class="nav-link" href="/betleague/public/search.php">Search</a></li>
          <?php endif; ?>
        </ul>

        <?php if ($headerUser): ?>
          <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
            <li class="nav-item"><a class="nav-link" href="/betleague/public/index.php">Account</a></li>
            <li class="nav-item"><a class="nav-link" href="/betleague/public/my_bets.php">My Bets</a></li>
            <li class="nav-item"><a class="nav-link" href="/betleague/public/tracked.php">Tracked</a></li>
            <li class="nav-item"><a class="nav-link" href="/betleague/public/notifications.php">Notifications
              <?php if ($headerUnreadNotifications > 0): ?>
                <span class="badge text-bg-danger ms-1"><?= (int) $headerUnreadNotifications ?></span>
              <?php endif; ?>
            </a></li>
            <li class="nav-item"><a class="nav-link" href="/betleague/public/settings.php">Settings</a></li>
            <li class="nav-item">
              <span class="navbar-text ms-lg-3 me-lg-2">
                Hello, <strong><?= htmlspecialchars($headerUser['username']) ?></strong>
                <?php if ($headerBalance !== null): ?>
                  &nbsp;|&nbsp; Balance: <strong><?= number_format($headerBalance, 2) ?></strong>
                <?php endif; ?>
              </span>
            </li>
            <li class="nav-item"><a class="btn btn-outline-secondary btn-sm" href="/betleague/public/logout.php">Logout</a></li>
          </ul>
        <?php else: ?>
          <ul class="navbar-nav ms-auto">
            <li class="nav-item"><a class="btn btn-primary btn-sm" href="/betleague/public/login.php">Login</a></li>
          </ul>
        <?php endif; ?>
      </div>
  </div>
</nav>
<main class="container my-4">
