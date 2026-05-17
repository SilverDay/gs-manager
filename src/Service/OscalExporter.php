<?php

declare(strict_types=1);

namespace GsppManager\Service;

use GsppManager\Config\Database;
use GsppManager\Repository\DomainRepository;
use GsppManager\Repository\ImplementationRepository;
use RuntimeException;

class OscalExporter
{
    /** Maps DB status values to OSCAL implementation-status values */
    private const STATUS_TO_OSCAL = [
        'implemented'    => 'implemented',
        'partial'        => 'partial',
        'planned'        => 'planned',
        'not_applicable' => 'not-applicable',
        'not_started'    => 'not-implemented',
    ];

    /** Maps OSCAL implementation-status values to DB status values */
    private const OSCAL_TO_STATUS = [
        'implemented'     => 'implemented',
        'partial'         => 'partial',
        'planned'         => 'planned',
        'not-applicable'  => 'not_applicable',
        'not-implemented' => 'not_started',
    ];

    private DomainRepository $domainRepo;
    private ImplementationRepository $implRepo;

    public function __construct()
    {
        $this->domainRepo = new DomainRepository();
        $this->implRepo   = new ImplementationRepository();
    }

    /**
     * Build a full OSCAL 1.1.3 SSP JSON structure for the given domain.
     *
     * @throws RuntimeException if the domain is not found.
     */
    public function exportSsp(int $domainId, int $tenantId): array
    {
        $domain = $this->domainRepo->findByIdAndTenant($domainId, $tenantId);
        if ($domain === null) {
            throw new RuntimeException("Informationsverbund {$domainId} nicht gefunden.");
        }

        $impls = $this->implRepo->findAllByDomain($domainId, $tenantId);
        $now   = gmdate('Y-m-d\TH:i:s\Z');
        $uuid  = $this->newUuid();

        $implementedRequirements = [];
        foreach ($impls as $impl) {
            $oscalStatus = self::STATUS_TO_OSCAL[$impl['status'] ?? 'not_started'] ?? 'not-implemented';

            $req = [
                'uuid'       => $this->newUuid(),
                'control-id' => $impl['control_id_str'],
                'set-parameters' => [],
                'statements' => [],
                'props' => [
                    [
                        'name'  => 'implementation-status',
                        'ns'    => 'https://gspp-manager.de/ns/oscal',
                        'value' => $oscalStatus,
                    ],
                ],
            ];

            if ($impl['maturity_level'] !== null && (int) $impl['maturity_level'] > 0) {
                $req['props'][] = [
                    'name'  => 'maturity-level',
                    'ns'    => 'https://gspp-manager.de/ns/oscal',
                    'value' => (string) $impl['maturity_level'],
                ];
            }

            if (!empty($impl['description'])) {
                $req['statements'][] = [
                    'statement-id' => $impl['control_id_str'] . '_smt',
                    'uuid'         => $this->newUuid(),
                    'description'  => $impl['description'],
                ];
            }

            if (!empty($impl['parameters_json'])) {
                $params = json_decode($impl['parameters_json'], true) ?? [];
                foreach ($params as $paramId => $value) {
                    $req['set-parameters'][] = [
                        'param-id' => $paramId,
                        'values'   => [(string) $value],
                    ];
                }
            }

            // Remove empty arrays to keep JSON clean
            if (empty($req['set-parameters'])) {
                unset($req['set-parameters']);
            }
            if (empty($req['statements'])) {
                unset($req['statements']);
            }

            $implementedRequirements[] = $req;
        }

        $metadata = json_decode($domain['metadata_json'] ?? '{}', true) ?? [];

        return [
            'system-security-plan' => [
                'uuid'     => $uuid,
                'metadata' => [
                    'title'         => $domain['name'] . ' — System Security Plan',
                    'last-modified' => $now,
                    'version'       => '1.0.0',
                    'oscal-version' => '1.1.3',
                    'remarks'       => 'Generiert durch GS++ KMU Compliance Manager',
                ],
                'import-profile' => [
                    'href' => 'urn:gspp-manager:domain:' . $domainId . ':profile',
                ],
                'system-characteristics' => [
                    'system-name'             => $domain['name'],
                    'description'             => $domain['description'] ?? '',
                    'security-sensitivity-level' => 'moderate',
                    'system-information'      => [
                        'information-types' => [
                            [
                                'uuid'        => $this->newUuid(),
                                'title'       => 'Geschäftsinformationen',
                                'description' => 'Vom ISMS erfasste Informationen',
                            ],
                        ],
                    ],
                    'security-impact-level' => [
                        'security-objective-confidentiality' => 'moderate',
                        'security-objective-integrity'       => 'moderate',
                        'security-objective-availability'    => 'moderate',
                    ],
                    'status'            => ['state' => 'operational'],
                    'authorization-boundary' => [
                        'description' => $metadata['scope'] ?? $domain['name'],
                    ],
                ],
                'system-implementation' => [
                    'users'      => [
                        [
                            'uuid'        => $this->newUuid(),
                            'title'       => 'ISMS-Verantwortlicher',
                            'role-ids'    => ['isb'],
                        ],
                    ],
                    'components' => [
                        [
                            'uuid'        => $this->newUuid(),
                            'type'        => 'this-system',
                            'title'       => $domain['name'],
                            'description' => $domain['description'] ?? '',
                            'status'      => ['state' => 'operational'],
                        ],
                    ],
                ],
                'control-implementation' => [
                    'description'              => 'Umsetzungsnachweis der Grundschutz++ Anforderungen',
                    'implemented-requirements' => $implementedRequirements,
                ],
            ],
        ];
    }

