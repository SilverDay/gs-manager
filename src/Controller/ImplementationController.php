<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Config\Database;
use GsppManager\Middleware\AuditLogger;
use GsppManager\Repository\DomainRepository;
use GsppManager\Repository\ImplementationRepository;

class ImplementationController extends BaseController
{
    private ImplementationRepository $repo;
    private DomainRepository $domainRepo;

    public function __construct()
    {
        $this->repo       = new ImplementationRepository();
        $this->domainRepo = new DomainRepository();
    }

    /**
     * GET /api/domains/{id}/implementations
     * Paginated list with progress summary.
     * Auto-creates implementation rows for any scoped controls that don't have one.
     */
    public function list(array $params): void
    {
        $domainId = (int) ($params['id'] ?? 0);
        $tenantId = $this->tenantId();

        $domain = $this->domainRepo->findByIdAndTenant($domainId, $tenantId);
        if ($domain === null) {
            $this->error('Informationsverbund nicht gefunden.', 404);
            return;
        }

        // Ensure all scoped controls have implementation rows
        $this->repo->ensureAllExist($domainId, $tenantId);

        $filters = [
            'status'   => $this->queryParam('status')   ?? '',
            'asset_id' => $this->queryParam('asset_id') ?? '',
            'search'   => $this->queryParam('search')   ?? '',
        ];

        // Override per_page default to 50 for this endpoint
        $perPage = min(200, max(1, (int) ($this->queryParam('per_page', 50))));
        $page    = max(1, (int) ($this->queryParam('page', 1)));

        $result = $this->repo->findByDomain($domainId, $tenantId, $filters, $page, $perPage);

        $this->json([
            'items'    => $result['items'],
            'progress' => $result['progress'],
            'meta'     => [
                'total'     => $result['total'],
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => (int) ceil($result['total'] / max($perPage, 1)),
            ],
        ]);
    }

    /**
     * PUT /api/implementations/{implId}
     */
    public function update(array $params): void
    {
        $implId   = (int) ($params['implId'] ?? 0);
        $tenantId = $this->tenantId();

        $existing = $this->repo->findById($implId, $tenantId);
        if ($existing === null) {
            $this->error('Implementierung nicht gefunden.', 404);
            return;
        }

        // Role check: auditor and readonly may not edit
        $role = $this->userRole();
        if (in_array($role, ['auditor', 'readonly', 'management'], true)) {
            $this->error('Keine Berechtigung zum Bearbeiten.', 403);
            return;
        }

        $body = $this->requestBody();

        // Validate status if provided
        $validStatuses = ['not_started', 'planned', 'partial', 'implemented', 'not_applicable'];
        if (isset($body['status']) && !in_array($body['status'], $validStatuses, true)) {
            $this->error('Ungültiger Status.', 422);
            return;
        }

        // Validate maturity_level if provided
        if (isset($body['maturity_level'])) {
            $ml = (int) $body['maturity_level'];
            if ($ml < 0 || $ml > 5) {
                $this->error('Reifegrad muss zwischen 0 und 5 liegen.', 422);
                return;
            }
            $body['maturity_level'] = $ml;
        }

        $fields = array_intersect_key($body, array_flip([
            'status', 'maturity_level', 'description',
            'responsible_user_id', 'target_date', 'completion_date',
            'parameters_json',
        ]));

        // Normalize empty strings to NULL for nullable FK/date columns
        foreach (['responsible_user_id', 'target_date', 'completion_date'] as $nullable) {
            if (array_key_exists($nullable, $fields) && $fields[$nullable] === '') {
                $fields[$nullable] = null;
            }
        }

        if (empty($fields)) {
            $this->error('Keine gültigen Felder zum Speichern.', 422);
            return;
        }

        $updated = $this->repo->update($implId, $tenantId, $fields, $this->userId());

        // update() returns false when rowCount()==0, which happens when the submitted
        // values are identical to the existing ones (no-op UPDATE). Since we already
        // verified ownership via findById() above, this is not an error — just return
        // the current state so the frontend's optimistic UI stays consistent.
        if ($updated) {
            $trackFields = ['status', 'maturity_level', 'description', 'responsible_user_id', 'target_date', 'completion_date'];
            $changes     = AuditLogger::diff($existing, $fields, $trackFields);
            if (!empty($changes)) {
                AuditLogger::log('implementation.update', 'implementations', $implId, $changes);
            }
        }

        $fresh = $this->repo->findById($implId, $tenantId);
        $this->json(['implementation' => $fresh]);
    }

