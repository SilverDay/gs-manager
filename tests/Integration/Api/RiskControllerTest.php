<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Api;

use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DomainController;
use GsppManager\Controller\RiskController;
use GsppManager\Tests\Integration\IntegrationTestCase;

class RiskControllerTest extends IntegrationTestCase
{
    private int $catalogId;
    private int $domainId;
    private int $scopedControlId;

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
            ['name' => 'Risk-Test-Verbund', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        $this->domainId = (int) ($domRes['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->domainId, 'Domain creation failed');

        // Fetch a scoped control ID for linkage tests
        $scRes = $this->callController(
            DomainController::class,
            'scopedControls',
            [],
            ['id' => $this->domainId],
            'GET'
        );
        $this->scopedControlId = (int) (($scRes['data']['items'][0] ?? [])['id'] ?? 0);
    }

    // ── list ─────────────────────────────────────────────────────────────────

    public function test_list_risks_empty_initially(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(RiskController::class, 'list', [], ['id' => $this->domainId]);

        $this->assertSuccess($res);
        $this->assertEmpty($res['data']['items']);
        $this->assertSame(0, $res['data']['meta']['total']);
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function test_create_risk_success(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            RiskController::class,
            'create',
            [
                'title'      => 'Unbefugter Zugriff',
                'likelihood' => 'high',
                'impact'     => 'high',
                'treatment'  => 'mitigate',
            ],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertSuccess($res);
        $risk = $res['data']['risk'];
        $this->assertSame('Unbefugter Zugriff', $risk['title']);
        // high(4) × high(4) = 16 → high
        $this->assertSame('high', $risk['risk_level']);
    }

    public function test_create_risk_requires_title(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            RiskController::class,
            'create',
            ['likelihood' => 'medium', 'impact' => 'medium'],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertFailure($res);
    }

    public function test_create_risk_accept_treatment_requires_justification(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            RiskController::class,
            'create',
            ['title' => 'Residualrisiko', 'treatment' => 'accept'],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertFailure($res);
        $this->assertStringContainsString('Begründung', $res['error']);
    }

    public function test_create_risk_accept_with_justification_succeeds(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            RiskController::class,
            'create',
            [
                'title'                    => 'Residualrisiko akzeptiert',
                'treatment'                => 'accept',
                'acceptance_justification' => 'Kosten-Nutzen-Abwägung: Massnahme unwirtschaftlich',
            ],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertSuccess($res);
        $this->assertSame('accept', $res['data']['risk']['treatment']);
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_update_risk_recalculates_level(): void
    {
        $this->loginAs('isb');

        // Create with medium/medium → risk_level = medium (3×3=9)
        $createRes = $this->callController(
            RiskController::class,
            'create',
            ['title' => 'Testrisiko', 'likelihood' => 'medium', 'impact' => 'medium'],
            ['id' => $this->domainId],
            'POST'
        );
        $riskId = (int) ($createRes['data']['risk']['id'] ?? 0);
        $this->assertGreaterThan(0, $riskId);
        $this->assertSame('medium', $createRes['data']['risk']['risk_level']);

        // Update to very_high/critical → 5×5=25 → critical
        $updateRes = $this->callController(
            RiskController::class,
            'update',
            ['likelihood' => 'very_high', 'impact' => 'critical'],
            ['riskId' => $riskId],
            'PUT'
        );

        $this->assertSuccess($updateRes);
        $this->assertSame('critical', $updateRes['data']['risk']['risk_level']);
    }

    public function test_update_accept_without_justification_fails(): void
    {
        $this->loginAs('isb');

        $createRes = $this->callController(
            RiskController::class,
            'create',
            ['title' => 'Risiko für Update-Test', 'treatment' => 'mitigate'],
            ['id' => $this->domainId],
            'POST'
        );
        $riskId = (int) ($createRes['data']['risk']['id'] ?? 0);

        $res = $this->callController(
            RiskController::class,
            'update',
            ['treatment' => 'accept', 'acceptance_justification' => ''],
            ['riskId' => $riskId],
            'PUT'
        );

        $this->assertFailure($res);
    }

    // ── control linking ───────────────────────────────────────────────────────

    public function test_link_control_to_risk(): void
    {
        if ($this->scopedControlId === 0) {
            $this->markTestSkipped('No scoped controls in fixture catalog for this domain');
        }

        $this->loginAs('isb');

        $createRes = $this->callController(
            RiskController::class,
            'create',
            ['title' => 'Risiko mit Control-Link'],
            ['id' => $this->domainId],
            'POST'
        );
        $riskId = (int) ($createRes['data']['risk']['id'] ?? 0);

        $linkRes = $this->callController(
            RiskController::class,
            'linkControl',
            ['scoped_control_id' => $this->scopedControlId],
            ['riskId' => $riskId],
            'POST'
        );

        $this->assertSuccess($linkRes);
        $controls = $linkRes['data']['risk']['linked_controls'];
        $this->assertNotEmpty($controls);
        $controlIds = array_column($controls, 'id');
        $this->assertContains($this->scopedControlId, $controlIds);
    }

    // ── heatmap ──────────────────────────────────────────────────────────────

    public function test_heatmap_returns_grouped_data(): void
    {
        $this->loginAs('isb');

        $this->callController(
            RiskController::class,
            'create',
            ['title' => 'Risiko A', 'likelihood' => 'high',   'impact' => 'high'],
            ['id' => $this->domainId],
            'POST'
        );
        $this->callController(
            RiskController::class,
            'create',
            ['title' => 'Risiko B', 'likelihood' => 'medium', 'impact' => 'low'],
            ['id' => $this->domainId],
            'POST'
        );

        $res = $this->callController(
            RiskController::class,
            'heatmap',
            [],
            ['id' => $this->domainId],
            'GET'
        );

        $this->assertSuccess($res);
        $this->assertArrayHasKey('risks', $res['data']);
        $this->assertArrayHasKey('cells', $res['data']);
        $this->assertCount(2, $res['data']['risks']);
        $this->assertArrayHasKey('high|high', $res['data']['cells']);
        $this->assertSame(1, $res['data']['cells']['high|high']);
    }

    // ── role checks ───────────────────────────────────────────────────────────

    public function test_auditor_cannot_create_risk(): void
    {
        $this->loginAs('auditor');
        $res = $this->callController(
            RiskController::class,
            'create',
            ['title' => 'Versuch'],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertFailure($res);
    }

    public function test_management_cannot_create_risk(): void
    {
        $this->loginAs('management');
        $res = $this->callController(
            RiskController::class,
            'create',
            ['title' => 'Versuch'],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertFailure($res);
    }

    public function test_readonly_cannot_create_risk(): void
    {
        $this->loginAs('readonly');
        $res = $this->callController(
            RiskController::class,
            'create',
            ['title' => 'Versuch'],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertFailure($res);
    }

    public function test_auditor_can_read_risks(): void
    {
        $this->loginAs('isb');
        $this->callController(
            RiskController::class,
            'create',
            ['title' => 'Sichtbares Risiko'],
            ['id' => $this->domainId],
            'POST'
        );

        $this->loginAs('auditor');
        $res = $this->callController(RiskController::class, 'list', [], ['id' => $this->domainId]);

        $this->assertSuccess($res);
        $this->assertCount(1, $res['data']['items']);
    }
}
