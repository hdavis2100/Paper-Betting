<?php
declare(strict_types=1);

/**
 * Populate your catalog from TheOddsAPI.
 *
 * Usage:
 *   php /var/www/html/sportsbet/src/fetch_all_catalog.php
 *   php /var/www/html/sportsbet/src/fetch_all_catalog.php regions=us,uk markets=h2h,spreads,totals
 *   php /var/www/html/sportsbet/src/fetch_all_catalog.php sports=basketball_nba,americanfootball_nfl
 *
 * Notes:
 * - Requires db.php and odds_api_key in /var/www/secure_config/sportsbet_config.php
 * - Skips inactive sports automatically.
 * - If `bookmakers` / `markets` tables do not exist, those inserts are silently skipped.
 */

require __DIR__ . '/db.php';

$config = require '/var/www/secure_config/sportsbet_config.php';
$apiKey = $config['odds_api_key'] ?? '';
if ($apiKey === '') {
  fwrite(STDERR, "ERROR: Missing odds_api_key in /var/www/secure_config/sportsbet_config.php\n");
  exit(1);
}

$preferredBookmakerKey   = trim((string)($config['preferred_bookmaker_key']   ?? '')) ?: null;
$preferredBookmakerTitle = trim((string)($config['preferred_bookmaker_title'] ?? '')) ?: null;
$preferredBookmakerLabel = trim((string)($config['preferred_bookmaker_label'] ?? '')) ?: null;

$BASE = 'https://api.the-odds-api.com/v4';

/* ---------- CLI overrides (key=value) ---------- */
$cli = [];
for ($i = 1; $i < $argc; $i++) {
  if (strpos($argv[$i], '=') !== false) {
    [$k, $v] = explode('=', $argv[$i], 2);
    $cli[$k] = $v;
  }

  if ($k === 'bookmaker' || $k === 'bookmaker_key') {
    $preferredBookmakerKey = trim($v) !== '' ? trim($v) : null;
  } elseif ($k === 'bookmaker_title') {
    $preferredBookmakerTitle = trim($v) !== '' ? trim($v) : null;
  } elseif ($k === 'bookmaker_label') {
    $preferredBookmakerLabel = trim($v) !== '' ? trim($v) : null;
  }
}

$bookmakerFilterActive = $preferredBookmakerKey !== null || $preferredBookmakerTitle !== null;

$REGIONS = $cli['regions'] ?? 'us,uk,eu';                // adjust as you like
$marketsInput = $cli['markets'] ?? 'h2h,spreads,totals'; // add btts, outrights, draw_no_bet if needed
$BLOCKED_MARKETS = ['h2h_lay'];

$marketList = array_values(array_filter(array_map('trim', explode(',', $marketsInput)), static function (string $m): bool {
  return $m !== '';
}));

$marketListInitial = $marketList;
$marketList = array_values(array_diff($marketList, $BLOCKED_MARKETS));
if ($marketListInitial !== $marketList) {
  fwrite(STDERR, "INFO: Removed blocked markets from request: " . implode(', ', array_diff($marketListInitial, $marketList)) . "\n");
}

if (empty($marketList)) {
  fwrite(STDERR, "ERROR: All requested markets are blocked; aborting fetch.\n");
  exit(1);
}

$MARKETS = implode(',', $marketList);
$SPORTS_OVERRIDE = isset($cli['sports'])
  ? array_filter(array_map('trim', explode(',', $cli['sports'])))
  : null;

/* ---------- Helpers ---------- */
function http_get(string $url): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HEADER => true,
  ]);
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) {
    return [$code ?: 0, null, [], $err ?: 'curl_exec failed'];
  }

  // Split headers/body
  $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
  $headersRaw = $parts[0] ?? '';
  $body = $parts[1] ?? '';
  $headers = [];
  foreach (explode("\n", $headersRaw) as $line) {
    $line = trim($line);
    if (strpos($line, ':') !== false) {
      [$h, $v] = explode(':', $line, 2);
      $headers[strtolower(trim($h))] = trim($v);
    }
  }
  return [$code, $body, $headers, $err ?: null];
}

function table_exists(PDO $pdo, string $name): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
  $stmt->execute([$name]);
  return (bool)$stmt->fetchColumn();
}

/* ---------- Prepare statements (idempotent upserts) ---------- */
$canBookmakers = table_exists($pdo, 'bookmakers');
$canMarkets    = table_exists($pdo, 'markets');
$canSports     = table_exists($pdo, 'sports');

