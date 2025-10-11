<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$authUser = current_user();
$userId = (int)$authUser['id'];

$stmt = $pdo->prepare('SELECT username, email, created_at, profile_public FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$account = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'username' => $authUser['username'],
    'email' => $authUser['email'],
    'created_at' => null,
    'profile_public' => 1,
];

$stmt = $pdo->prepare('SELECT balance FROM wallets WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$wallet = $stmt->fetch();
$balance = $wallet ? (float)$wallet['balance'] : 0.0;

$stats = fetch_user_stats($pdo, $userId);
$wins = $stats['wins'];
$losses = $stats['losses'];
$voids = $stats['voids'];
$cancelled = $stats['cancelled'];
$pending = $stats['pending'];
$totalBets = $stats['total'];
$spending = $stats['total_staked'];
$netProfit = $stats['net_profit'];
$pendingPotential = $stats['pending_potential'];
$winLossRatio = $stats['win_loss_ratio'];
$winRate = $stats['win_rate'];

$profitSeries = fetch_user_profit_timeseries($pdo, $userId);
$profitLabels = [];
$profitValues = [];
if ($profitSeries) {
    $eastern = new DateTimeZone('America/New_York');
    foreach ($profitSeries as $point) {
        $day = $point['day'];
        try {
            $dt = new DateTime($day, new DateTimeZone('UTC'));
            $dt->setTimezone($eastern);
            $label = $dt->format('M j, Y');
        } catch (Throwable $e) {
            $label = $day;
        }

        $profitLabels[] = $label;
        $profitValues[] = $point['net'];
    }
}

include __DIR__ . '/partials/header.php';

$joined = $account['created_at'] ? format_est_datetime($account['created_at']) : 'Unknown';
$visibility = ((int)($account['profile_public'] ?? 1)) === 1 ? 'Public profile' : 'Private profile';
$winRateDisplay = $winRate !== null ? number_format($winRate * 100, 1) . '%' : '—';
$ratioDisplay = $winLossRatio !== null ? number_format($winLossRatio, 2) : '—';
$netProfitClass = $netProfit > 0 ? 'text-success' : ($netProfit < 0 ? 'text-danger' : '');
?>
  <div class="d-flex justify-content-between align-items-start mb-4">
    <div>
      <h1 class="h3 mb-1">Account overview</h1>
      <p class="text-muted mb-0">Welcome back, <?= htmlspecialchars($account['username'] ?? '') ?>.</p>
    </div>
    <span class="badge bg-secondary align-self-center"><?= htmlspecialchars($visibility) ?></span>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-4">
      <div class="card h-100 shadow-sm">
        <div class="card-body">
          <h2 class="h5">Wallet</h2>
          <p class="display-6 fw-semibold mb-2">$<?= number_format($balance, 2) ?></p>
          <p class="text-muted mb-1">Total spending: <strong>$<?= number_format($spending, 2) ?></strong></p>
          <p class="text-muted mb-1">Net profit: <strong class="<?= $netProfitClass ?>">$<?= number_format($netProfit, 2) ?></strong></p>
          <p class="text-muted">Pending potential return: <strong>$<?= number_format($pendingPotential, 2) ?></strong></p>
          <hr>
          <p class="text-muted small mb-0">Member since <?= htmlspecialchars($joined) ?></p>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card h-100 shadow-sm">
        <div class="card-body">
          <h2 class="h5 mb-3">Betting performance</h2>
          <div class="row text-center">
            <div class="col-6 col-md-3 mb-3">
              <div class="fw-semibold h4 mb-0"><?= number_format($wins) ?></div>
              <small class="text-muted">Wins</small>
            </div>
            <div class="col-6 col-md-3 mb-3">
              <div class="fw-semibold h4 mb-0"><?= number_format($losses) ?></div>
              <small class="text-muted">Losses</small>
            </div>
            <div class="col-6 col-md-3 mb-3">
              <div class="fw-semibold h4 mb-0"><?= number_format($pending) ?></div>
              <small class="text-muted">Pending</small>
            </div>
            <div class="col-6 col-md-3 mb-3">
              <div class="fw-semibold h4 mb-0"><?= number_format($totalBets) ?></div>
              <small class="text-muted">Total bets</small>
            </div>
          </div>
          <div class="row text-center">
            <div class="col-6 col-md-3 mb-3">
              <div class="fw-semibold h4 mb-0"><?= $winRateDisplay ?></div>
              <small class="text-muted">Win rate</small>
            </div>
            <div class="col-6 col-md-3 mb-3">
              <div class="fw-semibold h4 mb-0"><?= $ratioDisplay ?></div>
              <small class="text-muted">Win/loss ratio</small>
            </div>
            <div class="col-6 col-md-3 mb-3">
              <div class="fw-semibold h4 mb-0"><?= number_format($voids) ?></div>
              <small class="text-muted">Voids</small>
            </div>
            <div class="col-6 col-md-3 mb-3">
              <div class="fw-semibold h4 mb-0"><?= number_format($cancelled) ?></div>
              <small class="text-muted">Cancelled</small>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <h2 class="h5 mb-3">Recent activity</h2>
      <p class="text-muted mb-0">View your <a href="/sportsbet/public/my_bets.php">bet history</a> for detailed tickets and settlement results.</p>
    </div>
  </div>

  <div class="card shadow-sm mt-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Profit over time</h2>
        <?php if ($profitSeries): ?>
          <span class="text-muted small">Last <?= count($profitSeries) ?> settled day<?= count($profitSeries) === 1 ? '' : 's' ?></span>
        <?php endif; ?>
      </div>
      <?php if ($profitSeries): ?>
        <canvas id="profitChart" height="240"></canvas>
      <?php else: ?>
        <p class="text-muted mb-0">Settle at least one bet to unlock your profit history.</p>
      <?php endif; ?>
    </div>
  </div>

<?php include __DIR__ . '/partials/footer.php';

if ($profitSeries):
    $labelsJson = json_encode($profitLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $valuesJson = json_encode($profitValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" integrity="sha384-+iKZ6LcCzxUdiqhc0HCN6LiFmbx1Ksd2VkMY1k9EkJxTRmjigi1C4bPFUMgpK2BQ" crossorigin="anonymous"></script>
  <script>
    (function() {
      const ctx = document.getElementById('profitChart');
      if (!ctx) {
        return;
      }

      const labels = <?= $labelsJson ?>;
      const data = <?= $valuesJson ?>;

      new Chart(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [{
            label: 'Net profit',
            data,
            fill: false,
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13, 110, 253, 0.2)',
            tension: 0.25,
            pointRadius: 3,
            pointHoverRadius: 5,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: 'index',
            intersect: false,
          },
          scales: {
            x: {
              ticks: {
                maxRotation: 0,
                autoSkip: true,
                maxTicksLimit: 8,
              },
            },
            y: {
              ticks: {
                callback: (value) => '$' + Number(value).toFixed(2),
              },
            },
          },
          plugins: {
            tooltip: {
              callbacks: {
                label: (ctx) => {
                  const value = ctx.parsed.y ?? 0;
                  return `Net profit: $${value.toFixed(2)}`;
                },
              },
            },
          },
        },
      });
    })();
  </script>
<?php endif; ?>
