<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$user = current_user();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_all'])) {
        mark_all_notifications_read($pdo, $userId);
        header('Location: /sportsbet/public/notifications.php');
        exit;
    }

    if (isset($_POST['mark_read'], $_POST['notification_id'])) {
        $notificationId = (int) $_POST['notification_id'];
        mark_notification_read($pdo, $userId, $notificationId);
        header('Location: /sportsbet/public/notifications.php');
        exit;
    }
}

$stmt = $pdo->prepare('SELECT id, event_id, market, outcome, line, current_price, message, created_at, read_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/partials/header.php';
?>
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h1 class="h3 mb-1">Notifications</h1>
      <p class="text-muted mb-0">Track your price alerts and event updates.</p>
    </div>
    <form method="post" class="mb-0">
      <input type="hidden" name="mark_all" value="1">
      <button type="submit" class="btn btn-sm btn-outline-secondary" <?= empty($notifications) ? 'disabled' : '' ?>>Mark all as read</button>
    </form>
  </div>

  <?php if (!$notifications): ?>
    <div class="alert alert-info">You don't have any notifications yet. Track an event to receive price alerts.</div>
  <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-body p-0">
        <table class="table mb-0 align-middle">
          <thead>
            <tr>
              <th style="width: 180px;">Received</th>
              <th>Notification</th>
              <th style="width: 140px;">Status</th>
              <th style="width: 120px;"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($notifications as $note): ?>
            <?php
              $isRead = $note['read_at'] !== null;
              $created = format_est_datetime($note['created_at']);
              $targetUrl = '/sportsbet/public/bet.php?event_id=' . urlencode((string) $note['event_id']);
            ?>
            <tr class="<?= $isRead ? '' : 'table-warning' ?>">
              <td><?= htmlspecialchars($created) ?></td>
              <td>
                <div class="fw-semibold">
                  <a href="<?= htmlspecialchars($targetUrl) ?>" class="text-decoration-none">View event</a>
                </div>
                <div><?= htmlspecialchars($note['message']) ?></div>
              </td>
              <td>
                <?php if ($isRead): ?>
                  <span class="badge bg-secondary">Read</span>
                <?php else: ?>
                  <span class="badge bg-primary">Unread</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!$isRead): ?>
                  <form method="post" class="mb-0">
                    <input type="hidden" name="notification_id" value="<?= (int) $note['id'] ?>">
                    <input type="hidden" name="mark_read" value="1">
                    <button type="submit" class="btn btn-sm btn-outline-success">Mark read</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

<?php include __DIR__ . '/partials/footer.php';
