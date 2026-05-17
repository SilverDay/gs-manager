<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Config\Database;

class DashboardController extends BaseController
{
    public function index(array $params): void
    {
        $tenantId = $this->tenantId();
        $pdo      = Database::getConnection();

        // Count imported catalogs
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM catalogs WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $catalogsCount = (int) $stmt->fetchColumn();

        // Per-domain lifecycle summary (includes implementation progress)
        $stmt = $pdo->prepare("
            SELECT
                d.id,
                d.name,
                d.isms_type,
                COUNT(DISTINCT sc.id)                                  AS scoped_controls_count,
                COUNT(DISTINCT p.id) > 0                               AS has_profile,
                COALESCE(SUM(i.status = 'implemented'), 0)             AS impl_implemented,
                COALESCE(SUM(i.status = 'partial'), 0)                 AS impl_partial,
                COALESCE(SUM(i.status = 'planned'), 0)                 AS impl_planned,
                COALESCE(SUM(i.status = 'not_started'), 0)             AS impl_not_started,
                COALESCE(SUM(i.status = 'not_applicable'), 0)          AS impl_not_applicable,
                COUNT(DISTINCT i.id)                                   AS impl_total
            FROM information_domains d
            LEFT JOIN scoped_controls sc ON sc.domain_id = d.id
            LEFT JOIN implementations i  ON i.scoped_control_id = sc.id
            LEFT JOIN profiles p         ON p.domain_id = d.id
            WHERE d.tenant_id = ?
            GROUP BY d.id, d.name, d.isms_type
            ORDER BY d.name
        ");
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $domains = array_map(function (array $row): array {
            return [
                'id'                   => (int)  $row['id'],
                'name'                 => $row['name'],
                'isms_type'            => $row['isms_type'],
                'scoped_controls_count'=> (int)  $row['scoped_controls_count'],
                'has_profile'          => (bool) $row['has_profile'],
                'impl_progress'        => [
                    'total'          => (int) $row['impl_total'],
                    'implemented'    => (int) $row['impl_implemented'],
                    'partial'        => (int) $row['impl_partial'],
                    'planned'        => (int) $row['impl_planned'],
                    'not_started'    => (int) $row['impl_not_started'],
                    'not_applicable' => (int) $row['impl_not_applicable'],
                ],
            ];
        }, $rows);

        $this->json([
            'catalogs_count' => $catalogsCount,
            'domains_count'  => count($domains),
            'domains'        => $domains,
        ]);
    }
}
