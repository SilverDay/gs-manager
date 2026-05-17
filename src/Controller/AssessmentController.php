<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Middleware\AuditLogger;
use GsppManager\Repository\AssessmentRepository;
use GsppManager\Repository\DomainRepository;
use GsppManager\Service\OscalExporter;

class AssessmentController extends BaseController
{
    private AssessmentRepository $repo;
    private DomainRepository $domainRepo;
    private OscalExporter $exporter;

    private const VALID_STATUSES  = ['draft', 'active', 'completed'];
    private const VALID_RESULTS   = ['satisfied', 'not_satisfied', 'partial', 'not_assessed'];
    private const VALID_METHODS   = ['examine', 'interview', 'test'];

    public function __construct()
    {
        $this->repo       = new AssessmentRepository();
        $this->domainRepo = new DomainRepository();
        $this->exporter   = new OscalExporter();
    }

    // ── Plans ─────────────────────────────────────────────────────────────────

    /**
     * GET /api/domains/{id}/assessments
     */
    public function listPlans(array $params): void
    {
        $domainId = (int) ($params['id'] ?? 0);
        $tenantId = $this->tenantId();

        $domain = $this->domainRepo->findByIdAndTenant($domainId, $tenantId);
        if ($domain === null) {
            $this->error('Informationsverbund nicht gefunden.', 404);
            return;
        }

        $plans = $this->repo->findByDomain($domainId, $tenantId);
        $this->json(['plans' => $plans]);
    }

    /**
     * POST /api/domains/{id}/assessments
     */
    public function createPlan(array $params): void
    {
        $domainId = (int) ($params['id'] ?? 0);
        $tenantId = $this->tenantId();

        if ($this->isWriteForbidden()) {
            $this->error('Keine Berechtigung zum Anlegen von Prüfplänen.', 403);
            return;
        }

        $domain = $this->domainRepo->findByIdAndTenant($domainId, $tenantId);
        if ($domain === null) {
            $this->error('Informationsverbund nicht gefunden.', 404);
            return;
        }

        $body = $this->requestBody();

        if (empty(trim((string) ($body['title'] ?? '')))) {
            $this->error('Pflichtfeld fehlt: title', 422);
            return;
        }

        if (isset($body['status']) && !in_array($body['status'], self::VALID_STATUSES, true)) {
            $this->error('Ungültiger Wert für status. Erlaubt: ' . implode(', ', self::VALID_STATUSES), 422);
            return;
        }

        $fields = array_intersect_key($body, array_flip([
            'title',
            'assessor_name',
            'assessor_org',
            'assessor_email',
            'period_start',
            'period_end',
            'methodology',
            'rules_of_engagement',
            'status',
        ]));

        $planId = $this->repo->create($domainId, $fields, $this->userId());
        AuditLogger::log('assessment.plan.create', 'assessment_plans', $planId, $fields);

        $fresh = $this->repo->findById($planId, $tenantId);
        $this->json(['plan' => $fresh], 201);
    }

    /**
     * GET /api/assessments/{planId}
     */
    public function showPlan(array $params): void
    {
        $planId   = (int) ($params['planId'] ?? 0);
        $tenantId = $this->tenantId();

        $plan = $this->repo->findById($planId, $tenantId);
        if ($plan === null) {
            $this->error('Prüfplan nicht gefunden.', 404);
            return;
        }

        $this->json(['plan' => $plan]);
    }

    /**
     * PUT /api/assessments/{planId}
     */
    public function updatePlan(array $params): void
    {
        $planId   = (int) ($params['planId'] ?? 0);
        $tenantId = $this->tenantId();

        if ($this->isWriteForbidden()) {
            $this->error('Keine Berechtigung zum Bearbeiten von Prüfplänen.', 403);
            return;
        }

        $existing = $this->repo->findById($planId, $tenantId);
        if ($existing === null) {
            $this->error('Prüfplan nicht gefunden.', 404);
            return;
        }

        $body = $this->requestBody();

        if (isset($body['status']) && !in_array($body['status'], self::VALID_STATUSES, true)) {
            $this->error('Ungültiger Wert für status. Erlaubt: ' . implode(', ', self::VALID_STATUSES), 422);
            return;
        }

        $fields = array_intersect_key($body, array_flip([
            'title',
            'assessor_name',
            'assessor_org',
            'assessor_email',
            'period_start',
            'period_end',
            'methodology',
            'rules_of_engagement',
            'status',
        ]));

        $updated = $this->repo->update($planId, $tenantId, $fields, $this->userId());
        if (!$updated) {
            $this->error('Keine Änderung gespeichert.', 422);
            return;
        }

        $trackFields = [
            'title',
            'assessor_name',
            'assessor_org',
            'assessor_email',
            'period_start',
            'period_end',
            'methodology',
            'rules_of_engagement',
            'status'
        ];
        $changes     = AuditLogger::diff($existing, $fields, $trackFields);
        AuditLogger::log('assessment.plan.update', 'assessment_plans', $planId, $changes);

        $fresh = $this->repo->findById($planId, $tenantId);
        $this->json(['plan' => $fresh]);
    }

