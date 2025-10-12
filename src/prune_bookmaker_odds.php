<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$config = require '/var/www/secure_config/sportsbet_config.php';

$preferredBookmakerKey   = trim((string)($config['preferred_bookmaker_key']   ?? '')) ?: null;
$preferredBookmakerTitle = trim((string)($config['preferred_bookmaker_title'] ?? '')) ?: null;
$preferredBookmakerLabel = trim((string)($config['preferred_bookmaker_label'] ?? '')) ?: null;

for ($i = 1; $i < $argc; $i++) {
  $arg = $argv[$i];
  if (strpos($arg, '=') === false) {
    continue;
  }
  [$key, $value] = explode('=', $arg, 2);
  $key = strtolower(trim($key));
  $value = trim($value);

  switch ($key) {
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

if ($preferredBookmakerKey === null && $preferredBookmakerTitle === null && $preferredBookmakerLabel === null) {
  fwrite(STDERR, "Usage: php prune_bookmaker_odds.php bookmaker_key=the-key [bookmaker_title=Display Name]\n");
  fwrite(STDERR, "Either configure preferred_bookmaker_* in the secure config or pass overrides here.\n");
  exit(1);
}

$bookmakerTitleLookup = $preferredBookmakerTitle;
if ($bookmakerTitleLookup === null && $preferredBookmakerKey !== null) {
  $stmt = $pdo->prepare('SELECT title FROM bookmakers WHERE bookmaker_key = ?');
  if ($stmt->execute([$preferredBookmakerKey])) {
    $found = $stmt->fetchColumn();
    if (is_string($found) && trim($found) !== '') {
      $bookmakerTitleLookup = trim($found);
    }
  }
}

if ($preferredBookmakerLabel === null) {
  $preferredBookmakerLabel = $bookmakerTitleLookup;
}

$allowed = array_values(array_unique(array_filter([
  $preferredBookmakerKey,
  $bookmakerTitleLookup,
  $preferredBookmakerLabel,
], static fn($v) => $v !== null && $v !== '')));

if (empty($allowed)) {
  fwrite(STDERR, "Unable to determine any identifiers for the preferred bookmaker.\n");
  exit(1);
}

$placeholders = implode(',', array_fill(0, count($allowed), '?'));
$delOdds = $pdo->prepare("DELETE FROM odds WHERE bookmaker NOT IN ($placeholders)");
$delOdds->execute($allowed);
$deletedOdds = $delOdds->rowCount();

$bookmakersPurged = 0;
$bookmakerTableExists = (function (PDO $pdo): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'bookmakers'");
  $stmt->execute();
  return (bool)$stmt->fetchColumn();
})($pdo);

if ($bookmakerTableExists && $preferredBookmakerKey !== null) {
  $delBooks = $pdo->prepare('DELETE FROM bookmakers WHERE bookmaker_key <> ?');
  $delBooks->execute([$preferredBookmakerKey]);
  $bookmakersPurged = $delBooks->rowCount();
}

$allowedList = implode(', ', $allowed);

echo "Kept bookmaker identifiers: {$allowedList}\n";
echo "Deleted odds rows: " . ($deletedOdds >= 0 ? (string)$deletedOdds : 'unknown') . "\n";
if ($bookmakerTableExists) {
  echo "Deleted bookmaker rows: " . ($bookmakersPurged >= 0 ? (string)$bookmakersPurged : 'unknown') . "\n";
}

echo "Done.\n";
