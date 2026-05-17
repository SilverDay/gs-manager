<?php

declare(strict_types=1);

namespace GsppManager\Service;

use GsppManager\Config\Database;

/**
 * Tracks changes to OSCAL documents by comparing JSON snapshots.
 * Writes diff records to the document_versions table (NFA-D3).
 */
class DiffService
{
    /**
     * Compute a human-readable diff between two JSON-serialisable documents.
     * Returns an array of change entries: { path, old, new }.
     *
     * @param array $before Previous document state.
     * @param array $after  New document state.
     *
     * @return array<int, array{path: string, old: mixed, new: mixed}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];
        $this->flatDiff($before, $after, '', $changes);
        return $changes;
    }

    /**
     * Persist a snapshot of a document to the document_versions table and return
     * the new version row ID.
     *
     * @param string $entityType  E.g. 'ssp', 'profile', 'ap', 'ar', 'poam'.
     * @param int    $entityId    Primary key of the owning entity.
     * @param int    $domainId    Owning domain.
     * @param int    $tenantId    Owning tenant.
     * @param int    $userId      User triggering the snapshot.
     * @param array  $document    Full document array (will be JSON-encoded).
     * @param array  $changes     Pre-computed diff array (from self::diff()).
     *
     * @return int The new document_versions.id.
     */
    public function snapshotDocument(
        string $entityType,
        int    $entityId,
        int    $domainId,
        int    $tenantId,
        int    $userId,
        array  $document,
        array  $changes = []
    ): int {
        $pdo = Database::getConnection();

        // Determine next version number for this entity
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(version_number), 0) + 1
             FROM document_versions
             WHERE entity_type = ? AND entity_id = ? AND tenant_id = ?'
        );
        $stmt->execute([$entityType, $entityId, $tenantId]);
        $nextVersion = (int) $stmt->fetchColumn();

        $insert = $pdo->prepare("
            INSERT INTO document_versions
                (tenant_id, domain_id, entity_type, entity_id, version_number,
                 document_json, changes_json, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $insert->execute([
            $tenantId,
            $domainId,
            $entityType,
            $entityId,
            $nextVersion,
            json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            empty($changes) ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Recursively walk both arrays and collect leaf-value differences.
     *
     * @param array  $before   Old data.
     * @param array  $after    New data.
     * @param string $prefix   JSON-path prefix for nested keys.
     * @param array  &$changes Accumulator.
     */
    private function flatDiff(array $before, array $after, string $prefix, array &$changes): void
    {
        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($allKeys as $key) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            $oldVal = $before[$key] ?? null;
            $newVal = $after[$key]  ?? null;

            if (is_array($oldVal) && is_array($newVal)) {
                $this->flatDiff($oldVal, $newVal, $path, $changes);
            } elseif ($oldVal !== $newVal) {
                $changes[] = [
                    'path' => $path,
                    'old'  => $oldVal,
                    'new'  => $newVal,
                ];
            }
        }
    }
}
