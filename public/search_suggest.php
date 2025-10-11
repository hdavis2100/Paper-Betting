<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$q = trim($_GET['q'] ?? '');
$results = [];

if ($q !== '' && mb_strlen($q) >= 2) {
  $stmt = $pdo->prepare("
    SELECT event_id, home_team, away_team
    FROM events
    WHERE (home_team LIKE :q OR away_team LIKE :q)
      AND commence_time >= UTC_TIMESTAMP()
    ORDER BY commence_time ASC
    LIMIT 10
  ");
  $stmt->execute([':q'=>"%$q%"]);
  $results = $stmt->fetchAll();
}

header('Content-Type: application/json');
echo json_encode($results);
