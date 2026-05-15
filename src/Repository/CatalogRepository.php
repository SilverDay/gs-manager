<?php

declare(strict_types=1);

namespace GsppManager\Repository;

use GsppManager\Config\Database;
use PDO;

class CatalogRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Return all catalogs for a tenant (metadata only, no oscal_json).
     *
     * @return array<int, array>
     */
    public function findAllByTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, source_url, version_hash, imported_at
            FROM catalogs
            WHERE tenant_id = ?
            ORDER BY imported_at DESC
        ");
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    /**
     * Return a single catalog row (including oscal_json) for a tenant.
     */
    public function findByIdAndTenant(int $id, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, source_url, oscal_json, version_hash, imported_at
            FROM catalogs
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Insert a new catalog and return its new ID.
     */
    public function create(
        int     $tenantId,
        string  $name,
        ?string $sourceUrl,
        string  $oscalJson,
        string  $versionHash
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO catalogs (tenant_id, name, source_url, oscal_json, version_hash, imported_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$tenantId, $name, $sourceUrl, $oscalJson, $versionHash]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update oscal_json and version_hash after a re-import.
     */
    public function updateAfterReimport(int $id, string $oscalJson, string $versionHash): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE catalogs
            SET oscal_json = ?, version_hash = ?, imported_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$oscalJson, $versionHash, $id]);
    }
}
