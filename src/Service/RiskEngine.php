<?php

declare(strict_types=1);

namespace GsppManager\Service;

class RiskEngine
{
    private const LIKELIHOOD_MAP = [
        'very_low'  => 1,
        'low'       => 2,
        'medium'    => 3,
        'high'      => 4,
        'very_high' => 5,
    ];

    private const IMPACT_MAP = [
        'negligible' => 1,
        'low'        => 2,
        'medium'     => 3,
        'high'       => 4,
        'critical'   => 5,
    ];

    /**
     * Compute risk_level from a 5×5 likelihood × impact matrix.
     *
     * Scores:  1–4 → low | 5–9 → medium | 10–16 → high | 17–25 → critical
     */
    public function calculateLevel(string $likelihood, string $impact): string
    {
        $l     = self::LIKELIHOOD_MAP[$likelihood] ?? 3;
        $i     = self::IMPACT_MAP[$impact]         ?? 3;
        $score = $l * $i;

        return match (true) {
            $score >= 17 => 'critical',
            $score >= 10 => 'high',
            $score >= 5  => 'medium',
            default      => 'low',
        };
    }

    /**
     * Acceptance treatment requires a non-empty justification.
     */
    public function requiresJustification(string $treatment): bool
    {
        return $treatment === 'accept';
    }

    public static function validLikelihoods(): array
    {
        return array_keys(self::LIKELIHOOD_MAP);
    }

    public static function validImpacts(): array
    {
        return array_keys(self::IMPACT_MAP);
    }

    public static function validTreatments(): array
    {
        return ['mitigate', 'accept', 'transfer', 'avoid'];
    }
}
