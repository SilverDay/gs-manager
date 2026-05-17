<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Api;

use GsppManager\Config\Clock;
use GsppManager\Controller\AssessmentController;
use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DomainController;
use GsppManager\Controller\PoamController;
use GsppManager\Controller\SspController;
use GsppManager\Tests\Integration\IntegrationTestCase;

class PoamControllerTest extends IntegrationTestCase
{
    private int $catalogId;
    private int $domainId;
    private int $planId;

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
            ['name' => 'POAM-Test-Verbund', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        $this->domainId = (int) ($domRes['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->domainId, 'Domain creation failed');

        // Generate SSP (creates scoped controls)
        $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');

        // Create assessment plan
        $planRes = $this->callController(
            AssessmentController::class,
            'createPlan',
            ['title' => 'POAM-Test-Plan'],
            ['id' => $this->domainId],
            'POST'
        );
        $this->planId = (int) ($planRes['data']['plan']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->planId, 'Plan creation failed');

        // Auto-create findings + set first two to not_satisfied
        $findingsRes = $this->callController(
            AssessmentController::class,
            'listFindings',
            [],
            ['planId' => $this->planId]
        );
        $findings = $findingsRes['data']['items'] ?? [];
        $this->assertNotEmpty($findings, 'No findings were auto-created');

        foreach (array_slice($findings, 0, 2) as $f) {
            $this->callController(
                AssessmentController::class,
                'updateFinding',
                ['result' => 'not_satisfied', 'observation' => 'Kontrolle nicht umgesetzt.'],
                ['findingId' => $f['id']],
                'PUT'
            );
        }
    }

    protected function tearDown(): void
    {
        Clock::reset();
        parent::tearDown();
    }

    // ── generate ─────────────────────────────────────────────────────────────

    public function test_generate_creates_items_from_not_satisfied_findings(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            PoamController::class,
            'generate',
            ['plan_id' => $this->planId],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertSuccess($res);
        $this->assertSame(2, $res['data']['count']);
        $this->assertCount(2, $res['data']['items']);
    }

    public function test_generate_is_idempotent(): void
    {
        $this->loginAs('isb');

        $res1 = $this->callController(
            PoamController::class,
            'generate',
            ['plan_id' => $this->planId],
            ['id' => $this->domainId],
            'POST'
        );

        $res2 = $this->callController(
            PoamController::class,
            'generate',
            ['plan_id' => $this->planId],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertSuccess($res1);
        $this->assertSuccess($res2);
        $this->assertSame(2, $res1['data']['count']);
        $this->assertSame(0, $res2['data']['count'], 'Second generate must not create duplicates');
        $this->assertCount(2, $res2['data']['items']);
    }

    public function test_auditor_cannot_generate(): void
    {
        $this->loginAs('auditor');
        $res = $this->callController(
            PoamController::class,
            'generate',
            ['plan_id' => $this->planId],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertFailure($res);
        $this->assertSame(403, http_response_code());
    }

    public function test_fachverantwortlich_cannot_generate(): void
    {
        $this->loginAs('fachverantwortlich');
        $res = $this->callController(
            PoamController::class,
            'generate',
            ['plan_id' => $this->planId],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertFailure($res);
        $this->assertSame(403, http_response_code());
    }

    public function test_generate_requires_plan_id(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            PoamController::class,
            'generate',
            [],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertFailure($res);
        $this->assertSame(422, http_response_code());
    }

    // ── list ─────────────────────────────────────────────────────────────────

    public function test_list_returns_items_with_summary(): void
    {
        $this->loginAs('isb');
        $this->generateItems();

        $res = $this->callController(
            PoamController::class,
            'list',
            [],
            ['id' => $this->domainId]
        );

        $this->assertSuccess($res);
        $this->assertNotEmpty($res['data']['items']);
        $summary = $res['data']['summary'];
        $this->assertArrayHasKey('open', $summary);
        $this->assertArrayHasKey('total', $summary);
        $this->assertSame(2, $summary['open']);
    }

    public function test_list_escalation_overdue(): void
    {
        $this->loginAs('isb');
        $this->generateItems();
        $itemId = $this->getFirstItemId();

        // Set deadline to yesterday
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->callController(
            PoamController::class,
            'update',
            ['deadline' => $yesterday],
            ['itemId' => $itemId],
            'PUT'
        );

        // Advance clock to tomorrow so deadline is in the past
        Clock::setNow(strtotime('+1 day'));

        $res = $this->callController(
            PoamController::class,
            'list',
            [],
            ['id' => $this->domainId]
        );

        $this->assertSuccess($res);
        $item = $this->findItemById($res['data']['items'], $itemId);
        $this->assertSame('overdue', $item['escalation_status']);
    }

    public function test_list_escalation_warning(): void
    {
        $this->loginAs('isb');
        $this->generateItems();
        $itemId = $this->getFirstItemId();

        // Set deadline to 3 days from now
        $soon = date('Y-m-d', strtotime('+3 days'));
        $this->callController(
            PoamController::class,
            'update',
            ['deadline' => $soon],
            ['itemId' => $itemId],
            'PUT'
        );

        $res = $this->callController(PoamController::class, 'list', [], ['id' => $this->domainId]);

        $this->assertSuccess($res);
        $item = $this->findItemById($res['data']['items'], $itemId);
        $this->assertSame('warning', $item['escalation_status']);
    }

    public function test_list_escalation_ok(): void
    {
        $this->loginAs('isb');
        $this->generateItems();
        $itemId = $this->getFirstItemId();

        // Set deadline to 30 days from now
        $far = date('Y-m-d', strtotime('+30 days'));
        $this->callController(
            PoamController::class,
            'update',
            ['deadline' => $far],
            ['itemId' => $itemId],
            'PUT'
        );

        $res = $this->callController(PoamController::class, 'list', [], ['id' => $this->domainId]);

        $this->assertSuccess($res);
        $item = $this->findItemById($res['data']['items'], $itemId);
        $this->assertSame('ok', $item['escalation_status']);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_status_to_in_progress(): void
    {
        $this->loginAs('isb');
        $this->generateItems();
        $itemId = $this->getFirstItemId();

        $res = $this->callController(
            PoamController::class,
            'update',
            ['status' => 'in_progress'],
            ['itemId' => $itemId],
            'PUT'
        );

        $this->assertSuccess($res, 'item');
        $this->assertSame('in_progress', $res['data']['item']['status']);
    }

    public function test_accepted_status_requires_justification(): void
    {
        $this->loginAs('isb');
        $this->generateItems();
        $itemId = $this->getFirstItemId();

        $res = $this->callController(
            PoamController::class,
            'update',
            ['status' => 'accepted', 'deviation_justification' => ''],
            ['itemId' => $itemId],
            'PUT'
        );

        $this->assertFailure($res);
        $this->assertSame(422, http_response_code());
    }

    public function test_accepted_status_with_justification_succeeds(): void
    {
        $this->loginAs('isb');
        $this->generateItems();
        $itemId = $this->getFirstItemId();

        $res = $this->callController(
            PoamController::class,
            'update',
            ['status' => 'accepted', 'deviation_justification' => 'Restrisiko bewusst akzeptiert.'],
            ['itemId' => $itemId],
            'PUT'
        );

        $this->assertSuccess($res, 'item');
        $this->assertSame('accepted', $res['data']['item']['status']);
    }

    public function test_auditor_cannot_update(): void
    {
        $this->loginAs('isb');
        $this->generateItems();
        $itemId = $this->getFirstItemId();

        $this->loginAs('auditor');
        $res = $this->callController(
            PoamController::class,
            'update',
            ['status' => 'in_progress'],
            ['itemId' => $itemId],
            'PUT'
        );

        $this->assertFailure($res);
        $this->assertSame(403, http_response_code());
    }

    public function test_fachverantwortlich_cannot_update_others_item(): void
    {
        $this->loginAs('isb');
        $this->generateItems();
        $itemId = $this->getFirstItemId();

        // Item has no responsible_user_id set, so fachverantwortlich (userId=3) cannot edit it
        $this->loginAs('fachverantwortlich');
        $res = $this->callController(
            PoamController::class,
            'update',
            ['status' => 'in_progress'],
            ['itemId' => $itemId],
            'PUT'
        );

        $this->assertFailure($res);
        $this->assertSame(403, http_response_code());
    }

    // ── export ────────────────────────────────────────────────────────────────

    public function test_export_returns_valid_oscal_poam(): void
    {
        $this->loginAs('isb');
        $this->generateItems();

        ob_start();
        (new PoamController())->export(['id' => $this->domainId]);
        $output = ob_get_clean();

        $poam = json_decode($output, true);
        $this->assertIsArray($poam);
        $this->assertArrayHasKey('plan-of-action-and-milestones', $poam);
        $this->assertArrayHasKey('metadata', $poam['plan-of-action-and-milestones']);
        $this->assertArrayHasKey('poam-items', $poam['plan-of-action-and-milestones']);
    }

    public function test_export_title_contains_domain_name(): void
    {
        $this->loginAs('isb');
        $this->generateItems();

        ob_start();
        (new PoamController())->export(['id' => $this->domainId]);
        $output = ob_get_clean();

        $poam  = json_decode($output, true);
        $title = $poam['plan-of-action-and-milestones']['metadata']['title'] ?? '';
        $this->assertStringContainsString('Plan of Action', $title);
        $this->assertStringContainsString('POAM-Test-Verbund', $title);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generateItems(): void
    {
        $this->callController(
            PoamController::class,
            'generate',
            ['plan_id' => $this->planId],
            ['id' => $this->domainId],
            'POST'
        );
    }

    private function getFirstItemId(): int
    {
        $res = $this->callController(
            PoamController::class,
            'list',
            [],
            ['id' => $this->domainId]
        );
        $id = (int) (($res['data']['items'][0] ?? [])['id'] ?? 0);
        $this->assertGreaterThan(0, $id, 'No POA&M items found');
        return $id;
    }

    private function findItemById(array $items, int $id): array
    {
        foreach ($items as $item) {
            if ((int) $item['id'] === $id) {
                return $item;
            }
        }
        $this->fail("Item {$id} not found in response");
    }
}
