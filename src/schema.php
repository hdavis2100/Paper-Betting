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
}
