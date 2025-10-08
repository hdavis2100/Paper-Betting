<?php
declare(strict_types=1);

/**
 * Usage (CLI/cron):
 *   php /var/www/html/sportsbet/src/fetch_odds.php
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

/** Choose a sport/region/markets to start with */
const SPORT_KEY = 'soccer_epl';       // e.g., 'americanfootball_nfl', 'basketball_nba', 'soccer_epl'
const REGION    = 'uk';               // 'us','uk','eu','au' (controls which books you get)
const MARKETS   = 'h2h';              // 'h2h,spreads,totals' if you want more

$url = sprintf(
  'https://api.the-odds-api.com/v4/sports/%s/odds?regions=%s&markets=%s&oddsFormat=decimal&dateFormat=iso&apiKey=%s',
  urlencode(SPORT_KEY), urlencode(REGION), urlencode(MARKETS), urlencode($apiKey)
);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 20,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false || $code !== 200) {
  fwrite(STDERR, "Fetch failed: HTTP {$code} {$err}\nResponse: {$resp}\n");
  exit(1);
}

$data = json_decode($resp, true);
if (!is_array($data)) {
  fwrite(STDERR, "Invalid JSON from API\n");
  exit(1);
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

$now = gmdate('Y-m-d H:i:s');
$countEvents = 0;
$countOdds   = 0;

foreach ($data as $event) {
  // Expected fields per TheOddsAPI v4
  $eventId  = $event['id'] ?? null;
  $sportKey = $event['sport_key'] ?? SPORT_KEY;
  $timeIso  = $event['commence_time'] ?? null;
  $home     = $event['home_team'] ?? '';
  $away     = $event['away_team'] ?? '';

  if (!$eventId || !$timeIso || $home === '' || $away === '') {
    // Skip malformed
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

  // Replace odds for this event
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

echo "OK: upserted {$countEvents} events, inserted {$countOdds} odds\n";