    /**
     * POST /api/implementations/{implId}/evidence
     * Multipart file upload.
     */
    public function uploadEvidence(array $params): void
    {
        $implId   = (int) ($params['implId'] ?? 0);
        $tenantId = $this->tenantId();

        $existing = $this->repo->findById($implId, $tenantId);
        if ($existing === null) {
            $this->error('Implementierung nicht gefunden.', 404);
            return;
        }

        $role = $this->userRole();
        if (in_array($role, ['auditor', 'readonly', 'management'], true)) {
            $this->error('Keine Berechtigung zum Hochladen.', 403);
            return;
        }

        if (empty($_FILES['file'])) {
            $this->error('Keine Datei übermittelt.', 422);
            return;
        }

        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->error('Upload-Fehler: ' . $file['error'], 422);
            return;
        }

        // Max 10 MB
        if ($file['size'] > 10 * 1024 * 1024) {
            $this->error('Datei zu groß (max. 10 MB).', 422);
            return;
        }

        // MIME allowlist — check actual content, not client-supplied type
        $allowedMimes = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/msword',
            'application/vnd.ms-excel',
            'image/png',
            'image/jpeg',
            'text/plain',
        ];
        $detectedMime = mime_content_type($file['tmp_name']);
        if (!in_array($detectedMime, $allowedMimes, true)) {
            $this->error('Dateityp nicht erlaubt. Erlaubt: PDF, Word, Excel, PNG, JPG, TXT.', 422);
            return;
        }

        // Build storage path: storage/uploads/{tenantId}/{domainId}/
        // Resolve domain id from existing implementation
        $domainIdForPath = $this->resolveDomainId($implId);
        $storageBase     = dirname(__DIR__, 2) . '/storage/uploads';
        $targetDir       = "{$storageBase}/{$tenantId}/{$domainIdForPath}";

        if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true)) {
            $this->error('Upload-Verzeichnis konnte nicht erstellt werden.', 500);
            return;
        }

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeName = bin2hex(random_bytes(16)) . ($ext ? '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext) : '');
        $destPath = $targetDir . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->error('Datei konnte nicht gespeichert werden.', 500);
            return;
        }

        $sha256 = hash_file('sha256', $destPath);

        // Relative stored_path for portability
        $storedPath = "uploads/{$tenantId}/{$domainIdForPath}/{$safeName}";

        $pdo    = Database::getConnection();
        $insert = $pdo->prepare("
            INSERT INTO evidence_files
                (tenant_id, original_name, stored_path, mime_type, file_size, sha256_hash, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $tenantId,
            basename($file['name']),
            $storedPath,
            $detectedMime,
            $file['size'],
            $sha256,
            $this->userId(),
        ]);
        $fileId = (int) $pdo->lastInsertId();

        $this->repo->addEvidence($implId, $tenantId, $fileId, $this->userId());
        AuditLogger::log('implementation.evidence_upload', 'implementations', $implId, ['file_id' => $fileId]);

        $this->json([
            'file' => [
                'id'            => $fileId,
                'original_name' => basename($file['name']),
                'mime_type'     => $detectedMime,
                'file_size'     => $file['size'],
            ],
        ]);
    }

    private function resolveDomainId(int $implId): int
    {
        $stmt = $this->pdo()->prepare("
            SELECT sc.domain_id
            FROM implementations i
            JOIN scoped_controls sc ON sc.id = i.scoped_control_id
            WHERE i.id = ? LIMIT 1
        ");
        $stmt->execute([$implId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function pdo(): \PDO
    {
        return Database::getConnection();
    }
}
