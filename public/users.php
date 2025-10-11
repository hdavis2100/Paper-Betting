<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$query = trim($_GET['q'] ?? '');
$results = [];

if ($query !== '') {
    $like = '%' . $query . '%';
    $stmt = $pdo->prepare('SELECT id, username, created_at, profile_public FROM users WHERE username LIKE ? ORDER BY username ASC LIMIT 50');
    $stmt->execute([$like]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/partials/header.php';
?>
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h1 class="h3 mb-1">Find players</h1>
      <p class="text-muted mb-0">Look up fellow bettors and view their public profiles.</p>
    </div>
  </div>

  <form class="card card-body shadow-sm mb-4" method="get" action="">
    <label class="form-label" for="user-search">Search by username</label>
    <div class="input-group">
      <input type="search" id="user-search" name="q" class="form-control" placeholder="Enter username" value="<?= htmlspecialchars($query) ?>" autofocus>
      <button class="btn btn-primary" type="submit">Search</button>
    </div>
    <small class="text-muted mt-2">Showing up to 50 results.</small>
  </form>

  <?php if ($query === ''): ?>
    <div class="alert alert-info">Enter a username to start searching.</div>
  <?php else: ?>
    <?php if (!$results): ?>
      <div class="alert alert-warning">No users matched "<?= htmlspecialchars($query) ?>".</div>
    <?php else: ?>
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h5 mb-3">Search results</h2>
          <div class="list-group list-group-flush">
            <?php foreach ($results as $row): ?>
              <?php $isPublic = ((int)($row['profile_public'] ?? 1)) === 1; ?>
              <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="/sportsbet/public/user_profile.php?username=<?= urlencode($row['username']) ?>">
                <div>
                  <div class="fw-semibold"><?= htmlspecialchars($row['username']) ?></div>
                  <small class="text-muted">Member since <?= htmlspecialchars($row['created_at'] ? format_est_datetime($row['created_at']) : 'Unknown') ?></small>
                </div>
                <span class="badge <?= $isPublic ? 'bg-success' : 'bg-secondary' ?>"><?= $isPublic ? 'Public' : 'Private' ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

<?php include __DIR__ . '/partials/footer.php';
