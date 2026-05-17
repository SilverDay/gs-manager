<?php

declare(strict_types=1);

namespace GsppManager\Tests\Integration\Api;

use GsppManager\Controller\CatalogController;
use GsppManager\Tests\Integration\IntegrationTestCase;

class CatalogControllerTest extends IntegrationTestCase
{
    private string $sampleJson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sampleJson = file_get_contents(__DIR__ . '/../../Fixtures/oscal/sample_catalog.json');
    }

    // ── list ─────────────────────────────────────────────────────────────────

    public function test_list_returns_empty_array_when_no_catalogs(): void
    {
        $this->loginAs('isb');

        $response = $this->callController(CatalogController::class, 'list');

        $this->assertSuccess($response);
        $this->assertSame([], $response['data']['catalogs']);
    }

    // ── import ───────────────────────────────────────────────────────────────

    public function test_import_requires_auth(): void
    {
        $response = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $this->sampleJson],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
    }

    public function test_import_forbidden_for_readonly_role(): void
    {
        $this->loginAs('readonly');

        $response = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $this->sampleJson],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
    }

    public function test_import_json_succeeds_for_isb(): void
    {
        $this->loginAs('isb');

        $response = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $this->sampleJson],
            httpMethod: 'POST'
        );

        $this->assertSuccess($response);
        $catalog = $response['data']['catalog'];
        $this->assertGreaterThan(0, $catalog['id']);
        $this->assertSame('BSI Grundschutz++ Anwenderkatalog (Test)', $catalog['name']);
        $this->assertSame(3, $catalog['control_count']);
    }

    public function test_import_json_succeeds_for_admin(): void
    {
        $this->loginAs('admin');

        $response = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $this->sampleJson],
            httpMethod: 'POST'
        );

        $this->assertSuccess($response);
    }

    public function test_import_uses_custom_name_when_provided(): void
    {
        $this->loginAs('isb');

        $response = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $this->sampleJson, 'name' => 'Mein Katalog'],
            httpMethod: 'POST'
        );

        $this->assertSuccess($response);
        $this->assertSame('Mein Katalog', $response['data']['catalog']['name']);
    }

    public function test_import_returns_422_for_invalid_json(): void
    {
        $this->loginAs('isb');

        $response = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => 'not valid json'],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
    }

    public function test_import_returns_422_for_missing_source(): void
    {
        $this->loginAs('isb');

        $response = $this->callController(
            CatalogController::class,
            'import',
            ['json' => $this->sampleJson],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
    }

    public function test_import_rejects_untrusted_url_sources(): void
    {
        $this->loginAs('isb');

        $response = $this->callController(
            CatalogController::class,
            'import',
            [
                'source' => 'url',
                'url'    => 'https://example.com/catalog.json',
            ],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
        $this->assertStringContainsString('vertrauenswürdige Katalogquelle', $response['error']);
    }

    public function test_import_persists_catalog_in_db(): void
    {
        $this->loginAs('isb');

        $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $this->sampleJson],
            httpMethod: 'POST'
        );

        $listResponse = $this->callController(CatalogController::class, 'list');
        $this->assertCount(1, $listResponse['data']['catalogs']);
    }

    // ── controls ─────────────────────────────────────────────────────────────

    public function test_controls_lists_all_controls_with_pagination(): void
    {
        $this->loginAs('isb');
        $id = $this->importSampleCatalog();

        $response = $this->callController(
            CatalogController::class,
            'controls',
            params: ['id' => (string) $id]
        );

        $this->assertSuccess($response);
        $this->assertSame(3, $response['data']['meta']['total']);
        $this->assertCount(3, $response['data']['items']);
    }

    public function test_controls_filters_by_search_term(): void
    {
        $this->loginAs('isb');
        $id = $this->importSampleCatalog();

        $_GET['search'] = 'Fremdfirmen';
        $response = $this->callController(
            CatalogController::class,
            'controls',
            params: ['id' => (string) $id]
        );
        unset($_GET['search']);

        $this->assertSuccess($response);
        $this->assertSame(2, $response['data']['meta']['total']);
    }

    public function test_controls_filters_by_group_id(): void
    {
        $this->loginAs('isb');
        $id = $this->importSampleCatalog();

        $_GET['group_id'] = 'BES';
        $response = $this->callController(
            CatalogController::class,
            'controls',
            params: ['id' => (string) $id]
        );
        unset($_GET['group_id']);

        $this->assertSuccess($response);
        $this->assertSame(1, $response['data']['meta']['total']);
        $this->assertSame('BES.1', $response['data']['items'][0]['id']);
    }

    public function test_controls_returns_404_for_unknown_catalog(): void
    {
        $this->loginAs('isb');

        $response = $this->callController(
            CatalogController::class,
            'controls',
            params: ['id' => '99999']
        );

        $this->assertFailure($response);
        $this->assertStringContainsString('nicht gefunden', strtolower($response['error']));
    }

    // ── control (single) ─────────────────────────────────────────────────────

    public function test_control_returns_single_control_by_id(): void
    {
        $this->loginAs('isb');
        $id = $this->importSampleCatalog();

        $response = $this->callController(
            CatalogController::class,
            'control',
            params: ['id' => (string) $id, 'controlId' => 'PERS.1.1']
        );

        $this->assertSuccess($response);
        $control = $response['data']['control'];
        $this->assertSame('PERS.1.1', $control['id']);
        $this->assertSame('Auswahl geeigneter Fremdfirmen', $control['title']);
        $this->assertStringContainsString('MUSS', $control['statement']);
    }

    public function test_control_returns_404_for_unknown_control_id(): void
    {
        $this->loginAs('isb');
        $id = $this->importSampleCatalog();

        $response = $this->callController(
            CatalogController::class,
            'control',
            params: ['id' => (string) $id, 'controlId' => 'UNKNOWN.99']
        );

        $this->assertFailure($response);
    }

    // ── check-update ──────────────────────────────────────────────────────────

    public function test_check_update_returns_422_when_no_source_url(): void
    {
        $this->loginAs('isb');
        $id = $this->importSampleCatalog();

        $response = $this->callController(
            CatalogController::class,
            'checkUpdate',
            params: ['id' => (string) $id],
            httpMethod: 'POST'
        );

        $this->assertFailure($response);
        $this->assertStringContainsString('keine Quell-URL', $response['error']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function importSampleCatalog(): int
    {
        $response = $this->callController(
            CatalogController::class,
            'import',
            ['source' => 'json', 'json' => $this->sampleJson],
            httpMethod: 'POST'
        );

        return (int) $response['data']['catalog']['id'];
    }
}