    /**
     * Parse an OSCAL SSP JSON and upsert implementation rows.
     * Matches by control-id string.
     *
     * @return int Number of implementation rows updated.
     * @throws RuntimeException if the domain is not found or JSON is invalid.
     */
    public function importSsp(int $domainId, int $tenantId, array $sspJson, int $userId): int
    {
        $domain = $this->domainRepo->findByIdAndTenant($domainId, $tenantId);
        if ($domain === null) {
            throw new RuntimeException("Informationsverbund {$domainId} nicht gefunden.");
        }

        $ssp = $sspJson['system-security-plan'] ?? null;
        if ($ssp === null) {
            throw new RuntimeException('Ungültiges SSP-JSON: system-security-plan fehlt.');
        }

        $requirements = $ssp['control-implementation']['implemented-requirements'] ?? [];
        if (empty($requirements)) {
            return 0;
        }

        // Build map: control_id_str → implementation_id
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT sc.control_id_str, i.id AS impl_id
            FROM implementations i
            JOIN scoped_controls sc ON sc.id = i.scoped_control_id
            JOIN information_domains d ON d.id = sc.domain_id
            WHERE d.id = ? AND d.tenant_id = ?
        ");
        $stmt->execute([$domainId, $tenantId]);
        $controlMap = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $controlMap[$row['control_id_str']] = (int) $row['impl_id'];
        }

        $implRepo = $this->implRepo;
        $updated  = 0;

        foreach ($requirements as $req) {
            $controlId = $req['control-id'] ?? null;
            if ($controlId === null || !isset($controlMap[$controlId])) {
                continue;
            }

            $implId = $controlMap[$controlId];
            $fields = [];

            // Extract status from props
            foreach ($req['props'] ?? [] as $prop) {
                if ($prop['name'] === 'implementation-status') {
                    $fields['status'] = self::OSCAL_TO_STATUS[$prop['value']] ?? 'not_started';
                }
                if ($prop['name'] === 'maturity-level') {
                    $fields['maturity_level'] = (int) $prop['value'];
                }
            }

            // Extract description from first statement
            foreach ($req['statements'] ?? [] as $stmt2) {
                if (!empty($stmt2['description'])) {
                    $fields['description'] = $stmt2['description'];
                    break;
                }
            }

            if (!empty($fields)) {
                $implRepo->update($implId, $tenantId, $fields, $userId);
                $updated++;
            }
        }

