<?php

declare(strict_types=1);

/**
 * Database migration runner
 *
 * Usage:
 *   php migrations/migrate.php            # Run pending migrations
 *   php migrations/migrate.php --status   # Show migration status
 *   php migrations/migrate.php --rollback # Rollback last migration
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GsppManager\Config\AppConfig;
use GsppManager\Config\Database;

AppConfig::load(__DIR__ . '/..');

$pdo = Database::getConnection();
$action = $argv[1] ?? '--up';

// Ensure migrations table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS _migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

// Get applied migrations
$applied = $pdo->query("SELECT filename FROM _migrations ORDER BY filename")
    ->fetchAll(PDO::FETCH_COLUMN);

// Get migration files
$files = glob(__DIR__ . '/*.sql');
sort($files);

if ($action === '--status') {
    echo "Migration Status:\n";
    echo str_repeat('-', 60) . "\n";
    foreach ($files as $file) {
        $name = basename($file);
        $status = in_array($name, $applied) ? '✅ Applied' : '⏳ Pending';
        echo "  {$status}  {$name}\n";
    }
    exit(0);
}

if ($action === '--rollback') {
    if (empty($applied)) {
        echo "Nothing to rollback.\n";
        exit(0);
    }

    $last = end($applied);
    $filePath = __DIR__ . '/' . $last;

    if (!file_exists($filePath)) {
        echo "Migration file not found: {$last}\n";
        exit(1);
    }

    $content = file_get_contents($filePath);
    $parts = preg_split('/^--\s*DOWN\s*$/m', $content);

    if (count($parts) < 2) {
        echo "No DOWN section found in {$last}\n";
        exit(1);
    }

    $downSql = trim($parts[1]);

    try {
        $pdo->exec($downSql);
        $stmt = $pdo->prepare("DELETE FROM _migrations WHERE filename = ?");
        $stmt->execute([$last]);
        echo "✅ Rolled back: {$last}\n";
    } catch (PDOException $e) {
        echo "❌ Rollback failed for {$last}: " . $e->getMessage() . "\n";
        exit(1);
    }

    exit(0);
}

// Default: run pending migrations
$pending = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $applied)) {
        continue;
    }

    $content = file_get_contents($file);

    // Extract UP section (everything before -- DOWN)
    $parts = preg_split('/^--\s*DOWN\s*$/m', $content);
    $upSql = trim($parts[0]);

    // Remove the -- UP marker if present
    $upSql = preg_replace('/^--\s*UP\s*$/m', '', $upSql);
    $upSql = trim($upSql);

    if (empty($upSql)) {
        echo "⏭️  Skipped (empty): {$name}\n";
        continue;
    }

    try {
        $pdo->exec($upSql);
        $stmt = $pdo->prepare("INSERT INTO _migrations (filename) VALUES (?)");
        $stmt->execute([$name]);
        echo "✅ Applied: {$name}\n";
        $pending++;
    } catch (PDOException $e) {
        echo "❌ Failed: {$name} — " . $e->getMessage() . "\n";
        exit(1);
    }
}

if ($pending === 0) {
    echo "Alle Migrationen sind aktuell.\n";
} else {
    echo "\n{$pending} Migration(en) angewendet.\n";
}
