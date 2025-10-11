<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$sport  = trim($_GET['sport']  ?? '');
$market = trim($_GET['market'] ?? '');

if ($sport === '' || $market === '') {
  header('Location: /sportsbet/public/sports.php');
  exit;
}

if (strcasecmp($market, 'h2h_lay') === 0) {
  header('Location: /sportsbet/public/markets.php?sport=' . urlencode($sport));
  exit;
}

$marketLower = strtolower($market);
$marketLabel = format_market_label($market);

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
  LIMIT 200
');
$eventsStmt->execute([':sport' => $sport]);
$events = $eventsStmt->fetchAll();

$oddsStmt = $pdo->prepare('
  SELECT bookmaker, outcome, price, line
  FROM odds
  WHERE event_id = :event_id AND market = :market
');

include __DIR__ . '/partials/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 mb-0">Browse — <?= htmlspecialchars($sportTitle) ?> / <?= htmlspecialchars($marketLabel) ?></h1>
    <div class="small text-muted">
      <a href="/sportsbet/public/sports.php">Sports</a> →
      <a href="/sportsbet/public/markets.php?sport=<?= urlencode($sport) ?>">Markets</a> →
      <span><?= htmlspecialchars($marketLabel) ?></span>
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
            <th style="width: 180px;">Commence (ET)</th>
            <th>Match</th>
            <?php if ($marketLower === 'h2h'): ?>
              <th>Home best</th>
              <th>Away best</th>
            <?php elseif ($marketLower === 'spreads'): ?>
              <th>Home spread</th>
              <th>Away spread</th>
            <?php elseif ($marketLower === 'totals'): ?>
              <th>Over</th>
              <th>Under</th>
            <?php else: ?>
              <th>Top outcomes (by price)</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($events as $ev): ?>
          <?php
            $oddsStmt->execute([':event_id' => $ev['event_id'], ':market' => $market]);
            $rows = $oddsStmt->fetchAll();

            $bestHome = null;
            $bestAway = null;
            $bestOver = null;
            $bestUnder = null;
            $topOutcomes = [];

            if ($marketLower === 'h2h') {
              foreach ($rows as $row) {
                if ($row['outcome'] === $ev['home_team']) {
                  if ($bestHome === null || (float) $row['price'] > (float) $bestHome['price']) {
                    $bestHome = $row;
                  }
                } elseif ($row['outcome'] === $ev['away_team']) {
                  if ($bestAway === null || (float) $row['price'] > (float) $bestAway['price']) {
                    $bestAway = $row;
                  }
                }
              }
            } elseif ($marketLower === 'spreads') {
              foreach ($rows as $row) {
                if ($row['outcome'] === $ev['home_team']) {
                  if ($bestHome === null || (float) $row['price'] > (float) $bestHome['price']) {
                    $bestHome = $row;
                  }
                } elseif ($row['outcome'] === $ev['away_team']) {
                  if ($bestAway === null || (float) $row['price'] > (float) $bestAway['price']) {
                    $bestAway = $row;
                  }
                }
              }
            } elseif ($marketLower === 'totals') {
              foreach ($rows as $row) {
                $nameLower = strtolower((string) $row['outcome']);
                if ($nameLower === 'over') {
                  if ($bestOver === null || (float) $row['price'] > (float) $bestOver['price']) {
                    $bestOver = $row;
                  }
                } elseif ($nameLower === 'under') {
                  if ($bestUnder === null || (float) $row['price'] > (float) $bestUnder['price']) {
                    $bestUnder = $row;
                  }
                }
              }
            } else {
              usort($rows, static fn($a, $b) => (float)$b['price'] <=> (float)$a['price']);
              $topOutcomes = array_slice($rows, 0, 3);
            }
          ?>
          <tr>
            <td><?= htmlspecialchars(format_est_datetime($ev['commence_time'])) ?></td>
            <td><?= htmlspecialchars($ev['home_team']) ?> vs <?= htmlspecialchars($ev['away_team']) ?></td>

            <?php if ($marketLower === 'h2h'): ?>
              <td>
                <?php if ($bestHome): ?>
                  <?= htmlspecialchars(format_american_odds((float) $bestHome['price'])) ?>
                  <small class="text-muted">(<?= htmlspecialchars($bestHome['bookmaker']) ?>)</small>
                  <a class="btn btn-sm btn-outline-primary ms-2"
                     href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&market=h2h&outcome=<?= urlencode($ev['home_team']) ?>">
                    Bet home
                  </a>
                <?php else: ?> — <?php endif; ?>
              </td>
              <td>
                <?php if ($bestAway): ?>
                  <?= htmlspecialchars(format_american_odds((float) $bestAway['price'])) ?>
                  <small class="text-muted">(<?= htmlspecialchars($bestAway['bookmaker']) ?>)</small>
                  <a class="btn btn-sm btn-outline-primary ms-2"
                     href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&market=h2h&outcome=<?= urlencode($ev['away_team']) ?>">
                    Bet away
                  </a>
                <?php else: ?> — <?php endif; ?>
              </td>
            <?php elseif ($marketLower === 'spreads'): ?>
              <td>
                <?php if ($bestHome): ?>
                  <?= htmlspecialchars(format_market_outcome_label('spreads', $bestHome['outcome'], $bestHome['line'] !== null ? (float) $bestHome['line'] : null)) ?>
                  — <?= htmlspecialchars(format_american_odds((float) $bestHome['price'])) ?>
                  <small class="text-muted">(<?= htmlspecialchars($bestHome['bookmaker']) ?>)</small>
                  <a class="btn btn-sm btn-outline-primary ms-2"
                     href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&market=spreads&outcome=<?= urlencode($bestHome['outcome']) ?><?= $bestHome['line'] !== null ? '&line=' . urlencode((string) $bestHome['line']) : '' ?>">
                    Bet home spread
                  </a>
                <?php else: ?> — <?php endif; ?>
              </td>
              <td>
                <?php if ($bestAway): ?>
                  <?= htmlspecialchars(format_market_outcome_label('spreads', $bestAway['outcome'], $bestAway['line'] !== null ? (float) $bestAway['line'] : null)) ?>
                  — <?= htmlspecialchars(format_american_odds((float) $bestAway['price'])) ?>
                  <small class="text-muted">(<?= htmlspecialchars($bestAway['bookmaker']) ?>)</small>
                  <a class="btn btn-sm btn-outline-primary ms-2"
                     href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&market=spreads&outcome=<?= urlencode($bestAway['outcome']) ?><?= $bestAway['line'] !== null ? '&line=' . urlencode((string) $bestAway['line']) : '' ?>">
                    Bet away spread
                  </a>
                <?php else: ?> — <?php endif; ?>
              </td>
            <?php elseif ($marketLower === 'totals'): ?>
              <td>
                <?php if ($bestOver): ?>
                  <?= htmlspecialchars(format_market_outcome_label('totals', $bestOver['outcome'], $bestOver['line'] !== null ? (float) $bestOver['line'] : null)) ?>
                  — <?= htmlspecialchars(format_american_odds((float) $bestOver['price'])) ?>
                  <small class="text-muted">(<?= htmlspecialchars($bestOver['bookmaker']) ?>)</small>
                  <a class="btn btn-sm btn-outline-primary ms-2"
                     href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&market=totals&outcome=<?= urlencode($bestOver['outcome']) ?><?= $bestOver['line'] !== null ? '&line=' . urlencode((string) $bestOver['line']) : '' ?>">
                    Bet over
                  </a>
                <?php else: ?> — <?php endif; ?>
              </td>
              <td>
                <?php if ($bestUnder): ?>
                  <?= htmlspecialchars(format_market_outcome_label('totals', $bestUnder['outcome'], $bestUnder['line'] !== null ? (float) $bestUnder['line'] : null)) ?>
                  — <?= htmlspecialchars(format_american_odds((float) $bestUnder['price'])) ?>
                  <small class="text-muted">(<?= htmlspecialchars($bestUnder['bookmaker']) ?>)</small>
                  <a class="btn btn-sm btn-outline-primary ms-2"
                     href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&market=totals&outcome=<?= urlencode($bestUnder['outcome']) ?><?= $bestUnder['line'] !== null ? '&line=' . urlencode((string) $bestUnder['line']) : '' ?>">
                    Bet under
                  </a>
                <?php else: ?> — <?php endif; ?>
              </td>
            <?php else: ?>
              <td>
                <?php if (!empty($topOutcomes)): ?>
                  <ul class="mb-0">
                    <?php foreach ($topOutcomes as $row): ?>
                      <?php $lineParam = $row['line'] !== null ? '&line=' . urlencode((string) $row['line']) : ''; ?>
                      <li>
                        <?= htmlspecialchars(format_market_outcome_label($marketLower, $row['outcome'], $row['line'] !== null ? (float) $row['line'] : null)) ?>
                        — <?= htmlspecialchars(format_american_odds((float) $row['price'])) ?>
                        <small class="text-muted">(<?= htmlspecialchars($row['bookmaker']) ?>)</small>
                        <a class="btn btn-sm btn-outline-primary ms-2"
                           href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&market=<?= urlencode($market) ?>&outcome=<?= urlencode($row['outcome']) ?><?= $lineParam ?>">
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
