<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

/** Filter (optional later): ?sport=soccer_epl */
$sport = isset($_GET['sport']) && $_GET['sport'] !== '' ? $_GET['sport'] : 'soccer_epl';

/** Upcoming events for this sport */
$eventsStmt = $pdo->prepare(
  "SELECT e.event_id, e.home_team, e.away_team, e.commence_time
   FROM events e
   WHERE e.sport_key = ? AND e.commence_time >= NOW()
   ORDER BY e.commence_time ASC
   LIMIT 100"
);
$eventsStmt->execute([$sport]);
$events = $eventsStmt->fetchAll();

/** Helper: get best (max) h2h odds per outcome for a given event */
$bestOddsStmt = $pdo->prepare(
  "SELECT outcome, MAX(price) AS best_price
   FROM odds
   WHERE event_id = ? AND market = 'h2h'
   GROUP BY outcome"
);
include __DIR__ . '/partials/header.php';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Events — <?= htmlspecialchars($sport) ?></title>
  <style>
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ccc; padding: 6px 8px; }
    th { background: #f5f5f5; text-align: left; }
    .when { white-space: nowrap; }
  </style>
</head>
<body>
  <h1>Upcoming events (<?= htmlspecialchars($sport) ?>)</h1>
  <nav>
    <a href="/sportsbet/public/index.php">Home</a> |
    <a href="/sportsbet/public/my_bets.php">My Bets</a> |
    <a href="/sportsbet/public/leaderboard.php">Leaderboard</a> |
    <a href="/sportsbet/public/events.php?sport=soccer_epl">EPL</a> |
    <a href="/sportsbet/public/events.php?sport=basketball_nba">NBA</a> |
    <a href="/sportsbet/public/events.php?sport=americanfootball_nfl">NFL</a> |
    <a href="/sportsbet/public/logout.php">Logout</a>
  </nav>

  <p><a href="/sportsbet/public/events.php?sport=<?= urlencode($sport) ?>">Refresh</a></p>

  <?php if (!$events): ?>
    <p>No events found yet. Run the fetcher:<br>
      <code>php /var/www/html/sportsbet/src/fetch_odds.php</code>
    </p>
  <?php else: ?>
    <table>
      <tr>
        <th class="when">Commence</th>
        <th>Match</th>
        <th>Best H2H — Home</th>
        <th>Best H2H — Away</th>
      </tr>
      <?php foreach ($events as $ev): ?>
        <?php
          $bestOddsStmt->execute([$ev['event_id']]);
          $rows = $bestOddsStmt->fetchAll();
          $best = [];
          foreach ($rows as $r) { $best[$r['outcome']] = $r['best_price']; }

          $homeBest = $best[$ev['home_team']] ?? null;
          $awayBest = $best[$ev['away_team']] ?? null;
        ?>
        <tr>
          <td class="when"><?= htmlspecialchars($ev['commence_time']) ?></td>
          <td><?= htmlspecialchars($ev['home_team']) ?> vs <?= htmlspecialchars($ev['away_team']) ?></td>
          <td>
            <?= $homeBest ? htmlspecialchars(number_format((float)$homeBest, 2)) : '—' ?>
            <?php if ($homeBest): ?>
              <a href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&outcome=<?= urlencode($ev['home_team']) ?>">Bet Home</a>
            <?php endif; ?>
          </td>
          <td>
            <?= $awayBest ? htmlspecialchars(number_format((float)$awayBest, 2)) : '—' ?>
            <?php if ($awayBest): ?>
              <a href="/sportsbet/public/bet.php?event_id=<?= urlencode($ev['event_id']) ?>&outcome=<?= urlencode($ev['away_team']) ?>">Bet Away</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</body>
</html>
