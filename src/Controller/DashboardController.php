<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Config\Database;

class DashboardController extends BaseController
{
    public function index(array $params): void
    {
        $tenantId = $this->tenantId();
        $pdo = Database::getConnection();

        // Count domains
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_domains WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $domainCount = (int) $stmt->fetchColumn();

        // TODO: Expand with real KPIs once modules are built
        $this->json([
            'domains_count'       => $domainCount,
            'controls_total'      => 0,
            'controls_implemented'=> 0,
            'controls_open'       => 0,
            'poam_open'           => 0,
            'poam_overdue'        => 0,
            'compliance_percent'  => 0.0,
        ]);
    }
}
