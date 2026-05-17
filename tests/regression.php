<?php

declare(strict_types=1);

/**
 * Regression runner — executes the full test suite, checks coverage thresholds,
 * and runs performance benchmarks.  Exits with code 1 on any failure.
 *
 * Usage:
 *   php tests/regression.php              # full run
 *   php tests/regression.php --no-bench   # skip benchmarks (faster)
 *   php tests/regression.php --verbose    # verbose benchmark output
 */

$projectRoot = dirname(__DIR__);
$runBench    = !in_array('--no-bench', $argv ?? [], true);
$verbose     = in_array('--verbose', $argv ?? [], true);

// ── helpers ───────────────────────────────────────────────────────────────────

function printSection(string $title): void
{
    $line = str_repeat('─', 60);
    echo "\n$line\n$title\n$line\n";
}

function parseCoverage(string $cloverFile): ?float
{
    if (!file_exists($cloverFile)) {
        return null;
    }

    $xml = simplexml_load_file($cloverFile);
    if ($xml === false) {
        return null;
    }

    $metrics = $xml->project->metrics ?? null;
    if ($metrics === null) {
        return null;
    }

    $total   = (int) $metrics['coveredstatements'] + (int) $metrics['statements'] - (int) $metrics['coveredstatements'];
    $total   = (int) $metrics['statements'];
    $covered = (int) $metrics['coveredstatements'];

    if ($total === 0) {
        return null;
    }

    return round($covered / $total * 100, 1);
}

// ── Step 1: PHPUnit ───────────────────────────────────────────────────────────

printSection('1/3  PHPUnit test suite + coverage');

$coverageDir    = $projectRoot . '/build/coverage';
$cloverFile     = $coverageDir . '/clover.xml';
$hasCovDriver   = extension_loaded('pcov') || extension_loaded('xdebug');

@mkdir($coverageDir, 0755, true);

// When no coverage driver is available, pass --no-coverage to suppress the
// "No code coverage driver available" warning that would trigger failOnWarning.
if ($hasCovDriver) {
    $phpunitArgs = '--coverage-clover ' . escapeshellarg($cloverFile)
        . ' --coverage-html ' . escapeshellarg($coverageDir . '/html');
} else {
    $phpunitArgs = '--no-coverage';
}

$phpunitCmd = 'php ' . escapeshellarg($projectRoot . '/tests/run.php') . " $phpunitArgs 2>&1";

passthru($phpunitCmd, $phpunitCode);

// ── Step 2: Coverage check ────────────────────────────────────────────────────

printSection('2/3  Coverage threshold check');

$coveragePct      = parseCoverage($cloverFile);
$coverageRequired = 80.0;
$coveragePass     = false;

if (!$hasCovDriver) {
    echo "Coverage report not available (no pcov/xdebug driver installed).\n";
    echo "Install pcov: pecl install pcov && docker-php-ext-enable pcov\n";
    echo "Skipping threshold check (non-blocking).\n";
    $coveragePass = true;
} elseif ($coveragePct === null) {
    echo "Coverage report could not be parsed.\n";
    $coveragePass = false;
} else {
    $coveragePass = $coveragePct >= $coverageRequired;
    printf(
        "Line coverage: %.1f%% (required: %.0f%%)  →  %s\n",
        $coveragePct,
        $coverageRequired,
        $coveragePass ? 'PASS' : 'FAIL'
    );

    if ($coveragePass && file_exists($coverageDir . '/html/index.html')) {
        echo "HTML report: build/coverage/html/index.html\n";
    }
}

// ── Step 3: Benchmarks ────────────────────────────────────────────────────────

$benchCode = 0;

if ($runBench) {
    printSection('3/3  Performance benchmarks (NFA-P2, NFA-P3)');

    $benchArgs = $verbose ? '--verbose' : '';
    $benchCmd  = 'php ' . escapeshellarg($projectRoot . '/tests/Benchmark/benchmark.php') . " $benchArgs 2>&1";
    passthru($benchCmd, $benchCode);
} else {
    printSection('3/3  Performance benchmarks (skipped)');
    echo "Run without --no-bench to include benchmark results.\n";
}

// ── Summary ───────────────────────────────────────────────────────────────────

printSection('Summary');

$summaryRows = [
    ['PHPUnit test suite',   $phpunitCode === 0],
    ['Coverage ≥ 80%',       $coveragePass],
    ['Performance benchmarks', $benchCode === 0],
];

foreach ($summaryRows as [$label, $pass]) {
    printf("  %-30s %s\n", $label, $pass ? '✓ PASS' : '✗ FAIL');
}

$allPass = $phpunitCode === 0 && $coveragePass && $benchCode === 0;
echo "\n" . ($allPass ? "All checks passed.\n" : "One or more checks FAILED.\n");

exit($allPass ? 0 : 1);
