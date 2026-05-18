<?php

declare(strict_types=1);

/**
 * One-time backfill: populate scoped_controls.description from catalog OSCAL JSON.
 *
 * Run: php scripts/backfill_scoped_control_descriptions.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GsppManager\Config\AppConfig;
use GsppManager\Config\Database;
use GsppManager\Service\OscalParser;

AppConfig::load(__DIR__ . '/..');

$pdo    = Database::getConnection();
$parser = new OscalParser();

// Find all scoped controls with no description, grouped by catalog
$stmt = $pdo->query("
    SELECT DISTINCT sc.catalog_id
    FROM scoped_controls sc
    WHERE sc.description IS NULL OR sc.description = ''
");
$catalogIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($catalogIds)) {
    echo "No scoped controls with missing description. Nothing to do.\n";
    exit(0);
}

$updated = 0;

foreach ($catalogIds as $catalogId) {
    $catStmt = $pdo->prepare("SELECT oscal_json FROM catalogs WHERE id = ? LIMIT 1");
    $catStmt->execute([$catalogId]);
    $row = $catStmt->fetch();

    if ($row === false) {
        echo "  WARNING: Catalog {$catalogId} not found, skipping.\n";
        continue;
    }

    $parsed   = $parser->parse($row['oscal_json']);
    $controls = $parser->flattenControls($parsed);

    // Build map: control_id_str => statement text
    $statements = [];
    foreach ($controls as $id => $ctrl) {
        $statements[$id] = $ctrl['statement'] ?? '';
    }

    // Fetch affected rows for this catalog
    $rowStmt = $pdo->prepare("
        SELECT id, control_id_str
        FROM scoped_controls
        WHERE catalog_id = ? AND (description IS NULL OR description = '')
    ");
    $rowStmt->execute([$catalogId]);
    $rows = $rowStmt->fetchAll();

    $upd = $pdo->prepare("UPDATE scoped_controls SET description = ? WHERE id = ?");
    foreach ($rows as $sc) {
        $desc = $statements[$sc['control_id_str']] ?? null;
        $upd->execute([$desc, $sc['id']]);
        $updated++;
    }

    echo "  Catalog {$catalogId}: updated " . count($rows) . " rows.\n";
}

echo "Done. Total updated: {$updated}\n";
