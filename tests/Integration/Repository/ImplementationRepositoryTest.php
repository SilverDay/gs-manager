<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Repository;

use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DomainController;
use GsppManager\Repository\ImplementationRepository;
use GsppManager\Tests\Integration\IntegrationTestCase;

class ImplementationRepositoryTest extends IntegrationTestCase
{
    private ImplementationRepository $repo;
    private int $catalogId;
    private int $domainId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ImplementationRepository();

        $sampleJson = file_get_contents(__DIR__ . '/../../Fixtures/oscal/sample_catalog.json');

        $this->loginAs('isb');

        // Import catalog
        $catRes = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $sampleJson],
            httpMethod: 'POST'
        );
        $this->catalogId = (int) ($catRes['data']['catalog']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->catalogId, 'Catalog import failed in setUp');

        // Create domain (auto-creates scoped controls)
        $domRes = $this->callController(
            DomainController::class,
            'create',
            ['name' => 'Impl-Test-Verbund', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        $this->domainId = (int) ($domRes['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->domainId, 'Domain creation failed in setUp');
    }

    // ── ensureAllExist ────────────────────────────────────────────────────────

    public function test_ensureAllExist_creates_rows_for_all_scoped_controls(): void
    {
        $created = $this->repo->ensureAllExist($this->domainId, 1);

        // Should have created at least one row
        $this->assertGreaterThan(0, $created);

        // All scoped controls should now have an implementation row
        $count = $this->db->query(
            "SELECT COUNT(*) FROM implementations i
             JOIN scoped_controls sc ON sc.id = i.scoped_control_id
             WHERE sc.domain_id = {$this->domainId}"
        )->fetchColumn();

        $expected = $this->db->query(
            "SELECT COUNT(*) FROM scoped_controls WHERE domain_id = {$this->domainId}"
        )->fetchColumn();

        $this->assertSame((int) $expected, (int) $count);
    }

    public function test_ensureAllExist_is_idempotent(): void
    {
        $first  = $this->repo->ensureAllExist($this->domainId, 1);
        $second = $this->repo->ensureAllExist($this->domainId, 1);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'Second call should create no new rows');
    }

    public function test_ensureAllExist_rejects_wrong_tenant(): void
    {
        $created = $this->repo->ensureAllExist($this->domainId, 999);
        $this->assertSame(0, $created);
    }

    // ── findById / tenant isolation ───────────────────────────────────────────

    public function test_update_requires_tenant_ownership(): void
    {
        $this->repo->ensureAllExist($this->domainId, 1);
        $result = $this->repo->findByDomain($this->domainId, 1);
        $impl   = $result['items'][0] ?? null;
        $this->assertNotNull($impl);

        // Update with correct tenant works
        $ok = $this->repo->update((int) $impl['id'], 1, ['status' => 'planned'], 2);
        $this->assertTrue($ok);

        // Update with wrong tenant silently fails (no row updated)
        $fail = $this->repo->update((int) $impl['id'], 999, ['status' => 'implemented'], 2);
        $this->assertFalse($fail);
    }

    // ── status transitions ────────────────────────────────────────────────────

    public function test_status_transitions_are_persisted(): void
    {
        $this->repo->ensureAllExist($this->domainId, 1);
        $result = $this->repo->findByDomain($this->domainId, 1);
        $impl   = $result['items'][0];
        $implId = (int) $impl['id'];

        $statuses = ['planned', 'partial', 'implemented', 'not_applicable', 'not_started'];
        foreach ($statuses as $status) {
            $ok   = $this->repo->update($implId, 1, ['status' => $status], 2);
            $this->assertTrue($ok);
            $fresh = $this->repo->findById($implId, 1);
            $this->assertSame($status, $fresh['status']);
        }
    }

    // ── findByDomain with filters ─────────────────────────────────────────────

    public function test_findByDomain_filters_by_status(): void
    {
        $this->repo->ensureAllExist($this->domainId, 1);
        $all = $this->repo->findByDomain($this->domainId, 1);

        if (empty($all['items'])) {
            $this->markTestSkipped('No controls in sample catalog.');
        }

        // Set first item to 'implemented'
        $implId = (int) $all['items'][0]['id'];
        $this->repo->update($implId, 1, ['status' => 'implemented'], 2);

        $filtered = $this->repo->findByDomain($this->domainId, 1, ['status' => 'implemented']);
        $this->assertCount(1, $filtered['items']);
        $this->assertSame('implemented', $filtered['items'][0]['status']);
    }

    // ── progress summary ──────────────────────────────────────────────────────

    public function test_progress_summary_is_returned(): void
    {
        $this->repo->ensureAllExist($this->domainId, 1);
        $result = $this->repo->findByDomain($this->domainId, 1);

        $p = $result['progress'];
        $this->assertArrayHasKey('total', $p);
        $this->assertArrayHasKey('implemented', $p);
        $this->assertArrayHasKey('not_started', $p);
        $this->assertGreaterThan(0, $p['total']);
    }
}
