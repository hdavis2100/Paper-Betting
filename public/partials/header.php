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
    <a class="navbar-brand" href="<?= app_url('index.php') ?>">Betleague</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="mainNav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($headerUser): ?>
          <li class="nav-item"><a class="nav-link" href="<?= app_url('sports.php') ?>">Sports</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= app_url('events.php') ?>">Events</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= app_url('my_bets.php') ?>">My Bets</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= app_url('leaderboard.php') ?>">Leaderboard</a></li>
        <?php endif; ?>
      </ul>
        <form class="d-flex position-relative" role="search" action="<?= app_url('search.php') ?>" method="get">
        <input class="form-control me-2" type="search" placeholder="Search teams..." id="search-box" name="q" autocomplete="off">
        <ul id="suggestions" class="list-group position-absolute" style="top:100%;z-index:1000;width:100%;"></ul>
        </form>

        <script>
        let timer;
        const box = document.getElementById('search-box');
        const list = document.getElementById('suggestions');
        const suggestEndpoint = <?= json_encode(app_url('search_suggest.php')) ?>;
        const betBase = <?= json_encode(app_url('bet.php')) ?>;
        if (box) {
        box.addEventListener('input', () => {
            clearTimeout(timer);
            const q = box.value.trim();
            if (q.length < 2) { list.innerHTML = ''; return; }
            timer = setTimeout(async () => {
            const res = await fetch(`${suggestEndpoint}?q=${encodeURIComponent(q)}`);
            const data = await res.json();
            list.innerHTML = data.map(r =>
                `<li class="list-group-item">
                <a href="${betBase}?event_id=${encodeURIComponent(r.event_id)}">
                    ${r.home_team} vs ${r.away_team}
                </a>
                </li>`
            ).join('');
            }, 200);
        });
        document.addEventListener('click', e => {
            if (!list.contains(e.target) && e.target !== box) list.innerHTML = '';
        });
        }
        </script>

        <?php if ($headerUser): ?>
          <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
            <li class="nav-item"><a class="nav-link" href="/betleague/public/index.php">Account</a></li>
            <li class="nav-item"><a class="nav-link" href="/betleague/public/my_bets.php">My Bets</a></li>
            <li class="nav-item"><a class="nav-link" href="/betleague/public/tracked.php">Tracked</a></li>
            <li class="nav-item"><a class="nav-link" href="/betleague/public/notifications.php">Notifications
              <?php if ($headerUnreadNotifications > 0): ?>
                <span class="badge text-bg-danger ms-1"><?= (int) $headerUnreadNotifications ?></span>
              <?php endif; ?>
            </span>
          </li>
          <li class="nav-item"><a class="btn btn-outline-secondary btn-sm" href="<?= app_url('logout.php') ?>">Logout</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="btn btn-primary btn-sm" href="<?= app_url('login.php') ?>">Login</a></li>
        <?php endif; ?>
      </div>
  </div>
</nav>
<main class="container my-4">
