<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

echo "Prune starting: " . date('Y-m-d H:i:s') . "\n";

/**
 * MySQL does not support parameter binding inside INTERVAL,
 * so we must concatenate the value into the SQL string.
 * We'll sanitize by casting to int before interpolation.
 */

$daysOdds = 30;
$daysEv   = 90;
$keepTx   = 365;

// --- 1) Delete odds for old events ---
$daysOdds = (int)$daysOdds;
$sqlOdds = "
  DELETE o
  FROM odds o
  JOIN events e ON e.event_id = o.event_id
  WHERE e.commence_time < NOW() - INTERVAL {$daysOdds} DAY
";
$countOdds = $pdo->exec($sqlOdds);
echo "Deleted odds for events older than {$daysOdds} days: {$countOdds}\n";

// --- 2) Delete events older than 90 days (no pending bets) ---
$daysEv = (int)$daysEv;
$sqlEvents = "
  DELETE e
  FROM events e
  LEFT JOIN bets b ON b.event_id = e.event_id AND b.status = 'pending'
  WHERE b.id IS NULL
    AND e.commence_time < NOW() - INTERVAL {$daysEv} DAY
";
$countEvents = $pdo->exec($sqlEvents);
echo "Deleted events older than {$daysEv} days: {$countEvents}\n";

// --- 3) Clean up old wallet transactions ---
$keepTx = (int)$keepTx;
$sqlTx = "
  DELETE FROM wallet_transactions
  WHERE created_at < NOW() - INTERVAL {$keepTx} DAY
";
$countTx = $pdo->exec($sqlTx);
echo "Deleted wallet transactions older than {$keepTx} days: {$countTx}\n";

echo "Prune complete: " . date('Y-m-d H:i:s') . "\n";
