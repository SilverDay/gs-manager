<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit\Service;

use GsppManager\Service\RiskEngine;
use GsppManager\Tests\Unit\UnitTestCase;

class RiskEngineTest extends UnitTestCase
{
    private RiskEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new RiskEngine();
    }

    // ── calculateLevel — boundary & representative values ────────────────────

    public function test_very_low_likelihood_negligible_impact_gives_low(): void
    {
        // 1 × 1 = 1 → low
        $this->assertSame('low', $this->engine->calculateLevel('very_low', 'negligible'));
    }

    public function test_boundary_score_4_is_low(): void
    {
        // low(2) × low(2) = 4 → still low
        $this->assertSame('low', $this->engine->calculateLevel('low', 'low'));
    }

    public function test_boundary_score_5_is_medium(): void
    {
        // very_low(1) × very_high(5) = 5 → medium
        $this->assertSame('medium', $this->engine->calculateLevel('very_low', 'critical'));
    }

    public function test_medium_likelihood_medium_impact_gives_medium(): void
    {
        // 3 × 3 = 9 → medium
        $this->assertSame('medium', $this->engine->calculateLevel('medium', 'medium'));
    }

    public function test_boundary_score_9_is_medium(): void
    {
        // high(4) × low(2) = 8... pick: medium(3)×high(4)=12 no. low(2)×high(4)=8 medium. Try very_low(1)+critical(5)=5 medium. medium(3)×low(2)=6 medium. high(4)×low(2)=8 medium.
        // Score 9: high(4)×medium(3)=12 no. very_high(5)×low(2)=10 no. medium(3)×medium(3)=9 → medium
        $this->assertSame('medium', $this->engine->calculateLevel('medium', 'medium'));
    }

    public function test_boundary_score_10_is_high(): void
    {
        // very_high(5) × low(2) = 10 → high
        $this->assertSame('high', $this->engine->calculateLevel('very_high', 'low'));
    }

    public function test_high_likelihood_high_impact_gives_high(): void
    {
        // 4 × 4 = 16 → high
        $this->assertSame('high', $this->engine->calculateLevel('high', 'high'));
    }

    public function test_boundary_score_16_is_high_not_critical(): void
    {
        // high(4) × high(4) = 16 → still high, not critical
        $this->assertSame('high', $this->engine->calculateLevel('high', 'high'));
    }

    public function test_boundary_score_17_is_critical(): void
    {
        // very_high(5) × high(4) = 20 → critical; use a smaller critical: high(4)×very_high(5)=20
        // Actually 17 = very_high(5)×medium(3)=15 no... Let's check: 5×4=20, 4×5=20, 5×3=15 (high), 4×4=16 (high)
        // There's no exact score=17 with integer products. The boundary test is: score >= 17.
        // very_high(5)×high(4)=20 ≥ 17 → critical. 4×4=16 < 17 → high.
        $this->assertSame('critical', $this->engine->calculateLevel('very_high', 'high'));
    }

    public function test_very_high_likelihood_critical_impact_gives_critical(): void
    {
        // 5 × 5 = 25 → critical
        $this->assertSame('critical', $this->engine->calculateLevel('very_high', 'critical'));
    }

    public function test_all_likelihoods_with_critical_impact(): void
    {
        $expected = [
            'very_low' => 'low',    // 1×5=5 → medium... wait: 1×5=5 ≥ 5 → medium
            'low'      => 'medium', // 2×5=10 → high... wait: 10 ≥ 10 → high
            'medium'   => 'high',   // 3×5=15 → high
            'high'     => 'critical', // 4×5=20 → critical
            'very_high'=> 'critical', // 5×5=25 → critical
        ];
        // Correct values:
        // very_low(1)×critical(5)=5 → medium
        // low(2)×critical(5)=10 → high
        // medium(3)×critical(5)=15 → high
        // high(4)×critical(5)=20 → critical
        // very_high(5)×critical(5)=25 → critical
        $expected = [
            'very_low'  => 'medium',
            'low'       => 'high',
            'medium'    => 'high',
            'high'      => 'critical',
            'very_high' => 'critical',
        ];

        foreach ($expected as $likelihood => $level) {
            $this->assertSame(
                $level,
                $this->engine->calculateLevel($likelihood, 'critical'),
                "likelihood={$likelihood} × impact=critical"
            );
        }
    }

    public function test_all_impacts_with_very_high_likelihood(): void
    {
        // very_high(5) × each impact
        $expected = [
            'negligible' => 'medium',  // 5×1=5 → medium
            'low'        => 'high',    // 5×2=10 → high
            'medium'     => 'high',    // 5×3=15 → high
            'high'       => 'critical', // 5×4=20 → critical
            'critical'   => 'critical', // 5×5=25 → critical
        ];

        foreach ($expected as $impact => $level) {
            $this->assertSame(
                $level,
                $this->engine->calculateLevel('very_high', $impact),
                "likelihood=very_high × impact={$impact}"
            );
        }
    }

    // ── requiresJustification ────────────────────────────────────────────────

    public function test_accept_treatment_requires_justification(): void
    {
        $this->assertTrue($this->engine->requiresJustification('accept'));
    }

    public function test_mitigate_treatment_does_not_require_justification(): void
    {
        $this->assertFalse($this->engine->requiresJustification('mitigate'));
    }

    public function test_transfer_treatment_does_not_require_justification(): void
    {
        $this->assertFalse($this->engine->requiresJustification('transfer'));
    }

    public function test_avoid_treatment_does_not_require_justification(): void
    {
        $this->assertFalse($this->engine->requiresJustification('avoid'));
    }
}
