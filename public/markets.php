<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$sport = trim($_GET['sport'] ?? '');
if ($sport === '') {
  header('Location: /sportsbet/public/sports.php');
  exit;
}

$target = '/sportsbet/public/browse.php?sport=' . urlencode($sport);
if (isset($_GET['limit'])) {
  $target .= '&limit=' . urlencode((string) (int) $_GET['limit']);
}

header('Location: ' . $target, true, 302);
exit;