if ($canSports) {
  $insSport = $pdo->prepare("
    INSERT INTO sports (sport_key, title, group_name, has_outrights)
    VALUES (:key,:title,:group_name,:has_outrights)
    ON DUPLICATE KEY UPDATE title=VALUES(title), group_name=VALUES(group_name), has_outrights=VALUES(has_outrights)
  ");
}

$insEvent = $pdo->prepare("
  INSERT INTO events (event_id, sport_key, commence_time, home_team, away_team, status)
  VALUES (:id, :sport_key, :commence_time, :home_team, :away_team, 'open')
  ON DUPLICATE KEY UPDATE
    commence_time = VALUES(commence_time),
    home_team     = VALUES(home_team),
    away_team     = VALUES(away_team)
");

$delOdds = $pdo->prepare("DELETE FROM odds WHERE event_id = ?");

$insOdds = $pdo->prepare("
  INSERT INTO odds (event_id, bookmaker, market, outcome, price)
  VALUES (:event_id, :bookmaker, :market, :outcome, :price)
");

if ($canBookmakers) {
  $insBook = $pdo->prepare("
    INSERT INTO bookmakers (bookmaker_key, title)
    VALUES (:key,:title)
    ON DUPLICATE KEY UPDATE title=VALUES(title)
  ");
}
if ($canMarkets) {
  $insMarket = $pdo->prepare("
    INSERT INTO markets (market_key, description)
    VALUES (:key,:desc)
    ON DUPLICATE KEY UPDATE description=VALUES(description)
  ");
}

/* ---------- Step 1: fetch sports list ---------- */
$sportsUrl = $BASE . '/sports/?apiKey=' . urlencode($apiKey);
[$code, $body, $hdr, $err] = http_get($sportsUrl);
if ($code !== 200 || !$body) {
  fwrite(STDERR, "ERROR fetching sports list: HTTP $code " . ($err ?? '') . "\nBody: " . substr((string)$body, 0, 500) . "\n");
  exit(1);
}
$sports = json_decode($body, true);
if (!is_array($sports)) {
  fwrite(STDERR, "ERROR: invalid JSON for sports list\nBody: " . substr((string)$body, 0, 500) . "\n");
  exit(1);
}

$activeSports = [];
foreach ($sports as $s) {
  if (empty($s['active'])) continue;
  $key = $s['key'] ?? null;
  if (!$key) continue;

  if ($canSports) {
    $insSport->execute([
      ':key'          => $key,
      ':title'        => $s['title'] ?? $key,
      ':group_name'   => $s['group'] ?? '',
      ':has_outrights'=> (int)($s['has_outrights'] ?? 0),
    ]);
  }
  $activeSports[] = $key;
}

if ($SPORTS_OVERRIDE !== null) {
  // use only the CLI-specified sports
  $activeSports = array_values(array_intersect($activeSports, $SPORTS_OVERRIDE));
  if (empty($activeSports)) {
    fwrite(STDERR, "WARN: No active sports matched your override list.\n");
  }
}

echo "Active sports to fetch: " . implode(', ', $activeSports) . "\n";

/* ---------- Step 2: fetch odds per sport ---------- */
$totalEvents = 0;
$totalOdds   = 0;

foreach ($activeSports as $sportKey) {
  $oddsUrl = sprintf(
    '%s/sports/%s/odds/?regions=%s&markets=%s&oddsFormat=decimal&dateFormat=iso&apiKey=%s',
    $BASE,
    rawurlencode($sportKey),
    rawurlencode($REGIONS),
    rawurlencode($MARKETS),
    rawurlencode($apiKey)
  );

  [$c2, $b2, $h2, $e2] = http_get($oddsUrl);
  if ($c2 !== 200 || !$b2) {
    $remain = $h2['x-requests-remaining'] ?? $h2['requests-remaining'] ?? 'n/a';
    $used   = $h2['x-requests-used']      ?? $h2['requests-used']      ?? 'n/a';
    fwrite(STDERR, "[{$sportKey}] FETCH FAILED: HTTP $c2 " . ($e2 ?? '') . " | remaining=$remain used=$used\nBody: " . substr((string)$b2, 0, 300) . "\n");
    // Don’t abort; continue to next sport
    usleep(200000);
    continue;
  }

  $data = json_decode($b2, true);
  if (!is_array($data)) {
    fwrite(STDERR, "[{$sportKey}] ERROR: invalid JSON\nBody: " . substr((string)$b2, 0, 300) . "\n");
    usleep(200000);
    continue;
  }

  $countEvents = 0;
  $countOdds   = 0;

  foreach ($data as $event) {
    $eventId = $event['id'] ?? null;
    $timeIso = $event['commence_time'] ?? null;
    $home    = $event['home_team'] ?? '';
    $away    = $event['away_team'] ?? '';

    if (!$eventId || !$timeIso || $home === '' || $away === '') {
      continue; // skip malformed rows
    }

    $insEvent->execute([
      ':id'             => $eventId,
      ':sport_key'      => $sportKey,
      ':commence_time'  => date('Y-m-d H:i:s', strtotime($timeIso)),
      ':home_team'      => $home,
      ':away_team'      => $away,
    ]);
    $countEvents++;

    // Replace odds for this event (simple and safe for MVP)
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

        if ($canBookmakers && $bkKey !== '') {
          try { $insBook->execute([':key' => $bkKey, ':title' => $bkTitle]); } catch (Throwable $__) {}
        }

        if (!empty($bk['markets'])) {
          foreach ($bk['markets'] as $m) {
            $mKey = $m['key'] ?? 'unknown';
            if (in_array($mKey, $BLOCKED_MARKETS, true)) {
              continue;
            }
            if ($canMarkets) {
              try { $insMarket->execute([':key' => $mKey, ':desc' => $mKey]); } catch (Throwable $__) {}
            }
            if (!empty($m['outcomes'])) {
              foreach ($m['outcomes'] as $o) {
                $name  = $o['name']  ?? '';
                $price = isset($o['price']) ? (float)$o['price'] : null;
                if ($name === '' || $price === null) continue;

                $insOdds->execute([
                  ':event_id'  => $eventId,
                  ':bookmaker' => $bookmakerLabel,
                  ':market'    => $mKey,
                  ':outcome'   => $name,
                  ':price'     => $price,
                ]);
                $countOdds++;
              }
            }
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

  $remain = $h2['x-requests-remaining'] ?? $h2['requests-remaining'] ?? 'n/a';
  $used   = $h2['x-requests-used']      ?? $h2['requests-used']      ?? 'n/a';
  echo "[{$sportKey}] upserted {$countEvents} events, inserted {$countOdds} odds | remaining={$remain} used={$used}\n";

  // brief pause to be polite with the API
  usleep(200000); // 200ms
}

echo "OK: total upserted {$totalEvents} events, total inserted {$totalOdds} odds across " . count($activeSports) . " sport(s)\n";
