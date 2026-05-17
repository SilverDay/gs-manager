<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Middleware\AuditLogger;
use GsppManager\Repository\AssessmentRepository;
use GsppManager\Repository\DomainRepository;
use GsppManager\Repository\PoamRepository;
use GsppManager\Service\OscalExporter;

class PoamController extends BaseController
{
    private PoamRepository       $repo;
    private AssessmentRepository $assessmentRepo;
    private DomainRepository     $domainRepo;
    private OscalExporter        $exporter;

    private const VALID_STATUSES  = ['open', 'in_progress', 'completed', 'verified', 'accepted'];
    private const VALID_PRIORITIES = ['high', 'medium', 'low'];

    public function __construct()
    {
        $this->repo           = new PoamRepository();
        $this->assessmentRepo = new AssessmentRepository();
        $this->domainRepo     = new DomainRepository();
        $this->exporter       = new OscalExporter();
    }

    /**
     * POST /api/domains/{id}/poam/generate
     */
    public function generate(array $params): void
    {
        $domainId = (int) ($params['id'] ?? 0);
        $tenantId = $this->tenantId();

        if ($this->isGenerateForbidden()) {
            $this->error('Keine Berechtigung zum Generieren von POA&M-Items.', 403);
            return;
        }

        $domain = $this->domainRepo->findByIdAndTenant($domainId, $tenantId);
        if ($domain === null) {
            $this->error('Informationsverbund nicht gefunden.', 404);
            return;
        }

        $body   = $this->requestBody();
        $planId = (int) ($body['plan_id'] ?? 0);

        if ($planId === 0) {
            $this->error('Pflichtfeld fehlt: plan_id', 422);
            return;
        }

        // Verify plan belongs to this domain
        $plan = $this->assessmentRepo->findById($planId, $tenantId);
        if ($plan === null || (int) ($plan['domain_id'] ?? 0) !== $domainId) {
            $this->error('Prüfplan nicht gefunden oder gehört nicht zu diesem Verbund.', 404);
            return;
        }

        $count = $this->repo->generateFromPlan($planId, $domainId, $tenantId);
        AuditLogger::log('poam.generate', 'poam_items', $domainId, [
            'plan_id' => $planId,
            'created' => $count,
        ]);

        $result = $this->repo->findByDomain($domainId, $tenantId, [], 1, 200);
        $this->json(['count' => $count, 'items' => $result['items']], 201);
    }

    /**
     * GET /api/domains/{id}/poam
     */
    public function list(array $params): void
    {
        $domainId = (int) ($params['id'] ?? 0);
        $tenantId = $this->tenantId();

        $domain = $this->domainRepo->findByIdAndTenant($domainId, $tenantId);
        if ($domain === null) {
            $this->error('Informationsverbund nicht gefunden.', 404);
            return;
        }

        $filters = [
            'status'   => $this->queryParam('status')   ?? '',
            'priority' => $this->queryParam('priority') ?? '',
            'search'   => $this->queryParam('search')   ?? '',
        ];

        $perPage = min(200, max(1, (int) ($this->queryParam('per_page', 50))));
        $page    = max(1, (int) ($this->queryParam('page', 1)));

        $result = $this->repo->findByDomain($domainId, $tenantId, $filters, $page, $perPage);

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
     * PUT /api/poam/{itemId}
     */
    public function update(array $params): void
    {
        $itemId   = (int) ($params['itemId'] ?? 0);
        $tenantId = $this->tenantId();

        if ($this->isUpdateForbidden()) {
            $this->error('Keine Berechtigung zum Bearbeiten von POA&M-Items.', 403);
            return;
        }

        $existing = $this->repo->findById($itemId, $tenantId);
        if ($existing === null) {
            $this->error('POA&M-Item nicht gefunden.', 404);
            return;
        }

        // Fachverantwortlich may only update items assigned to themselves
        if (
            $this->userRole() === 'fachverantwortlich'
            && (int) ($existing['responsible_user_id'] ?? 0) !== $this->userId()
        ) {
            $this->error('Keine Berechtigung zum Bearbeiten fremder Maßnahmen.', 403);
            return;
        }

        $body = $this->requestBody();

        $err = $this->validateFields($body);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $fields = array_intersect_key($body, array_flip([
            'title', 'description', 'priority', 'status',
            'responsible_user_id', 'deadline', 'completion_date',
            'deviation_justification', 'milestones_json',
        ]));

        $updated = $this->repo->update($itemId, $tenantId, $fields, $this->userId());
        if (!$updated) {
            $this->error('Keine Änderung gespeichert.', 422);
            return;
        }

        $trackFields = ['title', 'description', 'priority', 'status',
                        'responsible_user_id', 'deadline', 'completion_date',
                        'deviation_justification'];
        $changes     = AuditLogger::diff($existing, $fields, $trackFields);
        AuditLogger::log('poam.item.update', 'poam_items', $itemId, $changes);

        $fresh = $this->repo->findById($itemId, $tenantId);
        $this->json(['item' => $fresh]);
    }

    /**
     * GET /api/domains/{id}/poam/export
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

        $poam     = $this->exporter->exportPoam($domainId, $tenantId);
        $filename = $this->safeFilename($domain['name']) . '_POAM.json';

        AuditLogger::log('poam.export', 'information_domains', $domainId);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($poam, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function isGenerateForbidden(): bool
    {
        return in_array($this->userRole(), ['auditor', 'fachverantwortlich', 'management', 'readonly'], true);
    }

    private function isUpdateForbidden(): bool
    {
        return in_array($this->userRole(), ['auditor', 'management', 'readonly'], true);
    }

    private function validateFields(array $body): ?string
    {
        if (isset($body['status']) && !in_array($body['status'], self::VALID_STATUSES, true)) {
            return 'Ungültiger Wert für status. Erlaubt: ' . implode(', ', self::VALID_STATUSES);
        }

        if (isset($body['priority']) && !in_array($body['priority'], self::VALID_PRIORITIES, true)) {
            return 'Ungültiger Wert für priority. Erlaubt: ' . implode(', ', self::VALID_PRIORITIES);
        }

        if (($body['status'] ?? null) === 'accepted') {
            $justification = trim((string) ($body['deviation_justification'] ?? ''));
            if ($justification === '') {
                return 'Bei Risikoakzeptanz ist eine Begründung (deviation_justification) erforderlich.';
            }
        }

        if (isset($body['milestones_json']) && $body['milestones_json'] !== null && $body['milestones_json'] !== '') {
            $decoded = json_decode($body['milestones_json'], true);
            if (!is_array($decoded)) {
                return 'milestones_json muss ein gültiges JSON-Array sein.';
            }
        }

        return null;
    }

    private function safeFilename(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name) ?? 'export';
    }
}
