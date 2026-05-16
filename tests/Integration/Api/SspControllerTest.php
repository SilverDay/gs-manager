<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Api;

use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DomainController;
use GsppManager\Controller\SspController;
use GsppManager\Repository\ImplementationRepository;
use GsppManager\Tests\Integration\IntegrationTestCase;

class SspControllerTest extends IntegrationTestCase
{
    private int $catalogId;
    private int $domainId;
    private string $domainName = 'SSP-Test-Verbund';

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
        $this->assertGreaterThan(0, $this->catalogId);

        $domRes = $this->callController(
            DomainController::class,
            'create',
            ['name' => $this->domainName, 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        $this->domainId = (int) ($domRes['data']['domain']['id'] ?? 0);
        $this->assertGreaterThan(0, $this->domainId);
    }

    // ── generate-ssp ─────────────────────────────────────────────────────────

    public function test_generate_ssp_creates_implementations(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            SspController::class,
            'generateSsp',
            [],
            ['id' => $this->domainId],
            'POST'
        );

        $this->assertSuccess($res);
        $this->assertArrayHasKey('total', $res['data']);
        $this->assertArrayHasKey('created', $res['data']);
        $this->assertGreaterThan(0, $res['data']['total']);
        $this->assertGreaterThan(0, $res['data']['created']);
    }

    public function test_generate_ssp_is_idempotent(): void
    {
        $this->loginAs('isb');
        $res1 = $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');
        $res2 = $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');

        $this->assertSuccess($res1);
        $this->assertSuccess($res2);
        $this->assertSame(0, $res2['data']['created'], 'Second call should create 0 new rows');
    }

    public function test_generate_ssp_forbidden_for_auditor(): void
    {
        $this->loginAs('auditor');
        $res = $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');
        $this->assertFailure($res);
    }

    // ── export ────────────────────────────────────────────────────────────────

    public function test_export_returns_valid_oscal_ssp(): void
    {
        // Populate implementations first
        $this->loginAs('isb');
        $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');

        // Capture raw output (export() writes JSON directly)
        $_SESSION['csrf_token']      = 'test-csrf-token';
        ob_start();
        (new SspController())->export(['id' => $this->domainId]);
        $output = ob_get_clean();

        $ssp = json_decode($output, true);
        $this->assertIsArray($ssp);
        $this->assertArrayHasKey('system-security-plan', $ssp);
        $this->assertArrayHasKey('metadata', $ssp['system-security-plan']);
        $this->assertArrayHasKey('control-implementation', $ssp['system-security-plan']);
    }

    public function test_export_filename_matches_ntt_data_convention(): void
    {
        // Verify NTT DATA naming: the SSP title must contain the domain name,
        // and the exported JSON root key must be 'system-security-plan'.
        // (Header inspection is not reliable in CLI/PHPUnit — verify via content instead.)
        $this->loginAs('isb');
        $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');

        ob_start();
        (new SspController())->export(['id' => $this->domainId]);
        $output = ob_get_clean();

        $ssp = json_decode($output, true);
        $this->assertIsArray($ssp, 'Export output must be valid JSON');
        $this->assertArrayHasKey('system-security-plan', $ssp);

        $title = $ssp['system-security-plan']['metadata']['title'] ?? '';
        $this->assertStringContainsString($this->domainName, $title);
    }

    // ── import ────────────────────────────────────────────────────────────────

    public function test_import_updates_existing_implementations(): void
    {
        $this->loginAs('isb');

        // Generate implementations first
        $this->callController(SspController::class, 'generateSsp', [], ['id' => $this->domainId], 'POST');

        // Export to get a valid SSP structure, then modify and re-import
        ob_start();
        (new SspController())->export(['id' => $this->domainId]);
        $exportedJson = ob_get_clean();
        $ssp          = json_decode($exportedJson, true);

        // Change first requirement's status to 'implemented'
        $reqs = &$ssp['system-security-plan']['control-implementation']['implemented-requirements'];
        if (!empty($reqs)) {
            foreach ($reqs[0]['props'] as &$prop) {
                if ($prop['name'] === 'implementation-status') {
                    $prop['value'] = 'implemented';
                }
            }
            unset($prop);
        }

        $modifiedJson = json_encode($ssp);

        // POST the modified SSP as raw body
        $_SESSION['csrf_token']      = 'test-csrf-token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'test-csrf-token';
        $_SERVER['REQUEST_METHOD']   = 'POST';

        // Simulate php://input via tmp file trick isn't possible in CLI;
        // use the repository directly to verify the round-trip logic
        $repo = new ImplementationRepository();

        require_once dirname(__DIR__, 3) . '/src/Service/OscalExporter.php';
        $exporter = new \GsppManager\Service\OscalExporter();
        $updated  = $exporter->importSsp($this->domainId, 1, $ssp, 2);

        $this->assertGreaterThan(0, $updated);

        // Verify the status was actually persisted
        if (!empty($reqs)) {
            $controlId = $reqs[0]['control-id'];
            $row       = $this->db->query(
                "SELECT i.status FROM implementations i
                 JOIN scoped_controls sc ON sc.id = i.scoped_control_id
                 WHERE sc.domain_id = {$this->domainId} AND sc.control_id_str = " .
                $this->db->quote($controlId) . " LIMIT 1"
            )->fetch();
            $this->assertSame('implemented', $row['status'] ?? null);
        }
    }

    public function test_import_rejects_invalid_json(): void
    {
        $this->loginAs('isb');
        $res = $this->callController(
            SspController::class,
            'import',
            [],
            ['id' => $this->domainId],
            'POST'
        );
        // Empty body → error (no SSP JSON received)
        $this->assertFailure($res);
    }

    public function test_import_forbidden_for_auditor(): void
    {
        $this->loginAs('auditor');
        $res = $this->callController(
            SspController::class,
            'import',
            [],
            ['id' => $this->domainId],
            'POST'
        );
        $this->assertFailure($res);
    }
}
