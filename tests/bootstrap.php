<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GsppManager\Config\AppConfig;
use GsppManager\Config\Database;

// Load credentials from .env — then immediately override to test targets.
// This must happen after phpdotenv to win any race with $_ENV writes.
AppConfig::load(__DIR__ . '/..');

$_ENV['DB_DATABASE'] = 'gsm-db-test';
$_ENV['APP_ENV']     = 'testing';
$_ENV['APP_DEBUG']   = 'false';

// Reset DB singleton so it reconnects to gsm-db-test
Database::reset();

// Start a session for tests that touch $_SESSION (no cookie headers in CLI)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_cookies', '0');
    ini_set('session.use_only_cookies', '0');
    session_id('phpunit-test-session');
    session_start();
}

// Run all migrations against the test DB (idempotent — skips already-applied)
$pdo = Database::getConnection();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS _migrations (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename    VARCHAR(255) NOT NULL UNIQUE,
        applied_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");

$applied = $pdo->query("SELECT filename FROM _migrations ORDER BY filename")
    ->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/../migrations/*.sql');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    $content  = file_get_contents($file);
    $parts    = preg_split('/^--\s*DOWN\s*$/m', $content);
    $upSql    = trim(preg_replace('/^--\s*UP\s*$/m', '', $parts[0]));
    if ($upSql !== '') {
        $pdo->exec($upSql);
        $pdo->prepare("INSERT INTO _migrations (filename) VALUES (?)")->execute([$name]);
    }
}

// Load initial test fixtures (truncates + reseeds done per-test in IntegrationTestCase)
$fixturePath = __DIR__ . '/Fixtures/db/test_seed.sql';
if (file_exists($fixturePath)) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    // Truncate all data tables so the fixture is the single source of truth
    $tables = [
        'audit_log', 'ai_cache', 'document_versions', 'evidence_files',
        'poam_items', 'assessment_findings', 'assessment_plans',
        'risk_controls', 'risks', 'implementations', 'scoped_controls',
        'profiles', 'process_assets', 'business_processes', 'assets',
        'information_domains', 'catalogs', 'users', 'tenants',
    ];
    foreach ($tables as $t) {
        $pdo->exec("TRUNCATE TABLE `{$t}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->exec(file_get_contents($fixturePath));
}
