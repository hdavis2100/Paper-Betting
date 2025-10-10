<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$sport  = trim($_GET['sport']  ?? '');
$market = trim($_GET['market'] ?? '');

if ($sport === '' || $market === '') {
  header('Location: /sportsbet/public/sports.php'); exit;
}

// Pull upcoming events for this sport
$eventsStmt = $pdo->prepare("
  SELECT e.event_id, e.home_team, e.away_team, e.commence_time
  FROM events e
  WHERE e.sport_key = :sport
    AND e.commence_time >= NOW()
  ORDER BY e.commence_time ASC
  LIMIT 200
");
$eventsStmt->execute([':sport' => $sport]);
$events = $eventsStmt->fetchAll();

// Prepared: get odds for a specific event/market
$oddsStmt = $pdo->prepare("
  SELECT bookmaker, outcome, price
  FROM odds
  WHERE event_id = :event_id AND market = :market
");

include __DIR__ . '/partials/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 mb-0">Browse — <?= htmlspecialchars($sport) ?> / <?= htmlspecialchars($market) ?></h1>
    <div class="small text-muted">
      <a href="/sportsbet/public/sports.php">Sports</a> →
      <a href="/sportsbet/public/markets.php?sport=<?= urlencode($sport) ?>">Markets</a> →
      <span><?= htmlspecialchars($market) ?></span>
    </div>
  </div>
  <div>
    <a class="btn btn-outline-secondary btn-sm" href="/sportsbet/public/markets.php?sport=<?= urlencode($sport) ?>">Back to markets</a>
  </div>
</div>

<?php if (!$events): ?>
  <div class="alert alert-info">No upcoming events found for this sport.</div>
<?php else: ?>
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table mb-0">
        <thead>
          <tr>
            <th style="width: 180px;">Commence</th>
            <th>Match</th>
            <?php if ($market === 'h2h'): ?>
              <th>Home best</th>
              <th>Away best</th>
            <?php else: ?>
              <th>Top outcomes (by price)</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php
        foreach ($events as $ev):
          $oddsStmt->execute([':event_id' => $ev['event_id'], ':market' => $market]);
          $rows = $oddsStmt->fetchAll();

          if ($market === 'h2h') {
            $bestHome = null; $bestAway = null;
            foreach ($rows as $r) {
              if ($r['outcome'] === $ev['home_team']) {
                if ($bestHome === null || (float)$r['price'] > (float)$bestHome['price']) $bestHome = $r;
              } elseif ($r['outcome'] === $ev['away_team']) {
                if ($bestAway === null || (float)$r['price'] > (float)$bestAway['price']) $bestAway = $r;
              }
            }
          } else {
            // Generic: show top two outcomes for this market by price
            usort($rows, fn($a,$b) => (float)$b['price'] <=> (float)$a['price']);
            $top = array_slice($rows, 0, 2);
          }
        ?>
          <tr>
            <td><?= htmlspecialchars($ev['commence_time']) ?></td>
            <td><?= htmlspecialchars($ev['home_team']) ?> vs <?= htmlspecialchars($ev['away_team']) ?></td>

            <?php if ($market === 'h2h'): ?>
              <td>
                <?php if ($bestHome): ?>
                  <?= htmlspecialchars(format_american_odds((float)$bestHome['price'])) ?>
                  <small class="text-muted">(<?= htmlspecialchars($bestHome['bookmaker']) ?>)</small>
                  <a class="btn btn-sm btn-outline-primary ms-2"
                     href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&outcome=<?= urlencode($ev['home_team']) ?>">
                    Bet home
                  </a>
                <?php else: ?> — <?php endif; ?>
              </td>
              <td>
                <?php if ($bestAway): ?>
                  <?= htmlspecialchars(format_american_odds((float)$bestAway['price'])) ?>
                  <small class="text-muted">(<?= htmlspecialchars($bestAway['bookmaker']) ?>)</small>
                  <a class="btn btn-sm btn-outline-primary ms-2"
                     href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&outcome=<?= urlencode($ev['away_team']) ?>">
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
                        <?= htmlspecialchars($t['outcome']) ?> — <?= htmlspecialchars(format_american_odds((float)$t['price'])) ?>
                        <small class="text-muted">(<?= htmlspecialchars($t['bookmaker']) ?>)</small>
                        <a class="btn btn-sm btn-outline-primary ms-2"
                           href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&outcome=<?= urlencode($t['outcome']) ?>">
                          Bet
                        </a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
