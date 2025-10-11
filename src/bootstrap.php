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

function format_american_odds(float $decimal): string {
  if ($decimal <= 1.0) {
    return 'N/A';
  }

  if ($decimal >= 2.0) {
    $value = (int) round(($decimal - 1.0) * 100.0);
    return sprintf('+%d', $value);
  }

  $value = (int) round(-100.0 / ($decimal - 1.0));
  return (string) $value;
}

function format_est_datetime(?string $utcString): string {
  if ($utcString === null) {
    return '';
  }

  $trimmed = trim($utcString);
  if ($trimmed === '') {
    return '';
  }

  try {
    $dt = new DateTime($trimmed, new DateTimeZone('UTC'));
  } catch (Throwable $e) {
    return $utcString;
  }

  static $eastern = null;
  if ($eastern === null) {
    $eastern = new DateTimeZone('America/New_York');
  }

  $dt->setTimezone($eastern);
  return $dt->format('Y-m-d g:i A T');
}
