<?php

declare(strict_types=1);

namespace GsppManager\Repository;

use GsppManager\Config\Clock;
use GsppManager\Config\Database;
use PDO;

class PoamRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * INSERT IGNORE a POA&M item for every not_satisfied/partial finding in the plan
     * that doesn't already have one. Returns count of rows created.
     */
    public function generateFromPlan(int $planId, int $domainId, int $tenantId): int
    {
        // Verify domain belongs to tenant
        $check = $this->pdo->prepare("
            SELECT d.id FROM information_domains d
            WHERE d.id = ? AND d.tenant_id = ?
        ");
        $check->execute([$domainId, $tenantId]);
        if (!$check->fetch()) {
            return 0;
        }

        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO poam_items
                (domain_id, finding_id, scoped_control_id, title, status, priority, created_at, updated_at)
            SELECT ?, af.id, sc.id, sc.title, 'open', 'medium', NOW(), NOW()
            FROM assessment_findings af
            JOIN assessment_plans ap ON ap.id = af.plan_id AND ap.domain_id = ?
            JOIN scoped_controls sc  ON sc.id = af.scoped_control_id
            WHERE af.plan_id = ?
              AND af.result IN ('not_satisfied', 'partial')
              AND af.id NOT IN (
                  SELECT pi.finding_id FROM poam_items pi
                  WHERE pi.finding_id IS NOT NULL AND pi.domain_id = ?
              )
        ");
        $stmt->execute([$domainId, $domainId, $planId, $domainId]);

        return $stmt->rowCount();
    }

    /**
     * Paginated POA&M items for a domain, with computed escalation_status.
     *
     * @param array{status?: string, priority?: string, search?: string} $filters
     * @return array{items: array, total: int, summary: array}
     */
    public function findByDomain(
        int   $domainId,
        int   $tenantId,
        array $filters  = [],
        int   $page     = 1,
        int   $perPage  = 50
    ): array {
        $where  = ['d.tenant_id = ?', 'pi.domain_id = ?'];
        $params = [$tenantId, $domainId];

        if (!empty($filters['status'])) {
            $where[]  = 'pi.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $where[]  = 'pi.priority = ?';
            $params[] = $filters['priority'];
        }

        if (!empty($filters['search'])) {
            $like     = '%' . $filters['search'] . '%';
            $where[]  = '(pi.title LIKE ? OR sc.control_id_str LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $whereStr = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM poam_items pi
            JOIN information_domains d ON d.id = pi.domain_id
            LEFT JOIN scoped_controls sc ON sc.id = pi.scoped_control_id
            WHERE {$whereStr}
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset   = ($page - 1) * $perPage;
        $listStmt = $this->pdo->prepare("
            SELECT
                pi.*,
                sc.control_id_str,
                sc.title AS control_title,
                u.display_name AS responsible_name
            FROM poam_items pi
            JOIN information_domains d ON d.id = pi.domain_id
            LEFT JOIN scoped_controls sc ON sc.id = pi.scoped_control_id
            LEFT JOIN users u ON u.id = pi.responsible_user_id
            WHERE {$whereStr}
            ORDER BY
                FIELD(pi.priority, 'high', 'medium', 'low'),
                pi.deadline IS NULL,
                pi.deadline ASC,
                pi.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $listStmt->execute([...$params, $perPage, $offset]);
        $items = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        $items   = array_map([$this, 'castRow'], $items);
        $summary = $this->getSummary($domainId, $tenantId);

        return ['items' => $items, 'total' => $total, 'summary' => $summary];
    }

    /**
     * Single POA&M item with escalation_status, tenant-isolated.
     */
    public function findById(int $itemId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                pi.*,
                sc.control_id_str,
                sc.title AS control_title,
                u.display_name AS responsible_name
            FROM poam_items pi
            JOIN information_domains d ON d.id = pi.domain_id AND d.tenant_id = ?
            LEFT JOIN scoped_controls sc ON sc.id = pi.scoped_control_id
            LEFT JOIN users u ON u.id = pi.responsible_user_id
            WHERE pi.id = ?
        ");
        $stmt->execute([$tenantId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->castRow($row) : null;
    }

    /**
     * Update a POA&M item (tenant-isolated via JOIN).
     */
    public function update(int $itemId, int $tenantId, array $fields, int $userId): bool
    {
        $allowed = [
            'title', 'description', 'priority', 'status',
            'responsible_user_id', 'deadline', 'completion_date',
            'deviation_justification', 'milestones_json',
        ];

        $set       = [];
        $setParams = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $set[]       = "pi.{$col} = ?";
                $setParams[] = $fields[$col];
            }
        }

        if (empty($set)) {
            return false;
        }

        $set[] = 'pi.updated_at = NOW()';

        // SQL param order: JOIN ON (tenantId), SET values, WHERE (itemId)
        $params = [$tenantId, ...$setParams, $itemId];

        $stmt = $this->pdo->prepare("
            UPDATE poam_items pi
            JOIN information_domains d ON d.id = pi.domain_id AND d.tenant_id = ?
            SET " . implode(', ', $set) . "
            WHERE pi.id = ?
        ");
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Summary counts for a domain's POA&M items.
     *
     * @return array{open: int, in_progress: int, completed: int, verified: int, accepted: int, total: int}
     */
    public function getSummary(int $domainId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(SUM(pi.status = 'open'),        0) AS open,
                COALESCE(SUM(pi.status = 'in_progress'), 0) AS in_progress,
                COALESCE(SUM(pi.status = 'completed'),   0) AS completed,
                COALESCE(SUM(pi.status = 'verified'),    0) AS verified,
                COALESCE(SUM(pi.status = 'accepted'),    0) AS accepted,
                COUNT(pi.id)                                 AS total
            FROM poam_items pi
            JOIN information_domains d ON d.id = pi.domain_id AND d.tenant_id = ?
            WHERE pi.domain_id = ?
        ");
        $stmt->execute([$tenantId, $domainId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'open'        => (int) ($row['open']        ?? 0),
            'in_progress' => (int) ($row['in_progress'] ?? 0),
            'completed'   => (int) ($row['completed']   ?? 0),
            'verified'    => (int) ($row['verified']    ?? 0),
            'accepted'    => (int) ($row['accepted']    ?? 0),
            'total'       => (int) ($row['total']       ?? 0),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function castRow(array $row): array
    {
        foreach (['id', 'domain_id', 'finding_id', 'scoped_control_id', 'responsible_user_id'] as $col) {
            if (isset($row[$col])) {
                $row[$col] = (int) $row[$col];
            }
        }

        $row['escalation_status'] = $this->calcEscalation($row);

        return $row;
    }

    private function calcEscalation(array $row): string
    {
        $deadline = $row['deadline'] ?? null;
        if ($deadline === null || $deadline === '') {
            return 'none';
        }

        $closedStatuses = ['completed', 'verified', 'accepted'];
        if (in_array($row['status'] ?? '', $closedStatuses, true)) {
            return 'none';
        }

        $today   = Clock::today();
        $warning = date('Y-m-d', Clock::now() + 7 * 86400);

        if ($deadline < $today) {
            return 'overdue';
        }

        if ($deadline <= $warning) {
            return 'warning';
        }

        return 'ok';
    }
}
