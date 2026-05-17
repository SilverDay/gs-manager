<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Api;

use GsppManager\Config\Clock;
use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DashboardController;
use GsppManager\Controller\DomainController;
use GsppManager\Controller\SspController;
use GsppManager\Tests\Integration\IntegrationTestCase;

class DashboardControllerTest extends IntegrationTestCase
{
    private int $catalogId;
    private int $domainId;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->assertGreaterThan(0, $this->catalogId, 'Catalog import failed');

        // Create domain
        $domRes = $this->callController(
            DomainController::class,
            'create',
            ['name' => 'Dashboard-Test-Verbund', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        $this->domainId = (int) ($domRes['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->domainId, 'Domain creation failed');
    }

    protected function tearDown(): void
    {
        Clock::reset();
        parent::tearDown();
    }

    // ── Basic dashboard ──────────────────────────────────────────

    public function test_dashboard_returns_domain_list(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(DashboardController::class, 'index', [], []);

        $this->assertSuccess($res);
        $this->assertArrayHasKey('domains', $res['data']);
        $this->assertArrayHasKey('poam_summary', $res['data']);

        $domain = $this->findDomain($res['data']['domains']);
        $this->assertNotNull($domain, 'Test domain must appear in dashboard');
        $this->assertArrayHasKey('compliance_status', $domain);
        $this->assertArrayHasKey('impl_progress', $domain);
    }

    public function test_compliance_status_is_unknown_before_ssp_generated(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(DashboardController::class, 'index', [], []);

        $this->assertSuccess($res);
        $domain = $this->findDomain($res['data']['domains']);
        $this->assertSame('unknown', $domain['compliance_status']);
    }

    public function test_compliance_status_is_red_with_zero_percent(): void
    {
        // Generate SSP — all implementations start at not_started
        $this->loginAs('isb');
        $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');

        $res = $this->callController(DashboardController::class, 'index', [], []);

        $this->assertSuccess($res);
        $domain = $this->findDomain($res['data']['domains']);
        // 0 / N implemented → red
        $this->assertSame('red', $domain['compliance_status']);
    }

    public function test_compliance_status_turns_green_at_80_percent(): void
    {
        $this->loginAs('isb');
        $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');

        // Manually set 80% of implementations to 'implemented'
        $pdo = $this->db;
        $stmt = $pdo->prepare('
            SELECT i.id FROM implementations i
            JOIN scoped_controls sc ON sc.id = i.scoped_control_id
            WHERE sc.domain_id = ?
            ORDER BY i.id
        ');
        $stmt->execute([$this->domainId]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $count80 = (int) ceil(count($ids) * 0.8);
        $ids80   = array_slice($ids, 0, $count80);

        if (!empty($ids80)) {
            $placeholders = implode(',', array_fill(0, count($ids80), '?'));
            $pdo->prepare("UPDATE implementations SET status = 'implemented' WHERE id IN ({$placeholders})")
                ->execute($ids80);
        }

        $res = $this->callController(DashboardController::class, 'index', [], []);

        $this->assertSuccess($res);
        $domain = $this->findDomain($res['data']['domains']);
        $this->assertSame('green', $domain['compliance_status']);
    }

    // ── Timeline ─────────────────────────────────────────────────

    public function test_timeline_returns_upcoming_poam_items(): void
    {
        $this->loginAs('isb');

        // Generate SSP + assessment plan + POAM items
        $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');

        // Create a POA&M item with deadline in 3 days (bypass controller — direct DB insert for simplicity)
        $soon = date('Y-m-d', strtotime('+3 days'));
        $this->db->prepare('
            INSERT INTO poam_items (domain_id, title, status, priority, deadline, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ')->execute([$this->domainId, 'Test-Maßnahme', 'open', 'medium', $soon]);

        $res = $this->callController(
            DashboardController::class,
            'timeline',
            [],
            ['id' => $this->domainId]
        );

        $this->assertSuccess($res);
        $this->assertNotEmpty($res['data']['items']);

        $item = $res['data']['items'][0];
        $this->assertSame('poam',    $item['type']);
        $this->assertSame($soon,     $item['event_date']);
        $this->assertSame('warning', $item['escalation_status']);
    }

    public function test_timeline_excludes_completed_poam_items(): void
    {
        $this->loginAs('isb');
        $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');

        $soon = date('Y-m-d', strtotime('+5 days'));
        $this->db->prepare('
            INSERT INTO poam_items (domain_id, title, status, priority, deadline, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ')->execute([$this->domainId, 'Erledigte Maßnahme', 'completed', 'low', $soon]);

        $res = $this->callController(
            DashboardController::class,
            'timeline',
            [],
            ['id' => $this->domainId]
        );

        $this->assertSuccess($res);
        $this->assertEmpty($res['data']['items'], 'Completed items must not appear in timeline');
    }

    // ── Role access ──────────────────────────────────────────────

    public function test_management_can_access_dashboard(): void
    {
        $this->loginAs('management');
        $res = $this->callController(DashboardController::class, 'index', [], []);
        $this->assertSuccess($res);
    }

    public function test_management_cannot_create_domain(): void
    {
        $this->loginAs('management');
        $res = $this->callController(
            DomainController::class,
            'create',
            ['name' => 'Forbidden Verbund', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            [],
            'POST'
        );
        $this->assertFailure($res);
        $this->assertSame(403, http_response_code());
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function findDomain(array $domains): ?array
    {
        foreach ($domains as $d) {
            if ((int) $d['id'] === $this->domainId) {
                return $d;
            }
        }
        return null;
    }
}
