<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

function build_selection_options(PDO $pdo, array $event, string $market): array
{
    $stmt = $pdo->prepare(
        'SELECT outcome, price, line, bookmaker FROM odds WHERE event_id = ? AND market = ?'
    );
    $stmt->execute([$event['event_id'], $market]);
    $rows = $stmt->fetchAll();

    $marketLower = strtolower($market);
    $options = [];

    $makeOption = static function (array $row, string $marketKey): array {
        $line = $row['line'] !== null ? (float) $row['line'] : null;
        $price = (float) $row['price'];
        $bookmaker = trim((string) ($row['bookmaker'] ?? ''));
        $label = format_market_outcome_label($marketKey, $row['outcome'], $line);
        $display = $label . ' — ' . format_american_odds($price);
        if ($bookmaker !== '') {
            $display .= ' (' . $bookmaker . ')';
        }
        $value = json_encode([
            'outcome' => $row['outcome'],
            'line'    => $line,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($value === false) {
            $value = '';
        }

        return [
            'outcome'   => $row['outcome'],
            'line'      => $line,
            'price'     => $price,
            'bookmaker' => $bookmaker,
            'label'     => $display,
            'value'     => $value,
        ];
    };

    if ($marketLower === 'h2h') {
        $home = $event['home_team'];
        $away = $event['away_team'];
        $bestHome = null;
        $bestAway = null;
        foreach ($rows as $row) {
            if ($row['outcome'] === $home) {
                if ($bestHome === null || (float) $row['price'] > (float) $bestHome['price']) {
                    $bestHome = $row;
                }
            } elseif ($row['outcome'] === $away) {
                if ($bestAway === null || (float) $row['price'] > (float) $bestAway['price']) {
                    $bestAway = $row;
                }
            }
        }
        if ($bestHome) {
            $options[] = $makeOption($bestHome, $marketLower);
        }
        if ($bestAway) {
            $options[] = $makeOption($bestAway, $marketLower);
        }
    } elseif ($marketLower === 'spreads') {
        $home = $event['home_team'];
        $away = $event['away_team'];
        $bestHome = null;
        $bestAway = null;
        foreach ($rows as $row) {
            if ($row['outcome'] === $home) {
                if ($bestHome === null || (float) $row['price'] > (float) $bestHome['price']) {
                    $bestHome = $row;
                }
            } elseif ($row['outcome'] === $away) {
                if ($bestAway === null || (float) $row['price'] > (float) $bestAway['price']) {
                    $bestAway = $row;
                }
            }
        }
        if ($bestHome) {
            $options[] = $makeOption($bestHome, $marketLower);
        }
        if ($bestAway) {
            $options[] = $makeOption($bestAway, $marketLower);
        }
    } elseif ($marketLower === 'totals') {
        $bestOver = null;
        $bestUnder = null;
        foreach ($rows as $row) {
            $name = strtolower((string) $row['outcome']);
            if ($name === 'over') {
                if ($bestOver === null || (float) $row['price'] > (float) $bestOver['price']) {
                    $bestOver = $row;
                }
            } elseif ($name === 'under') {
                if ($bestUnder === null || (float) $row['price'] > (float) $bestUnder['price']) {
                    $bestUnder = $row;
                }
            }
        }
        if ($bestOver) {
            $options[] = $makeOption($bestOver, $marketLower);
        }
        if ($bestUnder) {
            $options[] = $makeOption($bestUnder, $marketLower);
        }
    } else {
        usort($rows, static fn($a, $b) => (float) $b['price'] <=> (float) $a['price']);
        foreach (array_slice($rows, 0, 4) as $row) {
            $options[] = $makeOption($row, $marketLower);
        }
    }

    return $options;
}

$errors = [];
$success = null;

$eventId = trim((string) ($_POST['event_id'] ?? $_GET['event_id'] ?? ''));
$requestedMarket = trim((string) ($_POST['market'] ?? $_GET['market'] ?? 'h2h'));
$requestedMarket = $requestedMarket !== '' ? strtolower($requestedMarket) : 'h2h';

$event = null;
$availableMarkets = [];
$market = $requestedMarket;
$marketLower = $requestedMarket;
$marketLabel = format_market_label($market);
$selectionOptions = [];
$bettingClosed = false;
$prefOutcome = $_GET['outcome'] ?? null;
$prefLineInput = $_GET['line'] ?? null;
$prefLine = null;
if ($prefLineInput !== null && $prefLineInput !== '' && is_numeric($prefLineInput)) {
    $prefLine = (float) $prefLineInput;
}

if ($eventId !== '') {
    $stmt = $pdo->prepare('SELECT event_id, sport_key, home_team, away_team, commence_time FROM events WHERE event_id = ? LIMIT 1');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if ($event) {
        $marketsStmt = $pdo->prepare('SELECT DISTINCT market FROM odds WHERE event_id = ? ORDER BY market');
        $marketsStmt->execute([$eventId]);
        $availableMarkets = array_map(static fn($row) => (string) $row['market'], $marketsStmt->fetchAll());

        if (!in_array($market, $availableMarkets, true)) {
            if (in_array('h2h', $availableMarkets, true)) {
                $market = 'h2h';
            } elseif ($availableMarkets) {
                $market = $availableMarkets[0];
            }
        }
        $marketLower = strtolower($market);
        $marketLabel = format_market_label($market);

        $selectionOptions = build_selection_options($pdo, $event, $market);

        try {
            $kickoff = new DateTime($event['commence_time'], new DateTimeZone('UTC'));
            $now = new DateTime('now', new DateTimeZone('UTC'));
            if ($kickoff <= $now) {
                $bettingClosed = true;
            }
        } catch (Throwable $__) {
            $bettingClosed = false;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stake = isset($_POST['stake']) ? (float) $_POST['stake'] : 0.0;
    $selectionRaw = $_POST['selection'] ?? '';

    if ($event === null) {
        $errors[] = 'Invalid event selected.';
    }

    if (!$selectionOptions) {
        $errors[] = 'No odds are available for this market.';
    }

    if ($stake <= 0) {
        $errors[] = 'Please enter a positive stake.';
    }

    if ($selectionRaw === '') {
        $errors[] = 'Please select an outcome.';
    }

    $selectedOutcome = null;
    $selectedLine = null;

    if (!$errors) {
        $decoded = json_decode($selectionRaw, true);
        if (!is_array($decoded) || !isset($decoded['outcome'])) {
            $errors[] = 'Invalid outcome selection.';
        } else {
            $selectedOutcome = (string) $decoded['outcome'];
            if (array_key_exists('line', $decoded) && $decoded['line'] !== null && $decoded['line'] !== '') {
                if (!is_numeric((string) $decoded['line'])) {
                    $errors[] = 'Invalid line selection.';
                } else {
                    $selectedLine = (float) $decoded['line'];
                }
            }
        }
    }

    $matchedOption = null;
    if (!$errors) {
        foreach ($selectionOptions as $opt) {
            $lineOpt = $opt['line'];
            $lineMatch = false;
            if ($selectedLine === null && $lineOpt === null) {
                $lineMatch = true;
            } elseif ($selectedLine !== null && $lineOpt !== null && abs($lineOpt - $selectedLine) < 0.0001) {
                $lineMatch = true;
            }
            if ($lineMatch && $opt['outcome'] === $selectedOutcome) {
                $matchedOption = $opt;
                break;
            }
        }
        if ($matchedOption === null) {
            $errors[] = 'The selected outcome is no longer available. Please refresh and try again.';
        }
    }

    if (!$errors && $bettingClosed) {
        $errors[] = 'Betting is closed for this event (already started).';
    }

    if (!$errors && $event) {
        try {
            $priceStmt = $pdo->prepare(
                'SELECT price, line FROM odds WHERE event_id = ? AND market = ? AND outcome = ? ORDER BY last_updated DESC'
            );
            $priceStmt->execute([$eventId, $market, $selectedOutcome]);
            $rows = $priceStmt->fetchAll();
            $oddsRow = null;
            foreach ($rows as $row) {
                $lineRow = $row['line'] !== null ? (float) $row['line'] : null;
                if ($selectedLine === null && $lineRow === null) {
                    $oddsRow = $row;
                    break;
                }
                if ($selectedLine !== null && $lineRow !== null && abs($lineRow - $selectedLine) < 0.0001) {
                    $oddsRow = $row;
                    break;
                }
            }
            if ($oddsRow === null) {
                $errors[] = 'Unable to locate current odds for that outcome. Try again.';
            } else {
                $odds = (float) $oddsRow['price'];
                $lineForBet = $oddsRow['line'] !== null ? (float) $oddsRow['line'] : null;
                $potential = round($stake * $odds, 2);
                $userId = current_user()['id'];

                $pdo->beginTransaction();

                $walletStmt = $pdo->prepare('SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE');
                $walletStmt->execute([$userId]);
                $wallet = $walletStmt->fetch();
                if (!$wallet) {
                    throw new RuntimeException('Wallet not found.');
                }
                if ((float) $wallet['balance'] < $stake) {
                    throw new RuntimeException('Insufficient funds.');
                }

                $newBalance = (float) $wallet['balance'] - $stake;
                $pdo->prepare('UPDATE wallets SET balance = ? WHERE user_id = ?')->execute([$newBalance, $userId]);

                $pdo->prepare('INSERT INTO bets (user_id, event_id, market, outcome, line, odds, stake, potential_return) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([
                        $userId,
                        $eventId,
                        $market,
                        $selectedOutcome,
                        $lineForBet,
                        $odds,
                        $stake,
                        $potential,
                    ]);

                $pdo->prepare('INSERT INTO wallet_transactions (user_id, change_amt, balance_after, reason) VALUES (?,?,?,?)')
                    ->execute([$userId, -$stake, $newBalance, 'bet']);

                $pdo->commit();

                $success = sprintf(
                    'Bet placed on %s (%s) — potential return %s',
                    format_market_outcome_label($marketLower, $selectedOutcome, $lineForBet),
                    format_american_odds($odds),
                    number_format($potential, 2)
                );

                $prefOutcome = $selectedOutcome;
                $prefLine = $lineForBet;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

include __DIR__ . '/partials/header.php';
?>
<div class="row">
  <div class="col-lg-8">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h1 class="h4 mb-3">Place Bet</h1>
        <?php foreach ($errors as $err): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>
        <?php if ($success): ?>
          <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if (!$event): ?>
          <p>Select an event from the <a href="/sportsbet/public/events.php">events list</a> to get started.</p>
        <?php else: ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <h2 class="h5 mb-1"><?= htmlspecialchars($event['home_team']) ?> vs <?= htmlspecialchars($event['away_team']) ?></h2>
                <div class="text-muted">Kickoff (ET): <?= htmlspecialchars(format_est_datetime($event['commence_time'])) ?></div>
              </div>
              <div>
                <form method="get" class="d-flex align-items-center gap-2">
                  <input type="hidden" name="event_id" value="<?= htmlspecialchars($event['event_id']) ?>">
                  <label class="form-label mb-0" for="market">Market</label>
                  <select class="form-select form-select-sm" id="market" name="market" onchange="this.form.submit()">
                    <?php foreach ($availableMarkets as $m): ?>
                      <option value="<?= htmlspecialchars(strtolower($m)) ?>" <?php if (strtolower($m) === $marketLower) echo 'selected'; ?>>
                        <?= htmlspecialchars(format_market_label($m)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </div>
            </div>
          </div>

          <?php if ($bettingClosed): ?>
            <div class="alert alert-warning">Betting is closed for this event (already started).</div>
          <?php endif; ?>

          <?php if (empty($selectionOptions)): ?>
            <div class="alert alert-info">No odds are available for the selected market.</div>
          <?php else: ?>
            <div class="mb-3">
              <h3 class="h6 text-uppercase text-muted mb-2">Available selections</h3>
              <ul class="list-unstyled mb-0">
                <?php foreach ($selectionOptions as $opt): ?>
                  <li>• <?= htmlspecialchars($opt['label']) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>

            <?php if (!$bettingClosed): ?>
              <form method="post" class="mt-3">
                <input type="hidden" name="event_id" value="<?= htmlspecialchars($event['event_id']) ?>">
                <input type="hidden" name="market" value="<?= htmlspecialchars($marketLower) ?>">
                <div class="mb-3">
                  <label class="form-label" for="selection">Outcome</label>
                  <select class="form-select" id="selection" name="selection" required>
                    <option value="">-- choose --</option>
                    <?php foreach ($selectionOptions as $opt): ?>
                      <?php
                        $selected = '';
                        $lineOpt = $opt['line'];
                        $lineMatch = ($prefLine === null && $lineOpt === null) || ($prefLine !== null && $lineOpt !== null && abs($prefLine - $lineOpt) < 0.0001);
                        if ($prefOutcome !== null && $opt['outcome'] === $prefOutcome && $lineMatch) {
                            $selected = 'selected';
                        }
                      ?>
                      <option value='<?= htmlspecialchars($opt['value']) ?>' <?= $selected ?>><?= htmlspecialchars($opt['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="stake">Stake</label>
                  <input class="form-control" type="number" min="1" step="0.01" id="stake" name="stake" value="<?= isset($_POST['stake']) ? htmlspecialchars((string) $_POST['stake']) : '' ?>" required>
                </div>
                <button class="btn btn-primary" type="submit">Place bet</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
