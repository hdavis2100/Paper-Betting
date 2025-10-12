<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$q = trim($_GET['q'] ?? '');
$eventResults = [];
$userResults = [];
$minQueryLength = 2;
$tooShort = false;

if ($q !== '') {
  if (mb_strlen($q) < $minQueryLength) {
    $tooShort = true;
  } else {
    $stmt = $pdo->prepare("
      SELECT e.event_id, e.sport_key, e.home_team, e.away_team, e.commence_time,
             s.title AS sport_title,
             MATCH(e.home_team, e.away_team) AGAINST(:q IN NATURAL LANGUAGE MODE) AS score
      FROM events e
      LEFT JOIN sports s ON s.sport_key = e.sport_key
      WHERE MATCH(e.home_team, e.away_team) AGAINST(:q IN NATURAL LANGUAGE MODE)
        AND e.commence_time >= UTC_TIMESTAMP()
      ORDER BY score DESC, e.commence_time ASC
      LIMIT 100
    ");
    $stmt->execute([':q' => $q]);
    $eventResults = $stmt->fetchAll();

    $userStmt = $pdo->prepare('
      SELECT id, username, created_at, profile_public
      FROM users
      WHERE username LIKE ?
      ORDER BY username ASC
      LIMIT 50
    ');
    $userStmt->execute(['%' . $q . '%']);
    $userResults = $userStmt->fetchAll(PDO::FETCH_ASSOC);
  }
}

include __DIR__ . '/partials/header.php';
?>
<div class="container mt-3">
  <h1 class="h4 mb-3">Search Results for “<?= htmlspecialchars($q) ?>”</h1>
  <form method="get" class="mb-3">
    <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search users or events">
  </form>

  <?php if ($q === ''): ?>
    <div class="alert alert-info">Enter at least <?= $minQueryLength ?> characters to search events or users.</div>
  <?php elseif ($tooShort): ?>
    <div class="alert alert-warning">Please enter at least <?= $minQueryLength ?> characters to search.</div>
  <?php elseif (!$eventResults && !$userResults): ?>
    <div class="alert alert-info">No events or users matched “<?= htmlspecialchars($q) ?>”.</div>
  <?php else: ?>
    <?php if ($eventResults): ?>
      <div class="card shadow-sm mb-4">
        <div class="card-body p-0">
          <div class="px-3 pt-3"><h2 class="h5">Events</h2></div>
          <table class="table mb-0">
            <thead><tr><th>Commence (ET)</th><th>Match</th><th>Sport</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($eventResults as $r): ?>
              <tr>
                <td><?= htmlspecialchars(format_est_datetime($r['commence_time'])) ?></td>
                <td><?= htmlspecialchars($r['home_team']) ?> vs <?= htmlspecialchars($r['away_team']) ?></td>
                <?php
                  $sportTitle = trim((string)($r['sport_title'] ?? ''));
                  if ($sportTitle === '') {
                    $sportTitle = ucwords(str_replace('_', ' ', $r['sport_key']));
                  }
                ?>
                <td><?= htmlspecialchars($sportTitle) ?></td>
                <td><a class="btn btn-sm btn-outline-primary"
                       href="/sportsbet/public/bet.php?event_id=<?= urlencode($r['event_id']) ?>">View Markets</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($userResults): ?>
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h5 mb-3">Users</h2>
          <div class="list-group list-group-flush">
            <?php foreach ($userResults as $row): ?>
              <?php $isPublic = ((int)($row['profile_public'] ?? 1)) === 1; ?>
              <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                 href="/sportsbet/public/user_profile.php?username=<?= urlencode($row['username']) ?>">
                <div>
                  <div class="fw-semibold"><?= htmlspecialchars($row['username']) ?></div>
                  <small class="text-muted">Member Since <?= htmlspecialchars($row['created_at'] ? format_est_datetime($row['created_at']) : 'Unknown') ?></small>
                </div>
                <span class="badge <?= $isPublic ? 'bg-success' : 'bg-secondary' ?>"><?= $isPublic ? 'Public' : 'Private' ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
