<?php

declare(strict_types=1);

namespace GsppManager\Repository;

use GsppManager\Config\Database;
use PDO;

class DomainRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    // ── Domains ───────────────────────────────────────────────────────────────

    /**
     * @return array<int, array>
     */
    public function findAllByTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT d.id, d.name, d.description, d.isms_type, d.status,
                   d.metadata_json, d.created_at, d.updated_at,
                   COUNT(sc.id) AS control_count
            FROM information_domains d
            LEFT JOIN scoped_controls sc ON sc.domain_id = d.id
            WHERE d.tenant_id = ?
            GROUP BY d.id
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    public function findByIdAndTenant(int $id, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT d.id, d.name, d.description, d.isms_type, d.status,
                   d.metadata_json, d.created_by, d.created_at, d.updated_at,
                   COUNT(sc.id) AS control_count
            FROM information_domains d
            LEFT JOIN scoped_controls sc ON sc.domain_id = d.id
            WHERE d.id = ? AND d.tenant_id = ?
            GROUP BY d.id
            LIMIT 1
        ");
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function create(int $tenantId, int $userId, array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO information_domains
                (tenant_id, name, description, isms_type, metadata_json, status, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())
        ");
        $stmt->execute([
            $tenantId,
            $data['name'],
            $data['description'] ?? null,
            $data['isms_type']   ?? 'standard',
            $data['metadata_json'] ?? null,
            $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE information_domains
            SET name = ?, description = ?, isms_type = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['isms_type']   ?? 'standard',
            $data['status']      ?? 'active',
            $id,
        ]);
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    /**
     * @return array<int, array>
     */
    public function findAssets(int $domainId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, asset_type, category_name, description,
                   protection_need_c, protection_need_i, protection_need_a,
                   metadata_json, created_at
            FROM assets
            WHERE domain_id = ?
            ORDER BY name ASC
        ");
        $stmt->execute([$domainId]);
        return $stmt->fetchAll();
    }

    public function createAsset(int $domainId, array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO assets
                (domain_id, name, asset_type, category_name, description,
                 protection_need_c, protection_need_i, protection_need_a, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $domainId,
            $data['name'],
            $data['asset_type']     ?? null,
            $data['category_name']  ?? null,
            $data['description']    ?? null,
            $data['protection_need_c'] ?? 'normal',
            $data['protection_need_i'] ?? 'normal',
            $data['protection_need_a'] ?? 'normal',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Business Processes ────────────────────────────────────────────────────

    /**
     * @return array<int, array>
     */
    public function findProcesses(int $domainId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT p.id, p.name, p.description, p.criticality,
                   p.owner_user_id, p.created_at,
                   GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ') AS linked_assets
            FROM business_processes p
            LEFT JOIN process_assets pa ON pa.process_id = p.id
            LEFT JOIN assets a ON a.id = pa.asset_id
            WHERE p.domain_id = ?
            GROUP BY p.id
            ORDER BY p.name ASC
        ");
        $stmt->execute([$domainId]);
        return $stmt->fetchAll();
    }

    public function createProcess(int $domainId, array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO business_processes
                (domain_id, name, description, criticality, owner_user_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $domainId,
            $data['name'],
            $data['description']    ?? null,
            $data['criticality']    ?? 'medium',
            $data['owner_user_id']  ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function linkProcessAsset(int $processId, int $assetId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO process_assets (process_id, asset_id) VALUES (?, ?)"
        );
        $stmt->execute([$processId, $assetId]);
    }

    // ── Scoped Controls ───────────────────────────────────────────────────────

    /**
     * @return array<int, array>
     */
    public function findScopedControls(int $domainId, string $search = ''): array
    {
        if ($search !== '') {
            $stmt = $this->pdo->prepare("
                SELECT id, control_id_str, catalog_id, title, description,
                       parameters_json, tailoring_json, is_custom, created_at, updated_at
                FROM scoped_controls
                WHERE domain_id = ?
                  AND (control_id_str LIKE ? OR title LIKE ?)
                ORDER BY control_id_str ASC
            ");
            $like = '%' . $search . '%';
            $stmt->execute([$domainId, $like, $like]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT id, control_id_str, catalog_id, title, description,
                       parameters_json, tailoring_json, is_custom, created_at, updated_at
                FROM scoped_controls
                WHERE domain_id = ?
                ORDER BY control_id_str ASC
            ");
            $stmt->execute([$domainId]);
        }
        return $stmt->fetchAll();
    }

    public function findScopedControlByStr(int $domainId, string $controlIdStr): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM scoped_controls
            WHERE domain_id = ? AND control_id_str = ?
            LIMIT 1
        ");
        $stmt->execute([$domainId, $controlIdStr]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function upsertScopedControl(int $domainId, array $data): int
    {
        $existing = $this->findScopedControlByStr($domainId, $data['control_id_str']);

        if ($existing !== null) {
            $stmt = $this->pdo->prepare("
                UPDATE scoped_controls
                SET parameters_json = ?, tailoring_json = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $data['parameters_json'] ?? $existing['parameters_json'],
                $data['tailoring_json']  ?? $existing['tailoring_json'],
                $existing['id'],
            ]);
            return (int) $existing['id'];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO scoped_controls
                (domain_id, control_id_str, catalog_id, title, description,
                 parameters_json, tailoring_json, is_custom, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $domainId,
            $data['control_id_str'],
            $data['catalog_id'],
            $data['title']           ?? '',
            $data['description']     ?? null,
            $data['parameters_json'] ?? '{}',
            $data['tailoring_json']  ?? '{}',
            $data['is_custom']       ?? false,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Bulk-insert scoped controls from a flattened control list (from TailoringEngine).
     * Used once at domain creation time.
     */
    public function saveScopedControls(int $domainId, array $controls, int $catalogId): void
    {
        if (empty($controls)) {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO scoped_controls
                (domain_id, control_id_str, catalog_id, title,
                 parameters_json, tailoring_json, is_custom, created_at, updated_at)
            VALUES (?, ?, ?, ?, '{}', '{}', FALSE, NOW(), NOW())
        ");

        foreach ($controls as $control) {
            $stmt->execute([
                $domainId,
                $control['id'],
                $catalogId,
                $control['title'],
            ]);
        }
    }

    // ── Profiles ──────────────────────────────────────────────────────────────

    public function saveProfile(int $domainId, int $userId, string $oscalJson): int
    {
        // Increment version
        $vStmt = $this->pdo->prepare(
            "SELECT COALESCE(MAX(version), 0) + 1 FROM profiles WHERE domain_id = ?"
        );
        $vStmt->execute([$domainId]);
        $version = (int) $vStmt->fetchColumn();

        $stmt = $this->pdo->prepare("
            INSERT INTO profiles (domain_id, version, oscal_json, created_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$domainId, $version, $oscalJson, $userId]);
        return (int) $this->pdo->lastInsertId();
    }
}
