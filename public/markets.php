<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$sport = trim($_GET['sport'] ?? '');
if ($sport === '') {
  header('Location: ' . app_url('sports.php')); exit;
}

// Markets available for this sport (from odds joined to events)
$stmt = $pdo->prepare("
  SELECT o.market,
         COUNT(DISTINCT o.event_id) AS event_count
  FROM odds o
  JOIN events e ON e.event_id = o.event_id
  WHERE e.sport_key = :sport
  GROUP BY o.market
  ORDER BY event_count DESC, o.market ASC
");
$stmt->execute([':sport' => $sport]);
$markets = $stmt->fetchAll();

// Sport title
$tstmt = $pdo->prepare("SELECT title FROM sports WHERE sport_key = ? LIMIT 1");
$tstmt->execute([$sport]);
$title = ($tstmt->fetch()['title'] ?? $sport);

include __DIR__ . '/partials/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0">Markets — <?= htmlspecialchars($title) ?></h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= app_url('sports.php') ?>">All sports</a>
</div>

<?php if (!$markets): ?>
  <div class="alert alert-info">No markets found for this sport. Try running your fetch again.</div>
<?php else: ?>
  <div class="list-group">
    <?php foreach ($markets as $m): ?>
      <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
         href="<?= app_url('browse.php?sport=' . urlencode($sport) . '&market=' . urlencode($m['market'])) ?>">
        <span><?= htmlspecialchars($m['market']) ?></span>
        <span class="badge bg-secondary"><?= (int)$m['event_count'] ?> events</span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