    // ── Findings ──────────────────────────────────────────────────────────────

    /**
     * GET /api/assessments/{planId}/findings
     */
    public function listFindings(array $params): void
    {
        $planId   = (int) ($params['planId'] ?? 0);
        $tenantId = $this->tenantId();

        $plan = $this->repo->findById($planId, $tenantId);
        if ($plan === null) {
            $this->error('Prüfplan nicht gefunden.', 404);
            return;
        }

        $this->repo->ensureFindingsExist($planId, $tenantId);

        $filters = [
            'result' => $this->queryParam('result') ?? '',
            'search' => $this->queryParam('search') ?? '',
        ];

        $perPage = min(200, max(1, (int) ($this->queryParam('per_page', 50))));
        $page    = max(1, (int) ($this->queryParam('page', 1)));

        $result = $this->repo->findFindings($planId, $tenantId, $filters, $page, $perPage);

        $this->json([
            'items'   => $result['items'],
            'summary' => $result['summary'],
            'meta'    => [
                'total'     => $result['total'],
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => (int) ceil($result['total'] / max($perPage, 1)),
            ],
        ]);
    }

    /**
     * PUT /api/findings/{findingId}
     */
    public function updateFinding(array $params): void
    {
        $findingId = (int) ($params['findingId'] ?? 0);
        $tenantId  = $this->tenantId();

        if ($this->isWriteForbidden()) {
            $this->error('Keine Berechtigung zum Bearbeiten von Befunden.', 403);
            return;
        }

        $existing = $this->repo->findFindingById($findingId, $tenantId);
        if ($existing === null) {
            $this->error('Befund nicht gefunden.', 404);
            return;
        }

        $body = $this->requestBody();

        if (isset($body['result']) && !in_array($body['result'], self::VALID_RESULTS, true)) {
            $this->error('Ungültiger Wert für result. Erlaubt: ' . implode(', ', self::VALID_RESULTS), 422);
            return;
        }

        if (isset($body['method'])) {
            $err = $this->validateMethod($body['method']);
            if ($err !== null) {
                $this->error($err, 422);
                return;
            }
        }

        $fields = array_intersect_key($body, array_flip([
            'method',
            'result',
            'observation',
            'risk_statement',
        ]));

        $updated = $this->repo->updateFinding($findingId, $tenantId, $fields, $this->userId());
        if (!$updated) {
            $this->error('Keine Änderung gespeichert.', 422);
            return;
        }

        $trackFields = ['method', 'result', 'observation', 'risk_statement'];
        $changes     = AuditLogger::diff($existing, $fields, $trackFields);
        AuditLogger::log('assessment.finding.update', 'assessment_findings', $findingId, $changes);

        $fresh = $this->repo->findFindingById($findingId, $tenantId);
        $this->json(['finding' => $fresh]);
    }

    // ── Exports ───────────────────────────────────────────────────────────────

    /**
     * GET /api/assessments/{planId}/export/ap
     */
    public function exportAp(array $params): void
    {
        $planId   = (int) ($params['planId'] ?? 0);
        $tenantId = $this->tenantId();

        $plan = $this->repo->findById($planId, $tenantId);
        if ($plan === null) {
            $this->error('Prüfplan nicht gefunden.', 404);
            return;
        }

        $ap       = $this->exporter->exportAp($planId, $tenantId);
        $filename = $this->safeFilename($plan['domain_name'] ?? 'export') . '_AP.json';

        AuditLogger::log('assessment.plan.export_ap', 'assessment_plans', $planId);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($ap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * GET /api/assessments/{planId}/export/ar
     */
    public function exportAr(array $params): void
    {
        $planId   = (int) ($params['planId'] ?? 0);
        $tenantId = $this->tenantId();

        $plan = $this->repo->findById($planId, $tenantId);
        if ($plan === null) {
            $this->error('Prüfplan nicht gefunden.', 404);
            return;
        }

        $ar       = $this->exporter->exportAr($planId, $tenantId);
        $filename = $this->safeFilename($plan['domain_name'] ?? 'export') . '_AR.json';

        AuditLogger::log('assessment.plan.export_ar', 'assessment_plans', $planId);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($ar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function isWriteForbidden(): bool
    {
        return in_array($this->userRole(), ['fachverantwortlich', 'management', 'readonly'], true);
    }

    private function validateMethod(string $method): ?string
    {
        if ($method === '') {
            return null;
        }
        $parts = array_map('trim', explode(',', $method));
        foreach ($parts as $part) {
            if ($part !== '' && !in_array($part, self::VALID_METHODS, true)) {
                return 'Ungültiger Wert für method. Erlaubt: ' . implode(', ', self::VALID_METHODS);
            }
        }
        return null;
    }
}
