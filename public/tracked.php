<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$user = current_user();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int) $_POST['delete_id'];
    delete_tracked_item($pdo, $userId, $deleteId);
    header('Location: /betleague/public/tracked.php');
    exit;
}

$trackedItems = fetch_tracked_items($pdo, $userId);

include __DIR__ . '/partials/header.php';
?>
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h1 class="h3 mb-1">Tracked events</h1>
      <p class="text-muted mb-0">Manage your price alerts and jump back into events.</p>
    </div>
  </div>

  <?php if (!$trackedItems): ?>
    <div class="alert alert-info">You aren't tracking any events yet. Visit an event page and use the "Track" option to set a price alert.</div>
  <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-body p-0">
        <table class="table mb-0 align-middle">
          <thead>
            <tr>
              <th style="width: 180px;">Commence (ET)</th>
              <th>Matchup</th>
              <th style="width: 150px;">Market</th>
              <th style="width: 150px;">Target odds</th>
              <th style="width: 150px;">Last notified</th>
              <th style="width: 120px;"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($trackedItems as $item): ?>
            <?php
              $eventId = (string) $item['event_id'];
              $eventUrl = '/betleague/public/bet.php?event_id=' . urlencode($eventId);
              $line = isset($item['line']) ? (float) $item['line'] : null;
              $targetDecimal = isset($item['target_price']) ? (float) $item['target_price'] : null;
              $targetAmerican = $targetDecimal ? decimal_to_american_odds($targetDecimal) : '—';
              $marketLabel = tracking_market_label((string) $item['market']);
              $outcomeLabel = tracking_format_outcome((string) $item['market'], (string) $item['outcome'], $line);
              $lastNotified = $item['last_notified_at'] ? format_est_datetime($item['last_notified_at']) : '—';
              $commence = $item['commence_time'] ? format_est_datetime($item['commence_time']) : '—';
              $matchup = trim(($item['home_team'] ?? '') . ' vs ' . ($item['away_team'] ?? ''));
            ?>
            <tr>
              <td><?= htmlspecialchars($commence) ?></td>
              <td>
                <a href="<?= htmlspecialchars($eventUrl) ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars($matchup) ?></a>
                <div class="text-muted small">Tracking: <?= htmlspecialchars($outcomeLabel) ?></div>
              </td>
              <td><?= htmlspecialchars($marketLabel) ?></td>
              <td><?= htmlspecialchars($targetAmerican) ?></td>
              <td><?= htmlspecialchars($lastNotified) ?></td>
              <td>
                <form method="post" class="mb-0" onsubmit="return confirm('Stop tracking this selection?');">
                  <input type="hidden" name="delete_id" value="<?= (int) $item['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

<?php include __DIR__ . '/partials/footer.php';
