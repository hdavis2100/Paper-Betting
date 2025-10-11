<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$current = current_user();
$userId = (int)$current['id'];

$errors = [];
$messages = [];

$stmt = $pdo->prepare('SELECT profile_public FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$profilePublic = (int)($stmt->fetchColumn() ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'visibility') {
        $newValue = isset($_POST['profile_public']) ? 1 : 0;
        try {
            $update = $pdo->prepare('UPDATE users SET profile_public = ? WHERE id = ? LIMIT 1');
            $update->execute([$newValue, $userId]);
            $profilePublic = $newValue;
            $_SESSION['user']['profile_public'] = $newValue;
            $messages[] = 'Profile visibility updated.';
        } catch (Throwable $e) {
            $errors[] = 'Failed to update visibility settings.';
        }
    } elseif ($action === 'delete') {
        $confirm = trim($_POST['confirm_username'] ?? '');
        if (strcasecmp($confirm, (string)$current['username']) !== 0) {
            $errors[] = 'Confirmation did not match your username.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM users WHERE id = ? LIMIT 1')->execute([$userId]);
                $pdo->commit();
                session_unset();
                session_destroy();
                header('Location: /sportsbet/public/register.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to delete your account at this time.';
            }
        }
    }
}

include __DIR__ . '/partials/header.php';
?>
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h1 class="h3 mb-1">Account settings</h1>
      <p class="text-muted mb-0">Control how others see your profile and manage your account.</p>
    </div>
  </div>

  <?php foreach ($messages as $message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endforeach; ?>

  <div class="row g-4">
    <div class="col-lg-6">
      <form method="post" class="card shadow-sm h-100">
        <div class="card-body">
          <h2 class="h5 mb-3">Profile visibility</h2>
          <input type="hidden" name="action" value="visibility">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="profile_public" name="profile_public" <?= $profilePublic === 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="profile_public">Make my profile visible to other users</label>
          </div>
          <p class="text-muted small mb-3">
            When your profile is private, other users will only see your username and join date.
          </p>
          <button type="submit" class="btn btn-primary">Save visibility</button>
        </div>
      </form>
    </div>
    <div class="col-lg-6">
      <form method="post" class="card shadow-sm h-100" onsubmit="return confirm('Are you sure you want to delete your account? This cannot be undone.');">
        <div class="card-body">
          <h2 class="h5 mb-3 text-danger">Delete account</h2>
          <input type="hidden" name="action" value="delete">
          <p class="text-muted">Deleting your account will remove your profile, wallet, bets, and history. This action cannot be undone.</p>
          <div class="mb-3">
            <label for="confirm_username" class="form-label">Type your username to confirm</label>
            <input type="text" class="form-control" id="confirm_username" name="confirm_username" placeholder="<?= htmlspecialchars($current['username']) ?>" required>
          </div>
          <button type="submit" class="btn btn-outline-danger">Delete my account</button>
        </div>
      </form>
    </div>
  </div>

<?php include __DIR__ . '/partials/footer.php';
