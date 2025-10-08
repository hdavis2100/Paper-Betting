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

$config = require '/var/www/secure_config/sportsbet_config.php';
$apiKey = $config['odds_api_key'] ?? '';
if ($apiKey === '') {
  fwrite(STDERR, "Missing odds_api_key in secure config.\n");
  exit(1);
}

/** Region/markets config (adjust as you like) */
const REGION  = 'uk';          // 'us','uk','eu','au'
const MARKETS = 'h2h';         // 'h2h,spreads,totals' etc.

/** Sports to fetch (edit this list). CLI can override with comma-separated list. */
$defaultSports = ['soccer_epl', 'basketball_nba', 'americanfootball_nfl'];
if (!empty($argv[1])) {
  $sports = array_filter(array_map('trim', explode(',', $argv[1])));
} else {
  $sports = $defaultSports;
}

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
  "INSERT INTO odds (event_id, bookmaker, market, outcome, price)
   VALUES (:event_id, :bookmaker, :market, :outcome, :price)"
);

$totalEvents = 0;
$totalOdds   = 0;

foreach ($sports as $sportKey) {
  $url = sprintf(
    'https://api.the-odds-api.com/v4/sports/%s/odds?regions=%s&markets=%s&oddsFormat=decimal&dateFormat=iso&apiKey=%s',
    urlencode($sportKey), urlencode(REGION), urlencode(MARKETS), urlencode($apiKey)
  );

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false || $code !== 200) {
    // Don’t abort the whole run; move to next sport.
    fwrite(STDERR, "[{$sportKey}] Fetch failed: HTTP {$code} {$err}\nResponse: {$resp}\n");
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
      foreach ($event['bookmakers'] as $bk) {
        $bookmaker = $bk['title'] ?? ($bk['key'] ?? 'unknown');
        if (empty($bk['markets'])) continue;
        foreach ($bk['markets'] as $m) {
          $market = $m['key'] ?? 'unknown';
          if (empty($m['outcomes'])) continue;
          foreach ($m['outcomes'] as $o) {
            $name  = $o['name']  ?? '';
            $price = isset($o['price']) ? (float)$o['price'] : null;
            if ($name === '' || $price === null) continue;

            $insOdds->execute([
              ':event_id'  => $eventId,
              ':bookmaker' => $bookmaker,
              ':market'    => $market,
              ':outcome'   => $name,
              ':price'     => $price,
            ]);
            $countOdds++;
          }
        }
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

