<?php
declare(strict_types=1);

/**
 * Lightweight schema helper that ensures the columns required by the
 * application exist. The scripts call this once per request/CLI run so we do
 * the minimal amount of work needed to detect the current schema.
 */

function column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $key = strtolower($db . '.' . $table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    $exists = (bool) $stmt->fetchColumn();
    $cache[$key] = $exists;
    return $exists;
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (column_exists($pdo, $table, $column)) {
        return;
    }

    $sql = sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition);
    $pdo->exec($sql);
}

function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $key = strtolower($db . '.' . $table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    $exists = (bool) $stmt->fetchColumn();
    $cache[$key] = $exists;

    return $exists;
}

function ensure_table(PDO $pdo, string $table, string $createSql): void
{
    if (table_exists($pdo, $table)) {
        return;
    }

    $pdo->exec($createSql);
}

function ensure_app_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        ensure_column($pdo, 'odds', 'line', 'DECIMAL(12,3) DEFAULT NULL AFTER `price`');
    } catch (Throwable $e) {
        // If we cannot add the column, leave the existing schema untouched but continue.
    }

    try {
        ensure_column($pdo, 'bets', 'market', "VARCHAR(40) NOT NULL DEFAULT 'h2h' AFTER `event_id`");
    } catch (Throwable $e) {
    }

    try {
        ensure_column($pdo, 'bets', 'line', 'DECIMAL(12,3) DEFAULT NULL AFTER `outcome`');
    } catch (Throwable $e) {
    }

    try {
        ensure_column($pdo, 'users', 'profile_public', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `created_at`');
    } catch (Throwable $e) {
    }

    try {
        ensure_table($pdo, 'tracked_odds', "
            CREATE TABLE IF NOT EXISTS `tracked_odds` (
                `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
                `user_id` INT(11) NOT NULL,
                `event_id` VARCHAR(100) NOT NULL,
                `market` VARCHAR(40) NOT NULL DEFAULT 'h2h',
                `outcome` VARCHAR(200) NOT NULL,
                `line` DECIMAL(12,3) DEFAULT NULL,
                `target_price` DECIMAL(11,4) NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
                `last_notified_at` TIMESTAMP NULL DEFAULT NULL,
                `last_notified_price` DECIMAL(11,4) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_event` (`user_id`, `event_id`, `market`, `outcome`, `line`),
                KEY `idx_event` (`event_id`),
                KEY `idx_user` (`user_id`),
                CONSTRAINT `fk_tracked_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_tracked_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }

    try {
        ensure_table($pdo, 'notifications', "
            CREATE TABLE IF NOT EXISTS `notifications` (
                `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
                `user_id` INT(11) NOT NULL,
                `tracked_id` BIGINT(20) DEFAULT NULL,
                `event_id` VARCHAR(100) NOT NULL,
                `market` VARCHAR(40) NOT NULL,
                `outcome` VARCHAR(200) NOT NULL,
                `line` DECIMAL(12,3) DEFAULT NULL,
                `current_price` DECIMAL(11,4) NOT NULL,
                `message` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
                `read_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_user_read` (`user_id`, `read_at`),
                CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_notifications_tracked` FOREIGN KEY (`tracked_id`) REFERENCES `tracked_odds` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }
}
