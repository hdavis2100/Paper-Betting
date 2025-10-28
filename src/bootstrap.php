<?php
declare(strict_types=1);

session_name('SPORTSBETSESSID');  // avoids cookie clashes with other apps
session_start();

if (!defined('APP_URL_PREFIX')) {
  define('APP_URL_PREFIX', '/sportsbet/public');
}

require __DIR__ . '/db.php';

function app_url(string $path = ''): string {
  $prefix = rtrim(APP_URL_PREFIX, '/');
  if ($path === '') {
    return $prefix;
  }
  return $prefix . '/' . ltrim($path, '/');
}

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_login(): void {
  if (!current_user()) {
    header('Location: ' . app_url('login.php'));
    exit;
  }
}
