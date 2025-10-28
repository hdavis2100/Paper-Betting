<?php
declare(strict_types=1);

session_name('SPORTSBETSESSID');  // avoids cookie clashes with other apps
session_start();

$urlPrefix = $_ENV['APP_URL_PREFIX'] ?? getenv('APP_URL_PREFIX');
if ($urlPrefix === false || $urlPrefix === null || $urlPrefix === '') {
  if (defined('APP_URL_PREFIX')) {
    $urlPrefix = APP_URL_PREFIX;
  } else {
    $urlPrefix = '/sportsbet/public';
  }
}

if (stripos($urlPrefix, 'betleague') !== false) {
  $urlPrefix = '/sportsbet/public';
}

$GLOBALS['_app_url_prefix'] = $urlPrefix;

require __DIR__ . '/db.php';

function app_url(string $path = ''): string {
  $prefix = rtrim($GLOBALS['_app_url_prefix'] ?? '/sportsbet/public', '/');
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
