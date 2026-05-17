<?php

declare(strict_types=1);

namespace GsppManager\Service;

use GsppManager\Config\Database;
use PDO;

/**
 * Manages BSI Grundschutz++ cross-reference mappings.
 *
 * Supported mapping types:
 *   - Baustein → Schutzobjekt (mapping_baustein_zo)
 *   - Control  → external requirement (mapping_controls_anf)
 */
class MappingService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    // ── Baustein → Schutzobjekt ───────────────────────────────────────────────

    /**
     * Return all Baustein→Schutzobjekt mappings for a tenant+catalog.
     *
     * @return array<int, array>
     */
    public function getBausteinMappings(int $tenantId, int $catalogId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, baustein_id, zo_type, zo_name, notes, created_at
             FROM mapping_baustein_zo
             WHERE tenant_id = ? AND catalog_id = ?
             ORDER BY baustein_id, zo_type'
        );
        $stmt->execute([$tenantId, $catalogId]);
        return $stmt->fetchAll();
    }

    /**
     * Upsert a single Baustein → Schutzobjekt mapping.
     * Identified by (tenant_id, catalog_id, baustein_id, zo_type).
     */
    public function upsertBausteinMapping(
        int    $tenantId,
        int    $catalogId,
        string $bausteinId,
        string $zoType,
        string $zoName,
        ?string $notes = null
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO mapping_baustein_zo
                (tenant_id, catalog_id, baustein_id, zo_type, zo_name, notes)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE zo_name = VALUES(zo_name), notes = VALUES(notes)
        ");
        $stmt->execute([$tenantId, $catalogId, $bausteinId, $zoType, $zoName, $notes]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Bulk-import Baustein → Schutzobjekt mappings from an array.
     * Each item: { baustein_id, zo_type, zo_name, notes? }
     *
     * @param array<int, array> $rows
     * @return int Number of rows affected.
     */
    public function importBausteinMappings(int $tenantId, int $catalogId, array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (empty($row['baustein_id']) || empty($row['zo_type']) || empty($row['zo_name'])) {
                continue;
            }
            $this->upsertBausteinMapping(
                $tenantId,
                $catalogId,
                $row['baustein_id'],
                $row['zo_type'],
                $row['zo_name'],
                $row['notes'] ?? null
            );
            $count++;
        }
        return $count;
    }

    // ── Control → external requirement ───────────────────────────────────────

    /**
     * Return all control → external requirement mappings for a tenant+catalog,
     * optionally filtered by target framework.
     *
     * @return array<int, array>
     */
    public function getControlMappings(int $tenantId, int $catalogId, ?string $targetFramework = null): array
    {
        if ($targetFramework !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT id, control_id_str, target_framework, target_control, mapping_type, notes, created_at
                 FROM mapping_controls_anf
                 WHERE tenant_id = ? AND catalog_id = ? AND target_framework = ?
                 ORDER BY control_id_str, target_framework'
            );
            $stmt->execute([$tenantId, $catalogId, $targetFramework]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id, control_id_str, target_framework, target_control, mapping_type, notes, created_at
                 FROM mapping_controls_anf
                 WHERE tenant_id = ? AND catalog_id = ?
                 ORDER BY control_id_str, target_framework'
            );
            $stmt->execute([$tenantId, $catalogId]);
        }
        return $stmt->fetchAll();
    }

    /**
     * Bulk-import control → external requirement mappings.
     * Each item: { control_id_str, target_framework, target_control, mapping_type?, notes? }
     *
     * @param array<int, array> $rows
     * @return int Number of rows affected.
     */
    public function importControlMappings(int $tenantId, int $catalogId, array $rows): int
    {
        $validTypes = ['full', 'partial', 'none'];
        $count      = 0;

        foreach ($rows as $row) {
            if (empty($row['control_id_str']) || empty($row['target_framework']) || empty($row['target_control'])) {
                continue;
            }
            $mappingType = in_array($row['mapping_type'] ?? '', $validTypes, true)
                ? $row['mapping_type']
                : 'partial';

            $stmt = $this->pdo->prepare("
                INSERT INTO mapping_controls_anf
                    (tenant_id, catalog_id, control_id_str, target_framework, target_control, mapping_type, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    mapping_type = VALUES(mapping_type),
                    notes = VALUES(notes)
            ");
            $stmt->execute([
                $tenantId,
                $catalogId,
                $row['control_id_str'],
                $row['target_framework'],
                $row['target_control'],
                $mappingType,
                $row['notes'] ?? null,
            ]);
            $count++;
        }
        return $count;
    }
}