        return $updated;
    }

    /** Maps DB finding result values to OSCAL state values */
    private const RESULT_TO_OSCAL = [
        'satisfied'     => 'satisfied',
        'not_satisfied' => 'not-satisfied',
        'partial'       => 'not-satisfied',
        'not_assessed'  => 'not-reviewed',
    ];

    /**
     * Build a full OSCAL 1.1.3 Assessment Plan JSON structure.
     *
     * @throws RuntimeException if the plan is not found.
     */
    public function exportAp(int $planId, int $tenantId): array
    {
        $pdo = Database::getConnection();

        $planStmt = $pdo->prepare("
            SELECT ap.*, d.name AS domain_name, d.id AS domain_id
            FROM assessment_plans ap
            JOIN information_domains d ON d.id = ap.domain_id AND d.tenant_id = ?
            WHERE ap.id = ?
        ");
        $planStmt->execute([$tenantId, $planId]);
        $plan = $planStmt->fetch(\PDO::FETCH_ASSOC);
        if ($plan === false) {
            throw new RuntimeException("Assessment Plan {$planId} nicht gefunden.");
        }

        $ctrlStmt = $pdo->prepare("
            SELECT control_id_str FROM scoped_controls
            WHERE domain_id = ?
            ORDER BY control_id_str
        ");
        $ctrlStmt->execute([(int) $plan['domain_id']]);
        $controlIds = $ctrlStmt->fetchAll(\PDO::FETCH_COLUMN);

        $now         = gmdate('Y-m-d\TH:i:s\Z');
        $partyUuid   = $this->newUuid();
        $assessorOrg = $plan['assessor_org'] ?? ($plan['assessor_name'] ?? 'Unbekannte Organisation');

        $includeControls = array_map(
            fn(string $id) => ['control-id' => $id],
            $controlIds
        );

        $task = [
            'uuid'                  => $this->newUuid(),
            'type'                  => 'action',
            'title'                 => $plan['title'],
            'associated-activities' => [],
        ];

        if (!empty($plan['period_start']) || !empty($plan['period_end'])) {
            $task['timing'] = [
                'within-date-range' => array_filter([
                    'start' => $plan['period_start'] ?? null,
                    'end'   => $plan['period_end']   ?? null,
                ]),
            ];
        }

        return [
            'assessment-plan' => [
                'uuid'     => $this->newUuid(),
                'metadata' => [
                    'title'         => $plan['title'] . ' — Assessment Plan',
                    'last-modified' => $now,
                    'version'       => '1.0.0',
                    'oscal-version' => '1.1.3',
                    'parties'       => [
                        [
                            'uuid' => $partyUuid,
                            'type' => 'organization',
                            'name' => $assessorOrg,
                        ],
                    ],
                    'responsible-parties' => [
                        [
                            'role-id'     => 'assessor',
                            'party-uuids' => [$partyUuid],
                        ],
                    ],
                ],
                'import-ssp' => [
                    'href' => 'urn:gspp-manager:domain:' . $plan['domain_id'] . ':ssp',
                ],
                'reviewed-controls' => [
                    'control-selections' => [
                        ['include-controls' => $includeControls],
                    ],
                ],
                'assessment-subjects' => [
                    ['type' => 'component', 'include-all' => new \stdClass()],
                ],
                'tasks' => [$task],
            ],
        ];
    }

    /**
     * Build a full OSCAL 1.1.3 Assessment Results JSON structure.
     * Only includes findings where result != 'not_assessed'.
     *
     * @throws RuntimeException if the plan is not found.
     */
    public function exportAr(int $planId, int $tenantId): array
    {
        $pdo = Database::getConnection();

        $planStmt = $pdo->prepare("
            SELECT ap.*, d.name AS domain_name, d.id AS domain_id
            FROM assessment_plans ap
            JOIN information_domains d ON d.id = ap.domain_id AND d.tenant_id = ?
            WHERE ap.id = ?
        ");
        $planStmt->execute([$tenantId, $planId]);
        $plan = $planStmt->fetch(\PDO::FETCH_ASSOC);
        if ($plan === false) {
            throw new RuntimeException("Assessment Plan {$planId} nicht gefunden.");
        }

        $findingStmt = $pdo->prepare("
            SELECT af.id, af.result, af.observation, af.risk_statement, af.method,
                   sc.control_id_str, sc.title AS control_title
            FROM assessment_findings af
            JOIN scoped_controls sc ON sc.id = af.scoped_control_id
            WHERE af.plan_id = ? AND af.result != 'not_assessed'
            ORDER BY sc.control_id_str
        ");
        $findingStmt->execute([$planId]);
        $dbFindings = $findingStmt->fetchAll(\PDO::FETCH_ASSOC);

        $ctrlStmt = $pdo->prepare("
            SELECT control_id_str FROM scoped_controls
            WHERE domain_id = ?
            ORDER BY control_id_str
        ");
        $ctrlStmt->execute([(int) $plan['domain_id']]);
        $controlIds = $ctrlStmt->fetchAll(\PDO::FETCH_COLUMN);

        $now       = gmdate('Y-m-d\TH:i:s\Z');
        $partyUuid = $this->newUuid();
        $assessorOrg = $plan['assessor_org'] ?? ($plan['assessor_name'] ?? 'Unbekannte Organisation');

        $includeControls = array_map(
            fn(string $id) => ['control-id' => $id],
            $controlIds
        );

        $observations = [];
        $oscalFindings = [];

        foreach ($dbFindings as $f) {
            $obsUuid  = $this->newUuid();
            $oscalState = self::RESULT_TO_OSCAL[$f['result']] ?? 'not-reviewed';

            $observation = [
                'uuid'        => $obsUuid,
                'title'       => $f['control_id_str'] . ' — Beobachtung',
                'description' => $f['observation'] ?? '',
                'methods'     => $this->mapMethods($f['method'] ?? ''),
            ];

            if (!empty($f['risk_statement'])) {
                $observation['remarks'] = $f['risk_statement'];
            }

            $observations[] = $observation;

            $finding = [
                'uuid'        => $this->newUuid(),
                'title'       => $f['control_id_str'] . ' — ' . $f['result'],
                'description' => $f['observation'] ?? '',
                'target'      => [
                    'type'      => 'statement-id',
                    'target-id' => $f['control_id_str'] . '_smt',
                    'status'    => ['state' => $oscalState],
                ],
                'related-observations' => [['observation-uuid' => $obsUuid]],
            ];

            if ($f['result'] === 'partial') {
                $finding['target']['status']['remarks'] = 'Teilweise erfüllt';
            }

            $oscalFindings[] = $finding;
        }

        $result = [
            'uuid'  => $this->newUuid(),
            'title' => $plan['title'] . ' — Results',
            'reviewed-controls' => [
                'control-selections' => [
                    ['include-controls' => $includeControls],
                ],
            ],
            'findings' => $oscalFindings,
        ];

        if (!empty($plan['period_start'])) {
            $result['start'] = $plan['period_start'];
        }
        if (!empty($plan['period_end'])) {
            $result['end'] = $plan['period_end'];
        }
        if (!empty($observations)) {
            $result['observations'] = $observations;
        }

        return [
            'assessment-results' => [
                'uuid'     => $this->newUuid(),
                'metadata' => [
                    'title'         => $plan['title'] . ' — Assessment Results',
                    'last-modified' => $now,
                    'version'       => '1.0.0',
                    'oscal-version' => '1.1.3',
                    'parties'       => [
                        [
                            'uuid' => $partyUuid,
                            'type' => 'organization',
                            'name' => $assessorOrg,
                        ],
                    ],
                    'responsible-parties' => [
                        [
                            'role-id'     => 'assessor',
                            'party-uuids' => [$partyUuid],
                        ],
                    ],
                ],
                'import-ap' => [
                    'href' => 'urn:gspp-manager:plan:' . $planId . ':ap',
                ],
                'results' => [$result],
            ],
        ];
    }

    private function mapMethods(string $methodStr): array
    {
        $map = [
            'examine'   => 'EXAMINE',
            'interview' => 'INTERVIEW',
            'test'      => 'TEST',
        ];
        $methods = [];
        foreach (explode(',', $methodStr) as $m) {
            $m = trim($m);
            if (isset($map[$m])) {
                $methods[] = $map[$m];
            }
        }
        return $methods ?: ['EXAMINE'];
    }

    private function newUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
