<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Middleware\AuditLogger;
use GsppManager\Middleware\AuthMiddleware;
use GsppManager\Repository\CatalogRepository;
use GsppManager\Service\MappingService;
use GsppManager\Service\OscalParser;
use RuntimeException;

class CatalogController extends BaseController
{
    private CatalogRepository $repo;
    private OscalParser $parser;
    private MappingService $mappingService;

    public function __construct()
    {
        $this->repo           = new CatalogRepository();
        $this->parser         = new OscalParser();
        $this->mappingService = new MappingService();
    }

    // ── GET /api/catalogs ────────────────────────────────────────────────────

    public function list(array $params): void
    {
        $catalogs = $this->repo->findAllByTenant($this->tenantId());
        $this->json(['catalogs' => $catalogs]);
    }

    // ── GET /api/catalogs/library ─────────────────────────────────────────────

    /**
     * Return a curated list of known OSCAL catalog sources for one-click import.
     * Update the KNOWN_SOURCES list below when new catalogs become available.
     */
    public function library(array $params): void
    {
        $sources = $this->trustedCatalogSources();

        $this->json(['sources' => $sources]);
    }

    // ── POST /api/catalogs/import ─────────────────────────────────────────────

    public function import(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb'])) {
            return;
        }

        $body = $this->requestBody();

        $source = $body['source'] ?? '';
        if (!in_array($source, ['url', 'json'], true)) {
            $this->error('source muss "url" oder "json" sein.', 422);
            return;
        }

        if ($source === 'url') {
            $url = trim($body['url'] ?? '');
            if ($url === '') {
                $this->error('url ist erforderlich.', 422);
                return;
            }

            $trustedUrl = $this->resolveTrustedCatalogUrl($url);
            if ($trustedUrl === null) {
                $this->error('Die angegebene URL ist nicht als vertrauenswürdige Katalogquelle freigegeben.', 422);
                return;
            }

            $rawJson = $this->fetchUrl($trustedUrl);
            if ($rawJson === null) {
                $this->error('Katalog konnte von der angegebenen URL nicht geladen werden.', 422);
                return;
            }
        } else {
            $rawJson = $body['json'] ?? '';
            if ($rawJson === '') {
                $this->error('json ist erforderlich.', 422);
                return;
            }
            if (strlen($rawJson) > 20 * 1024 * 1024) {
                $this->error('JSON-Katalog überschreitet die maximale Größe von 20 MB.', 413);
                return;
            }
        }

        try {
            $parsed = $this->parser->parse($rawJson);
        } catch (RuntimeException $e) {
            $this->error('Ungültiger OSCAL-Katalog: ' . $e->getMessage(), 422);
            return;
        }

        $meta        = $this->parser->extractMetadata($parsed);
        $name        = trim($body['name'] ?? '') ?: $meta['title'];
        $versionHash = $this->parser->computeHash($rawJson);
        $sourceUrl   = $source === 'url' ? ($body['url'] ?? null) : null;

        $id = $this->repo->create(
            $this->tenantId(),
            $name,
            $sourceUrl,
            $rawJson,
            $versionHash
        );

        AuditLogger::log('create', 'catalogs', $id);

        $controls = $this->parser->flattenControls($parsed);

