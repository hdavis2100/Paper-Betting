<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

echo "Prune starting: " . date('Y-m-d H:i:s') . "\n";

// 1) Delete odds for events older than, say, 30 days
$daysOdds = 30;
$delOdds = $pdo->prepare("
  DELETE o
  FROM odds o
  JOIN events e ON e.event_id = o.event_id
  WHERE e.commence_time < NOW() - INTERVAL :d DAYS
");
$delOdds->execute([':d' => $daysOdds]);
echo "Deleted odds for events older than {$daysOdds} days: " . $delOdds->rowCount() . "\n";

// 2) Delete events older than, say, 90 days, only if no pending bets
$daysEv = 90;
$delEvents = $pdo->prepare("
  DELETE e
  FROM events e
  LEFT JOIN bets b ON b.event_id = e.event_id AND b.status = 'pending'
  WHERE b.id IS NULL
    AND e.commence_time < NOW() - INTERVAL :d DAYS
");
$delEvents->execute([':d' => $daysEv]);
echo "Deleted events older than {$daysEv} days: " . $delEvents->rowCount() . "\n";

// (Optional) Clean up old wallet_transactions
$keepTx = 365;  // 1 year
$delTx = $pdo->prepare("
  DELETE FROM wallet_transactions
  WHERE created_at < NOW() - INTERVAL :k DAY
");
$delTx->execute([':k' => $keepTx]);
echo "Deleted wallet transactions older than {$keepTx} days: " . $delTx->rowCount() . "\n";

echo "Prune complete: " . date('Y-m-d H:i:s') . "\n";
