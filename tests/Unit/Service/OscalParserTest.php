<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit\Service;

use GsppManager\Service\OscalParser;
use GsppManager\Tests\Unit\UnitTestCase;
use RuntimeException;

class OscalParserTest extends UnitTestCase
{
    private OscalParser $parser;
    private string $sampleJson;
    private array $parsed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser     = new OscalParser();
        $this->sampleJson = file_get_contents(__DIR__ . '/../../Fixtures/oscal/sample_catalog.json');
        $this->parsed     = $this->parser->parse($this->sampleJson);
    }

    // ── parse ────────────────────────────────────────────────────────────────

    public function test_parse_returns_array_with_catalog_key(): void
    {
        $this->assertIsArray($this->parsed);
        $this->assertArrayHasKey('catalog', $this->parsed);
    }

    public function test_parse_throws_on_invalid_json(): void
    {
        $this->expectException(RuntimeException::class);
        $this->parser->parse('not json at all');
    }

    public function test_parse_throws_when_catalog_key_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->parser->parse('{"not_a_catalog": true}');
    }

    // ── extractMetadata ──────────────────────────────────────────────────────

    public function test_extract_metadata_returns_all_fields(): void
    {
        $meta = $this->parser->extractMetadata($this->parsed);

        $this->assertSame('BSI Grundschutz++ Anwenderkatalog (Test)', $meta['title']);
        $this->assertSame('1.0.0', $meta['version']);
        $this->assertSame('1.1.3', $meta['oscal_version']);
        $this->assertNotEmpty($meta['last_modified']);
    }

    // ── extractGroups ────────────────────────────────────────────────────────

    public function test_extract_groups_returns_all_top_level_groups(): void
    {
        $groups = $this->parser->extractGroups($this->parsed);

        $this->assertCount(2, $groups);
        $this->assertSame('PERS', $groups[0]['id']);
        $this->assertSame('BES', $groups[1]['id']);
    }

    // ── flattenControls ──────────────────────────────────────────────────────

    public function test_flatten_controls_returns_all_controls(): void
    {
        $controls = $this->parser->flattenControls($this->parsed);

        $this->assertCount(3, $controls);
        $ids = array_column($controls, 'id');
        $this->assertContains('PERS.1', $ids);
        $this->assertContains('PERS.1.1', $ids);
        $this->assertContains('BES.1', $ids);
    }

    public function test_flatten_controls_includes_group_context(): void
    {
        $controls = $this->parser->flattenControls($this->parsed);
        $pers1    = array_values(array_filter($controls, fn($c) => $c['id'] === 'PERS.1'))[0];

        $this->assertSame('PERS', $pers1['group_id']);
        $this->assertSame('Personal', $pers1['group_title']);
    }

    public function test_flatten_controls_includes_statement(): void
    {
        $controls = $this->parser->flattenControls($this->parsed);
        $pers1    = array_values(array_filter($controls, fn($c) => $c['id'] === 'PERS.1'))[0];

        $this->assertStringContainsString('Fremdfirmen', $pers1['statement']);
    }

    public function test_flatten_controls_includes_props_map(): void
    {
        $controls = $this->parser->flattenControls($this->parsed);
        $pers1    = array_values(array_filter($controls, fn($c) => $c['id'] === 'PERS.1'))[0];

        $this->assertSame('standard', $pers1['props']['requirement-type']);
        $this->assertSame('PERS.1', $pers1['props']['label']);
    }

    // ── extractStatement ─────────────────────────────────────────────────────

    public function test_extract_statement_returns_prose_text(): void
    {
        $raw       = $this->parsed['catalog']['groups'][0]['controls'][0];
        $statement = $this->parser->extractStatement($raw);

        $this->assertStringContainsString('MUSS', $statement);
        $this->assertStringContainsString('Fremdfirmen', $statement);
    }

    public function test_extract_statement_returns_empty_when_no_statement_part(): void
    {
        $rawControl = ['id' => 'X.1', 'title' => 'Test', 'parts' => [
            ['name' => 'guidance', 'prose' => 'Some guidance'],
        ]];

        $this->assertSame('', $this->parser->extractStatement($rawControl));
    }

    // ── extractProp ──────────────────────────────────────────────────────────

    public function test_extract_prop_returns_value_for_existing_prop(): void
    {
        $raw = $this->parsed['catalog']['groups'][0]['controls'][0];

        $this->assertSame('PERS.1', $this->parser->extractProp($raw, 'label'));
        $this->assertSame('standard', $this->parser->extractProp($raw, 'requirement-type'));
    }

    public function test_extract_prop_returns_null_for_missing_prop(): void
    {
        $raw = $this->parsed['catalog']['groups'][0]['controls'][0];

        $this->assertNull($this->parser->extractProp($raw, 'nonexistent'));
    }

    // ── findControl ──────────────────────────────────────────────────────────

    public function test_find_control_returns_matching_control(): void
    {
        $control = $this->parser->findControl($this->parsed, 'BES.1');

        $this->assertNotNull($control);
        $this->assertSame('BES.1', $control['id']);
        $this->assertSame('Anforderungen an Lieferanten', $control['title']);
    }

    public function test_find_control_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->parser->findControl($this->parsed, 'UNKNOWN.99'));
    }

    // ── computeHash ──────────────────────────────────────────────────────────

    public function test_compute_hash_returns_64_char_sha256(): void
    {
        $hash = $this->parser->computeHash($this->sampleJson);

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_compute_hash_is_deterministic(): void
    {
        $this->assertSame(
            $this->parser->computeHash($this->sampleJson),
            $this->parser->computeHash($this->sampleJson)
        );
    }

    public function test_compute_hash_differs_for_different_input(): void
    {
        $this->assertNotSame(
            $this->parser->computeHash($this->sampleJson),
            $this->parser->computeHash('{}')
        );
    }
}
