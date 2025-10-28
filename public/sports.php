<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

// Upcoming counts per sport
$sql = "
  SELECT s.sport_key, s.title,
         COALESCE(SUM(e.commence_time >= UTC_TIMESTAMP()), 0) AS upcoming_count
  FROM sports s
  LEFT JOIN events e ON e.sport_key = s.sport_key
  GROUP BY s.sport_key, s.title
  ORDER BY s.title
";
$rows = $pdo->query($sql)->fetchAll();

include __DIR__ . '/partials/header.php';
?>
<h1 class="h4 mb-3">Browse by sport</h1>

<?php if (!$rows): ?>
  <div class="alert alert-info">No sports yet. Run your fetcher.</div>
<?php else: ?>
  <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
    <?php foreach ($rows as $r): ?>
      <div class="col">
        <div class="card shadow-sm h-100">
          <div class="card-body d-flex flex-column">
            <?php
              $rawTitle = trim((string)($r['title'] ?? ''));
              $displayTitle = $rawTitle !== ''
                ? $rawTitle
                : ucwords(str_replace('_', ' ', (string)$r['sport_key']));
            ?>
            <h5 class="card-title mb-3"><?= htmlspecialchars($displayTitle) ?></h5>
            <div class="mt-auto d-flex align-items-center justify-content-between">
              <span class="badge bg-secondary"><?= (int)$r['upcoming_count'] ?> upcoming</span>
              <a class="btn btn-sm btn-primary"
                 href="/betleague/public/browse.php?sport=<?= urlencode($r['sport_key']) ?>">
                View events
              </a>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
