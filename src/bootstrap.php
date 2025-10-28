<?php
declare(strict_types=1);

session_name('BETLEAGUESESSID');
session_start();

if (!defined('APP_URL_PREFIX')) {
  define('APP_URL_PREFIX', '/sportsbet/public');
}

require __DIR__ . '/db.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/tracking.php';

ensure_app_schema($pdo);

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

function format_american_odds(float $decimal): string {
  return decimal_to_american_odds($decimal);
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

function build_fulltext_boolean_terms(string $query): string
{
  $terms = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
  $booleanTerms = [];

  foreach ($terms as $term) {
    $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $term);
    if ($clean === '') {
      continue;
    }

    $booleanTerms[] = '+' . $clean . '*';
  }

  return implode(' ', $booleanTerms);
}

function fetch_user_stats(PDO $pdo, int $userId): array
{
  $stmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS wins,
        SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS losses,
        SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) AS voids,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        COUNT(*) AS total,
        SUM(CASE WHEN status IN ('won','lost','void','cancelled') THEN 1 ELSE 0 END) AS settled,
        COALESCE(SUM(stake), 0) AS total_staked,
        COALESCE(SUM(CASE WHEN status IN ('won','lost','void','cancelled') THEN stake ELSE 0 END), 0) AS settled_staked,
        COALESCE(SUM(CASE WHEN status IN ('won','lost','void','cancelled') THEN actual_return ELSE 0 END), 0) AS settled_return,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN potential_return ELSE 0 END), 0) AS pending_potential
      FROM bets
      WHERE user_id = ?"
  );
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

  $wins = (int)($row['wins'] ?? 0);
  $losses = (int)($row['losses'] ?? 0);
  $voids = (int)($row['voids'] ?? 0);
  $cancelled = (int)($row['cancelled'] ?? 0);
  $pending = (int)($row['pending'] ?? 0);
  $total = (int)($row['total'] ?? 0);
  $settled = (int)($row['settled'] ?? 0);
  $totalStaked = (float)($row['total_staked'] ?? 0.0);
  $settledStaked = (float)($row['settled_staked'] ?? 0.0);
  $settledReturn = (float)($row['settled_return'] ?? 0.0);
  $pendingPotential = (float)($row['pending_potential'] ?? 0.0);

  $netProfit = $settledReturn - $settledStaked;
  $winLossRatio = $losses > 0 ? $wins / max($losses, 1) : null;
  $winRate = $settled > 0 ? $wins / $settled : null;

  return [
    'wins' => $wins,
    'losses' => $losses,
    'voids' => $voids,
    'cancelled' => $cancelled,
    'pending' => $pending,
    'total' => $total,
    'settled' => $settled,
    'total_staked' => $totalStaked,
    'settled_staked' => $settledStaked,
    'settled_return' => $settledReturn,
    'pending_potential' => $pendingPotential,
    'net_profit' => $netProfit,
    'win_loss_ratio' => $winLossRatio,
    'win_rate' => $winRate,
  ];
}

function fetch_user_profit_timeseries(PDO $pdo, int $userId, int $daysBack = 90): array
{
  $daysBack = max(1, $daysBack);

  $utc = new DateTimeZone('UTC');
  $cutoff = (new DateTimeImmutable('now', $utc))
    ->modify(sprintf('-%d days', $daysBack))
    ->format('Y-m-d H:i:s');

  $stmt = $pdo->prepare(
    "SELECT DATE(COALESCE(settled_at, placed_at)) AS day,
            SUM(actual_return - stake) AS net_change
       FROM bets
      WHERE user_id = ?
        AND status IN ('won','lost','void','cancelled')
        AND COALESCE(settled_at, placed_at) >= ?
      GROUP BY DATE(COALESCE(settled_at, placed_at))
      ORDER BY DATE(COALESCE(settled_at, placed_at))"
  );
  $stmt->execute([$userId, $cutoff]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $series = [];
  $running = 0.0;

  foreach ($rows as $row) {
    $day = $row['day'] ?? null;
    if (!$day) {
      continue;
    }

    $change = (float)($row['net_change'] ?? 0.0);
    $running += $change;
    $series[] = [
      'day' => $day,
      'net' => round($running, 2),
    ];
  }

  return $series;
}

function best_h2h_snapshot(PDO $pdo, array $events): array
{
  if (!$events) {
    return [];
  }

  $eventIds = [];
  $homeTeams = [];
  $awayTeams = [];

  foreach ($events as $event) {
    if (!isset($event['event_id'])) {
      continue;
    }

    $eventId = (string) $event['event_id'];
    $eventIds[] = $eventId;
    $homeTeams[$eventId] = (string) ($event['home_team'] ?? '');
    $awayTeams[$eventId] = (string) ($event['away_team'] ?? '');
  }

  if (!$eventIds) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
  $sql = "SELECT event_id, outcome, price, bookmaker FROM odds WHERE market = 'h2h' AND event_id IN ($placeholders)";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($eventIds);

  $snapshot = [];
  foreach ($eventIds as $eventId) {
    $snapshot[$eventId] = ['home' => null, 'away' => null];
  }

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!isset($row['event_id'])) {
      continue;
    }

    $eventId = (string) $row['event_id'];
    if (!isset($snapshot[$eventId])) {
      continue;
    }

    $outcome = (string) ($row['outcome'] ?? '');
    $price = isset($row['price']) ? (float) $row['price'] : null;
    $bookmaker = trim((string) ($row['bookmaker'] ?? ''));

    if ($price === null) {
      continue;
    }

    if ($outcome === ($homeTeams[$eventId] ?? null)) {
      $current = $snapshot[$eventId]['home'];
      if ($current === null || $price > $current['price']) {
        $snapshot[$eventId]['home'] = ['price' => $price, 'bookmaker' => $bookmaker];
      }
      continue;
    }

    if ($outcome === ($awayTeams[$eventId] ?? null)) {
      $current = $snapshot[$eventId]['away'];
      if ($current === null || $price > $current['price']) {
        $snapshot[$eventId]['away'] = ['price' => $price, 'bookmaker' => $bookmaker];
      }
    }
  }

  return $snapshot;
}
