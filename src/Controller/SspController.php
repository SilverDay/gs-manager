<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Middleware\AuditLogger;
use GsppManager\Repository\DomainRepository;
use GsppManager\Repository\ImplementationRepository;
use GsppManager\Service\OscalExporter;
use RuntimeException;

class SspController extends BaseController
{
    private OscalExporter $exporter;
    private DomainRepository $domainRepo;
    private ImplementationRepository $implRepo;

    public function __construct()
    {
        $this->exporter   = new OscalExporter();
        $this->domainRepo = new DomainRepository();
        $this->implRepo   = new ImplementationRepository();
    }

    /**
     * GET /api/domains/{id}/ssp/export
     * Returns the SSP as a downloadable JSON file.
     */
    public function export(array $params): void
    {
        $domainId = (int) ($params['id'] ?? 0);
        $tenantId = $this->tenantId();

        $domain = $this->domainRepo->findByIdAndTenant($domainId, $tenantId);
        if ($domain === null) {
            $this->error('Informationsverbund nicht gefunden.', 404);
            return;
        }

        try {
            $ssp = $this->exporter->exportSsp($domainId, $tenantId);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage(), 500);
            return;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-äöüÄÖÜß ]/', '', $domain['name']);
        $filename = $safeName . '_SSP-edited.json';

        AuditLogger::log('ssp.export', 'information_domains', $domainId);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($ssp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * POST /api/domains/{id}/ssp/import
     * Accepts a raw JSON body containing an OSCAL SSP.
     */
    public function import(array $params): void
    {
        $domainId = (int) ($params['id'] ?? 0);
        $tenantId = $this->tenantId();

        $domain = $this->domainRepo->findByIdAndTenant($domainId, $tenantId);
        if ($domain === null) {
            $this->error('Informationsverbund nicht gefunden.', 404);
            return;
        }

        $role = $this->userRole();
        if (in_array($role, ['auditor', 'readonly', 'management'], true)) {
            $this->error('Keine Berechtigung für den SSP-Import.', 403);
            return;
        }

        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            // Fallback: check for file upload
            if (!empty($_FILES['ssp']['tmp_name'])) {
                $raw = file_get_contents($_FILES['ssp']['tmp_name']);
            }
        }

        if (empty($raw)) {
            $this->error('Kein SSP-JSON empfangen.', 422);
            return;
        }

        $sspJson = json_decode($raw, true);
        if (!is_array($sspJson)) {
            $this->error('Ungültiges JSON.', 422);
            return;
        }

        try {
            $updated = $this->exporter->importSsp($domainId, $tenantId, $sspJson, $this->userId());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage(), 422);
            return;
        }

        AuditLogger::log('ssp.import', 'information_domains', $domainId, ['updated' => $updated]);

        $this->json(['updated' => $updated]);
    }

    /**
     * POST /api/domains/{id}/generate-ssp
     * Ensures all scoped controls have implementation rows.
     */
    public function generateSsp(array $params): void
    {
        $domainId = (int) ($params['id'] ?? 0);
        $tenantId = $this->tenantId();

        $domain = $this->domainRepo->findByIdAndTenant($domainId, $tenantId);
        if ($domain === null) {
            $this->error('Informationsverbund nicht gefunden.', 404);
            return;
        }

        $role = $this->userRole();
        if (in_array($role, ['auditor', 'readonly', 'management'], true)) {
            $this->error('Keine Berechtigung.', 403);
            return;
        }

        $created = $this->implRepo->ensureAllExist($domainId, $tenantId);
        $total   = $this->implRepo->countScopedControls($domainId, $tenantId);

        AuditLogger::log('ssp.generate', 'information_domains', $domainId, ['created' => $created]);

        $this->json(['created' => $created, 'total' => $total]);
    }
}