        $this->json([
            'catalog' => [
                'id'            => $id,
                'name'          => $name,
                'source_url'    => $sourceUrl,
                'version_hash'  => $versionHash,
                'control_count' => count($controls),
                'metadata'      => $meta,
            ],
        ], 201);
    }

    // ── GET /api/catalogs/{id}/controls ──────────────────────────────────────

    public function controls(array $params): void
    {
        $row = $this->resolveCatalog($params);
        if ($row === null) {
            return;
        }

        $parsed   = $this->parser->parse($row['oscal_json']);
        $all      = $this->parser->flattenControls($parsed);

        // Filtering
        $search  = strtolower(trim($this->queryParam('search', '')));
        $groupId = trim($this->queryParam('group_id', ''));

        if ($search !== '') {
            $all = array_filter(
                $all,
                fn(array $c) =>
                str_contains(strtolower($c['id']), $search)
                    || str_contains(strtolower($c['title']), $search)
                    || str_contains(strtolower($c['statement']), $search)
            );
            $all = array_values($all);
        }

        if ($groupId !== '') {
            $all = array_filter($all, fn(array $c) => $c['group_id'] === $groupId);
            $all = array_values($all);
        }

        // Pagination
        $pag   = $this->pagination();
        $total = count($all);
        $page  = array_slice($all, $pag['offset'], $pag['per_page']);

        $this->paginated($page, $total, $pag['page'], $pag['per_page']);
    }

    // ── GET /api/catalogs/{id}/controls/{controlId} ───────────────────────────

    public function control(array $params): void
    {
        $row = $this->resolveCatalog($params);
        if ($row === null) {
            return;
        }

        $controlId = $params['controlId'] ?? '';
        if ($controlId === '') {
            $this->error('Control-ID fehlt.', 400);
            return;
        }

        $parsed  = $this->parser->parse($row['oscal_json']);
        $control = $this->parser->findControl($parsed, $controlId);

        if ($control === null) {
            $this->error('Control nicht gefunden.', 404);
            return;
        }

        $this->json(['control' => $control]);
    }

    // ── POST /api/catalogs/{id}/check-update ─────────────────────────────────

    public function checkUpdate(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb'])) {
            return;
        }

        $row = $this->resolveCatalog($params);
        if ($row === null) {
            return;
        }

        if (empty($row['source_url'])) {
            $this->error('Dieser Katalog hat keine Quell-URL und kann nicht automatisch aktualisiert werden.', 422);
            return;
        }

        $trustedUrl = $this->resolveTrustedCatalogUrl((string) $row['source_url']);
        if ($trustedUrl === null) {
            $this->error('Die gespeicherte Quell-URL ist nicht als vertrauenswürdige Katalogquelle freigegeben.', 422);
            return;
        }

        $rawJson = $this->fetchUrl($trustedUrl);
        if ($rawJson === null) {
            $this->error('Quell-URL konnte nicht abgerufen werden.', 502);
            return;
        }

        $newHash  = $this->parser->computeHash($rawJson);
        $upToDate = $newHash === $row['version_hash'];

        // When the caller explicitly requests an update, apply it now
        $body  = $this->requestBody();
        $apply = isset($body['apply']) && (bool) $body['apply'];

        if ($apply && !$upToDate) {
            $catalogId = (int) $row['id'];
            $this->repo->updateAfterReimport($catalogId, $rawJson, $newHash);
            AuditLogger::log('catalog.updated', 'catalogs', $catalogId, [
                'old_hash' => $row['version_hash'],
                'new_hash' => $newHash,
            ]);
        }

        $this->json([
            'up_to_date'   => $upToDate,
            'current_hash' => $row['version_hash'],
            'new_hash'     => $newHash,
            'applied'      => $apply && !$upToDate,
        ]);
    }

    // ── private helpers ──────────────────────────────────────────────────────

    // ── GET /api/catalogs/{id}/mappings ───────────────────────────────────────

    /**
     * Return all cross-reference mappings for a catalog.
     * Optional query param: ?framework=ISO27001
     */
    public function getMappings(array $params): void
    {
        $row = $this->resolveCatalog($params);
        if ($row === null) {
            return;
        }

        $framework    = $this->queryParam('framework', '') ?: null;
        $bausteinMaps = $this->mappingService->getBausteinMappings($this->tenantId(), (int) $row['id']);
        $controlMaps  = $this->mappingService->getControlMappings($this->tenantId(), (int) $row['id'], $framework);

        $this->json([
            'baustein_mappings' => $bausteinMaps,
            'control_mappings'  => $controlMaps,
        ]);
    }

    // ── POST /api/catalogs/{id}/mappings ──────────────────────────────────────

    /**
     * Bulk-import mappings for a catalog.
     * Body: { "type": "baustein_zo"|"controls_anf", "rows": [...] }
     */
    public function importMappings(array $params): void
    {
        if (!AuthMiddleware::requireRole(['admin', 'isb'])) {
            return;
        }

        $row = $this->resolveCatalog($params);
        if ($row === null) {
            return;
        }

        $body = $this->requestBody();
        $type = $body['type'] ?? '';
        if (!in_array($type, ['baustein_zo', 'controls_anf'], true)) {
            $this->error('type muss "baustein_zo" oder "controls_anf" sein.', 422);
            return;
        }

        $rows = $body['rows'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            $this->error('rows muss ein nicht-leeres Array sein.', 422);
            return;
        }

        $catalogId = (int) $row['id'];
        $tenantId  = $this->tenantId();

        if ($type === 'baustein_zo') {
            $count = $this->mappingService->importBausteinMappings($tenantId, $catalogId, $rows);
        } else {
            $count = $this->mappingService->importControlMappings($tenantId, $catalogId, $rows);
        }

        AuditLogger::log('catalog.import_mappings', 'catalogs', $catalogId, ['type' => $type, 'count' => $count]);

        $this->json(['imported' => $count], 201);
    }

    // ── private helpers ──────────────────────────────────────────────────────

    private function resolveCatalog(array $params): ?array
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            $this->error('Ungültige Katalog-ID.', 400);
            return null;
        }

        $row = $this->repo->findByIdAndTenant($id, $this->tenantId());
        if ($row === null) {
            $this->error('Katalog nicht gefunden.', 404);
            return null;
        }

        return $row;
    }

    /**
     * @return array<int, array{key: string, name: string, description: string, source: string, tags: array<int, string>, repo_url: string, raw_url: string}>
     */
    private function trustedCatalogSources(): array
    {
        return [
            [
                'key'         => 'bsi-anwenderkatalog',
                'name'        => 'BSI GS++ Anwenderkatalog',
                'description' => 'Vollständiger Anforderungskatalog für BSI Grundschutz++ — enthält alle Prozessbausteine und Anforderungen der Stand-der-Technik-Bibliothek.',
                'source'      => 'BSI',
                'tags'        => ['Pflicht', 'Primär', 'OSCAL 1.1.3'],
                'repo_url'    => 'https://github.com/BSI-Bund/Stand-der-Technik-Bibliothek/tree/main/Anwenderkataloge/Grundschutz%2B%2B',
                'raw_url'     => 'https://raw.githubusercontent.com/BSI-Bund/Stand-der-Technik-Bibliothek/main/Anwenderkataloge/Grundschutz%2B%2B/Grundschutz%2B%2B-catalog.json',
            ],
            [
                'key'         => 'bsi-dsgvo',
                'name'        => 'DSGVO-Beispielkatalog',
                'description' => 'Anforderungskatalog mit datenschutzrechtlichen Maßnahmen gemäß DSGVO Art. 32, als OSCAL-Katalog modelliert.',
                'source'      => 'NTT DATA',
                'tags'        => ['Beispiel', 'DSGVO'],
                'repo_url'    => 'https://github.com/NTT-Data-Deutschland-SE/Grundschutz-Plus-Plus-Tools/tree/main/beispiel-kataloge',
                'raw_url'     => 'https://raw.githubusercontent.com/NTT-Data-Deutschland-SE/Grundschutz-Plus-Plus-Tools/main/beispiel-kataloge/dsgvo_oscal_catalog.json',
            ],
            [
                'key'         => 'bsi-kritis',
                'name'        => 'KRITIS-Beispielkatalog',
                'description' => 'Anforderungskatalog für kritische Infrastrukturen (KRITIS) nach dem IT-Sicherheitsgesetz 2.0.',
                'source'      => 'NTT DATA',
                'tags'        => ['Beispiel', 'KRITIS'],
                'repo_url'    => 'https://github.com/NTT-Data-Deutschland-SE/Grundschutz-Plus-Plus-Tools/tree/main/beispiel-kataloge',
                'raw_url'     => 'https://raw.githubusercontent.com/NTT-Data-Deutschland-SE/Grundschutz-Plus-Plus-Tools/main/beispiel-kataloge/kritis_oscal_catalog.json',
            ],
            [
                'key'         => 'bsi-c5',
                'name'        => 'BSI C5 Cloud-Katalog (2026)',
                'description' => 'BSI Cloud Computing Compliance Controls Catalogue (C5) — Sicherheitsanforderungen für Cloud-Dienste.',
                'source'      => 'NTT DATA',
                'tags'        => ['Beispiel', 'Cloud', 'C5'],
                'repo_url'    => 'https://github.com/NTT-Data-Deutschland-SE/Grundschutz-Plus-Plus-Tools/tree/main/kataloge',
                'raw_url'     => 'https://raw.githubusercontent.com/NTT-Data-Deutschland-SE/Grundschutz-Plus-Plus-Tools/main/kataloge/c5-2026-oscal-catalog.json',
            ],
        ];
    }

    private function resolveTrustedCatalogUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        foreach ($this->trustedCatalogSources() as $source) {
            if (hash_equals($source['raw_url'], $url)) {
                return $source['raw_url'];
            }
        }

        return null;
    }

    /**
     * Fetch raw content from a URL; returns null on failure.
     * Only HTTPS is allowed; SSRF protection blocks private/internal IP ranges.
     */
    private function fetchUrl(string $url): ?string
    {
        try {
            $this->validateUrl($url);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'         => 15,
                'follow_location' => true,
                'max_redirects'   => 3,
                'user_agent'      => 'GSM-CatalogImporter/1.0',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $ctx);
        return $content !== false ? $content : null;
    }

    /**
     * Validate a URL for safe external fetching.
     * Only HTTPS is allowed; private and reserved IP ranges are blocked (SSRF prevention).
     *
     * @throws \InvalidArgumentException if the URL is unsafe.
     */
    private function validateUrl(string $url): void
    {
        // Only HTTPS — blocks plain HTTP and all other schemes
        if (!preg_match('#^https://#i', $url)) {
            throw new \InvalidArgumentException('Nur HTTPS-URLs sind erlaubt.');
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException('Ungültiger oder fehlender Hostname in URL.');
        }

        // Resolve hostname to IPv4 address
        $ip = gethostbyname($host);
        if ($ip === $host) {
            // gethostbyname returns the input unchanged when DNS resolution fails
            throw new \InvalidArgumentException('Hostname konnte nicht aufgelöst werden.');
        }

        if (self::isPrivateOrReservedIp($ip)) {
            throw new \InvalidArgumentException('Zugriff auf interne Netzwerkadressen ist nicht gestattet.');
        }
    }

    /**
     * Returns true if the given IPv4 address is private, loopback, or reserved.
     * IPv6 addresses are blocked entirely (not needed for catalog sources).
     */
    private static function isPrivateOrReservedIp(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            // Not a valid IPv4 address (e.g. IPv6) — block for safety
            return true;
        }

        foreach (
            [
                ['127.0.0.0',    '127.255.255.255'],  // Loopback
                ['10.0.0.0',     '10.255.255.255'],   // RFC 1918
                ['172.16.0.0',   '172.31.255.255'],   // RFC 1918
                ['192.168.0.0',  '192.168.255.255'],  // RFC 1918
                ['169.254.0.0',  '169.254.255.255'],  // Link-local (APIPA)
                ['100.64.0.0',   '100.127.255.255'],  // Carrier-grade NAT (RFC 6598)
                ['192.0.0.0',    '192.0.0.255'],      // IETF Protocol Assignments
                ['192.0.2.0',    '192.0.2.255'],      // TEST-NET-1 (RFC 5737)
                ['198.51.100.0', '198.51.100.255'],   // TEST-NET-2 (RFC 5737)
                ['203.0.113.0',  '203.0.113.255'],    // TEST-NET-3 (RFC 5737)
                ['0.0.0.0',      '0.255.255.255'],    // Unspecified / this-network
                ['240.0.0.0',    '255.255.255.254'],  // Reserved / Multicast
            ] as [$start, $end]
        ) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                return true;
            }
        }
        return false;
    }
}
