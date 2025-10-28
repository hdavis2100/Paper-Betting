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
$limitParam = isset($_GET['limit']) ? (int)$_GET['limit'] : 500;
$limitParam = max(1, $limitParam);                 // floor to a positive number
$limit    = min(500, $limitParam);                 // enforce safety cap

// build SQL
$params = [];
if ($selSport !== 'all' && isset($MAJOR_SPORTS[$selSport])) {
  $sql = "
    SELECT e.event_id, e.sport_key, e.home_team, e.away_team, e.commence_time
    FROM events e
    WHERE e.sport_key = :sport
      AND e.commence_time >= UTC_TIMESTAMP()
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
      AND e.commence_time >= UTC_TIMESTAMP()
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

$bestH2H = best_h2h_snapshot($pdo, $rows);

include __DIR__ . '/partials/header.php';
?>
<div class="container mt-3">
  <div class="d-flex align-items-center justify-content-between">
    <h1 class="h4 mb-0">Major Events (Upcoming)</h1>
    <div class="text-muted small">Showing <?= count($rows) ?> event(s)</div>
  </div>

  <p class="text-muted mt-2">See the best available moneyline for each side. Select any matchup to view spreads, totals, and all other markets.</p>

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
              <th style="width: 180px;">Commence (ET)</th>
              <th>Match / Fight</th>
              <th style="width: 140px;">Sport</th>
              <th style="width: 160px;">Home (Moneyline)</th>
              <th style="width: 160px;">Away (Moneyline)</th>
              <th style="width: 120px;"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $eventId = (string) $r['event_id'];
              $snapshot = $bestH2H[$eventId] ?? ['home' => null, 'away' => null];
              $homeOdds = $snapshot['home'] ?? null;
              $awayOdds = $snapshot['away'] ?? null;
            ?>
            <tr>
              <td><?= htmlspecialchars(format_est_datetime($r['commence_time'])) ?></td>
              <td>
                <a href="/betleague/public/bet.php?event_id=<?= urlencode($eventId) ?>" class="text-decoration-none">
                  <?= htmlspecialchars($r['home_team']) ?> vs <?= htmlspecialchars($r['away_team']) ?>
                </a>
              </td>
              <td><?= htmlspecialchars($MAJOR_SPORTS[$r['sport_key']] ?? $r['sport_key']) ?></td>
              <td>
                <?php if ($homeOdds): ?>
                  <?= htmlspecialchars(format_american_odds($homeOdds['price'])) ?>
                  <?php if ($homeOdds['bookmaker'] !== ''): ?>
                    <small class="text-muted">(<?= htmlspecialchars($homeOdds['bookmaker']) ?>)</small>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($awayOdds): ?>
                  <?= htmlspecialchars(format_american_odds($awayOdds['price'])) ?>
                  <?php if ($awayOdds['bookmaker'] !== ''): ?>
                    <small class="text-muted">(<?= htmlspecialchars($awayOdds['bookmaker']) ?>)</small>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
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

