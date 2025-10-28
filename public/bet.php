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
$trackErrors = [];
$trackSuccess = null;

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
$trackOutcomePref = $prefOutcome;
$trackLinePref = $prefLine;
$trackTargetPref = '';

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
    $action = $_POST['action'] ?? 'bet';

    if ($action === 'track') {
        if ($event === null) {
            $trackErrors[] = 'Invalid event selected.';
        }

        if (!$selectionOptions) {
            $trackErrors[] = 'No odds are available for tracking right now.';
        }

        $selectionRaw = $_POST['track_selection'] ?? '';
        $trackTargetPref = $_POST['target_american'] ?? '';
        if ($selectionRaw === '') {
            $trackErrors[] = 'Please choose a selection to track.';
        }

        $trackOutcome = null;
        $trackLine = null;
        if (!$trackErrors) {
            $decoded = json_decode($selectionRaw, true);
            if (!is_array($decoded) || !isset($decoded['outcome'])) {
                $trackErrors[] = 'Invalid selection payload.';
            } else {
                $trackOutcome = (string) $decoded['outcome'];
                $trackOutcomePref = $trackOutcome;
                if (array_key_exists('line', $decoded) && $decoded['line'] !== null && $decoded['line'] !== '') {
                    if (!is_numeric((string) $decoded['line'])) {
                        $trackErrors[] = 'Invalid line value for tracking.';
                    } else {
                        $trackLine = (float) $decoded['line'];
                        $trackLinePref = $trackLine;
                    }
                }
            }
        }

        $matchForTracking = null;
        if (!$trackErrors) {
            foreach ($selectionOptions as $opt) {
                $lineOpt = $opt['line'];
                $lineMatch = ($trackLine === null && $lineOpt === null) || ($trackLine !== null && $lineOpt !== null && abs($trackLine - $lineOpt) < 0.0001);
                if ($lineMatch && $opt['outcome'] === $trackOutcome) {
                    $matchForTracking = $opt;
                    break;
                }
            }
            if ($matchForTracking === null) {
                $trackErrors[] = 'The selected outcome is no longer available to track.';
            }
        }

        $targetInput = trim((string) ($_POST['target_american'] ?? ''));
        $targetDecimal = null;
        if ($targetInput === '') {
            $trackErrors[] = 'Enter a target odds value (e.g. +160 or 2.6).';
        } else {
            $targetDecimal = american_to_decimal_odds($targetInput);
            if ($targetDecimal === null || $targetDecimal <= 1.0) {
                $trackErrors[] = 'Enter a valid odds value greater than +100/1.00.';
            }
        }

        if (!$trackErrors && $event !== null && $matchForTracking !== null && $targetDecimal !== null) {
            $userId = (int) current_user()['id'];
            $lookup = $pdo->prepare(
                'SELECT id FROM tracked_odds WHERE user_id = ? AND event_id = ? AND market = ? AND outcome = ? AND (line <=> ?)' 
            );
            $lookup->execute([$userId, $eventId, $market, $trackOutcome, $trackLine]);
            $existingId = $lookup->fetchColumn();

            if ($existingId) {
                $update = $pdo->prepare(
                    'UPDATE tracked_odds SET target_price = ?, updated_at = UTC_TIMESTAMP(), last_notified_at = NULL, last_notified_price = NULL WHERE id = ?'
                );
                $update->execute([$targetDecimal, (int) $existingId]);
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO tracked_odds (user_id, event_id, market, outcome, line, target_price) VALUES (?,?,?,?,?,?)'
                );
                $insert->execute([$userId, $eventId, $market, $trackOutcome, $trackLine, $targetDecimal]);
            }

            $trackSuccess = sprintf(
                'Tracking %s at %s. We\'ll notify you when the price reaches your target.',
                format_market_outcome_label($marketLower, $trackOutcome, $trackLine),
                decimal_to_american_odds($targetDecimal)
            );
            $trackTargetPref = '';

            if (isset($matchForTracking['price']) && (float) $matchForTracking['price'] >= $targetDecimal) {
                record_tracked_notifications($pdo, $eventId, $market, $trackOutcome, $trackLine, (float) $matchForTracking['price'], $matchForTracking['bookmaker'] ?? '');
            }
        }
    } else {
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
        <?php foreach ($trackErrors as $err): ?>
          <div class="alert alert-warning"><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>
        <?php if ($trackSuccess): ?>
          <div class="alert alert-info"><?= htmlspecialchars($trackSuccess) ?></div>
        <?php endif; ?>

        <?php if (!$event): ?>
          <p>Select an event from the <a href="/betleague/public/events.php">events list</a> to get started.</p>
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
                <input type="hidden" name="action" value="bet">
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
        <?php if ($event && $selectionOptions): ?>
          <hr class="my-4">
          <div class="mt-3">
            <h3 class="h6 text-uppercase text-muted mb-2">Set a price alert</h3>
            <p class="text-muted small mb-3">Choose a selection and target odds. We'll notify you when the market reaches your goal.</p>
            <form method="post" class="row gy-2 gx-3 align-items-end">
              <input type="hidden" name="action" value="track">
              <input type="hidden" name="event_id" value="<?= htmlspecialchars($event['event_id']) ?>">
              <input type="hidden" name="market" value="<?= htmlspecialchars($marketLower) ?>">
              <div class="col-md-6">
                <label class="form-label" for="track_selection">Selection</label>
                <select class="form-select" id="track_selection" name="track_selection" required>
                  <option value="">-- choose --</option>
                  <?php foreach ($selectionOptions as $opt): ?>
                    <?php
                      $selected = '';
                      $lineOpt = $opt['line'];
                      $lineMatch = ($trackLinePref === null && $lineOpt === null) || ($trackLinePref !== null && $lineOpt !== null && abs($trackLinePref - $lineOpt) < 0.0001);
                      if ($trackOutcomePref !== null && $opt['outcome'] === $trackOutcomePref && $lineMatch) {
                          $selected = 'selected';
                      }
                    ?>
                    <option value='<?= htmlspecialchars($opt['value']) ?>' <?= $selected ?>><?= htmlspecialchars($opt['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label" for="target_american">Target odds</label>
                <input class="form-control" type="text" id="target_american" name="target_american" placeholder="e.g. +160" value="<?= htmlspecialchars((string) $trackTargetPref) ?>" required>
              </div>
              <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary w-100">Track price</button>
              </div>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
