<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Api;

use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DomainController;
use GsppManager\Tests\Integration\IntegrationTestCase;

class DomainControllerTest extends IntegrationTestCase
{
    private string $sampleJson;
    private int $catalogId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sampleJson = file_get_contents(__DIR__ . '/../../Fixtures/oscal/sample_catalog.json');

        // Import a catalog so domains can reference it
        $this->loginAs('isb');
        $res = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $this->sampleJson],
            httpMethod: 'POST'
        );
        $this->catalogId = (int) ($res['data']['catalog']['id'] ?? 0);
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function test_list_returns_empty_for_new_tenant(): void
    {
        $this->loginAs('isb');
        $response = $this->callController(DomainController::class, 'list');

        $this->assertSuccess($response);
        $this->assertSame([], $response['data']['domains']);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_forbidden_for_readonly_role(): void
    {
        $this->loginAs('readonly');
        $response = $this->callController(
            DomainController::class, 'create',
            ['name' => 'Test', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        $this->assertFailure($response);
    }

    public function test_create_requires_name_and_isms_type(): void
    {
        $this->loginAs('isb');
        $response = $this->callController(
            DomainController::class, 'create',
            ['name' => 'Test'],
            httpMethod: 'POST'
        );
        $this->assertFailure($response);
    }

    public function test_create_requires_valid_catalog_id(): void
    {
        $this->loginAs('isb');
        $response = $this->callController(
            DomainController::class, 'create',
            ['name' => 'Test', 'isms_type' => 'standard', 'catalog_id' => 99999],
            httpMethod: 'POST'
        );
        $this->assertFailure($response);
    }

    public function test_create_succeeds_and_auto_loads_controls(): void
    {
        $this->loginAs('isb');
        $response = $this->callController(
            DomainController::class, 'create',
            ['name' => 'Mein Verbund', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );

        $this->assertSuccess($response);
        $domain = $response['data']['domain'];
        $this->assertGreaterThan(0, $domain['id']);
        $this->assertSame('Mein Verbund', $domain['name']);
        $this->assertSame('standard', $domain['isms_type']);
        // Sample catalog: 2 standard + 1 basis = 3 total for standard ISMS
        $this->assertSame(3, $domain['control_count']);
    }

    public function test_create_enhanced_loads_all_controls(): void
    {
        $this->loginAs('isb');
        $response = $this->callController(
            DomainController::class, 'create',
            ['name' => 'Enhanced Verbund', 'isms_type' => 'enhanced', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );

        $this->assertSuccess($response);
        // Sample catalog has no elevated controls, so same count
        $this->assertSame(3, $response['data']['domain']['control_count']);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_domain_with_control_count(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(
            DomainController::class, 'show',
            params: ['id' => (string) $id]
        );

        $this->assertSuccess($response);
        $this->assertSame($id, (int) $response['data']['domain']['id']);
        $this->assertArrayHasKey('control_count', $response['data']['domain']);
    }

    public function test_show_returns_404_for_unknown_domain(): void
    {
        $this->loginAs('isb');
        $response = $this->callController(
            DomainController::class, 'show',
            params: ['id' => '99999']
        );
        $this->assertFailure($response);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_changes_name_and_description(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(
            DomainController::class, 'update',
            ['name' => 'Geänderter Name', 'description' => 'Neue Beschreibung'],
            params: ['id' => (string) $id],
            httpMethod: 'PUT'
        );

        $this->assertSuccess($response);

        $show = $this->callController(DomainController::class, 'show', params: ['id' => (string) $id]);
        $this->assertSame('Geänderter Name', $show['data']['domain']['name']);
    }

    public function test_update_forbidden_for_fachverantwortlich(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $this->loginAs('fachverantwortlich');
        $response = $this->callController(
            DomainController::class, 'update',
            ['name' => 'Hacker'],
            params: ['id' => (string) $id],
            httpMethod: 'PUT'
        );
        $this->assertFailure($response);
    }

    // ── assets ────────────────────────────────────────────────────────────────

    public function test_assets_returns_empty_initially(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(DomainController::class, 'assets', params: ['id' => (string) $id]);
        $this->assertSuccess($response);
        $this->assertSame([], $response['data']['assets']);
    }

    public function test_create_asset_saves_with_protection_needs(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(
            DomainController::class, 'createAsset',
            [
                'name'               => 'Kundendatenbank',
                'asset_type'         => 'it-systeme',
                'protection_need_c'  => 'high',
                'protection_need_i'  => 'high',
                'protection_need_a'  => 'normal',
            ],
            params: ['id' => (string) $id],
            httpMethod: 'POST'
        );

        $this->assertSuccess($response);
        $this->assertGreaterThan(0, $response['data']['asset_id']);

        $assets = $this->callController(DomainController::class, 'assets', params: ['id' => (string) $id]);
        $asset  = $assets['data']['assets'][0];
        $this->assertSame('Kundendatenbank', $asset['name']);
        $this->assertSame('high', $asset['protection_need_c']);
    }

    public function test_create_asset_rejects_invalid_protection_need(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(
            DomainController::class, 'createAsset',
            ['name' => 'Asset', 'protection_need_c' => 'critical'],
            params: ['id' => (string) $id],
            httpMethod: 'POST'
        );
        $this->assertFailure($response);
    }

    // ── processes ─────────────────────────────────────────────────────────────

    public function test_processes_returns_empty_initially(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(DomainController::class, 'processes', params: ['id' => (string) $id]);
        $this->assertSuccess($response);
        $this->assertSame([], $response['data']['processes']);
    }

    public function test_create_process_succeeds(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(
            DomainController::class, 'createProcess',
            ['name' => 'Rechnungsstellung', 'criticality' => 'high'],
            params: ['id' => (string) $id],
            httpMethod: 'POST'
        );

        $this->assertSuccess($response);
        $this->assertGreaterThan(0, $response['data']['process_id']);
    }

    // ── scoped controls ───────────────────────────────────────────────────────

    public function test_scoped_controls_returns_auto_loaded_controls(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(DomainController::class, 'scopedControls', params: ['id' => (string) $id]);

        $this->assertSuccess($response);
        $this->assertSame(3, $response['data']['meta']['total']);
    }

    // ── tailoring ─────────────────────────────────────────────────────────────

    public function test_tailoring_updates_parameter_on_control(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(
            DomainController::class, 'tailoring',
            [
                'control_id_str' => 'PERS.1.1',
                'parameters'     => ['pers-1-1-p1' => 'jährlich'],
            ],
            params: ['id' => (string) $id],
            httpMethod: 'POST'
        );

        $this->assertSuccess($response);

        // Verify the parameter was stored
        $controls = $this->callController(DomainController::class, 'scopedControls', params: ['id' => (string) $id]);
        $pers11   = array_values(array_filter(
            $controls['data']['items'],
            fn($c) => $c['control_id_str'] === 'PERS.1.1'
        ))[0];
        $params = json_decode($pers11['parameters_json'], true);
        $this->assertSame('jährlich', $params['pers-1-1-p1']);
    }

    public function test_tailoring_requires_reason_when_excluding(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(
            DomainController::class, 'tailoring',
            ['control_id_str' => 'PERS.1', 'excluded' => true, 'exclusion_reason' => ''],
            params: ['id' => (string) $id],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
    }

    public function test_tailoring_returns_404_for_unknown_control(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(
            DomainController::class, 'tailoring',
            ['control_id_str' => 'UNKNOWN.99'],
            params: ['id' => (string) $id],
            httpMethod: 'POST'
        );
        $this->assertFailure($response);
    }

    // ── generate profile ──────────────────────────────────────────────────────

    public function test_generate_profile_returns_oscal_profile_json(): void
    {
        $this->loginAs('isb');
        $id = $this->createSampleDomain();

        $response = $this->callController(
            DomainController::class, 'generateProfile',
            params: ['id' => (string) $id],
            httpMethod: 'POST'
        );

        $this->assertSuccess($response);
        $this->assertGreaterThan(0, $response['data']['profile_id']);
        $profile = $response['data']['profile'];
        $this->assertArrayHasKey('profile', $profile);
        $this->assertArrayHasKey('uuid', $profile['profile']);
        $this->assertArrayHasKey('imports', $profile['profile']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function createSampleDomain(): int
    {
        $response = $this->callController(
            DomainController::class, 'create',
            ['name' => 'Test Verbund', 'isms_type' => 'standard', 'catalog_id' => $this->catalogId],
            httpMethod: 'POST'
        );
        return (int) ($response['data']['domain']['id'] ?? 0);
    }
}
