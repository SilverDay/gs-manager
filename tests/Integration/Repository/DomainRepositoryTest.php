<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Repository;

use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DomainController;
use GsppManager\Repository\DomainRepository;
use GsppManager\Tests\Integration\IntegrationTestCase;

class DomainRepositoryTest extends IntegrationTestCase
{
    private DomainRepository $repo;
    private int $catalogId;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo     = new DomainRepository();
        $this->loginAs('isb');
        $this->tenantId = (int) ($_SESSION['tenant_id'] ?? 1);

        $sampleJson = file_get_contents(__DIR__ . '/../../Fixtures/oscal/sample_catalog.json');

        $catRes = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $sampleJson],
            httpMethod: 'POST'
        );
        $this->catalogId = (int) ($catRes['data']['catalog']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->catalogId, 'Catalog import failed in setUp');
    }

    // ── create / findByIdAndTenant ────────────────────────────────────────────

    public function test_create_returns_integer_id(): void
    {
        $res = $this->callController(
            DomainController::class,
            'create',
            ['name' => 'DomRepo-Test', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        $id = (int) ($res['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $id);
    }

    public function test_findByIdAndTenant_returns_created_domain(): void
    {
        $res = $this->callController(
            DomainController::class,
            'create',
            ['name' => 'FindDomRepo-Test', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        $id = (int) ($res['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $id);

        $domain = $this->repo->findByIdAndTenant($id, $this->tenantId);
        $this->assertNotNull($domain);
        $this->assertSame('FindDomRepo-Test', $domain['name']);
    }

    public function test_findByIdAndTenant_returns_null_for_unknown_id(): void
    {
        $domain = $this->repo->findByIdAndTenant(99999, $this->tenantId);
        $this->assertNull($domain);
    }

    // ── findAll ───────────────────────────────────────────────────────────────

    public function test_findAll_returns_array(): void
    {
        $domains = $this->repo->findAllByTenant($this->tenantId);
        $this->assertIsArray($domains);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_changes_name(): void
    {
        $res = $this->callController(
            DomainController::class,
            'create',
            ['name' => 'BeforeUpdate', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        $id = (int) ($res['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $id);

        $this->repo->update($id, ['name' => 'AfterUpdate', 'description' => '', 'isms_type' => 'standard', 'status' => 'active']);

        $updated = $this->repo->findByIdAndTenant($id, $this->tenantId);
        $this->assertSame('AfterUpdate', $updated['name']);
    }

    // ── findAssets ────────────────────────────────────────────────────────────

    public function test_findAssets_returns_empty_for_new_domain(): void
    {
        $res = $this->callController(
            DomainController::class,
            'create',
            ['name' => 'AssetTest', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        $id = (int) ($res['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $id);

        $assets = $this->repo->findAssets($id);
        $this->assertIsArray($assets);
        $this->assertEmpty($assets);
    }
}
