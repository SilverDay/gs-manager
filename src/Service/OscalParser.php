<?php

declare(strict_types=1);

namespace GsppManager\Service;

use RuntimeException;

class OscalParser
{
    /**
     * Decode and validate an OSCAL catalog JSON string.
     *
     * @return array The decoded structure with a 'catalog' key.
     * @throws RuntimeException if the JSON is invalid or missing the catalog root.
     */
    public function parse(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new RuntimeException('Ungültiges JSON: ' . json_last_error_msg());
        }

        if (!isset($data['catalog'])) {
            throw new RuntimeException('Kein OSCAL-Katalog gefunden (fehlendes "catalog"-Schlüsselelement).');
        }

        $this->validateSchema($data);

        return $data;
    }

    /**
     * Validate that the decoded OSCAL data contains the required top-level fields.
     * Checks OSCAL 1.1.3 catalog structure requirements.
     *
     * @throws RuntimeException if a required field is missing.
     */
    public function validateSchema(array $data): void
    {
        $catalog = $data['catalog'] ?? null;
        if (!is_array($catalog)) {
            throw new RuntimeException('Ungültiges OSCAL: "catalog" muss ein Objekt sein.');
        }

        if (empty($catalog['uuid'])) {
            throw new RuntimeException('Ungültiges OSCAL: "catalog.uuid" fehlt.');
        }

        $metadata = $catalog['metadata'] ?? null;
        if (!is_array($metadata)) {
            throw new RuntimeException('Ungültiges OSCAL: "catalog.metadata" fehlt oder ist kein Objekt.');
        }

        if (empty($metadata['title'])) {
            throw new RuntimeException('Ungültiges OSCAL: "catalog.metadata.title" fehlt.');
        }

        if (empty($metadata['oscal-version'])) {
            throw new RuntimeException('Ungültiges OSCAL: "catalog.metadata.oscal-version" fehlt.');
        }

        // At least one of groups or controls must be present
        if (empty($catalog['groups']) && empty($catalog['controls'])) {
            throw new RuntimeException('Ungültiges OSCAL: "catalog" enthält weder "groups" noch "controls".');
        }
    }

    /**
     * Extract catalog metadata (title, version, oscal-version, last-modified).
     */
    public function extractMetadata(array $parsed): array
    {
        $meta = $parsed['catalog']['metadata'] ?? [];
        return [
            'title'         => $meta['title'] ?? '',
            'version'       => $meta['version'] ?? '',
            'oscal_version' => $meta['oscal-version'] ?? '',
            'last_modified' => $meta['last-modified'] ?? '',
        ];
    }

    /**
     * Return the top-level groups array from the catalog.
     *
     * @return array<int, array>
     */
    public function extractGroups(array $parsed): array
    {
        return $parsed['catalog']['groups'] ?? [];
    }

    /**
     * Flatten all controls from all groups (recursively) into a single list.
     * Each item includes id, title, group_id, group_title, statement, and props.
     * The returned array is keyed by control id for O(1) lookup.
     *
     * @return array<string, array>
     */
    public function flattenControls(array $parsed): array
    {
        $controls = [];
        foreach ($this->extractGroups($parsed) as $group) {
            $this->collectControls($group, $group['id'] ?? '', $group['title'] ?? '', $controls);
        }
        return $controls;
    }

    /**
     * Find a single control by its OSCAL id within a parsed catalog.
     * Uses O(1) map lookup.
     */
    public function findControl(array $parsed, string $controlId): ?array
    {
        $controls = $this->flattenControls($parsed);
        return $controls[$controlId] ?? null;
    }

    /**
     * Extract the statement prose from a raw control array.
     */
    public function extractStatement(array $control): string
    {
        foreach ($control['parts'] ?? [] as $part) {
            if (($part['name'] ?? '') === 'statement') {
                return $part['prose'] ?? '';
            }
        }
        return '';
    }

    /**
     * Extract a property value by name from a raw control array.
     */
    public function extractProp(array $control, string $name): ?string
    {
        foreach ($control['props'] ?? [] as $prop) {
            if (($prop['name'] ?? '') === $name) {
                return $prop['value'] ?? null;
            }
        }
        return null;
    }

    /**
     * Compute a SHA-256 hash of the raw JSON string (used for update detection).
     */
    public function computeHash(string $json): string
    {
        return hash('sha256', $json);
    }

    // ── private helpers ──────────────────────────────────────────────────────

    private function collectControls(
        array  $group,
        string $groupId,
        string $groupTitle,
        array  &$result
    ): void {
        foreach ($group['controls'] ?? [] as $rawControl) {
            $entry = $this->buildControlEntry($rawControl, $groupId, $groupTitle);
            $result[$entry['id']] = $entry;
        }

        // OSCAL groups may contain nested groups
        foreach ($group['groups'] ?? [] as $subGroup) {
            $this->collectControls($subGroup, $subGroup['id'] ?? $groupId, $subGroup['title'] ?? $groupTitle, $result);
        }
    }

    private function buildControlEntry(array $raw, string $groupId, string $groupTitle): array
    {
        $props = [];
        foreach ($raw['props'] ?? [] as $prop) {
            $props[$prop['name'] ?? ''] = $prop['value'] ?? '';
        }

        $params = array_map(fn(array $p) => [
            'id'    => $p['id'] ?? '',
            'label' => $p['label'] ?? '',
        ], $raw['params'] ?? []);

        return [
            'id'          => $raw['id'] ?? '',
            'title'       => $raw['title'] ?? '',
            'group_id'    => $groupId,
            'group_title' => $groupTitle,
            'statement'   => $this->extractStatement($raw),
            'props'       => $props,
            'params'      => $params,
        ];
    }
}
