<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Middleware\AuditLogger;
use GsppManager\Middleware\AuthMiddleware;
use GsppManager\Repository\CatalogRepository;
use GsppManager\Repository\DomainRepository;
use GsppManager\Service\OscalParser;
use GsppManager\Service\TailoringEngine;
use RuntimeException;

class DomainController extends BaseController
{
    private DomainRepository $repo;
    private CatalogRepository $catalogRepo;
    private TailoringEngine $tailoring;
    private OscalParser $parser;

    public function __construct()
    {
        $this->repo        = new DomainRepository();
        $this->catalogRepo = new CatalogRepository();
        $this->tailoring   = new TailoringEngine();
        $this->parser      = new OscalParser();
    }

    // ── GET /api/domains ──────────────────────────────────────────────────────

    public function list(array $params): void
    {
        $domains = $this->repo->findAllByTenant($this->tenantId());
        $this->json(['domains' => $domains]);
    }

    // ── POST /api/domains ─────────────────────────────────────────────────────

    public function create(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb', 'fachverantwortlich'])) {
            return;
        }

        $body = $this->requestBody();

        $error = $this->validateRequired($body, ['name', 'isms_type']);
        if ($error !== null) {
            $this->error($error, 422);
            return;
        }

        $ismsType  = $body['isms_type'];
        if (!in_array($ismsType, ['standard', 'enhanced'], true)) {
            $this->error('isms_type muss "standard" oder "enhanced" sein.', 422);
            return;
        }

        $catalogId = isset($body['catalog_id']) ? (int) $body['catalog_id'] : 0;
        if ($catalogId <= 0) {
            $this->error('catalog_id ist erforderlich.', 422);
            return;
        }

        // Verify catalog belongs to this tenant
        $catalog = $this->catalogRepo->findByIdAndTenant($catalogId, $this->tenantId());
        if ($catalog === null) {
            $this->error('Katalog nicht gefunden.', 404);
            return;
        }

        $metaJson = json_encode([
            'catalog_id' => $catalogId,
            'branche'    => $body['branche'] ?? '',
            'zweck'      => $body['zweck']   ?? '',
            'version'    => $body['version'] ?? '1.0',
        ], JSON_UNESCAPED_UNICODE);

        $domainId = $this->repo->create($this->tenantId(), $this->userId(), [
            'name'          => trim($body['name']),
            'description'   => trim($body['description'] ?? ''),
            'isms_type'     => $ismsType,
            'metadata_json' => $metaJson,
        ]);

        // Auto-load controls by ISMS type
        try {
            $controls = $this->tailoring->loadControlsFromCatalog($catalogId, $ismsType, $this->parser, $this->tenantId());
            $this->repo->saveScopedControls($domainId, $controls, $catalogId);
        } catch (RuntimeException $e) {
            // Domain is created; log but don't fail — controls can be managed manually
            error_log('TailoringEngine::loadControlsFromCatalog failed: ' . $e->getMessage());
            $controls = [];
        }

        AuditLogger::log('create', 'information_domains', $domainId);

