<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$targets = ['h2h_lay'];

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '=') === false) {
        $candidate = trim($arg);
        if ($candidate !== '') {
            $targets = array_merge($targets, array_map('trim', explode(',', $candidate)));
        }
        continue;
    }

    [$key, $value] = explode('=', $arg, 2);
    $key = strtolower(trim($key));
    $value = trim($value);

    if ($key === 'market' || $key === 'markets') {
        if ($value !== '') {
            $targets = array_merge($targets, array_map('trim', explode(',', $value)));
        }
    }
}

$targets = array_values(array_unique(array_filter($targets, static fn($v) => $v !== '')));

if (empty($targets)) {
    fwrite(STDERR, "No markets provided. Pass names as arguments or market=...\n");
    exit(1);
}

$placeholders = implode(',', array_fill(0, count($targets), '?'));

$delOdds = $pdo->prepare("DELETE FROM odds WHERE market IN ($placeholders)");
$delOdds->execute($targets);
$deletedOdds = $delOdds->rowCount();

$marketTableExists = (function (PDO $pdo): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'markets'");
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
})($pdo);

$deletedMarkets = 0;
if ($marketTableExists) {
    $delMarkets = $pdo->prepare("DELETE FROM markets WHERE market_key IN ($placeholders)");
    $delMarkets->execute($targets);
    $deletedMarkets = $delMarkets->rowCount();
}

echo 'Removed markets: ' . implode(', ', $targets) . PHP_EOL;
echo 'Deleted odds rows: ' . ($deletedOdds >= 0 ? (string)$deletedOdds : 'unknown') . PHP_EOL;
if ($marketTableExists) {
    echo 'Deleted markets rows: ' . ($deletedMarkets >= 0 ? (string)$deletedMarkets : 'unknown') . PHP_EOL;
}

echo "Done.\n";
