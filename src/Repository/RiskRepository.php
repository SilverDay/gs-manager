<?php

declare(strict_types=1);

namespace GsppManager\Repository;

use GsppManager\Config\Database;
use PDO;

class RiskRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Paginated list of risks for a domain, tenant-isolated via JOIN.
     *
     * @param array{risk_level?: string, treatment?: string, search?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findByDomain(
        int   $domainId,
        int   $tenantId,
        array $filters  = [],
        int   $page     = 1,
        int   $perPage  = 25
    ): array {
        $where  = ['d.tenant_id = ?', 'r.domain_id = ?'];
        $params = [$tenantId, $domainId];

        if (!empty($filters['risk_level'])) {
            $where[]  = 'r.risk_level = ?';
            $params[] = $filters['risk_level'];
        }

        if (!empty($filters['treatment'])) {
            $where[]  = 'r.treatment = ?';
            $params[] = $filters['treatment'];
        }

        if (!empty($filters['search'])) {
            $where[]  = 'r.title LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereStr = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM risks r
            JOIN information_domains d ON d.id = r.domain_id
            WHERE {$whereStr}
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset    = ($page - 1) * $perPage;
        $listStmt  = $this->pdo->prepare("
            SELECT
                r.*,
                u.display_name AS owner_name,
                a.name         AS asset_name,
                COUNT(rc.scoped_control_id) AS linked_controls_count
            FROM risks r
            JOIN information_domains d ON d.id = r.domain_id
            LEFT JOIN users u          ON u.id  = r.owner_user_id
            LEFT JOIN assets a         ON a.id  = r.asset_id
            LEFT JOIN risk_controls rc ON rc.risk_id = r.id
            WHERE {$whereStr}
            GROUP BY r.id
            ORDER BY
                FIELD(r.risk_level, 'critical', 'high', 'medium', 'low'),
                r.title
            LIMIT ? OFFSET ?
        ");
        $listStmt->execute([...$params, $perPage, $offset]);
        $items = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        return ['items' => $this->castRows($items), 'total' => $total];
    }

    /**
     * Single risk with its linked controls, tenant-isolated.
     */
    public function findById(int $riskId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                r.*,
                u.display_name AS owner_name,
                a.name         AS asset_name
            FROM risks r
            JOIN information_domains d ON d.id = r.domain_id AND d.tenant_id = ?
            LEFT JOIN users u          ON u.id  = r.owner_user_id
            LEFT JOIN assets a         ON a.id  = r.asset_id
            WHERE r.id = ?
        ");
        $stmt->execute([$tenantId, $riskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row = $this->castRow($row);
        $row['linked_controls'] = $this->findControls($riskId);

        return $row;
    }

    /**
     * Linked scoped controls for a risk.
     */
    public function findControls(int $riskId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT sc.id, sc.control_id_str, sc.title
            FROM risk_controls rc
            JOIN scoped_controls sc ON sc.id = rc.scoped_control_id
            WHERE rc.risk_id = ?
            ORDER BY sc.control_id_str
        ");
        $stmt->execute([$riskId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Insert a new risk, return its ID.
     *
     * @param array{title: string, description?: string, asset_id?: int, likelihood?: string,
     *              impact?: string, risk_level?: string, treatment?: string,
     *              acceptance_justification?: string, owner_user_id?: int} $fields
     */
    public function create(int $domainId, array $fields, int $userId): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO risks
                (domain_id, title, description, asset_id, likelihood, impact,
                 risk_level, treatment, acceptance_justification, owner_user_id,
                 created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $domainId,
            $fields['title'],
            $fields['description']              ?? null,
            $fields['asset_id']                 ?? null,
            $fields['likelihood']               ?? 'medium',
            $fields['impact']                   ?? 'medium',
            $fields['risk_level']               ?? 'medium',
            $fields['treatment']                ?? 'mitigate',
            $fields['acceptance_justification'] ?? null,
            $fields['owner_user_id']            ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update an existing risk (tenant-isolated via JOIN).
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $riskId, int $tenantId, array $fields, int $userId): bool
    {
        $allowed = [
            'title', 'description', 'asset_id', 'likelihood', 'impact',
            'risk_level', 'treatment', 'acceptance_justification', 'owner_user_id',
        ];

        $set       = [];
        $setParams = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $set[]       = "r.{$col} = ?";
                $setParams[] = $fields[$col];
            }
        }

        if (empty($set)) {
            return false;
        }

        $set[] = 'r.updated_at = NOW()';

        // SQL param order: JOIN ON (tenantId), SET values, WHERE (riskId)
        $params = [$tenantId, ...$setParams, $riskId];

        $stmt = $this->pdo->prepare("
            UPDATE risks r
            JOIN information_domains d ON d.id = r.domain_id AND d.tenant_id = ?
            SET " . implode(', ', $set) . "
            WHERE r.id = ?
        ");

        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Link a scoped control to a risk (INSERT IGNORE).
     * Verifies risk and control share the same tenant domain.
     */
    public function linkControl(int $riskId, int $tenantId, int $controlId): bool
    {
        // Verify risk belongs to tenant
        $check = $this->pdo->prepare("
            SELECT r.id FROM risks r
            JOIN information_domains d ON d.id = r.domain_id AND d.tenant_id = ?
            WHERE r.id = ?
        ");
        $check->execute([$tenantId, $riskId]);
        if (!$check->fetch()) {
            return false;
        }

        // Verify control belongs to the same domain as the risk
        $check2 = $this->pdo->prepare("
            SELECT sc.id FROM scoped_controls sc
            JOIN risks r ON r.domain_id = sc.domain_id AND r.id = ?
            WHERE sc.id = ?
        ");
        $check2->execute([$riskId, $controlId]);
        if (!$check2->fetch()) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO risk_controls (risk_id, scoped_control_id) VALUES (?, ?)
        ");
        $stmt->execute([$riskId, $controlId]);

        return true;
    }

    /**
     * Unlink a scoped control from a risk (tenant-isolated).
     */
    public function unlinkControl(int $riskId, int $tenantId, int $controlId): bool
    {
        $stmt = $this->pdo->prepare("
            DELETE rc FROM risk_controls rc
            JOIN risks r ON r.id = rc.risk_id
            JOIN information_domains d ON d.id = r.domain_id AND d.tenant_id = ?
            WHERE rc.risk_id = ? AND rc.scoped_control_id = ?
        ");
        $stmt->execute([$tenantId, $riskId, $controlId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Heatmap data: all risks positioned by likelihood/impact, plus cell counts.
     *
     * @return array{risks: array, cells: array<string, int>}
     */
    public function heatmapData(int $domainId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT r.id, r.title, r.likelihood, r.impact, r.risk_level, r.treatment
            FROM risks r
            JOIN information_domains d ON d.id = r.domain_id AND d.tenant_id = ?
            WHERE r.domain_id = ?
            ORDER BY r.risk_level DESC, r.title
        ");
        $stmt->execute([$tenantId, $domainId]);
        $risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build cell count map: "likelihood|impact" => count
        $cells = [];
        foreach ($risks as $r) {
            $key          = $r['likelihood'] . '|' . $r['impact'];
            $cells[$key]  = ($cells[$key] ?? 0) + 1;
        }

        return ['risks' => $risks, 'cells' => $cells];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function castRows(array $rows): array
    {
        return array_map([$this, 'castRow'], $rows);
    }

    private function castRow(array $row): array
    {
        $ints = ['id', 'domain_id', 'asset_id', 'owner_user_id', 'linked_controls_count'];
        foreach ($ints as $col) {
            if (isset($row[$col])) {
                $row[$col] = (int) $row[$col];
            }
        }
        return $row;
    }
}
