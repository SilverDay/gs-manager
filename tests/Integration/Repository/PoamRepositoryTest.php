<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Repository;

use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DomainController;
use GsppManager\Repository\PoamRepository;
use GsppManager\Tests\Integration\IntegrationTestCase;

class PoamRepositoryTest extends IntegrationTestCase
{
    private PoamRepository $repo;
    private int $domainId;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo     = new PoamRepository();
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
            ['name' => 'Poam-Repo-Test', 'isms_type' => 'standard', 'catalog_id' => $catalogId],
            httpMethod: 'POST'
        );
        $this->domainId = (int) ($domRes['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->domainId);
    }

    private function createItem(array $overrides = []): int
    {
        $data = array_merge([
            'domain_id'           => $this->domainId,
            'title'               => 'Test POAM item',
            'status'              => 'open',
            'priority'            => 'medium',
            'deadline'            => date('Y-m-d', strtotime('+30 days')),
            'responsible_user_id' => (int) ($_SESSION['user_id'] ?? 1),
        ], $overrides);

        $stmt = $this->db->prepare("
            INSERT INTO poam_items (domain_id, title, status, priority, deadline, responsible_user_id, created_at, updated_at)
            VALUES (:domain_id, :title, :status, :priority, :deadline, :responsible_user_id, NOW(), NOW())
        ");
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_integer_id(): void
    {
        $id = $this->createItem();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    // ── findById ──────────────────────────────────────────────────────────────

    public function test_findById_returns_created_item(): void
    {
        $id   = $this->createItem(['title' => 'Find Me POAM']);
        $item = $this->repo->findById($id, $this->tenantId);
        $this->assertNotNull($item);
        $this->assertSame('Find Me POAM', $item['title']);
    }

    public function test_findById_returns_null_for_unknown_id(): void
    {
        $item = $this->repo->findById(99999, $this->tenantId);
        $this->assertNull($item);
    }

    // ── findAllByDomain ───────────────────────────────────────────────────────

    public function test_findAllByDomain_includes_created_item(): void
    {
        $this->createItem();
        $items = $this->repo->findByDomain($this->domainId, $this->tenantId);
        $this->assertArrayHasKey('items', $items);
        $this->assertNotEmpty($items['items']);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_changes_status(): void
    {
        $id = $this->createItem(['title' => 'Update Status']);
        $this->repo->update($id, $this->tenantId, ['status' => 'in_progress'], 1);
        $item = $this->repo->findById($id, $this->tenantId);
        $this->assertSame('in_progress', $item['status']);
    }
}
