<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Middleware\AuditLogger;
use GsppManager\Middleware\AuthMiddleware;
use GsppManager\Repository\CatalogRepository;
use GsppManager\Service\OscalParser;
use RuntimeException;

class CatalogController extends BaseController
{
    private CatalogRepository $repo;
    private OscalParser $parser;

    public function __construct()
    {
        $this->repo   = new CatalogRepository();
        $this->parser = new OscalParser();
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
        $sources = [
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

            $rawJson = $this->fetchUrl($url);
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
            $all = array_filter($all, fn(array $c) =>
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

        $rawJson = $this->fetchUrl($row['source_url']);
        if ($rawJson === null) {
            $this->error('Quell-URL konnte nicht abgerufen werden.', 502);
            return;
        }

        $newHash  = $this->parser->computeHash($rawJson);
        $upToDate = $newHash === $row['version_hash'];

        $this->json([
            'up_to_date'   => $upToDate,
            'current_hash' => $row['version_hash'],
            'new_hash'     => $newHash,
        ]);
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
     * Fetch raw content from a URL; returns null on failure.
     * Only HTTP/HTTPS allowed.
     */
    private function fetchUrl(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'          => 15,
                'follow_location'  => true,
                'max_redirects'    => 3,
                'user_agent'       => 'GSM-CatalogImporter/1.0',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $ctx);
        return $content !== false ? $content : null;
    }
}
