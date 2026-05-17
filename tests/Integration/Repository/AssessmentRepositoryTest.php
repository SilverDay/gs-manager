<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Repository;

use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DomainController;
use GsppManager\Repository\AssessmentRepository;
use GsppManager\Tests\Integration\IntegrationTestCase;

class AssessmentRepositoryTest extends IntegrationTestCase
{
    private AssessmentRepository $repo;
    private int $domainId;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo     = new AssessmentRepository();
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
            ['name' => 'Assessment-Repo-Test', 'isms_type' => 'standard', 'catalog_id' => $catalogId],
            httpMethod: 'POST'
        );
        $this->domainId = (int) ($domRes['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->domainId);
    }

    private function createPlan(string $title = 'Test Plan'): int
    {
        return $this->repo->create($this->domainId, [
            'title'        => $title,
            'planned_start' => date('Y-m-d'),
            'planned_end'  => date('Y-m-d', strtotime('+30 days')),
            'status'       => 'draft',
        ], (int) ($_SESSION['user_id'] ?? 1));
    }

    // ── createPlan ────────────────────────────────────────────────────────────

    public function test_createPlan_returns_integer_id(): void
    {
        $id = $this->createPlan();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    // ── findById ──────────────────────────────────────────────────────────────

    public function test_findById_returns_created_plan(): void
    {
        $id   = $this->createPlan('My Plan');
        $plan = $this->repo->findById($id, $this->tenantId);
        $this->assertNotNull($plan);
        $this->assertSame('My Plan', $plan['title']);
    }

    public function test_findById_returns_null_for_unknown_id(): void
    {
        $plan = $this->repo->findById(99999, $this->tenantId);
        $this->assertNull($plan);
    }

    // ── findAllByDomain ───────────────────────────────────────────────────────

    public function test_findAllByDomain_includes_created_plan(): void
    {
        $this->createPlan('List Plan');
        $plans = $this->repo->findByDomain($this->domainId, $this->tenantId);
        $this->assertNotEmpty($plans);
    }

    // ── updatePlan ────────────────────────────────────────────────────────────

    public function test_updatePlan_changes_status(): void
    {
        $id = $this->createPlan('Update Me');
        $this->repo->update($id, $this->tenantId, ['status' => 'active'], 1);
        $plan = $this->repo->findById($id, $this->tenantId);
        $this->assertSame('active', $plan['status']);
    }
}
