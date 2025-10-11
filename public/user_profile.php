<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$username = trim($_GET['username'] ?? '');
if ($username === '') {
    header('Location: /sportsbet/public/users.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, username, email, created_at, profile_public FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    include __DIR__ . '/partials/header.php';
    ?>
      <div class="alert alert-danger">No user named "<?= htmlspecialchars($username) ?>" was found.</div>
    <?php
    include __DIR__ . '/partials/footer.php';
    return;
}

$current = current_user();
$isSelf = (int)$current['id'] === (int)$profile['id'];
$isPublic = ((int)($profile['profile_public'] ?? 1)) === 1;

include __DIR__ . '/partials/header.php';
?>
  <div class="d-flex justify-content-between align-items-start mb-4">
    <div>
      <h1 class="h3 mb-1"><?= htmlspecialchars($profile['username']) ?><?php if ($isSelf): ?> <span class="badge bg-primary">You</span><?php endif; ?></h1>
      <p class="text-muted mb-0">Member since <?= htmlspecialchars($profile['created_at'] ? format_est_datetime($profile['created_at']) : 'Unknown') ?></p>
    </div>
    <span class="badge <?= $isPublic ? 'bg-success' : 'bg-secondary' ?>"><?= $isPublic ? 'Public profile' : 'Private profile' ?></span>
  </div>

  <?php if (!$isSelf && !$isPublic): ?>
    <div class="alert alert-info">
      This user keeps their profile private. You can view only their basic information.
    </div>
  <?php endif; ?>

  <?php if ($isSelf || $isPublic): ?>
    <?php $stats = fetch_user_stats($pdo, (int)$profile['id']);
    $winRate = $stats['win_rate'];
    $winLossRatio = $stats['win_loss_ratio'];
    $netProfit = $stats['net_profit'];
    $netProfitClass = $netProfit > 0 ? 'text-success' : ($netProfit < 0 ? 'text-danger' : '');
    ?>
    <div class="row g-4 mb-4">
      <div class="col-lg-4">
        <div class="card h-100 shadow-sm">
          <div class="card-body">
            <h2 class="h5">Overview</h2>
            <p class="text-muted mb-1">Total bets: <strong><?= number_format($stats['total']) ?></strong></p>
            <p class="text-muted mb-1">Total spending: <strong>$<?= number_format($stats['total_staked'], 2) ?></strong></p>
            <p class="text-muted mb-1">Net profit: <strong class="<?= $netProfitClass ?>">$<?= number_format($netProfit, 2) ?></strong></p>
            <p class="text-muted mb-0">Pending potential return: <strong>$<?= number_format($stats['pending_potential'], 2) ?></strong></p>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card h-100 shadow-sm">
          <div class="card-body">
            <h2 class="h5 mb-3">Performance</h2>
            <div class="row text-center">
              <div class="col-6 col-md-3 mb-3">
                <div class="fw-semibold h4 mb-0"><?= number_format($stats['wins']) ?></div>
                <small class="text-muted">Wins</small>
              </div>
              <div class="col-6 col-md-3 mb-3">
                <div class="fw-semibold h4 mb-0"><?= number_format($stats['losses']) ?></div>
                <small class="text-muted">Losses</small>
              </div>
              <div class="col-6 col-md-3 mb-3">
                <div class="fw-semibold h4 mb-0"><?= number_format($stats['pending']) ?></div>
                <small class="text-muted">Pending</small>
              </div>
              <div class="col-6 col-md-3 mb-3">
                <div class="fw-semibold h4 mb-0"><?= number_format($stats['voids']) ?></div>
                <small class="text-muted">Voids</small>
              </div>
            </div>
            <div class="row text-center">
              <div class="col-6 col-md-3 mb-3">
                <div class="fw-semibold h4 mb-0"><?= $winRate !== null ? number_format($winRate * 100, 1) . '%' : '—' ?></div>
                <small class="text-muted">Win rate</small>
              </div>
              <div class="col-6 col-md-3 mb-3">
                <div class="fw-semibold h4 mb-0"><?= $winLossRatio !== null ? number_format($winLossRatio, 2) : '—' ?></div>
                <small class="text-muted">Win/loss ratio</small>
              </div>
              <div class="col-6 col-md-3 mb-3">
                <div class="fw-semibold h4 mb-0"><?= number_format($stats['cancelled']) ?></div>
                <small class="text-muted">Cancelled</small>
              </div>
              <div class="col-6 col-md-3 mb-3">
                <div class="fw-semibold h4 mb-0"><?= number_format($stats['total']) ?></div>
                <small class="text-muted">Total bets</small>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

<?php include __DIR__ . '/partials/footer.php';
