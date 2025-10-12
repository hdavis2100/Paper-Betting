<?php
declare(strict_types=1);

/**
 * Usage:
 *   php /var/www/html/sportsbet/src/fetch_odds.php
 *   php /var/www/html/sportsbet/src/fetch_odds.php basketball_nba,americanfootball_nfl
 *
 * Requires: curl, TheOddsAPI key in /var/www/secure_config/sportsbet_config.php
 */

require __DIR__ . '/db.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/tracking.php';
require __DIR__ . '/http.php';

ensure_app_schema($pdo);

$config = require '/var/www/secure_config/sportsbet_config.php';
$apiKey = $config['odds_api_key'] ?? '';
if ($apiKey === '') {
  fwrite(STDERR, "Missing odds_api_key in secure config.\n");
  exit(1);
}

$preferredBookmakerKey   = trim((string)($config['preferred_bookmaker_key']   ?? '')) ?: null;
$preferredBookmakerTitle = trim((string)($config['preferred_bookmaker_title'] ?? '')) ?: null;
$preferredBookmakerLabel = trim((string)($config['preferred_bookmaker_label'] ?? '')) ?: null;

/** Region/markets config (adjust as you like) */
const REGION  = 'uk';          // 'us','uk','eu','au'
const MARKETS = 'h2h';         // 'h2h,spreads,totals' etc.

const BLOCKED_MARKETS = ['h2h_lay'];

/** Sports to fetch (edit this list). CLI can override with comma-separated list. */
$defaultSports = ['soccer_epl', 'basketball_nba', 'americanfootball_nfl'];
$sports = $defaultSports;

$firstArgConsumed = false;
for ($i = 1; $i < $argc; $i++) {
  $arg = $argv[$i];

  if (!$firstArgConsumed && strpos($arg, '=') === false) {
    $sports = array_filter(array_map('trim', explode(',', $arg)));
    $firstArgConsumed = true;
    continue;
  }

  if (strpos($arg, '=') === false) {
    continue;
  }

  [$key, $value] = explode('=', $arg, 2);
  $key = strtolower(trim($key));
  $value = trim($value);

  switch ($key) {
    case 'sports':
      $sports = array_filter(array_map('trim', explode(',', $value)));
      break;
    case 'bookmaker':
    case 'bookmaker_key':
      $preferredBookmakerKey = $value !== '' ? $value : null;
      break;
    case 'bookmaker_title':
      $preferredBookmakerTitle = $value !== '' ? $value : null;
      break;
    case 'bookmaker_label':
      $preferredBookmakerLabel = $value !== '' ? $value : null;
      break;
  }
}

$bookmakerFilterActive = $preferredBookmakerKey !== null || $preferredBookmakerTitle !== null;

$insEvent = $pdo->prepare(
  "INSERT INTO events (event_id, sport_key, commence_time, home_team, away_team, status)
   VALUES (:id, :sport_key, :commence_time, :home_team, :away_team, 'open')
   ON DUPLICATE KEY UPDATE
     sport_key = VALUES(sport_key),
     commence_time = VALUES(commence_time),
     home_team = VALUES(home_team),
     away_team = VALUES(away_team)"
);

$delOdds = $pdo->prepare("DELETE FROM odds WHERE event_id = ?");

$insOdds = $pdo->prepare(
  "INSERT INTO odds (event_id, bookmaker, market, outcome, price, line)
   VALUES (:event_id, :bookmaker, :market, :outcome, :price, :line)"
);

$totalEvents = 0;
$totalOdds   = 0;

