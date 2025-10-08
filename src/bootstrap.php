<?php
declare(strict_types=1);

session_name('SPORTSBETSESSID');  // avoids cookie clashes with other apps
session_start();

require __DIR__ . '/db.php';

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_login(): void {
  if (!current_user()) {
    header('Location: /sportsbet/public/login.php');
    exit;
  }
}
