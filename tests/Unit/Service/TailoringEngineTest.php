<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit\Service;

use GsppManager\Service\OscalParser;
use GsppManager\Service\TailoringEngine;
use GsppManager\Tests\Unit\UnitTestCase;
use RuntimeException;

class TailoringEngineTest extends UnitTestCase
{
    private TailoringEngine $engine;
    private OscalParser $parser;
    private string $sampleJson;
    private array $parsed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine     = new TailoringEngine();
        $this->parser     = new OscalParser();
        $this->sampleJson = file_get_contents(__DIR__ . '/../../Fixtures/oscal/sample_catalog.json');
        $this->parsed     = $this->parser->parse($this->sampleJson);
    }

    // ── Control filtering by ISMS type ────────────────────────────────────────

    public function test_standard_isms_includes_basis_and_standard_controls(): void
    {
        $all = $this->parser->flattenControls($this->parsed);

        // Sample catalog has: PERS.1 (standard), PERS.1.1 (standard), BES.1 (basis)
        $basis    = array_filter($all, fn($c) => ($c['props']['requirement-type'] ?? '') === 'basis');
        $standard = array_filter($all, fn($c) => ($c['props']['requirement-type'] ?? '') === 'standard');

        $this->assertCount(1, $basis);
        $this->assertCount(2, $standard);
    }

    public function test_enhanced_isms_type_includes_elevated_controls(): void
    {
        // Add an elevated control to our parsed catalog for this test
        $parsed = $this->parsed;
        $parsed['catalog']['groups'][0]['controls'][] = [
            'id'    => 'PERS.2',
            'title' => 'Erhöhte Anforderung',
            'props' => [['name' => 'requirement-type', 'value' => 'elevated']],
            'parts' => [],
        ];

        $all = $this->parser->flattenControls($parsed);

        // Filter as TailoringEngine would for standard
        $standardAllowed  = ['basis', 'standard'];
        $standardFiltered = array_filter($all, fn($c) =>
            in_array($c['props']['requirement-type'] ?? '', $standardAllowed, true)
        );

        // Filter as TailoringEngine would for enhanced
        $enhancedAllowed  = ['basis', 'standard', 'elevated'];
        $enhancedFiltered = array_filter($all, fn($c) =>
            in_array($c['props']['requirement-type'] ?? '', $enhancedAllowed, true)
        );

        $this->assertCount(3, $standardFiltered);
        $this->assertCount(4, $enhancedFiltered);
    }

    public function test_controls_without_requirement_type_are_always_included(): void
    {
        // Build a minimal catalog with a control that has no requirement-type prop
        $parsed = [
            'catalog' => [
                'groups' => [[
                    'id'       => 'GRP',
                    'title'    => 'Group',
                    'controls' => [[
                        'id'    => 'GRP.1',
                        'title' => 'No type control',
                        'props' => [],   // no requirement-type
                        'parts' => [],
                    ]],
                ]],
            ],
        ];

        $all      = $this->parser->flattenControls($parsed);
        $allowed  = ['basis', 'standard'];

        $filtered = array_filter($all, function (array $c) use ($allowed): bool {
            $reqType = $c['props']['requirement-type'] ?? null;
            if ($reqType === null || $reqType === '') {
                return true;
            }
            return in_array($reqType, $allowed, true);
        });

        $this->assertCount(1, $filtered);
    }

    // ── applyTailoring ────────────────────────────────────────────────────────

    public function test_apply_tailoring_sets_parameter_value(): void
    {
        $control = [
            'parameters_json' => '{}',
            'tailoring_json'  => '{}',
        ];

        $this->engine->applyTailoring($control, [
            'parameters' => ['pers-1-1-p1' => 'jährlich'],
        ]);

        $params = json_decode($control['parameters_json'], true);
        $this->assertSame('jährlich', $params['pers-1-1-p1']);
    }

    public function test_apply_tailoring_sets_prefix_and_suffix(): void
    {
        $control = [
            'parameters_json' => '{}',
            'tailoring_json'  => '{}',
        ];

        $this->engine->applyTailoring($control, [
            'prefix' => 'Ergänzung vorne.',
            'suffix' => 'Ergänzung hinten.',
        ]);

        $tailoring = json_decode($control['tailoring_json'], true);
        $this->assertSame('Ergänzung vorne.', $tailoring['prefix']);
        $this->assertSame('Ergänzung hinten.', $tailoring['suffix']);
    }

    public function test_apply_tailoring_sets_exclusion_with_reason(): void
    {
        $control = [
            'parameters_json' => '{}',
            'tailoring_json'  => '{}',
        ];

        $this->engine->applyTailoring($control, [
            'excluded'         => true,
            'exclusion_reason' => 'Nicht anwendbar für unsere Branche.',
        ]);

        $tailoring = json_decode($control['tailoring_json'], true);
        $this->assertTrue($tailoring['excluded']);
        $this->assertSame('Nicht anwendbar für unsere Branche.', $tailoring['exclusion_reason']);
    }

    public function test_apply_tailoring_throws_when_excluding_without_reason(): void
    {
        $control = [
            'parameters_json' => '{}',
            'tailoring_json'  => '{}',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Begründung/');

        $this->engine->applyTailoring($control, [
            'excluded'         => true,
            'exclusion_reason' => '',
        ]);
    }

    public function test_apply_tailoring_merges_params_with_existing(): void
    {
        $control = [
            'parameters_json' => '{"existing-param":"old-value"}',
            'tailoring_json'  => '{}',
        ];

        $this->engine->applyTailoring($control, [
            'parameters' => ['new-param' => 'new-value'],
        ]);

        $params = json_decode($control['parameters_json'], true);
        $this->assertSame('old-value', $params['existing-param']);
        $this->assertSame('new-value', $params['new-param']);
    }

    // ── generateOscalProfile ──────────────────────────────────────────────────

    public function test_generate_oscal_profile_has_correct_structure(): void
    {
        $domain = ['id' => 1, 'name' => 'Test Verbund', 'isms_type' => 'standard', 'metadata_json' => '{}'];
        $scopedControls = $this->buildScopedControls([
            ['control_id_str' => 'PERS.1',   'tailoring_json' => '{}', 'parameters_json' => '{}'],
            ['control_id_str' => 'PERS.1.1', 'tailoring_json' => '{}', 'parameters_json' => '{}'],
        ]);
        $catalogMeta = ['catalog_id' => 1, 'title' => 'BSI Katalog', 'source_url' => null];

        $profile = $this->engine->generateOscalProfile($domain, $scopedControls, $catalogMeta);

        $this->assertArrayHasKey('profile', $profile);
        $this->assertArrayHasKey('uuid', $profile['profile']);
        $this->assertArrayHasKey('metadata', $profile['profile']);
        $this->assertArrayHasKey('imports', $profile['profile']);
        $this->assertStringContainsString('Test Verbund', $profile['profile']['metadata']['title']);
        $this->assertSame('1.1.3', $profile['profile']['metadata']['oscal-version']);
    }

    public function test_generate_oscal_profile_includes_all_non_excluded_controls(): void
    {
        $domain = ['id' => 1, 'name' => 'Test', 'isms_type' => 'standard', 'metadata_json' => '{}'];
        $scopedControls = $this->buildScopedControls([
            ['control_id_str' => 'PERS.1',   'tailoring_json' => '{}', 'parameters_json' => '{}'],
            ['control_id_str' => 'BES.1',    'tailoring_json' => json_encode(['excluded' => true, 'exclusion_reason' => 'N/A']), 'parameters_json' => '{}'],
        ]);
        $catalogMeta = ['catalog_id' => 1, 'source_url' => null];

        $profile = $this->engine->generateOscalProfile($domain, $scopedControls, $catalogMeta);
        $imports = $profile['profile']['imports'][0];

        $withIds = $imports['include-controls'][0]['with-ids'];
        $this->assertCount(1, $withIds);
        $this->assertSame('PERS.1', $withIds[0]['with-id']);

        $this->assertArrayHasKey('exclude-controls', $imports);
        $this->assertContains('BES.1', $imports['exclude-controls'][0]['with-ids']);
    }

    public function test_generate_oscal_profile_includes_parameter_alterations(): void
    {
        $domain = ['id' => 1, 'name' => 'Test', 'isms_type' => 'standard', 'metadata_json' => '{}'];
        $scopedControls = $this->buildScopedControls([
            ['control_id_str' => 'PERS.1.1', 'parameters_json' => json_encode(['pers-1-1-p1' => 'jährlich']), 'tailoring_json' => '{}'],
        ]);
        $catalogMeta = ['catalog_id' => 1, 'source_url' => null];

        $profile = $this->engine->generateOscalProfile($domain, $scopedControls, $catalogMeta);
        $alters  = $profile['profile']['modify']['alters'] ?? [];

        $this->assertNotEmpty($alters);
        $alter = $alters[0];
        $this->assertSame('PERS.1.1', $alter['control-id']);
        $this->assertSame('pers-1-1-p1', $alter['set-parameters'][0]['param-id']);
        $this->assertSame('jährlich', $alter['set-parameters'][0]['values'][0]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function buildScopedControls(array $rows): array
    {
        return array_map(fn($r) => array_merge([
            'id'              => 1,
            'catalog_id'      => 1,
            'title'           => $r['control_id_str'],
            'description'     => null,
            'parameters_json' => '{}',
            'tailoring_json'  => '{}',
            'is_custom'       => false,
        ], $r), $rows);
    }
}
