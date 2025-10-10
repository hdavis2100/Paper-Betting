<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$q = trim($_GET['q'] ?? '');
$results = [];

if ($q !== '' && mb_strlen($q) >= 2) {
  $stmt = $pdo->prepare("
    SELECT e.event_id, e.sport_key, e.home_team, e.away_team, e.commence_time,
           MATCH(e.home_team, e.away_team) AGAINST(:q IN NATURAL LANGUAGE MODE) AS score
    FROM events e
    WHERE MATCH(e.home_team, e.away_team) AGAINST(:q IN NATURAL LANGUAGE MODE)
      AND e.commence_time >= UTC_TIMESTAMP()
    ORDER BY score DESC, e.commence_time ASC
    LIMIT 100
  ");
  $stmt->execute([':q'=>$q]);
  $results = $stmt->fetchAll();
}

include __DIR__ . '/partials/header.php';
?>
<div class="container mt-3">
  <h1 class="h4 mb-3">Search results for “<?= htmlspecialchars($q) ?>”</h1>
  <form method="get" class="mb-3">
    <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search teams">
  </form>
  <?php if (!$results): ?>
    <div class="alert alert-info">No results found.</div>
  <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-body p-0">
        <table class="table mb-0">
          <thead><tr><th>Commence (ET)</th><th>Match</th><th>Sport</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($results as $r): ?>
            <tr>
              <td><?= htmlspecialchars(format_est_datetime($r['commence_time'])) ?></td>
              <td><?= htmlspecialchars($r['home_team']) ?> vs <?= htmlspecialchars($r['away_team']) ?></td>
              <td><?= htmlspecialchars($r['sport_key']) ?></td>
              <td><a class="btn btn-sm btn-outline-primary"
                     href="/sportsbet/public/bet.php?event_id=<?= urlencode($r['event_id']) ?>">Bet</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
