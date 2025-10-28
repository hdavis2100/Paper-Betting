<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$MAJOR_SPORTS = [
  'boxing_boxing'               => 'Boxing',
  'mma_mixed_martial_arts'      => 'UFC / MMA',
  'basketball_nba'              => 'NBA',
  'americanfootball_nfl'        => 'NFL',
  'baseball_mlb'                => 'MLB',
  'icehockey_nhl'               => 'NHL',
];

// query params
$selSport = $_GET['sport'] ?? 'all';               // 'all' or one of the keys above
$limit    = isset($_GET['limit']) ? max(50, (int)$_GET['limit']) : 500;  // safety cap

// build SQL
$params = [];
if ($selSport !== 'all' && isset($MAJOR_SPORTS[$selSport])) {
  $sql = "
    SELECT e.event_id, e.sport_key, e.home_team, e.away_team, e.commence_time
    FROM events e
    WHERE e.sport_key = :sport
      AND e.commence_time >= NOW()
    ORDER BY e.commence_time ASC
    LIMIT :lim
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':sport', $selSport, PDO::PARAM_STR);
  $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
  $stmt->execute();
} else {
  // all major sports
  $keys = array_keys($MAJOR_SPORTS);
  $place = implode(',', array_fill(0, count($keys), '?'));
  $sql = "
    SELECT e.event_id, e.sport_key, e.home_team, e.away_team, e.commence_time
    FROM events e
    WHERE e.sport_key IN ($place)
      AND e.commence_time >= NOW()
    ORDER BY e.commence_time ASC
    LIMIT ?
  ";
  $stmt = $pdo->prepare($sql);
  // bind IN list positionally + trailing LIMIT
  $i = 1;
  foreach ($keys as $k) { $stmt->bindValue($i++, $k, PDO::PARAM_STR); }
  $stmt->bindValue($i, $limit, PDO::PARAM_INT);
  $stmt->execute();
}

$rows = $stmt->fetchAll();

include __DIR__ . '/partials/header.php';
?>
<div class="container mt-3">
  <div class="d-flex align-items-center justify-content-between">
    <h1 class="h4 mb-0">Major Events (Upcoming)</h1>
    <div class="text-muted small">Showing <?= count($rows) ?> event(s)</div>
  </div>

  <!-- Sport filter pills -->
  <div class="mt-2 mb-3">
    <a class="btn btn-sm <?= $selSport==='all' ? 'btn-primary' : 'btn-outline-secondary' ?>"
       href="<?= app_url('events.php?sport=all') ?>">All</a>
    <?php foreach ($MAJOR_SPORTS as $k => $label): ?>
      <a class="btn btn-sm <?= $selSport===$k ? 'btn-primary' : 'btn-outline-secondary' ?>"
         href="<?= app_url('events.php?sport=' . urlencode($k)) ?>">
        <?= htmlspecialchars($label) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="alert alert-info">No upcoming events found for this selection.</div>
  <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-body p-0">
        <table class="table mb-0 align-middle">
          <thead>
            <tr>
              <th style="width: 180px;">Commence (UTC)</th>
              <th>Match / Fight</th>
              <th style="width: 140px;">Sport</th>
              <th style="width: 120px;"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['commence_time']) ?></td>
              <td><?= htmlspecialchars($r['home_team']) ?> vs <?= htmlspecialchars($r['away_team']) ?></td>
              <td><?= htmlspecialchars($MAJOR_SPORTS[$r['sport_key']] ?? $r['sport_key']) ?></td>
              <td>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= app_url('bet.php?event_id=' . urlencode($r['event_id'])) ?>">
                  Bet
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="mt-2 small text-muted">
    Tip: add <code>&limit=1000</code> to the URL if you want a bigger list.
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>