foreach ($sports as $sportKey) {
  $url = sprintf(
    'https://api.the-odds-api.com/v4/sports/%s/odds?regions=%s&markets=%s&oddsFormat=decimal&dateFormat=iso&apiKey=%s',
    urlencode($sportKey), urlencode(REGION), urlencode(MARKETS), urlencode($apiKey)
  );

  [$code, $resp, $headers, $err] = oddsapi_request($url, 'odds:' . $sportKey, [CURLOPT_TIMEOUT => 25]);

  if ($resp === null || $code !== 200) {
    $remain = $headers['x-requests-remaining'] ?? $headers['requests-remaining'] ?? 'n/a';
    $used   = $headers['x-requests-used']      ?? $headers['requests-used']      ?? 'n/a';
    fwrite(STDERR, "[{$sportKey}] Fetch failed: HTTP {$code} " . ($err ?? '') . " | remaining={$remain} used={$used}\nResponse: " . substr((string)$resp, 0, 300) . "\n");
    continue;
  }

  $data = json_decode($resp, true);
  if (!is_array($data)) {
    fwrite(STDERR, "[{$sportKey}] Invalid JSON from API\n");
    continue;
  }

  $countEvents = 0;
  $countOdds   = 0;

  foreach ($data as $event) {
    $eventId  = $event['id'] ?? null;
    $timeIso  = $event['commence_time'] ?? null;
    $home     = $event['home_team'] ?? '';
    $away     = $event['away_team'] ?? '';

    if (!$eventId || !$timeIso || $home === '' || $away === '') {
      continue;
    }

    $insEvent->execute([
      ':id'            => $eventId,
      ':sport_key'     => $sportKey,
      ':commence_time' => date('Y-m-d H:i:s', strtotime($timeIso)),
      ':home_team'     => $home,
      ':away_team'     => $away,
    ]);
    $countEvents++;

    // Replace odds for this event (simple + safe for MVP)
    $delOdds->execute([$eventId]);

    if (!empty($event['bookmakers'])) {
      $matchedPreferred = false;
      foreach ($event['bookmakers'] as $bk) {
        $bkKey   = $bk['key']   ?? '';
        $bkTitle = $bk['title'] ?? ($bkKey ?: 'unknown');

        if ($bookmakerFilterActive) {
          if ($preferredBookmakerKey !== null) {
            if ($bkKey !== $preferredBookmakerKey) {
              continue;
            }
          } elseif ($preferredBookmakerTitle !== null) {
            if (strcasecmp($bkTitle, $preferredBookmakerTitle) !== 0) {
              continue;
            }
          }
        }

        $matchedPreferred = true;
        $bookmakerLabel = $bkTitle;
        if ($bookmakerFilterActive) {
          if ($preferredBookmakerLabel !== null) {
            $bookmakerLabel = $preferredBookmakerLabel;
          } elseif ($preferredBookmakerTitle !== null) {
            $bookmakerLabel = $preferredBookmakerTitle;
          }
        }

        if (empty($bk['markets'])) continue;
        foreach ($bk['markets'] as $m) {
          $market = $m['key'] ?? 'unknown';
          if (in_array($market, BLOCKED_MARKETS, true)) {
            continue;
          }
          if (empty($m['outcomes'])) continue;
          foreach ($m['outcomes'] as $o) {
            $name  = $o['name']  ?? '';
            $price = isset($o['price']) ? (float)$o['price'] : null;
            if ($name === '' || $price === null) continue;

            $line = null;
            if (array_key_exists('point', $o) && $o['point'] !== null && $o['point'] !== '') {
              if (is_numeric($o['point'])) {
                $line = (float)$o['point'];
              }
            }

            $insOdds->execute([
              ':event_id'  => $eventId,
              ':bookmaker' => $bookmakerLabel,
              ':market'    => $market,
              ':outcome'   => $name,
              ':price'     => $price,
              ':line'      => $line,
            ]);
            record_tracked_notifications($pdo, $eventId, $market, $name, $line, $price, $bookmakerLabel);
            $countOdds++;
          }
        }
      }
      if ($bookmakerFilterActive && !$matchedPreferred) {
        fwrite(STDERR, "[{$sportKey}] No odds from preferred bookmaker for event {$eventId}\n");
      }
    }
  }

  $totalEvents += $countEvents;
  $totalOdds   += $countOdds;
  echo "[{$sportKey}] upserted {$countEvents} events, inserted {$countOdds} odds\n";

  // Polite pause to avoid hammering the API (tune as needed)
  usleep(200000); // 200ms
}

echo "OK: total upserted {$totalEvents} events, total inserted {$totalOdds} odds across " . count($sports) . " sport(s)\n";

