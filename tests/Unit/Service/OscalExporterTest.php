<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit\Service;

use GsppManager\Service\OscalExporter;
use GsppManager\Tests\Unit\UnitTestCase;
use RuntimeException;

/**
 * Unit tests for OscalExporter helper methods.
 *
 * Database-dependent methods (exportSsp, importSsp, exportAp, exportAr, exportPoam)
 * are covered by integration tests. This file tests purely in-memory logic.
 */
class OscalExporterTest extends UnitTestCase
{
    private OscalExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new OscalExporter();
    }

    // ── deriveSecurityLevel (via reflection) ─────────────────────────────────

    public function test_derive_security_level_returns_moderate_when_no_assets(): void
    {
        $level = $this->callPrivate('deriveSecurityLevel', [[]]);
        $this->assertSame('moderate', $level);
    }

    public function test_derive_security_level_returns_moderate_when_all_normal(): void
    {
        $assets = [
            ['protection_need_c' => 'normal', 'protection_need_i' => 'normal', 'protection_need_a' => 'normal'],
        ];
        $level = $this->callPrivate('deriveSecurityLevel', [$assets]);
        $this->assertSame('moderate', $level);
    }

    public function test_derive_security_level_returns_high_when_any_high_c(): void
    {
        $assets = [
            ['protection_need_c' => 'high', 'protection_need_i' => 'normal', 'protection_need_a' => 'normal'],
        ];
        $level = $this->callPrivate('deriveSecurityLevel', [$assets]);
        $this->assertSame('high', $level);
    }

    public function test_derive_security_level_returns_high_when_any_high_i(): void
    {
        $assets = [
            ['protection_need_c' => 'normal', 'protection_need_i' => 'high', 'protection_need_a' => 'normal'],
        ];
        $level = $this->callPrivate('deriveSecurityLevel', [$assets]);
        $this->assertSame('high', $level);
    }

    public function test_derive_security_level_returns_high_when_any_high_a(): void
    {
        $assets = [
            ['protection_need_c' => 'normal', 'protection_need_i' => 'normal', 'protection_need_a' => 'high'],
        ];
        $level = $this->callPrivate('deriveSecurityLevel', [$assets]);
        $this->assertSame('high', $level);
    }

    public function test_derive_security_level_high_wins_over_multiple_assets(): void
    {
        $assets = [
            ['protection_need_c' => 'normal', 'protection_need_i' => 'normal', 'protection_need_a' => 'normal'],
            ['protection_need_c' => 'normal', 'protection_need_i' => 'high',   'protection_need_a' => 'normal'],
        ];
        $level = $this->callPrivate('deriveSecurityLevel', [$assets]);
        $this->assertSame('high', $level);
    }

    // ── deriveImpactLevel (via reflection) ───────────────────────────────────

    public function test_derive_impact_level_defaults_to_low(): void
    {
        $level = $this->callPrivate('deriveImpactLevel', [[]]);
        $this->assertSame([
            'security-objective-confidentiality' => 'low',
            'security-objective-integrity'       => 'low',
            'security-objective-availability'    => 'low',
        ], $level);
    }

    public function test_derive_impact_level_maps_high_per_dimension(): void
    {
        $assets = [
            ['protection_need_c' => 'high', 'protection_need_i' => 'normal', 'protection_need_a' => 'normal'],
        ];
        $level = $this->callPrivate('deriveImpactLevel', [$assets]);
        $this->assertSame('high',     $level['security-objective-confidentiality']);
        $this->assertSame('moderate', $level['security-objective-integrity']);
        $this->assertSame('moderate', $level['security-objective-availability']);
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    /**
     * Call a private method on $this->exporter via reflection.
     *
     * @param mixed[] $args
     */
    private function callPrivate(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(OscalExporter::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->exporter, $args);
    }
}
