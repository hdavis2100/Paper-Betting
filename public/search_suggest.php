<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$q = trim($_GET['q'] ?? '');
$payload = [
  'events' => [],
  'users' => [],
];

if ($q !== '' && mb_strlen($q) >= 2) {
  $terms = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
  $boolean = '';
  if ($terms) {
    $booleanTerms = [];
    foreach ($terms as $term) {
      $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $term);
      if ($clean !== '') {
        $booleanTerms[] = $clean . '*';
      }
    }
    $boolean = trim(implode(' ', $booleanTerms));
  }

  if ($boolean !== '') {
    $stmt = $pdo->prepare(
      "SELECT e.event_id, e.home_team, e.away_team, e.commence_time, e.sport_key, s.title AS sport_title,
              MATCH(e.home_team, e.away_team) AGAINST(:boolean IN BOOLEAN MODE) AS relevance
         FROM events e
    LEFT JOIN sports s ON s.sport_key = e.sport_key
        WHERE e.commence_time >= UTC_TIMESTAMP()
          AND MATCH(e.home_team, e.away_team) AGAINST(:boolean IN BOOLEAN MODE)
     ORDER BY relevance DESC, e.commence_time ASC
        LIMIT 8"
    );
    $stmt->execute([':boolean' => $boolean]);
    $rows = $stmt->fetchAll();
  } else {
    $stmt = $pdo->prepare(
      "SELECT e.event_id, e.home_team, e.away_team, e.commence_time, e.sport_key, s.title AS sport_title
         FROM events e
    LEFT JOIN sports s ON s.sport_key = e.sport_key
        WHERE e.commence_time >= UTC_TIMESTAMP()
          AND (e.home_team LIKE :like OR e.away_team LIKE :like)
     ORDER BY e.commence_time ASC
        LIMIT 8"
    );
    $stmt->execute([':like' => "%$q%"]);
    $rows = $stmt->fetchAll();
  }

  foreach ($rows ?? [] as $row) {
    $sportTitle = trim((string)($row['sport_title'] ?? ''));
    if ($sportTitle === '') {
      $sportTitle = ucwords(str_replace('_', ' ', (string)$row['sport_key']));
    }
    $payload['events'][] = [
      'event_id' => $row['event_id'],
      'home_team' => $row['home_team'],
      'away_team' => $row['away_team'],
      'commence_time_est' => format_est_datetime($row['commence_time']),
      'sport_title' => $sportTitle,
    ];
  }

  $userBoolean = build_fulltext_boolean_terms($q);
  if ($userBoolean !== '') {
    $userStmt = $pdo->prepare(
      'SELECT username, profile_public,
              MATCH(username) AGAINST(:boolean IN BOOLEAN MODE) AS relevance
         FROM users
        WHERE MATCH(username) AGAINST(:boolean IN BOOLEAN MODE)
     ORDER BY relevance DESC, username ASC
        LIMIT 8'
    );
    $userStmt->execute([':boolean' => $userBoolean]);
  } else {
    $userStmt = $pdo->prepare(
      'SELECT username, profile_public FROM users WHERE username LIKE :uq ORDER BY username ASC LIMIT 8'
    );
    $userStmt->execute([':uq' => $q . '%']);
  }

  foreach ($userStmt->fetchAll(PDO::FETCH_ASSOC) as $userRow) {
    $payload['users'][] = [
      'username' => $userRow['username'],
      'public' => ((int)($userRow['profile_public'] ?? 1)) === 1,
    ];
  }
}

header('Content-Type: application/json');
echo json_encode($payload);
