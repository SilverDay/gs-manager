<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Middleware\AuditLogger;
use GsppManager\Repository\DomainRepository;
use GsppManager\Repository\RiskRepository;
use GsppManager\Service\RiskEngine;

class RiskController extends BaseController
{
    private RiskRepository $repo;
    private DomainRepository $domainRepo;
    private RiskEngine $engine;

    public function __construct()
    {
        $this->repo       = new RiskRepository();
        $this->domainRepo = new DomainRepository();
        $this->engine     = new RiskEngine();
    }

    /**
     * GET /api/domains/{id}/risks
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
            'risk_level' => $this->queryParam('risk_level') ?? '',
            'treatment'  => $this->queryParam('treatment')  ?? '',
            'search'     => $this->queryParam('search')     ?? '',
        ];

        $perPage = min(200, max(1, (int) ($this->queryParam('per_page', 50))));
        $page    = max(1, (int) ($this->queryParam('page', 1)));

        $result = $this->repo->findByDomain($domainId, $tenantId, $filters, $page, $perPage);

        $this->json([
            'items' => $result['items'],
            'meta'  => [
                'total'     => $result['total'],
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => (int) ceil($result['total'] / max($perPage, 1)),
            ],
        ]);
    }

    /**
     * POST /api/domains/{id}/risks
     */
    public function create(array $params): void
    {
        $domainId = (int) ($params['id'] ?? 0);
        $tenantId = $this->tenantId();

        if ($this->isReadOnly()) {
            $this->error('Keine Berechtigung zum Anlegen von Risiken.', 403);
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

        $err = $this->validateRiskFields($body);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $likelihood = $body['likelihood'] ?? 'medium';
        $impact     = $body['impact']     ?? 'medium';

        $fields = array_intersect_key($body, array_flip([
            'title', 'description', 'asset_id', 'likelihood', 'impact',
            'treatment', 'acceptance_justification', 'owner_user_id',
        ]));
        $fields['asset_id']      = ($fields['asset_id']      ?? '') !== '' ? (int) $fields['asset_id']      : null;
        $fields['owner_user_id'] = ($fields['owner_user_id'] ?? '') !== '' ? (int) $fields['owner_user_id'] : null;
        $fields['risk_level'] = $this->engine->calculateLevel($likelihood, $impact);

        $riskId = $this->repo->create($domainId, $fields, $this->userId());
        AuditLogger::log('risk.create', 'risks', $riskId, $fields);

        $fresh = $this->repo->findById($riskId, $tenantId);
        $this->json(['risk' => $fresh], 201);
    }

    /**
     * PUT /api/risks/{riskId}
     */
    public function update(array $params): void
    {
        $riskId   = (int) ($params['riskId'] ?? 0);
        $tenantId = $this->tenantId();

        if ($this->isReadOnly()) {
            $this->error('Keine Berechtigung zum Bearbeiten von Risiken.', 403);
            return;
        }

        $existing = $this->repo->findById($riskId, $tenantId);
        if ($existing === null) {
            $this->error('Risiko nicht gefunden.', 404);
            return;
        }

        $body = $this->requestBody();

        $err = $this->validateRiskFields($body);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $fields = array_intersect_key($body, array_flip([
            'title', 'description', 'asset_id', 'likelihood', 'impact',
            'treatment', 'acceptance_justification', 'owner_user_id',
        ]));
        if (array_key_exists('asset_id', $fields)) {
            $fields['asset_id'] = ($fields['asset_id'] ?? '') !== '' ? (int) $fields['asset_id'] : null;
        }
        if (array_key_exists('owner_user_id', $fields)) {
            $fields['owner_user_id'] = ($fields['owner_user_id'] ?? '') !== '' ? (int) $fields['owner_user_id'] : null;
        }

        // Recompute risk_level if likelihood or impact changed
        $likelihood = $fields['likelihood'] ?? $existing['likelihood'];
        $impact     = $fields['impact']     ?? $existing['impact'];
        $fields['risk_level'] = $this->engine->calculateLevel($likelihood, $impact);

        $updated = $this->repo->update($riskId, $tenantId, $fields, $this->userId());
        if (!$updated) {
            $this->error('Keine Änderung gespeichert.', 422);
            return;
        }

        $trackFields = ['title', 'description', 'asset_id', 'likelihood', 'impact', 'risk_level', 'treatment', 'acceptance_justification', 'owner_user_id'];
        $changes     = AuditLogger::diff($existing, $fields, $trackFields);
        AuditLogger::log('risk.update', 'risks', $riskId, $changes);

        $fresh = $this->repo->findById($riskId, $tenantId);
        $this->json(['risk' => $fresh]);
    }

    /**
     * POST /api/risks/{riskId}/controls
     */
    public function linkControl(array $params): void
    {
        $riskId   = (int) ($params['riskId'] ?? 0);
        $tenantId = $this->tenantId();

        if ($this->isReadOnly()) {
            $this->error('Keine Berechtigung.', 403);
            return;
        }

        $existing = $this->repo->findById($riskId, $tenantId);
        if ($existing === null) {
            $this->error('Risiko nicht gefunden.', 404);
            return;
        }

        $body      = $this->requestBody();
        $controlId = (int) ($body['scoped_control_id'] ?? 0);
        if ($controlId === 0) {
            $this->error('Pflichtfeld fehlt: scoped_control_id', 422);
            return;
        }

        $ok = $this->repo->linkControl($riskId, $tenantId, $controlId);
        if (!$ok) {
            $this->error('Anforderung gehört nicht zu diesem Verbund oder Risiko nicht gefunden.', 422);
            return;
        }

        AuditLogger::log('risk.link_control', 'risks', $riskId, ['scoped_control_id' => $controlId]);

        $fresh = $this->repo->findById($riskId, $tenantId);
        $this->json(['risk' => $fresh]);
    }

    /**
     * DELETE /api/risks/{riskId}/controls/{controlId}
     */
    public function unlinkControl(array $params): void
    {
        $riskId    = (int) ($params['riskId']    ?? 0);
        $controlId = (int) ($params['controlId'] ?? 0);
        $tenantId  = $this->tenantId();

        if ($this->isReadOnly()) {
            $this->error('Keine Berechtigung.', 403);
            return;
        }

        $existing = $this->repo->findById($riskId, $tenantId);
        if ($existing === null) {
            $this->error('Risiko nicht gefunden.', 404);
            return;
        }

        $this->repo->unlinkControl($riskId, $tenantId, $controlId);
        AuditLogger::log('risk.unlink_control', 'risks', $riskId, ['scoped_control_id' => $controlId]);

        $fresh = $this->repo->findById($riskId, $tenantId);
        $this->json(['risk' => $fresh]);
    }

    /**
     * GET /api/domains/{id}/dashboard/risks
     */
    public function heatmap(array $params): void
    {
        $domainId = (int) ($params['id'] ?? 0);
        $tenantId = $this->tenantId();

        $domain = $this->domainRepo->findByIdAndTenant($domainId, $tenantId);
        if ($domain === null) {
            $this->error('Informationsverbund nicht gefunden.', 404);
            return;
        }

        $data = $this->repo->heatmapData($domainId, $tenantId);
        $this->json($data);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function isReadOnly(): bool
    {
        return in_array($this->userRole(), ['auditor', 'management', 'readonly'], true);
    }

    /**
     * Validate ENUM fields and acceptance_justification rule.
     * Returns error string or null if valid.
     */
    private function validateRiskFields(array $body): ?string
    {
        if (isset($body['likelihood']) && !in_array($body['likelihood'], RiskEngine::validLikelihoods(), true)) {
            return 'Ungültiger Wert für likelihood.';
        }

        if (isset($body['impact']) && !in_array($body['impact'], RiskEngine::validImpacts(), true)) {
            return 'Ungültiger Wert für impact.';
        }

        if (isset($body['treatment']) && !in_array($body['treatment'], RiskEngine::validTreatments(), true)) {
            return 'Ungültiger Wert für treatment.';
        }

        $treatment     = $body['treatment'] ?? null;
        $justification = trim((string) ($body['acceptance_justification'] ?? ''));

        if ($treatment === 'accept' && $justification === '') {
            return 'Bei Risikoakzeptanz ist eine Begründung (acceptance_justification) erforderlich.';
        }

        return null;
    }
}
