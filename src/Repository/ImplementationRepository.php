<?php

declare(strict_types=1);

namespace GsppManager\Repository;

use GsppManager\Config\Database;
use PDO;

class ImplementationRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Returns paginated implementation rows for a domain, each merged with its control metadata.
     * Tenant isolation is enforced via JOIN to information_domains.
     *
     * @param array{status?: string, asset_id?: int|string, search?: string} $filters
     * @return array{items: array, total: int, progress: array}
     */
    public function findByDomain(
        int   $domainId,
        int   $tenantId,
        array $filters  = [],
        int   $page     = 1,
        int   $perPage  = 50
    ): array {
        $where  = ['d.tenant_id = ?', 'sc.domain_id = ?'];
        $params = [$tenantId, $domainId];

        if (!empty($filters['status'])) {
            $where[]  = 'i.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['asset_id'])) {
            $where[]  = 'EXISTS (SELECT 1 FROM implementation_assets ia WHERE ia.implementation_id = i.id AND ia.asset_id = ?)';
            $params[] = (int) $filters['asset_id'];
        }

        if (!empty($filters['search'])) {
            $where[]  = '(sc.control_id_str LIKE ? OR sc.title LIKE ?)';
            $like     = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereStr = implode(' AND ', $where);

        // Progress summary (always unfiltered by status/asset so the bar totals are correct)
        $progressSql = "
            SELECT
                COUNT(*) AS total,
                SUM(i.status = 'implemented')    AS implemented,
                SUM(i.status = 'partial')         AS partial,
                SUM(i.status = 'planned')         AS planned,
                SUM(i.status = 'not_started')     AS not_started,
                SUM(i.status = 'not_applicable')  AS not_applicable
            FROM implementations i
            JOIN scoped_controls sc ON sc.id = i.scoped_control_id
            JOIN information_domains d ON d.id = sc.domain_id
            WHERE d.tenant_id = ? AND sc.domain_id = ?
        ";
        $pStmt = $this->pdo->prepare($progressSql);
        $pStmt->execute([$tenantId, $domainId]);
        $progress = $pStmt->fetch(PDO::FETCH_ASSOC);
        $progress = array_map('intval', $progress);

        // Count for pagination
        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM implementations i
             JOIN scoped_controls sc ON sc.id = i.scoped_control_id
             JOIN information_domains d ON d.id = sc.domain_id
             WHERE {$whereStr}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch page
        $offset  = ($page - 1) * $perPage;
        $listSql = "
            SELECT
                i.id, i.scoped_control_id,
                i.status, i.maturity_level, i.description,
                i.responsible_user_id, i.target_date, i.completion_date,
                i.evidence_json, i.parameters_json,
                i.updated_by, i.updated_at,
                sc.control_id_str, sc.title AS control_title,
                sc.description AS control_description, sc.tailoring_json,
                u.display_name AS responsible_name
            FROM implementations i
            JOIN scoped_controls sc ON sc.id = i.scoped_control_id
            JOIN information_domains d ON d.id = sc.domain_id
            LEFT JOIN users u ON u.id = i.responsible_user_id
            WHERE {$whereStr}
            ORDER BY sc.control_id_str ASC
            LIMIT ? OFFSET ?
        ";
        $listStmt = $this->pdo->prepare($listSql);
        $listStmt->execute(array_merge($params, [$perPage, $offset]));
        $items = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach asset_ids arrays
        if (!empty($items)) {
            $implIds     = array_column($items, 'id');
            $placeholders = implode(',', array_fill(0, count($implIds), '?'));
            $iaStmt = $this->pdo->prepare(
                "SELECT implementation_id, asset_id FROM implementation_assets WHERE implementation_id IN ({$placeholders})"
            );
            $iaStmt->execute($implIds);
            $assetMap = [];
            foreach ($iaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $assetMap[(int) $row['implementation_id']][] = (int) $row['asset_id'];
            }
            foreach ($items as &$item) {
                $item['asset_ids'] = $assetMap[(int) $item['id']] ?? [];
            }
            unset($item);
        }

        return ['items' => $items, 'total' => $total, 'progress' => $progress];
    }

    /**
     * Tenant-safe lookup of a single implementation.
     */
    public function findById(int $implId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT i.*, sc.control_id_str, sc.title AS control_title,
                   sc.description AS control_description, sc.tailoring_json
            FROM implementations i
            JOIN scoped_controls sc ON sc.id = i.scoped_control_id
            JOIN information_domains d ON d.id = sc.domain_id
            WHERE i.id = ? AND d.tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$implId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $iaStmt = $this->pdo->prepare(
            "SELECT asset_id FROM implementation_assets WHERE implementation_id = ?"
        );
        $iaStmt->execute([$implId]);
        $row['asset_ids'] = array_map('intval', $iaStmt->fetchAll(PDO::FETCH_COLUMN));
        return $row;
    }

    /**
     * Replace the full set of linked assets for an implementation.
     * Tenant ownership must be verified by the caller before invoking.
     *
     * @param int[] $assetIds
     */
    public function setAssets(int $implId, array $assetIds): void
    {
        $this->pdo->prepare("DELETE FROM implementation_assets WHERE implementation_id = ?")
                  ->execute([$implId]);

        if (!empty($assetIds)) {
            $placeholders = implode(',', array_fill(0, count($assetIds), '(?,?)'));
            $values = [];
            foreach ($assetIds as $aid) {
                $values[] = $implId;
                $values[] = (int) $aid;
            }
            $this->pdo->prepare("INSERT IGNORE INTO implementation_assets (implementation_id, asset_id) VALUES {$placeholders}")
                      ->execute($values);
        }
    }

    /**
     * Create implementation rows for every scoped control in the domain that
     * does not yet have one. Returns the count of newly created rows.
     */
    public function ensureAllExist(int $domainId, int $tenantId): int
    {
        // Verify tenant owns this domain
        $chk = $this->pdo->prepare(
            "SELECT id FROM information_domains WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $chk->execute([$domainId, $tenantId]);
        if ($chk->fetch() === false) {
            return 0;
        }

        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO implementations (scoped_control_id)
            SELECT sc.id
            FROM scoped_controls sc
            LEFT JOIN implementations i ON i.scoped_control_id = sc.id
            WHERE sc.domain_id = ? AND i.id IS NULL
        ");
        $stmt->execute([$domainId]);
        return $stmt->rowCount();
    }

    /**
     * Update allowed fields on an implementation. Tenant isolation via JOIN.
     *
     * Allowed fields: status, maturity_level, description, responsible_user_id,
     *                 target_date, completion_date, parameters_json
     */
    public function update(int $implId, int $tenantId, array $fields, int $userId): bool
    {
        $allowed = [
            'status', 'maturity_level', 'description',
            'responsible_user_id', 'target_date', 'completion_date',
            'parameters_json',
        ];
        unset($fields['asset_id'], $fields['asset_ids']);

        $sets   = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                // Table-qualify to avoid ambiguity in multi-table UPDATE
                // (information_domains also has a 'status' column)
                $sets[]   = "i.{$col} = ?";
                $params[] = $fields[$col];
            }
        }

        if (empty($sets)) {
            return false;
        }

        $sets[]   = 'i.updated_by = ?';
        $params[] = $userId;
        $params[] = $implId;
        $params[] = $tenantId;

        $setStr = implode(', ', $sets);
        $stmt   = $this->pdo->prepare("
            UPDATE implementations i
            JOIN scoped_controls sc ON sc.id = i.scoped_control_id
            JOIN information_domains d ON d.id = sc.domain_id
            SET {$setStr}
            WHERE i.id = ? AND d.tenant_id = ?
        ");
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Append an evidence_files.id reference to implementations.evidence_json.
     */
    public function addEvidence(int $implId, int $tenantId, int $fileId, int $userId): bool
    {
        $row = $this->findById($implId, $tenantId);
        if ($row === null) {
            return false;
        }

        $existing = json_decode($row['evidence_json'] ?? '[]', true) ?? [];
        if (!in_array($fileId, $existing, true)) {
            $existing[] = $fileId;
        }

        $stmt = $this->pdo->prepare("
            UPDATE implementations i
            JOIN scoped_controls sc ON sc.id = i.scoped_control_id
            JOIN information_domains d ON d.id = sc.domain_id
            SET i.evidence_json = ?, i.updated_by = ?
            WHERE i.id = ? AND d.tenant_id = ?
        ");
        $stmt->execute([json_encode($existing), $userId, $implId, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return all implementation rows for a domain (for OSCAL export), joined with control data.
     * No pagination — full set needed for SSP build.
     */
    public function findAllByDomain(int $domainId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT i.*, sc.control_id_str, sc.title AS control_title,
                   sc.description AS control_description, sc.tailoring_json
            FROM implementations i
            JOIN scoped_controls sc ON sc.id = i.scoped_control_id
            JOIN information_domains d ON d.id = sc.domain_id
            WHERE d.id = ? AND d.tenant_id = ?
            ORDER BY sc.control_id_str ASC
        ");
        $stmt->execute([$domainId, $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Total count of scoped controls for a domain (for generate-ssp response).
     */
    public function countScopedControls(int $domainId, int $tenantId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM scoped_controls sc
            JOIN information_domains d ON d.id = sc.domain_id
            WHERE sc.domain_id = ? AND d.tenant_id = ?
        ");
        $stmt->execute([$domainId, $tenantId]);
        return (int) $stmt->fetchColumn();
    }
}
