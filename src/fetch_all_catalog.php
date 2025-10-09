<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$config = require '/var/www/secure_config/sportsbet_config.php';
$apiKey = $config['odds_api_key'];
$base = 'https://api.the-odds-api.com/v4';

// Step 1: fetch list of all sports
$sports = json_decode(file_get_contents("$base/sports/?apiKey=$apiKey"), true);
if (!is_array($sports)) { exit("Failed to fetch sports\n"); }

$insSport = $pdo->prepare("
  INSERT INTO sports (sport_key, title, group_name, has_outrights)
  VALUES (:key,:title,:group_name,:has_outrights)
  ON DUPLICATE KEY UPDATE title=VALUES(title),group_name=VALUES(group_name),has_outrights=VALUES(has_outrights)
");

foreach ($sports as $s) {
  if (empty($s['active'])) continue;
  $insSport->execute([
    ':key' => $s['key'],
    ':title' => $s['title'] ?? $s['key'],
    ':group_name' => $s['group'] ?? '',
    ':has_outrights' => (int)($s['has_outrights'] ?? 0),
  ]);
}

// Step 2: fetch odds for each sport
$regions = 'us,uk,eu';
$markets = 'h2h,spreads,totals,btts,outrights,draw_no_bet';

$insEvent = $pdo->prepare("
  INSERT INTO events (event_id, sport_key, commence_time, home_team, away_team, status)
  VALUES (:id,:sport_key,:time,:home,:away,'open')
  ON DUPLICATE KEY UPDATE commence_time=VALUES(commence_time),home_team=VALUES(home_team),away_team=VALUES(away_team)
");

$delOdds = $pdo->prepare("DELETE FROM odds WHERE event_id=?");

$insOdds = $pdo->prepare("
  INSERT INTO odds (event_id, bookmaker, market, outcome, price)
  VALUES (:event_id,:bookmaker,:market,:outcome,:price)
");

foreach ($sports as $s) {
  if (empty($s['active'])) continue;
  $sportKey = $s['key'];
  echo "Fetching $sportKey...\n";
  $url = "$base/sports/$sportKey/odds/?regions=$regions&markets=$markets&apiKey=$apiKey";
  $data = json_decode(@file_get_contents($url), true);
  if (!is_array($data)) continue;

  foreach ($data as $event) {
    $id = $event['id'] ?? null;
    if (!$id) continue;
    $insEvent->execute([
      ':id' => $id,
      ':sport_key' => $sportKey,
      ':time' => date('Y-m-d H:i:s', strtotime($event['commence_time'] ?? 'now')),
      ':home' => $event['home_team'] ?? '',
      ':away' => $event['away_team'] ?? ''
    ]);

    $delOdds->execute([$id]);
    foreach ($event['bookmakers'] ?? [] as $bk) {
      foreach ($bk['markets'] ?? [] as $m) {
        foreach ($m['outcomes'] ?? [] as $o) {
          if (!isset($o['name'],$o['price'])) continue;
          $insOdds->execute([
            ':event_id'=>$id,
            ':bookmaker'=>$bk['key'] ?? '',
            ':market'=>$m['key'] ?? '',
            ':outcome'=>$o['name'],
            ':price'=>$o['price']
          ]);
        }
      }
    }
  }
  sleep(1);
}
echo "Catalog populated.\n";
