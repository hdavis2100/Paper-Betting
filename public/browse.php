<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$sport = trim($_GET['sport'] ?? '');
if ($sport === '') {
  header('Location: /betleague/public/sports.php');
  exit;
}

$limitParam = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
$limit = max(1, min(200, $limitParam));

$titleStmt = $pdo->prepare('SELECT title FROM sports WHERE sport_key = ? LIMIT 1');
$titleStmt->execute([$sport]);
$sportTitle = trim((string) ($titleStmt->fetchColumn() ?? ''));
if ($sportTitle === '') {
  $sportTitle = ucwords(str_replace('_', ' ', $sport));
}

$eventsStmt = $pdo->prepare('
  SELECT e.event_id, e.home_team, e.away_team, e.commence_time
  FROM events e
  WHERE e.sport_key = :sport
    AND e.commence_time >= UTC_TIMESTAMP()
  ORDER BY e.commence_time ASC
  LIMIT :lim
');
$eventsStmt->bindValue(':sport', $sport, PDO::PARAM_STR);
$eventsStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$eventsStmt->execute();
$events = $eventsStmt->fetchAll();

$bestH2H = best_h2h_snapshot($pdo, $events);

include __DIR__ . '/partials/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 mb-0">Upcoming — <?= htmlspecialchars($sportTitle) ?></h1>
    <div class="small text-muted">
      <a href="/betleague/public/sports.php">Sports</a> →
      <span><?= htmlspecialchars($sportTitle) ?></span>
    </div>
  </div>
  <div>
    <a class="btn btn-outline-secondary btn-sm" href="/betleague/public/sports.php">All sports</a>
  </div>
</div>

<p class="text-muted">Moneyline odds are shown below. Click any matchup to explore spreads, totals, and more markets for that game.</p>

<?php if (!$events): ?>
  <div class="alert alert-info">No upcoming events found for this sport.</div>
<?php else: ?>
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table mb-0 align-middle">
        <thead>
          <tr>
            <th style="width: 180px;">Commence (ET)</th>
            <th>Match</th>
            <th style="width: 180px;">Home (Moneyline)</th>
            <th style="width: 180px;">Away (Moneyline)</th>
            <th style="width: 140px;"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($events as $ev): ?>
          <?php
            $eventId = (string) $ev['event_id'];
            $snapshot = $bestH2H[$eventId] ?? ['home' => null, 'away' => null];
            $homeOdds = $snapshot['home'] ?? null;
            $awayOdds = $snapshot['away'] ?? null;
          ?>
          <tr>
            <td><?= htmlspecialchars(format_est_datetime($ev['commence_time'])) ?></td>
            <td>
              <a href="/betleague/public/bet.php?event_id=<?= urlencode($eventId) ?>" class="text-decoration-none">
                <?= htmlspecialchars($ev['home_team']) ?> vs <?= htmlspecialchars($ev['away_team']) ?>
              </a>
            </td>
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
              <a class="btn btn-sm btn-outline-primary" href="/betleague/public/bet.php?event_id=<?= urlencode($eventId) ?>">View markets</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
