<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Repository;

use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DomainController;
use GsppManager\Repository\RiskRepository;
use GsppManager\Tests\Integration\IntegrationTestCase;

class RiskRepositoryTest extends IntegrationTestCase
{
    private RiskRepository $repo;
    private int $domainId;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo     = new RiskRepository();
        $this->loginAs('isb');
        $this->tenantId = (int) ($_SESSION['tenant_id'] ?? 1);

        $sampleJson = file_get_contents(__DIR__ . '/../../Fixtures/oscal/sample_catalog.json');

        $catRes = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $sampleJson],
            httpMethod: 'POST'
        );
        $catalogId = (int) ($catRes['data']['catalog']['id'] ?? 0);
        $this->assertGreaterThan(0, $catalogId);

        $domRes = $this->callController(
            DomainController::class,
            'create',
            ['name' => 'Risk-Repo-Test', 'isms_type' => 'standard', 'catalog_id' => $catalogId],
            httpMethod: 'POST'
        );
        $this->domainId = (int) ($domRes['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->domainId);
    }

    private function createRisk(array $overrides = []): array
    {
        return array_merge([
            'title'      => 'Test risk',
            'description' => 'Risk description',
            'likelihood' => 'medium',
            'impact'     => 'medium',
            'risk_level' => 'medium',
            'treatment'  => 'mitigate',
        ], $overrides);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_integer_id(): void
    {
        $id = $this->repo->create($this->domainId, $this->createRisk(), 1);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    // ── findById ──────────────────────────────────────────────────────────────

    public function test_findById_returns_created_risk(): void
    {
        $id   = $this->repo->create($this->domainId, $this->createRisk(['title' => 'FindMe']), 1);
        $risk = $this->repo->findById($id, $this->tenantId);
        $this->assertNotNull($risk);
        $this->assertSame('FindMe', $risk['title']);
    }

    public function test_findById_returns_null_for_unknown_id(): void
    {
        $risk = $this->repo->findById(99999, $this->tenantId);
        $this->assertNull($risk);
    }

    // ── findAllByDomain ───────────────────────────────────────────────────────

    public function test_findAllByDomain_includes_created_risk(): void
    {
        $this->repo->create($this->domainId, $this->createRisk(), 1);
        $risks = $this->repo->findByDomain($this->domainId, $this->tenantId);
        $this->assertNotEmpty($risks);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_changes_title(): void
    {
        $id = $this->repo->create($this->domainId, $this->createRisk(), 1);
        $this->repo->update($id, $this->tenantId, ['title' => 'Updated title'], 1);
        $risk = $this->repo->findById($id, $this->tenantId);
        $this->assertSame('Updated title', $risk['title']);
    }
}
