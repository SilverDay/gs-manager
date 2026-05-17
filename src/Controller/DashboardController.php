<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Config\Clock;
use GsppManager\Config\Database;

class DashboardController extends BaseController
{
    /**
     * GET /api/dashboard
     * Tenant-wide compliance KPIs across all domains.
     */
    public function index(array $params): void
    {
        $tenantId = $this->tenantId();
        $pdo      = Database::getConnection();
        $today    = Clock::today();

        // Count imported catalogs
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM catalogs WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $catalogsCount = (int) $stmt->fetchColumn();

        // Per-domain lifecycle summary including POA&M counts
        $stmt = $pdo->prepare("
            SELECT
                d.id,
                d.name,
                d.isms_type,
                COUNT(DISTINCT sc.id)                                               AS scoped_controls_count,
                COUNT(DISTINCT p.id) > 0                                            AS has_profile,
                COALESCE(SUM(i.status = 'implemented'), 0)                          AS impl_implemented,
                COALESCE(SUM(i.status = 'partial'), 0)                              AS impl_partial,
                COALESCE(SUM(i.status = 'planned'), 0)                              AS impl_planned,
                COALESCE(SUM(i.status = 'not_started'), 0)                          AS impl_not_started,
                COALESCE(SUM(i.status = 'not_applicable'), 0)                       AS impl_not_applicable,
                COUNT(DISTINCT i.id)                                                AS impl_total,
                COALESCE(SUM(pi.status NOT IN ('completed','verified','accepted')), 0) AS poam_open,
                COALESCE(SUM(
                    pi.status NOT IN ('completed','verified','accepted')
                    AND pi.deadline IS NOT NULL
                    AND pi.deadline < ?
                ), 0)                                                               AS poam_overdue
            FROM information_domains d
            LEFT JOIN scoped_controls sc ON sc.domain_id = d.id
            LEFT JOIN implementations i  ON i.scoped_control_id = sc.id
            LEFT JOIN profiles p         ON p.domain_id = d.id
            LEFT JOIN poam_items pi      ON pi.domain_id = d.id
            WHERE d.tenant_id = ?
            GROUP BY d.id, d.name, d.isms_type
            ORDER BY d.name
        ");
        $stmt->execute([$today, $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $domains = array_map(function (array $row): array {
            $implTotal       = (int) $row['impl_total'];
            $implImplemented = (int) $row['impl_implemented'];
            $poamOverdue     = (int) $row['poam_overdue'];

            return [
                'id'                    => (int)  $row['id'],
                'name'                  => $row['name'],
                'isms_type'             => $row['isms_type'],
                'scoped_controls_count' => (int)  $row['scoped_controls_count'],
                'has_profile'           => (bool) $row['has_profile'],
                'impl_progress'         => [
                    'total'          => $implTotal,
                    'implemented'    => $implImplemented,
                    'partial'        => (int) $row['impl_partial'],
                    'planned'        => (int) $row['impl_planned'],
                    'not_started'    => (int) $row['impl_not_started'],
                    'not_applicable' => (int) $row['impl_not_applicable'],
                    'percent'        => $implTotal > 0
                        ? round($implImplemented / $implTotal * 100, 1)
                        : 0.0,
                ],
                'poam_open'             => (int) $row['poam_open'],
                'poam_overdue'          => $poamOverdue,
                'compliance_status'     => $this->calcComplianceStatus(
                    $implTotal, $implImplemented, $poamOverdue
                ),
            ];
        }, $rows);

        // Tenant-wide POA&M summary
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                                                           AS total,
                SUM(pi.status NOT IN ('completed','verified','accepted'))           AS open,
                SUM(
                    pi.status NOT IN ('completed','verified','accepted')
                    AND pi.deadline IS NOT NULL
                    AND pi.deadline < ?
                )                                                                  AS overdue
            FROM poam_items pi
            JOIN information_domains d ON d.id = pi.domain_id AND d.tenant_id = ?
        ");
        $stmt->execute([$today, $tenantId]);
        $poamRow     = $stmt->fetch(\PDO::FETCH_ASSOC);
        $poamSummary = [
            'total'   => (int) $poamRow['total'],
            'open'    => (int) $poamRow['open'],
            'overdue' => (int) $poamRow['overdue'],
        ];

        $this->json([
            'catalogs_count' => $catalogsCount,
            'domains_count'  => count($domains),
            'domains'        => $domains,
            'poam_summary'   => $poamSummary,
        ]);
    }

    /**
     * GET /api/domains/{id}/dashboard/timeline
     * Upcoming milestones for a single domain (next 30 days).
     */
    public function timeline(array $params): void
    {
        $domainId = (int) ($params['id'] ?? 0);
        $tenantId = $this->tenantId();
        $pdo      = Database::getConnection();
        $today    = Clock::today();
        $horizon  = date('Y-m-d', strtotime($today . ' +30 days'));

        // Verify domain belongs to tenant
        $chk = $pdo->prepare('SELECT id FROM information_domains WHERE id = ? AND tenant_id = ?');
        $chk->execute([$domainId, $tenantId]);
        if ($chk->fetch() === false) {
            $this->error('Verbund nicht gefunden.', 404);
            return;
        }

        $items = [];

        // Upcoming POA&M deadlines
        $stmt = $pdo->prepare("
            SELECT pi.id, pi.title, pi.deadline, pi.status
            FROM poam_items pi
            WHERE pi.domain_id = ?
              AND pi.deadline IS NOT NULL
              AND pi.deadline >= ?
              AND pi.deadline <= ?
              AND pi.status NOT IN ('completed','verified','accepted')
            ORDER BY pi.deadline ASC
            LIMIT 20
        ");
        $stmt->execute([$domainId, $today, $horizon]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $deadline = $row['deadline'];
            $daysDiff = (int) ((strtotime($deadline) - strtotime($today)) / 86400);
            $esc      = $daysDiff <= 7 ? 'warning' : 'ok';

            $items[] = [
                'type'              => 'poam',
                'id'                => (int) $row['id'],
                'title'             => $row['title'],
                'event_date'        => $deadline,
                'status'            => $row['status'],
                'escalation_status' => $esc,
            ];
        }

        // Upcoming assessment plans (use period_end as the milestone date)
        $stmt = $pdo->prepare("
            SELECT id, title, period_end, status
            FROM assessment_plans
            WHERE domain_id = ?
              AND period_end IS NOT NULL
              AND period_end >= ?
              AND period_end <= ?
              AND status != 'completed'
            ORDER BY period_end ASC
            LIMIT 10
        ");
        $stmt->execute([$domainId, $today, $horizon]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'type'       => 'assessment',
                'id'         => (int) $row['id'],
                'title'      => $row['title'],
                'event_date' => $row['period_end'],
                'status'     => $row['status'],
            ];
        }

        // Sort merged list by event_date
        usort($items, fn($a, $b) => strcmp($a['event_date'], $b['event_date']));

        $this->json(['items' => array_slice($items, 0, 20)]);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function calcComplianceStatus(int $implTotal, int $implImplemented, int $poamOverdue): string
    {
        if ($implTotal === 0) {
            return 'unknown';
        }
        $ratio = $implImplemented / $implTotal;
        if ($ratio >= 0.8 && $poamOverdue === 0) {
            return 'green';
        }
        if ($ratio >= 0.5) {
            return 'yellow';
        }
        return 'red';
    }
}
