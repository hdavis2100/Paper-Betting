<?php
declare(strict_types=1);

session_name('SPORTSBETSESSID');  // avoids cookie clashes with other apps
session_start();

require __DIR__ . '/db.php';
require __DIR__ . '/schema.php';

ensure_app_schema($pdo);

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

function format_decimal_point(?float $value): string
{
  if ($value === null) {
    return '';
  }

  $rounded = round($value, 3);
  $str = number_format($rounded, 3, '.', '');
  $str = rtrim(rtrim($str, '0'), '.');
  if ($str === '-0') {
    $str = '0';
  }
  return $str;
}

function format_spread_point(?float $value): string
{
  if ($value === null) {
    return '';
  }

  $formatted = format_decimal_point($value);
  if ($formatted === '') {
    return '';
  }

  if ($formatted[0] !== '-' && $formatted[0] !== '+') {
    $formatted = '+' . $formatted;
  }

  return $formatted;
}

function format_market_label(string $market): string
{
  $map = [
    'h2h'     => 'Moneyline',
    'spreads' => 'Spreads',
    'totals'  => 'Totals',
  ];

  return $map[strtolower($market)] ?? ucfirst($market);
}

function format_market_outcome_label(string $market, string $outcome, ?float $line): string
{
  $marketLower = strtolower($market);

  if ($marketLower === 'spreads' && $line !== null) {
    return trim($outcome . ' ' . format_spread_point($line));
  }

  if ($marketLower === 'totals' && $line !== null) {
    return trim($outcome . ' ' . format_decimal_point($line));
  }

  return $outcome;
}

function supported_markets(): array
{
  return ['h2h', 'spreads', 'totals'];
}

function normalize_market(string $market): string
{
  $market = strtolower($market);
  return in_array($market, supported_markets(), true) ? $market : 'h2h';
}