        $this->json([
            'domain' => [
                'id'            => $domainId,
                'name'          => trim($body['name']),
                'isms_type'     => $ismsType,
                'status'        => 'active',
                'control_count' => count($controls),
            ],
        ], 201);
    }

    // ── GET /api/domains/{id} ─────────────────────────────────────────────────

    public function show(array $params): void
    {
        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }
        $this->json(['domain' => $domain]);
    }

    // ── PUT /api/domains/{id} ─────────────────────────────────────────────────

    public function update(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb'])) {
            return;
        }

        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }

        $body  = $this->requestBody();
        $error = $this->validateRequired($body, ['name']);
        if ($error !== null) {
            $this->error($error, 422);
            return;
        }

        $oldIsmsType = $domain['isms_type'];
        $newIsmsType = $body['isms_type'] ?? $oldIsmsType;

        $this->repo->update((int) $domain['id'], [
            'name'        => trim($body['name']),
            'description' => trim($body['description'] ?? ''),
            'isms_type'   => $newIsmsType,
            'status'      => $body['status']    ?? $domain['status'],
        ]);

        AuditLogger::log('update', 'information_domains', (int) $domain['id']);

        // When the ISMS type changes, re-scope controls from the linked catalog
        if ($newIsmsType !== $oldIsmsType) {
            $meta      = json_decode($domain['metadata_json'] ?? '{}', true) ?? [];
            $catalogId = (int) ($meta['catalog_id'] ?? 0);
            if ($catalogId > 0) {
                try {
                    $catalog = $this->catalogRepo->findByIdAndTenant($catalogId, $this->tenantId());
                    if ($catalog !== null && !empty($catalog['catalog_json'])) {
                        $controls = $this->tailoring->loadControlsFromCatalog($catalogId, $newIsmsType, $this->parser);
                        $this->repo->saveScopedControls((int) $domain['id'], $controls, $catalogId);
                        AuditLogger::log('rescope_controls', 'information_domains', (int) $domain['id'], [
                            'old_isms_type' => $oldIsmsType,
                            'new_isms_type' => $newIsmsType,
                        ]);
                    }
                } catch (RuntimeException $e) {
                    // Log but don't fail the update — controls can be re-scoped manually
                    error_log('Re-scoping failed after isms_type change: ' . $e->getMessage());
                }
            }
        }

        $this->json(['updated' => true]);
    }

    // ── GET /api/domains/{id}/assets ──────────────────────────────────────────

    public function assets(array $params): void
    {
        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }
        $assets = $this->repo->findAssets((int) $domain['id']);
        $this->json(['assets' => $assets]);
    }

    // ── POST /api/domains/{id}/assets ─────────────────────────────────────────

    public function createAsset(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb', 'fachverantwortlich'])) {
            return;
        }

        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }

        $body  = $this->requestBody();
        $error = $this->validateRequired($body, ['name']);
        if ($error !== null) {
            $this->error($error, 422);
            return;
        }

        $validNeeds = ['normal', 'high'];
        foreach (['protection_need_c', 'protection_need_i', 'protection_need_a'] as $field) {
            if (isset($body[$field]) && !in_array($body[$field], $validNeeds, true)) {
                $this->error("{$field} muss 'normal' oder 'high' sein.", 422);
                return;
            }
        }

        $assetId = $this->repo->createAsset((int) $domain['id'], $body);
        AuditLogger::log('create', 'assets', $assetId);

        $this->json(['asset_id' => $assetId], 201);
    }

    // ── POST /api/domains/{id}/assets/import-category ────────────────────────

    /**
     * Bulk-create a named category of assets in one request.
     * Body: { "category_name": "...", "assets": [ {"name": "...", "asset_type": "...", ...} ] }
     */
    public function importAssetCategory(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb', 'fachverantwortlich'])) {
            return;
        }

        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }

        $body  = $this->requestBody();
        $error = $this->validateRequired($body, ['category_name', 'assets']);
        if ($error !== null) {
            $this->error($error, 422);
            return;
        }

        $categoryName = trim($body['category_name']);
        if ($categoryName === '') {
            $this->error('category_name darf nicht leer sein.', 422);
            return;
        }

        if (!is_array($body['assets']) || empty($body['assets'])) {
            $this->error('assets muss ein nicht-leeres Array sein.', 422);
            return;
        }

        $validNeeds  = ['normal', 'high'];
        $created     = [];
        $domainId    = (int) $domain['id'];

        foreach ($body['assets'] as $idx => $asset) {
            if (empty($asset['name'])) {
                $this->error("assets[{$idx}].name ist erforderlich.", 422);
                return;
            }
            foreach (['protection_need_c', 'protection_need_i', 'protection_need_a'] as $field) {
                if (isset($asset[$field]) && !in_array($asset[$field], $validNeeds, true)) {
                    $this->error("assets[{$idx}].{$field} muss 'normal' oder 'high' sein.", 422);
                    return;
                }
            }

            $assetData            = $asset;
            $assetData['category_name'] = $categoryName;
            $assetId  = $this->repo->createAsset($domainId, $assetData);
            $created[] = $assetId;
        }

        AuditLogger::log('create', 'assets', null, [
            'category_name' => $categoryName,
            'count'         => count($created),
            'ids'           => $created,
        ]);

        $this->json(['created' => count($created), 'asset_ids' => $created], 201);
    }

    // ── GET /api/domains/{id}/processes ───────────────────────────────────────

    public function processes(array $params): void
    {
        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }
        $processes = $this->repo->findProcesses((int) $domain['id']);
        $this->json(['processes' => $processes]);
    }

    // ── POST /api/domains/{id}/processes ──────────────────────────────────────

    public function createProcess(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb', 'fachverantwortlich'])) {
            return;
        }

        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }

        $body  = $this->requestBody();
        $error = $this->validateRequired($body, ['name']);
        if ($error !== null) {
            $this->error($error, 422);
            return;
        }

        $validCriticalities = ['low', 'medium', 'high', 'very_high'];
        if (isset($body['criticality']) && !in_array($body['criticality'], $validCriticalities, true)) {
            $this->error('Ungültiger Kritikalitätswert.', 422);
            return;
        }

        $processId = $this->repo->createProcess((int) $domain['id'], $body);
        AuditLogger::log('create', 'business_processes', $processId);

        $this->json(['process_id' => $processId], 201);
    }

    // ── PUT /api/domains/{id}/assets/{assetId} ────────────────────────────────

    public function updateAsset(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb', 'fachverantwortlich'])) {
            return;
        }

        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }

        $assetId = (int) ($params['assetId'] ?? 0);
        if ($assetId === 0) {
            $this->error('Asset-ID fehlt.', 422);
            return;
        }

        $body  = $this->requestBody();
        $error = $this->validateRequired($body, ['name']);
        if ($error !== null) {
            $this->error($error, 422);
            return;
        }

        $updated = $this->repo->updateAsset($assetId, (int) $domain['id'], $body);
        if (!$updated) {
            $this->error('Asset nicht gefunden.', 404);
            return;
        }

        AuditLogger::log('update', 'assets', $assetId);
        $this->json(['updated' => true]);
    }

    // ── DELETE /api/domains/{id}/assets/{assetId} ─────────────────────────────

    public function deleteAsset(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb', 'fachverantwortlich'])) {
            return;
        }

        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }

        $assetId = (int) ($params['assetId'] ?? 0);
        if ($assetId === 0) {
            $this->error('Asset-ID fehlt.', 422);
            return;
        }

        $deleted = $this->repo->deleteAsset($assetId, (int) $domain['id']);
        if (!$deleted) {
            $this->error('Asset nicht gefunden.', 404);
            return;
        }

        AuditLogger::log('delete', 'assets', $assetId);
        $this->json(['deleted' => true]);
    }

    // ── PUT /api/domains/{id}/processes/{processId} ───────────────────────────

    public function updateProcess(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb', 'fachverantwortlich'])) {
            return;
        }

        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }

        $processId = (int) ($params['processId'] ?? 0);
        if ($processId === 0) {
            $this->error('Prozess-ID fehlt.', 422);
            return;
        }

        $body  = $this->requestBody();
        $error = $this->validateRequired($body, ['name']);
        if ($error !== null) {
            $this->error($error, 422);
            return;
        }

        $validCriticalities = ['low', 'medium', 'high', 'very_high'];
        if (isset($body['criticality']) && !in_array($body['criticality'], $validCriticalities, true)) {
            $this->error('Ungültiger Kritikalitätswert.', 422);
            return;
        }

        $updated = $this->repo->updateProcess($processId, (int) $domain['id'], $body);
        if (!$updated) {
            $this->error('Prozess nicht gefunden.', 404);
            return;
        }

        AuditLogger::log('update', 'business_processes', $processId);
        $this->json(['updated' => true]);
    }

    // ── DELETE /api/domains/{id}/processes/{processId} ────────────────────────

    public function deleteProcess(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb', 'fachverantwortlich'])) {
            return;
        }

        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }

        $processId = (int) ($params['processId'] ?? 0);
        if ($processId === 0) {
            $this->error('Prozess-ID fehlt.', 422);
            return;
        }

        $deleted = $this->repo->deleteProcess($processId, (int) $domain['id']);
        if (!$deleted) {
            $this->error('Prozess nicht gefunden.', 404);
            return;
        }

        AuditLogger::log('delete', 'business_processes', $processId);
        $this->json(['deleted' => true]);
    }

    // ── GET /api/domains/{id}/scoped-controls ─────────────────────────────────

    public function scopedControls(array $params): void
    {
        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }

        $search = trim($this->queryParam('search', ''));
        $all    = $this->repo->findScopedControls((int) $domain['id'], $search);

        $pag   = $this->pagination();
        $total = count($all);
        $page  = array_slice($all, $pag['offset'], $pag['per_page']);

        $this->paginated($page, $total, $pag['page'], $pag['per_page']);
    }

    // ── POST /api/domains/{id}/tailoring ──────────────────────────────────────

    public function tailoring(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb'])) {
            return;
        }

        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }

        $body = $this->requestBody();

        $controlIdStr = trim($body['control_id_str'] ?? '');
        if ($controlIdStr === '') {
            $this->error('control_id_str ist erforderlich.', 422);
            return;
        }

        $existing = $this->repo->findScopedControlByStr((int) $domain['id'], $controlIdStr);
        if ($existing === null) {
            $this->error('Anforderung nicht im Scope dieses Informationsverbunds.', 404);
            return;
        }

        try {
            $this->tailoring->applyTailoring($existing, $body);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage(), 422);
            return;
        }

        $this->repo->upsertScopedControl((int) $domain['id'], [
            'control_id_str'  => $controlIdStr,
            'catalog_id'      => $existing['catalog_id'],
            'title'           => $existing['title'],
            'parameters_json' => $existing['parameters_json'],
            'tailoring_json'  => $existing['tailoring_json'],
        ]);

        AuditLogger::log('update', 'scoped_controls', (int) $existing['id']);

        $this->json(['updated' => true, 'control_id_str' => $controlIdStr]);
    }

    // ── POST /api/domains/{id}/generate-profile ───────────────────────────────

    public function generateProfile(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb'])) {
            return;
        }

        $domain = $this->resolveDomain($params);
        if ($domain === null) {
            return;
        }

        $scopedControls = $this->repo->findScopedControls((int) $domain['id']);
        if (empty($scopedControls)) {
            $this->error('Keine Anforderungen im Scope. Bitte zuerst Anforderungen laden.', 422);
            return;
        }

        // Get catalog metadata for the domain's primary catalog
        $meta = json_decode($domain['metadata_json'] ?? '{}', true) ?? [];
        $catalogId = (int) ($meta['catalog_id'] ?? 0);
        $catalog   = $catalogId > 0
            ? $this->catalogRepo->findByIdAndTenant($catalogId, $this->tenantId())
            : null;

        $catalogMeta = [
            'catalog_id' => $catalogId,
            'title'      => $catalog['name']       ?? 'Unknown Catalog',
            'source_url' => $catalog['source_url'] ?? null,
        ];

        $profile     = $this->tailoring->generateOscalProfile($domain, $scopedControls, $catalogMeta);
        $profileJson = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $profileId = $this->repo->saveProfile((int) $domain['id'], $this->userId(), $profileJson);
        AuditLogger::log('create', 'profiles', $profileId);

        $this->json([
            'profile_id' => $profileId,
            'profile'    => $profile,
        ], 201);
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function resolveDomain(array $params): ?array
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            $this->error('Ungültige Domain-ID.', 400);
            return null;
        }

        $domain = $this->repo->findByIdAndTenant($id, $this->tenantId());
        if ($domain === null) {
            $this->error('Informationsverbund nicht gefunden.', 404);
            return null;
        }

        return $domain;
    }
}
