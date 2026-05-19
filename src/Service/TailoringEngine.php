<?php

declare(strict_types=1);

namespace GsppManager\Service;

use GsppManager\Config\Database;
use RuntimeException;

class TailoringEngine
{
    /**
     * Requirement types included per ISMS type.
     * 'standard' → basis + standard
     * 'enhanced' → basis + standard + elevated
     */
    private const ISMS_TYPE_REQUIREMENTS = [
        'standard' => ['basis', 'standard'],
        'enhanced' => ['basis', 'standard', 'elevated'],
    ];

    /**
     * Fetch and filter controls from a catalog by ISMS type.
     *
     * Returns flattened control entries (same shape as OscalParser::flattenControls)
     * filtered to only those whose 'requirement-type' prop matches the ISMS type rules.
     * Controls with no requirement-type prop are included in both types.
     *
     * @return array<int, array>
     */
    public function loadControlsFromCatalog(
        int         $catalogId,
        string      $ismsType,
        OscalParser $parser,
        int         $tenantId = 0
    ): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT oscal_json FROM catalogs WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$catalogId, $tenantId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new RuntimeException("Katalog mit ID {$catalogId} nicht gefunden.");
        }

        $parsed   = $parser->parse($row['oscal_json']);
        $all      = $parser->flattenControls($parsed);
        $allowed  = self::ISMS_TYPE_REQUIREMENTS[$ismsType] ?? self::ISMS_TYPE_REQUIREMENTS['standard'];

        return array_values(array_filter($all, function (array $control) use ($allowed): bool {
            $reqType = $control['props']['requirement-type'] ?? null;
            // Controls with no requirement-type are always included
            if ($reqType === null || $reqType === '') {
                return true;
            }
            return in_array($reqType, $allowed, true);
        }));
    }

    /**
     * Apply tailoring data to a scoped control row (in-place on the array).
     *
     * Tailoring array may contain:
     *   parameters  (array<string,string>) — param id → value
     *   prefix      (string)
     *   suffix      (string)
     *   excluded    (bool)
     *   exclusion_reason (string) — required when excluded = true
     *
     * @throws RuntimeException if excluded=true and no exclusion_reason provided.
     */
    public function applyTailoring(array &$scopedControl, array $tailoring): void
    {
        $excluded = (bool) ($tailoring['excluded'] ?? false);
        $reason   = trim($tailoring['exclusion_reason'] ?? '');

        if ($excluded && $reason === '') {
            throw new RuntimeException(
                'Eine Begründung ist erforderlich, wenn eine Anforderung ausgeschlossen wird.'
            );
        }

        $currentParams  = json_decode($scopedControl['parameters_json'] ?? '{}', true) ?? [];
        $currentTailor  = json_decode($scopedControl['tailoring_json']   ?? '{}', true) ?? [];

        if (!empty($tailoring['parameters'])) {
            foreach ($tailoring['parameters'] as $key => $value) {
                $currentParams[(string) $key] = (string) $value;
            }
        }

        $currentTailor['prefix']           = $tailoring['prefix']  ?? ($currentTailor['prefix']  ?? '');
        $currentTailor['suffix']           = $tailoring['suffix']  ?? ($currentTailor['suffix']  ?? '');
        $currentTailor['excluded']         = $excluded;
        $currentTailor['exclusion_reason'] = $excluded ? $reason : '';

        $scopedControl['parameters_json'] = json_encode($currentParams, JSON_UNESCAPED_UNICODE);
        $scopedControl['tailoring_json']  = json_encode($currentTailor, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Generate an OSCAL 1.1.3 Profile JSON structure from a domain's scoped controls.
     *
     * @param array $domain        Domain row (id, name, isms_type, metadata_json)
     * @param array $scopedControls All scoped control rows for the domain
     * @param array $catalogMeta   ['title', 'version', 'oscal_version', 'source_url']
     * @return array               Ready to json_encode as OSCAL Profile
     */
    public function generateOscalProfile(
        array $domain,
        array $scopedControls,
        array $catalogMeta
    ): array {
        $uuid      = $this->newUuid();
        $now       = gmdate('Y-m-d\TH:i:s\Z');
        $sourceUrl = $catalogMeta['source_url'] ?? 'urn:local:catalog:' . ($catalogMeta['catalog_id'] ?? 'unknown');

        // Split controls into included (not excluded) and excluded
        $included = [];
        $removed  = [];
        $alters   = [];

        foreach ($scopedControls as $sc) {
            $tailoring = json_decode($sc['tailoring_json'] ?? '{}', true) ?? [];
            $params    = json_decode($sc['parameters_json'] ?? '{}', true) ?? [];

            if (!empty($tailoring['excluded'])) {
                $removed[] = ['control-id' => $sc['control_id_str']];
                continue;
            }

            $included[] = ['with-id' => $sc['control_id_str']];

            // Build alterations for non-empty tailoring
            $hasParams = !empty(array_filter($params));
            $hasPrefix = !empty($tailoring['prefix']);
            $hasSuffix = !empty($tailoring['suffix']);

            if ($hasParams || $hasPrefix || $hasSuffix) {
                $alter = ['control-id' => $sc['control_id_str']];

                if ($hasParams) {
                    $alter['set-parameters'] = array_map(
                        fn($id, $val) => ['param-id' => $id, 'values' => [$val]],
                        array_keys($params),
                        array_values($params)
                    );
                }

                if ($hasPrefix || $hasSuffix) {
                    $parts = [];
                    if ($hasPrefix) {
                        $parts[] = ['name' => 'statement', 'prose' => $tailoring['prefix']];
                    }
                    if ($hasSuffix) {
                        $parts[] = ['name' => 'statement', 'prose' => $tailoring['suffix']];
                    }
                    $alter['adds'] = [['position' => 'ending', 'parts' => $parts]];
                }

                $alters[] = $alter;
            }
        }

        $profile = [
            'profile' => [
                'uuid'     => $uuid,
                'metadata' => [
                    'title'          => $domain['name'] . ' — OSCAL Profile',
                    'last-modified'  => $now,
                    'version'        => '1.0.0',
                    'oscal-version'  => '1.1.3',
                ],
                'imports'  => [
                    [
                        'href'            => $sourceUrl,
                        'include-controls' => [
                            ['with-ids' => $included],
                        ],
                    ],
                ],
                'merge'    => ['combine' => ['method' => 'merge'], 'flat' => (object) []],
                'modify'   => [],
            ],
        ];

        if (!empty($alters)) {
            $profile['profile']['modify']['alters'] = $alters;
        }

        if (!empty($removed)) {
            $profile['profile']['imports'][0]['exclude-controls'] = [
                ['with-ids' => array_column($removed, 'control-id')],
            ];
        }

        return $profile;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
