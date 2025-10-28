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

if ($sport === '' || $market === '') {
  header('Location: ' . app_url('sports.php')); exit;
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
      <a href="<?= app_url('sports.php') ?>">Sports</a> →
      <a href="<?= app_url('markets.php?sport=' . urlencode($sport)) ?>">Markets</a> →
      <span><?= htmlspecialchars($market) ?></span>
    </div>
  </div>
  <div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= app_url('markets.php?sport=' . urlencode($sport)) ?>">Back to markets</a>
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
            <td><?= htmlspecialchars($ev['commence_time']) ?></td>
            <td><?= htmlspecialchars($ev['home_team']) ?> vs <?= htmlspecialchars($ev['away_team']) ?></td>

            <?php if ($market === 'h2h'): ?>
              <td>
                <?php if ($bestHome): ?>
                  <?= htmlspecialchars(number_format((float)$bestHome['price'], 2)) ?>
                  <small class="text-muted">(<?= htmlspecialchars($bestHome['bookmaker']) ?>)</small>
                  <a class="btn btn-sm btn-outline-primary ms-2"
                     href="<?= app_url('bet.php?event_id=' . urlencode($ev['event_id']) . '&outcome=' . urlencode($ev['home_team'])) ?>">
                    Bet home
                  </a>
                <?php else: ?> — <?php endif; ?>
              </td>
              <td>
                <?php if ($bestAway): ?>
                  <?= htmlspecialchars(number_format((float)$bestAway['price'], 2)) ?>
                  <small class="text-muted">(<?= htmlspecialchars($bestAway['bookmaker']) ?>)</small>
                  <a class="btn btn-sm btn-outline-primary ms-2"
                     href="<?= app_url('bet.php?event_id=' . urlencode($ev['event_id']) . '&outcome=' . urlencode($ev['away_team'])) ?>">
                    Bet away
                  </a>
                <?php else: ?> — <?php endif; ?>
              </td>
            <?php else: ?>
              <td>
                <?php if (!empty($top)): ?>
                  <ul class="mb-0">
                    <?php foreach ($top as $t): ?>
                      <li>
                        <?= htmlspecialchars($t['outcome']) ?> — <?= htmlspecialchars(number_format((float)$t['price'], 2)) ?>
                        <small class="text-muted">(<?= htmlspecialchars($t['bookmaker']) ?>)</small>
                        <a class="btn btn-sm btn-outline-primary ms-2"
                           href="<?= app_url('bet.php?event_id=' . urlencode($ev['event_id']) . '&outcome=' . urlencode($t['outcome'])) ?>">
                          Bet
                        </a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  —
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
