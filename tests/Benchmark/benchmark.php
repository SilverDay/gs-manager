<?php

declare(strict_types=1);

/**
 * Performance benchmarks for NFA-P2 (catalog import < 30 s) and NFA-P3 (OSCAL export < 10 s).
 *
 * Usage: php tests/Benchmark/benchmark.php [--verbose]
 *
 * Uses the test database (gsm-db-test) to avoid touching production data.
 * Exits with code 1 if any benchmark exceeds its limit.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use GsppManager\Config\AppConfig;
use GsppManager\Config\Database;
use GsppManager\Service\OscalParser;
use GsppManager\Service\OscalExporter;

// ── Bootstrap ────────────────────────────────────────────────────────────────

AppConfig::load(__DIR__ . '/../..');
$_ENV['DB_DATABASE'] = 'gsm-db-test';
$_ENV['APP_ENV']     = 'testing';
$_ENV['APP_DEBUG']   = 'false';
Database::reset();

$verbose = in_array('--verbose', $argv ?? [], true);
$pdo     = Database::getConnection();

// Ensure migrations are applied
require_once __DIR__ . '/../bootstrap.php';

// ── Helpers ──────────────────────────────────────────────────────────────────

function bench(string $label, int $limitSeconds, callable $fn, bool $verbose): array
{
    $start  = microtime(true);
    $fn();
    $elapsed = microtime(true) - $start;
    $pass    = $elapsed < $limitSeconds;

    if ($verbose) {
        printf("  [%s] %.3f s / %d s limit\n", $pass ? 'PASS' : 'FAIL', $elapsed, $limitSeconds);
    }

    return ['label' => $label, 'elapsed' => $elapsed, 'limit' => $limitSeconds, 'pass' => $pass];
}

function printTable(array $results): void
{
    $width = ['label' => 30, 'time' => 10, 'limit' => 10, 'status' => 8];
    $line  = str_repeat('-', array_sum($width) + count($width) * 3 + 1);

    printf("\n%s\n", $line);
    printf("| %-{$width['label']}s | %-{$width['time']}s | %-{$width['limit']}s | %-{$width['status']}s |\n",
        'Benchmark', 'Time', 'Limit', 'Status');
    printf("%s\n", $line);

    foreach ($results as $r) {
        printf("| %-{$width['label']}s | %-{$width['time']}s | %-{$width['limit']}s | %-{$width['status']}s |\n",
            $r['label'],
            sprintf('%.3f s', $r['elapsed']),
            sprintf('%d s', $r['limit']),
            $r['pass'] ? 'PASS' : 'FAIL'
        );
    }

    printf("%s\n\n", $line);
}

// ── Fixture setup ────────────────────────────────────────────────────────────

// Ensure a benchmark tenant + domain exist
$tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'bench-tenant' LIMIT 1")->fetchColumn();
if ($tenantId === 0) {
    $pdo->prepare("INSERT INTO tenants (name, slug, settings_json) VALUES ('Bench Tenant', 'bench-tenant', '{}')")
        ->execute();
    $tenantId = (int) $pdo->lastInsertId();
}

$catalogId = (int) $pdo->query("SELECT id FROM catalogs WHERE tenant_id = $tenantId LIMIT 1")->fetchColumn();
$domainId  = (int) $pdo->query("SELECT id FROM information_domains WHERE tenant_id = $tenantId LIMIT 1")->fetchColumn();

// ── P2: Catalog parse benchmark ───────────────────────────────────────────────
// Parse the sample fixture N times to accumulate meaningful elapsed time.
// The BSI production catalog is ~4 MB; the fixture is smaller but exercises the same code path.

$fixtureJson  = file_get_contents(__DIR__ . '/../Fixtures/oscal/sample_catalog.json');
$fixtureSizeKb = round(strlen($fixtureJson) / 1024, 1);
$parser       = new OscalParser();
$repetitions  = 50; // repeat to get a stable measurement on small fixtures

echo "Running benchmarks (NFA-P2 catalog parse × {$repetitions}, NFA-P3 SSP export)...\n";
echo "Fixture size: {$fixtureSizeKb} KB (production catalog is ~4 MB; limit scales linearly)\n";

$results = [];

$results[] = bench(
    "Catalog parse ×{$repetitions} iterations",
    30,
    static function () use ($parser, $fixtureJson, $repetitions): void {
        for ($i = 0; $i < $repetitions; $i++) {
            $parser->parse($fixtureJson);
        }
    },
    $verbose
);

// ── P3: SSP export benchmark ──────────────────────────────────────────────────

if ($domainId === 0) {
    // Create a minimal domain with scoped controls so the exporter has data
    $pdo->prepare(
        "INSERT INTO information_domains (tenant_id, name, description, isms_type, status, metadata_json) VALUES (?, 'Bench Domain', '', 'standard', 'active', '{}')"
    )->execute([$tenantId]);
    $domainId = (int) $pdo->lastInsertId();

    // Seed a handful of scoped controls and implementations if catalog exists
    if ($catalogId > 0) {
        $controls = $pdo->query(
            "SELECT control_id_str FROM catalog_controls WHERE catalog_id = $catalogId LIMIT 20"
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($controls as $ctrlId) {
            $pdo->prepare(
                "INSERT IGNORE INTO scoped_controls (domain_id, catalog_id, control_id_str, title)
                 VALUES (?, ?, ?, ?)"
            )->execute([$domainId, $catalogId, $ctrlId, $ctrlId]);

            $scopeId = (int) $pdo->query(
                "SELECT id FROM scoped_controls WHERE domain_id = $domainId AND control_id_str = " .
                $pdo->quote($ctrlId) . " LIMIT 1"
            )->fetchColumn();

            if ($scopeId > 0) {
                $pdo->prepare(
                    "INSERT IGNORE INTO implementations (scoped_control_id, status, description, updated_by)
                     VALUES (?, 'implemented', 'Benchmark placeholder', 1)"
                )->execute([$scopeId]);
            }
        }
    }
}

$exporter = new OscalExporter();

$results[] = bench(
    'SSP export',
    10,
    static function () use ($exporter, $domainId, $tenantId): void {
        $exporter->exportSsp($domainId, $tenantId);
    },
    $verbose
);

// ── Cleanup ───────────────────────────────────────────────────────────────────

// Remove benchmark-specific data to keep test DB clean
$scopeIds = $pdo->query("SELECT id FROM scoped_controls WHERE domain_id = $domainId")->fetchAll(\PDO::FETCH_COLUMN);
if (!empty($scopeIds)) {
    $in = implode(',', array_map('intval', $scopeIds));
    $pdo->exec("DELETE FROM implementations WHERE scoped_control_id IN ($in)");
}
$pdo->exec("DELETE FROM scoped_controls WHERE domain_id = $domainId");
$pdo->exec("DELETE FROM information_domains WHERE id = $domainId");
$pdo->exec("DELETE FROM tenants WHERE id = $tenantId");

// ── Results ───────────────────────────────────────────────────────────────────

printTable($results);

$failed = array_filter($results, static fn(array $r) => !$r['pass']);

if (count($failed) > 0) {
    echo 'BENCHMARK FAILED: ' . implode(', ', array_column($failed, 'label')) . "\n";
    exit(1);
}

echo "All benchmarks passed.\n";
exit(0);
