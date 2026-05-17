<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Api;

use GsppManager\Controller\AssessmentController;
use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DomainController;
use GsppManager\Controller\SspController;
use GsppManager\Tests\Integration\IntegrationTestCase;

class AssessmentControllerTest extends IntegrationTestCase
{
    private int $catalogId;
    private int $domainId;

    protected function setUp(): void
    {
        parent::setUp();

        $sampleJson = file_get_contents(__DIR__ . '/../../Fixtures/oscal/sample_catalog.json');

        $this->loginAs('isb');

        $catRes = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $sampleJson],
            httpMethod: 'POST'
        );
        $this->catalogId = (int) ($catRes['data']['catalog']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->catalogId, 'Catalog import failed');

        $domRes = $this->callController(
            DomainController::class,
            'create',
            ['name' => 'Audit-Test-Verbund', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        $this->domainId = (int) ($domRes['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->domainId, 'Domain creation failed');

        // Generate SSP so scoped_controls rows exist
        $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');
    }

    // ── createPlan ───────────────────────────────────────────────────────────

    public function test_create_plan_success(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            AssessmentController::class,
            'createPlan',
            ['title' => 'Erstprüfung 2026'],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertSuccess($res, 'plan');
        $this->assertSame('Erstprüfung 2026', $res['data']['plan']['title']);
        $this->assertSame('draft', $res['data']['plan']['status']);
    }

    public function test_create_plan_requires_title(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            AssessmentController::class,
            'createPlan',
            ['assessor_name' => 'Max Mustermann'],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertFailure($res);
        $this->assertStringContainsString('title', $res['error']);
    }

    public function test_fachverantwortlich_cannot_create_plan(): void
    {
        $this->loginAs('fachverantwortlich');
        $res = $this->callController(
            AssessmentController::class,
            'createPlan',
            ['title' => 'Unerlaubter Plan'],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertFailure($res);
        $this->assertSame(403, http_response_code());
    }

    public function test_management_cannot_create_plan(): void
    {
        $this->loginAs('management');
        $res = $this->callController(
            AssessmentController::class,
            'createPlan',
            ['title' => 'Unerlaubter Plan'],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertFailure($res);
        $this->assertSame(403, http_response_code());
    }

    public function test_auditor_can_create_plan(): void
    {
        $this->loginAs('auditor');
        $res = $this->callController(
            AssessmentController::class,
            'createPlan',
            ['title' => 'Auditor-Plan'],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertSuccess($res, 'plan');
        $this->assertGreaterThan(0, $res['data']['plan']['id']);
    }

    // ── showPlan ─────────────────────────────────────────────────────────────

    public function test_show_plan_returns_summary(): void
    {
        $this->loginAs('isb');
        $planId = $this->createPlan('Prüfplan mit Summary');

        $res = $this->callController(
            AssessmentController::class,
            'showPlan',
            [],
            ['planId' => $planId]
        );

        $this->assertSuccess($res, 'plan');
        $summary = $res['data']['plan']['summary'] ?? null;
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('satisfied', $summary);
        $this->assertArrayHasKey('not_assessed', $summary);
        $this->assertArrayHasKey('total', $summary);
    }

    // ── updatePlan ───────────────────────────────────────────────────────────

    public function test_update_plan_status(): void
    {
        $this->loginAs('isb');
        $planId = $this->createPlan('Status-Test-Plan');

        $res = $this->callController(
            AssessmentController::class,
            'updatePlan',
            ['status' => 'active'],
            ['planId' => $planId],
            'PUT'
        );

        $this->assertSuccess($res, 'plan');
        $this->assertSame('active', $res['data']['plan']['status']);
    }

    // ── listFindings ─────────────────────────────────────────────────────────

    public function test_findings_auto_created_on_list(): void
    {
        $this->loginAs('isb');
        $planId = $this->createPlan('Auto-Create-Plan');

        $res = $this->callController(
            AssessmentController::class,
            'listFindings',
            [],
            ['planId' => $planId]
        );

        $this->assertSuccess($res);
        $this->assertNotEmpty($res['data']['items'], 'Findings should have been auto-created for all scoped controls');
        $this->assertSame($res['data']['meta']['total'], $res['data']['summary']['not_assessed']);
    }

    // ── updateFinding ────────────────────────────────────────────────────────

    public function test_update_finding_result(): void
    {
        $this->loginAs('isb');
        $planId    = $this->createPlan('Finding-Update-Plan');
        $findingId = $this->getFirstFindingId($planId);

        $res = $this->callController(
            AssessmentController::class,
            'updateFinding',
            ['result' => 'satisfied', 'observation' => 'Kontrolle vollständig umgesetzt.'],
            ['findingId' => $findingId],
            'PUT'
        );

        $this->assertSuccess($res, 'finding');
        $this->assertSame('satisfied', $res['data']['finding']['result']);
        $this->assertNotEmpty($res['data']['finding']['assessed_at']);
    }

    public function test_finding_aggregation_counts(): void
    {
        $this->loginAs('isb');
        $planId   = $this->createPlan('Aggregation-Plan');
        $findings = $this->getAllFindingIds($planId);
        $this->assertGreaterThanOrEqual(2, count($findings), 'Need at least 2 scoped controls for this test');

        // Set first finding to satisfied
        $this->callController(
            AssessmentController::class,
            'updateFinding',
            ['result' => 'satisfied'],
            ['findingId' => $findings[0]],
            'PUT'
        );

        // Set second finding to not_satisfied
        $this->callController(
            AssessmentController::class,
            'updateFinding',
            ['result' => 'not_satisfied'],
            ['findingId' => $findings[1]],
            'PUT'
        );

        $res = $this->callController(
            AssessmentController::class,
            'listFindings',
            [],
            ['planId' => $planId]
        );

        $this->assertSuccess($res);
        $summary = $res['data']['summary'];
        $this->assertSame(1, $summary['satisfied']);
        $this->assertSame(1, $summary['not_satisfied']);
        $this->assertSame(count($findings) - 2, $summary['not_assessed']);
    }

    // ── exportAp ─────────────────────────────────────────────────────────────

    public function test_export_ap_returns_valid_oscal(): void
    {
        $this->loginAs('isb');
        $planId = $this->createPlan('AP-Export-Plan');

        ob_start();
        (new AssessmentController())->exportAp(['planId' => $planId]);
        $output = ob_get_clean();

        $ap = json_decode($output, true);
        $this->assertIsArray($ap);
        $this->assertArrayHasKey('assessment-plan', $ap);
        $this->assertArrayHasKey('metadata', $ap['assessment-plan']);
        $this->assertArrayHasKey('reviewed-controls', $ap['assessment-plan']);
    }

    public function test_export_ap_filename_matches_convention(): void
    {
        $this->loginAs('isb');
        $planId = $this->createPlan('AP-Filename-Plan');

        ob_start();
        (new AssessmentController())->exportAp(['planId' => $planId]);
        $output = ob_get_clean();

        // Verify root key (header not inspectable in CLI)
        $ap = json_decode($output, true);
        $this->assertIsArray($ap);
        $this->assertArrayHasKey('assessment-plan', $ap);

        $title = $ap['assessment-plan']['metadata']['title'] ?? '';
        $this->assertStringContainsString('Assessment Plan', $title);
    }

    // ── exportAr ─────────────────────────────────────────────────────────────

    public function test_export_ar_returns_valid_oscal(): void
    {
        $this->loginAs('isb');
        $planId = $this->createPlan('AR-Export-Plan');

        ob_start();
        (new AssessmentController())->exportAr(['planId' => $planId]);
        $output = ob_get_clean();

        $ar = json_decode($output, true);
        $this->assertIsArray($ar);
        $this->assertArrayHasKey('assessment-results', $ar);
        $this->assertArrayHasKey('metadata', $ar['assessment-results']);
        $this->assertArrayHasKey('results', $ar['assessment-results']);
    }

    public function test_export_ar_filename_matches_convention(): void
    {
        $this->loginAs('isb');
        $planId = $this->createPlan('AR-Filename-Plan');

        ob_start();
        (new AssessmentController())->exportAr(['planId' => $planId]);
        $output = ob_get_clean();

        $ar = json_decode($output, true);
        $this->assertIsArray($ar);
        $this->assertArrayHasKey('assessment-results', $ar);

        $title = $ar['assessment-results']['metadata']['title'] ?? '';
        $this->assertStringContainsString('Assessment Results', $title);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createPlan(string $title): int
    {
        $res = $this->callController(
            AssessmentController::class,
            'createPlan',
            ['title' => $title],
            ['id' => $this->domainId],
            'POST'
        );
        $planId = (int) ($res['data']['plan']['id'] ?? 0);
        $this->assertGreaterThan(0, $planId, "Failed to create plan: {$title}");
        return $planId;
    }

    private function getFirstFindingId(int $planId): int
    {
        $res = $this->callController(
            AssessmentController::class,
            'listFindings',
            [],
            ['planId' => $planId]
        );
        $id = (int) (($res['data']['items'][0] ?? [])['id'] ?? 0);
        $this->assertGreaterThan(0, $id, 'No findings found for plan');
        return $id;
    }

    private function getAllFindingIds(int $planId): array
    {
        $res = $this->callController(
            AssessmentController::class,
            'listFindings',
            [],
            ['planId' => $planId]
        );
        return array_column($res['data']['items'] ?? [], 'id');
    }
}
