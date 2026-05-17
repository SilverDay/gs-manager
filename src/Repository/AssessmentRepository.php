<?php

declare(strict_types=1);

namespace GsppManager\Repository;

use GsppManager\Config\Database;
use PDO;

class AssessmentRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    // ── Assessment Plans ──────────────────────────────────────────────────────

    /**
     * All plans for a domain with finding summary counts, tenant-isolated.
     */
    public function findByDomain(int $domainId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ap.*,
                u.display_name  AS creator_name,
                COALESCE(SUM(af.result = 'satisfied'),     0) AS cnt_satisfied,
                COALESCE(SUM(af.result = 'not_satisfied'), 0) AS cnt_not_satisfied,
                COALESCE(SUM(af.result = 'partial'),       0) AS cnt_partial,
                COALESCE(SUM(af.result = 'not_assessed'),  0) AS cnt_not_assessed,
                COUNT(af.id)                                   AS cnt_total
            FROM assessment_plans ap
            JOIN information_domains d ON d.id = ap.domain_id AND d.tenant_id = ?
            LEFT JOIN users u           ON u.id  = ap.created_by
            LEFT JOIN assessment_findings af ON af.plan_id = ap.id
            WHERE ap.domain_id = ?
            GROUP BY ap.id
            ORDER BY ap.created_at DESC
        ");
        $stmt->execute([$tenantId, $domainId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'castPlanRow'], $rows);
    }

    /**
     * Single plan with finding summary, tenant-isolated.
     */
    public function findById(int $planId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ap.*,
                u.display_name  AS creator_name,
                d.name          AS domain_name,
                COALESCE(SUM(af.result = 'satisfied'),     0) AS cnt_satisfied,
                COALESCE(SUM(af.result = 'not_satisfied'), 0) AS cnt_not_satisfied,
                COALESCE(SUM(af.result = 'partial'),       0) AS cnt_partial,
                COALESCE(SUM(af.result = 'not_assessed'),  0) AS cnt_not_assessed,
                COUNT(af.id)                                   AS cnt_total
            FROM assessment_plans ap
            JOIN information_domains d ON d.id = ap.domain_id AND d.tenant_id = ?
            LEFT JOIN users u           ON u.id  = ap.created_by
            LEFT JOIN assessment_findings af ON af.plan_id = ap.id
            WHERE ap.id = ?
            GROUP BY ap.id
        ");
        $stmt->execute([$tenantId, $planId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->castPlanRow($row);
    }

    /**
     * Insert a new assessment plan, return its ID.
     */
    public function create(int $domainId, array $fields, int $userId): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO assessment_plans
                (domain_id, title, assessor_name, assessor_org, assessor_email,
                 period_start, period_end, methodology, rules_of_engagement, status,
                 created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $domainId,
            $fields['title'],
            $fields['assessor_name']        ?? null,
            $fields['assessor_org']         ?? null,
            $fields['assessor_email']       ?? null,
            $fields['period_start']         ?? null,
            $fields['period_end']           ?? null,
            $fields['methodology']          ?? null,
            $fields['rules_of_engagement']  ?? null,
            $fields['status']               ?? 'draft',
            $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update a plan (tenant-isolated).
     */
    public function update(int $planId, int $tenantId, array $fields, int $userId): bool
    {
        $allowed = [
            'title', 'assessor_name', 'assessor_org', 'assessor_email',
            'period_start', 'period_end', 'methodology', 'rules_of_engagement', 'status',
        ];

        $set       = [];
        $setParams = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $set[]       = "ap.{$col} = ?";
                $setParams[] = $fields[$col];
            }
        }

        if (empty($set)) {
            return false;
        }

        $set[] = 'ap.updated_at = NOW()';

        // SQL param order: JOIN ON (tenantId), SET values, WHERE (planId)
        $params = [$tenantId, ...$setParams, $planId];

        $stmt = $this->pdo->prepare("
            UPDATE assessment_plans ap
            JOIN information_domains d ON d.id = ap.domain_id AND d.tenant_id = ?
            SET " . implode(', ', $set) . "
            WHERE ap.id = ?
        ");
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    // ── Assessment Findings ───────────────────────────────────────────────────

    /**
     * Create a finding row for every scoped control in the plan's domain that
     * doesn't already have one. Returns count of rows created.
     */
    public function ensureFindingsExist(int $planId, int $tenantId): int
    {
        // Verify plan belongs to tenant and get its domain_id
        $check = $this->pdo->prepare("
            SELECT ap.domain_id FROM assessment_plans ap
            JOIN information_domains d ON d.id = ap.domain_id AND d.tenant_id = ?
            WHERE ap.id = ?
        ");
        $check->execute([$tenantId, $planId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0;
        }
        $domainId = (int) $row['domain_id'];

        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO assessment_findings (plan_id, scoped_control_id, result, created_at, updated_at)
            SELECT ?, sc.id, 'not_assessed', NOW(), NOW()
            FROM scoped_controls sc
            WHERE sc.domain_id = ?
              AND sc.id NOT IN (
                  SELECT af.scoped_control_id FROM assessment_findings af WHERE af.plan_id = ?
              )
        ");
        $stmt->execute([$planId, $domainId, $planId]);

        return $stmt->rowCount();
    }

    /**
     * Paginated findings for a plan, joined with control metadata.
     *
     * @param array{result?: string, search?: string} $filters
     * @return array{items: array, total: int, summary: array}
     */
    public function findFindings(
        int   $planId,
        int   $tenantId,
        array $filters  = [],
        int   $page     = 1,
        int   $perPage  = 50
    ): array {
        $where  = ['d.tenant_id = ?', 'af.plan_id = ?'];
        $params = [$tenantId, $planId];

        if (!empty($filters['result'])) {
            $where[]  = 'af.result = ?';
            $params[] = $filters['result'];
        }

        if (!empty($filters['search'])) {
            $where[]  = '(sc.control_id_str LIKE ? OR sc.title LIKE ?)';
            $like     = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereStr = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM assessment_findings af
            JOIN assessment_plans ap ON ap.id = af.plan_id
            JOIN information_domains d ON d.id = ap.domain_id
            JOIN scoped_controls sc ON sc.id = af.scoped_control_id
            WHERE {$whereStr}
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;

        $listStmt = $this->pdo->prepare("
            SELECT
                af.*,
                sc.control_id_str,
                sc.title    AS control_title,
                u.display_name AS assessor_name
            FROM assessment_findings af
            JOIN assessment_plans ap ON ap.id = af.plan_id
            JOIN information_domains d ON d.id = ap.domain_id
            JOIN scoped_controls sc ON sc.id = af.scoped_control_id
            LEFT JOIN users u ON u.id = af.assessed_by
            WHERE {$whereStr}
            ORDER BY sc.control_id_str
            LIMIT ? OFFSET ?
        ");
        $listStmt->execute([...$params, $perPage, $offset]);
        $items = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = $this->getSummary($planId, $tenantId);

        return [
            'items'   => array_map([$this, 'castFindingRow'], $items),
            'total'   => $total,
            'summary' => $summary,
        ];
    }

    /**
     * Single finding, tenant-isolated.
     */
    public function findFindingById(int $findingId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                af.*,
                sc.control_id_str,
                sc.title AS control_title,
                u.display_name AS assessor_name
            FROM assessment_findings af
            JOIN assessment_plans ap ON ap.id = af.plan_id
            JOIN information_domains d ON d.id = ap.domain_id AND d.tenant_id = ?
            JOIN scoped_controls sc ON sc.id = af.scoped_control_id
            LEFT JOIN users u ON u.id = af.assessed_by
            WHERE af.id = ?
        ");
        $stmt->execute([$tenantId, $findingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->castFindingRow($row) : null;
    }

    /**
     * Update a finding (tenant-isolated via plan → domain JOIN).
     */
    public function updateFinding(int $findingId, int $tenantId, array $fields, int $userId): bool
    {
        $allowed = ['method', 'result', 'observation', 'risk_statement'];

        $set       = [];
        $setParams = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $set[]       = "af.{$col} = ?";
                $setParams[] = $fields[$col];
            }
        }

        if (empty($set)) {
            return false;
        }

        // Mark assessor + timestamp when result changes away from not_assessed
        if (isset($fields['result']) && $fields['result'] !== 'not_assessed') {
            $set[]       = 'af.assessed_by = ?';
            $setParams[] = $userId;
            $set[]       = 'af.assessed_at = NOW()';
        }

        $set[] = 'af.updated_at = NOW()';

        // SQL param order: JOIN ON (tenantId), SET values, WHERE (findingId)
        $params = [$tenantId, ...$setParams, $findingId];

        $stmt = $this->pdo->prepare("
            UPDATE assessment_findings af
            JOIN assessment_plans ap ON ap.id = af.plan_id
            JOIN information_domains d ON d.id = ap.domain_id AND d.tenant_id = ?
            SET " . implode(', ', $set) . "
            WHERE af.id = ?
        ");
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Summary counts for a plan.
     *
     * @return array{satisfied: int, not_satisfied: int, partial: int, not_assessed: int, total: int}
     */
    public function getSummary(int $planId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(SUM(af.result = 'satisfied'),     0) AS satisfied,
                COALESCE(SUM(af.result = 'not_satisfied'), 0) AS not_satisfied,
                COALESCE(SUM(af.result = 'partial'),       0) AS partial,
                COALESCE(SUM(af.result = 'not_assessed'),  0) AS not_assessed,
                COUNT(af.id)                                   AS total
            FROM assessment_findings af
            JOIN assessment_plans ap ON ap.id = af.plan_id
            JOIN information_domains d ON d.id = ap.domain_id AND d.tenant_id = ?
            WHERE af.plan_id = ?
        ");
        $stmt->execute([$tenantId, $planId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'satisfied'     => (int) ($row['satisfied']     ?? 0),
            'not_satisfied' => (int) ($row['not_satisfied'] ?? 0),
            'partial'       => (int) ($row['partial']       ?? 0),
            'not_assessed'  => (int) ($row['not_assessed']  ?? 0),
            'total'         => (int) ($row['total']         ?? 0),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function castPlanRow(array $row): array
    {
        foreach (['id', 'domain_id', 'version', 'created_by'] as $col) {
            if (isset($row[$col])) {
                $row[$col] = (int) $row[$col];
            }
        }
        $row['summary'] = [
            'satisfied'     => (int) ($row['cnt_satisfied']     ?? 0),
            'not_satisfied' => (int) ($row['cnt_not_satisfied'] ?? 0),
            'partial'       => (int) ($row['cnt_partial']       ?? 0),
            'not_assessed'  => (int) ($row['cnt_not_assessed']  ?? 0),
            'total'         => (int) ($row['cnt_total']         ?? 0),
        ];
        unset($row['cnt_satisfied'], $row['cnt_not_satisfied'], $row['cnt_partial'],
              $row['cnt_not_assessed'], $row['cnt_total'], $row['oscal_json']);

        return $row;
    }

    private function castFindingRow(array $row): array
    {
        foreach (['id', 'plan_id', 'scoped_control_id', 'assessed_by'] as $col) {
            if (isset($row[$col])) {
                $row[$col] = (int) $row[$col];
            }
        }
        return $row;
    }
}
