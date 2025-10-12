<?php
declare(strict_types=1);

if (!function_exists('decimal_to_american_odds')) {
    function decimal_to_american_odds(float $decimal): string
    {
        if ($decimal <= 1.0) {
            return 'N/A';
        }

        if ($decimal >= 2.0) {
            $value = (int) round(($decimal - 1.0) * 100.0);
            return sprintf('+%d', $value);
        }

        $value = (int) round(-100.0 / ($decimal - 1.0));
        return (string) $value;
    }
}

if (!function_exists('american_to_decimal_odds')) {
    function american_to_decimal_odds(string $raw): ?float
    {
        $trim = trim($raw);
        if ($trim === '') {
            return null;
        }

        $normalized = str_replace(',', '', $trim);
        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $normalized)) {
            return null;
        }

        $value = (float) $normalized;

        if (abs($value) >= 100.0) {
            // treat as American odds
            if ($value > 0.0) {
                return 1.0 + ($value / 100.0);
            }

            if ($value < 0.0) {
                return 1.0 + (100.0 / abs($value));
            }
        }

        if ($value > 1.0) {
            // Treat as decimal odds directly when > 1.0 but not in American range
            return $value;
        }

        return null;
    }
}

if (!function_exists('tracking_market_label')) {
    function tracking_market_label(string $market): string
    {
        $map = [
            'h2h'     => 'Moneyline',
            'spreads' => 'Spread',
            'totals'  => 'Total',
        ];

        $key = strtolower($market);
        return $map[$key] ?? ucfirst($market);
    }
}

if (!function_exists('tracking_format_outcome')) {
    function tracking_format_outcome(string $market, string $outcome, ?float $line): string
    {
        $marketLower = strtolower($market);
        $label = $outcome;

        if ($marketLower === 'spreads' && $line !== null) {
            $formatted = number_format($line, 3, '.', '');
            $formatted = rtrim(rtrim($formatted, '0'), '.');
            if ($formatted !== '' && $formatted[0] !== '-' && $formatted[0] !== '+') {
                $formatted = '+' . $formatted;
            }
            $label = trim($outcome . ' ' . $formatted);
        } elseif ($marketLower === 'totals' && $line !== null) {
            $formatted = number_format($line, 3, '.', '');
            $formatted = rtrim(rtrim($formatted, '0'), '.');
            $label = trim($outcome . ' ' . $formatted);
        }

        return $label;
    }
}

if (!function_exists('record_tracked_notifications')) {
    function record_tracked_notifications(
        PDO $pdo,
        string $eventId,
        string $market,
        string $outcome,
        ?float $line,
        float $price,
        string $bookmakerLabel
    ): void {
        static $stmtSelect = null;
        static $stmtEvent = null;
        static $stmtInsert = null;
        static $stmtUpdate = null;

        if ($stmtSelect === null) {
            $stmtSelect = $pdo->prepare(
                'SELECT id, user_id, target_price, last_notified_price FROM tracked_odds WHERE event_id = ? AND market = ? AND outcome = ? AND (line <=> ?)' 
            );
        }
        if ($stmtEvent === null) {
            $stmtEvent = $pdo->prepare('SELECT home_team, away_team FROM events WHERE event_id = ? LIMIT 1');
        }
        if ($stmtInsert === null) {
            $stmtInsert = $pdo->prepare(
                'INSERT INTO notifications (user_id, tracked_id, event_id, market, outcome, line, current_price, message) VALUES (:user_id, :tracked_id, :event_id, :market, :outcome, :line, :current_price, :message)'
            );
        }
        if ($stmtUpdate === null) {
            $stmtUpdate = $pdo->prepare(
                'UPDATE tracked_odds SET last_notified_at = UTC_TIMESTAMP(), last_notified_price = :price WHERE id = :id'
            );
        }

        $stmtSelect->execute([$eventId, $market, $outcome, $line]);
        $trackedRows = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);
        if (!$trackedRows) {
            return;
        }

        $eventLabel = null;
        $stmtEvent->execute([$eventId]);
        if ($row = $stmtEvent->fetch(PDO::FETCH_ASSOC)) {
            $home = (string) ($row['home_team'] ?? 'Home');
            $away = (string) ($row['away_team'] ?? 'Away');
            $eventLabel = trim($home . ' vs ' . $away);
        }
        if ($eventLabel === null || $eventLabel === '') {
            $eventLabel = 'Event ' . $eventId;
        }

        foreach ($trackedRows as $tracked) {
            $target = isset($tracked['target_price']) ? (float) $tracked['target_price'] : null;
            if ($target === null) {
                continue;
            }
            if (($price + 1e-9) < $target) {
                continue;
            }

            $lastPrice = $tracked['last_notified_price'] !== null ? (float) $tracked['last_notified_price'] : null;
            if ($lastPrice !== null && $price <= $lastPrice + 1e-6) {
                continue;
            }

            $currentAmerican = decimal_to_american_odds($price);
            $targetAmerican  = decimal_to_american_odds($target);
            $outcomeLabel    = tracking_format_outcome($market, $outcome, $line);
            $marketLabel     = tracking_market_label($market);

            $bookmakerNote = $bookmakerLabel !== '' ? ' at ' . $bookmakerLabel : '';
            $message = sprintf(
                '%s — %s reached %s (now %s%s)',
                $eventLabel,
                $marketLabel . ': ' . $outcomeLabel,
                $targetAmerican,
                $currentAmerican,
                $bookmakerNote
            );

            $stmtInsert->execute([
                ':user_id'       => (int) $tracked['user_id'],
                ':tracked_id'    => (int) $tracked['id'],
                ':event_id'      => $eventId,
                ':market'        => $market,
                ':outcome'       => $outcome,
                ':line'          => $line,
                ':current_price' => $price,
                ':message'       => $message,
            ]);

            $stmtUpdate->execute([
                ':price' => $price,
                ':id'    => (int) $tracked['id'],
            ]);
        }
    }
}

if (!function_exists('fetch_unread_notifications_count')) {
    function fetch_unread_notifications_count(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('fetch_tracked_items')) {
    function fetch_tracked_items(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.event_id, t.market, t.outcome, t.line, t.target_price, t.created_at, t.last_notified_at, e.commence_time, e.home_team, e.away_team
             FROM tracked_odds t
             LEFT JOIN events e ON e.event_id = t.event_id
             WHERE t.user_id = ?
             ORDER BY e.commence_time ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('delete_tracked_item')) {
    function delete_tracked_item(PDO $pdo, int $userId, int $trackedId): void
    {
        $stmt = $pdo->prepare('DELETE FROM tracked_odds WHERE id = ? AND user_id = ?');
        $stmt->execute([$trackedId, $userId]);
    }
}

if (!function_exists('mark_notification_read')) {
    function mark_notification_read(PDO $pdo, int $userId, int $notificationId): void
    {
        $stmt = $pdo->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $userId]);
    }
}

if (!function_exists('mark_all_notifications_read')) {
    function mark_all_notifications_read(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
    }
}
