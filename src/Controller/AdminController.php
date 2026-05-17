<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Config\Database;
use GsppManager\Middleware\AuditLogger;
use GsppManager\Security\FieldEncryptor;
use GsppManager\Security\PasswordHasher;
use GsppManager\Service\MailService;

class AdminController extends BaseController
{
    // ─── Role guard ─────────────────────────────────────────────

    private function requireAdmin(): bool
    {
        if ($this->userRole() !== 'admin') {
            $this->error('Nur Administratoren haben Zugriff auf diesen Bereich.', 403);
            return false;
        }
        return true;
    }

    // ─── User management ────────────────────────────────────────

    /**
     * GET /api/admin/users
     * List all users of the current tenant.
     */
    public function listUsers(array $params): void
    {
        if (!$this->requireAdmin()) return;

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT id, email, display_name, role, is_active, totp_enabled, last_login_at, created_at
            FROM users
            WHERE tenant_id = ?
            ORDER BY display_name
        ');
        $stmt->execute([$this->tenantId()]);
        $users = $stmt->fetchAll();

        $this->json([
            'users' => array_map(fn($u) => [
                'id'           => (int) $u['id'],
                'email'        => $u['email'],
                'display_name' => $u['display_name'],
                'role'         => $u['role'],
                'is_active'    => (bool) $u['is_active'],
                'totp_enabled' => (bool) $u['totp_enabled'],
                'last_login_at' => $u['last_login_at'],
                'created_at'   => $u['created_at'],
            ], $users),
        ]);
    }

    /**
     * POST /api/admin/users
     * Create a new user in the current tenant.
     */
    public function createUser(array $params): void
    {
        if (!$this->requireAdmin()) return;

        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['email', 'display_name', 'role', 'password']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $email       = trim($body['email']);
        $displayName = trim($body['display_name']);
        $role        = $body['role'];
        $password    = $body['password'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Ungültige E-Mail-Adresse.', 422);
            return;
        }

        $validRoles = ['admin', 'isb', 'fachverantwortlich', 'auditor', 'management', 'readonly'];
        if (!in_array($role, $validRoles, true)) {
            $this->error('Ungültige Rolle.', 422);
            return;
        }

        if (strlen($password) < 12) {
            $this->error('Passwort muss mindestens 12 Zeichen lang sein.', 422);
            return;
        }

        $pdo = Database::getConnection();

        // Uniqueness check
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND tenant_id = ?');
        $chk->execute([$email, $this->tenantId()]);
        if ($chk->fetch() !== false) {
            $this->error('Diese E-Mail-Adresse ist bereits vergeben.', 409);
            return;
        }

        $hash = PasswordHasher::hash($password);
        $pdo->prepare('
            INSERT INTO users (tenant_id, email, password_hash, display_name, role, is_active)
            VALUES (?, ?, ?, ?, ?, TRUE)
        ')->execute([$this->tenantId(), $email, $hash, $displayName, $role]);

        $newId = (int) $pdo->lastInsertId();
        AuditLogger::log('admin.user_created', 'users', $newId, ['email' => $email, 'role' => $role]);

        $this->json(['id' => $newId, 'message' => 'Benutzer angelegt.'], 201);
    }

    /**
     * GET /api/admin/users/{id}
     * Get details of a single user.
     */
    public function showUser(array $params): void
    {
        if (!$this->requireAdmin()) return;

        $userId = (int) ($params['id'] ?? 0);
        $pdo    = Database::getConnection();
        $stmt   = $pdo->prepare('
            SELECT id, email, display_name, role, is_active, totp_enabled, last_login_at, created_at
            FROM users
            WHERE id = ? AND tenant_id = ?
        ');
        $stmt->execute([$userId, $this->tenantId()]);
        $user = $stmt->fetch();

        if ($user === false) {
            $this->error('Benutzer nicht gefunden.', 404);
            return;
        }

        $this->json([
            'id'           => (int) $user['id'],
            'email'        => $user['email'],
            'display_name' => $user['display_name'],
            'role'         => $user['role'],
            'is_active'    => (bool) $user['is_active'],
            'totp_enabled' => (bool) $user['totp_enabled'],
            'last_login_at' => $user['last_login_at'],
            'created_at'   => $user['created_at'],
        ]);
    }

    /**
     * PUT /api/admin/users/{id}
     * Update display_name, role, or is_active of a user.
     */
    public function updateUser(array $params): void
    {
        if (!$this->requireAdmin()) return;

        $userId = (int) ($params['id'] ?? 0);
        $body   = $this->requestBody();
        $pdo    = Database::getConnection();

        $stmt = $pdo->prepare('SELECT id, display_name, role, is_active FROM users WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$userId, $this->tenantId()]);
        $oldUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($oldUser === false) {
            $this->error('Benutzer nicht gefunden.', 404);
            return;
        }

        $fields = [];
        $binds  = [];

        if (isset($body['display_name'])) {
            $fields[] = 'display_name = ?';
            $binds[]  = trim($body['display_name']);
        }

        if (isset($body['role'])) {
            $validRoles = ['admin', 'isb', 'fachverantwortlich', 'auditor', 'management', 'readonly'];
            if (!in_array($body['role'], $validRoles, true)) {
                $this->error('Ungültige Rolle.', 422);
                return;
            }
            $fields[] = 'role = ?';
            $binds[]  = $body['role'];
        }

        if (isset($body['is_active'])) {
            $fields[] = 'is_active = ?';
            $binds[]  = (bool) $body['is_active'] ? 1 : 0;
        }

        if (empty($fields)) {
            $this->error('Keine Änderung angegeben.', 422);
            return;
        }

        $binds[] = $userId;
        $binds[] = $this->tenantId();
        $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ? AND tenant_id = ?')
            ->execute($binds);

        // Build new state from the incoming body for diff
        $newUser = [
            'display_name' => isset($body['display_name']) ? trim($body['display_name']) : $oldUser['display_name'],
            'role'         => $body['role'] ?? $oldUser['role'],
            'is_active'    => isset($body['is_active']) ? ((bool) $body['is_active'] ? 1 : 0) : $oldUser['is_active'],
        ];
        $diff = AuditLogger::diff($oldUser, $newUser, ['display_name', 'role', 'is_active']);
        AuditLogger::log('admin.user_updated', 'users', $userId, $diff);

        $this->json(['message' => 'Benutzer aktualisiert.']);
    }

    /**
     * POST /api/admin/users/{id}/reset-password
     * Admin sets a new temporary password for any user in the tenant.
     */
    public function resetUserPassword(array $params): void
    {
        if (!$this->requireAdmin()) return;

        $userId = (int) ($params['id'] ?? 0);
        $body   = $this->requestBody();
        $err    = $this->validateRequired($body, ['new_password']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        if (strlen($body['new_password']) < 12) {
            $this->error('Passwort muss mindestens 12 Zeichen lang sein.', 422);
            return;
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$userId, $this->tenantId()]);
        if ($stmt->fetch() === false) {
            $this->error('Benutzer nicht gefunden.', 404);
            return;
        }

        $hash = PasswordHasher::hash($body['new_password']);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$hash, $userId, $this->tenantId()]);

        AuditLogger::log('admin.user_password_reset', 'users', $userId, ['reset_by' => $this->userId()]);

        $this->json(['message' => 'Passwort zurückgesetzt.']);
    }

    // ─── Tenant settings ────────────────────────────────────────

    /**
     * GET /api/admin/settings
     * Return tenant settings (SMTP config redacted, password masked).
     */
    public function getSettings(array $params): void
    {
        if (!$this->requireAdmin()) return;

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT settings_json FROM tenants WHERE id = ?');
        $stmt->execute([$this->tenantId()]);
        $row = $stmt->fetch();

        $settings = json_decode($row['settings_json'] ?? '{}', true) ?? [];

        // Never send the SMTP password in clear text
        if (isset($settings['smtp_pass'])) {
            $settings['smtp_pass'] = '••••••••';
        }

        // Never expose the encrypted AI API key — return masked placeholder if set
        if (isset($settings['ai_api_key_enc'])) {
            $settings['ai_api_key'] = '••••••••';
            unset($settings['ai_api_key_enc']);
        }

        $this->json(['settings' => $settings]);
    }

    /**
     * PUT /api/admin/settings
     * Save tenant settings. SMTP password is only updated if a non-masked value is provided.
     */
    public function updateSettings(array $params): void
    {
        if (!$this->requireAdmin()) return;

        $body = $this->requestBody();
        $pdo  = Database::getConnection();

        // Load existing settings to merge
        $stmt = $pdo->prepare('SELECT settings_json FROM tenants WHERE id = ?');
        $stmt->execute([$this->tenantId()]);
        $row      = $stmt->fetch();
        $existing = json_decode($row['settings_json'] ?? '{}', true) ?? [];

        $allowed = [
            'language',
            'timezone',
            'session_timeout',
            'smtp_host',
            'smtp_port',
            'smtp_user',
            'smtp_pass',
            'smtp_from',
            'smtp_from_name',
            'smtp_encryption',
            'ai_provider',
        ];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            // Don't overwrite real password with placeholder
            if ($key === 'smtp_pass' && $body[$key] === '••••••••') {
                continue;
            }
            $existing[$key] = $body[$key];
        }

        // AI API key: encrypt before storing — never saved in plain text (NFA-S10/NFA-S12)
        if (!empty($body['ai_api_key']) && $body['ai_api_key'] !== '••••••••') {
            $existing['ai_api_key_enc'] = (new FieldEncryptor())->encrypt($body['ai_api_key']);
        }

        $pdo->prepare('UPDATE tenants SET settings_json = ? WHERE id = ?')
            ->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $this->tenantId()]);

        AuditLogger::log('admin.settings_updated', 'tenants', $this->tenantId());

        $this->json(['message' => 'Einstellungen gespeichert.']);
    }

    /**
     * POST /api/admin/settings/smtp/test
     * Send a test email using the currently saved SMTP configuration.
     */
    public function testSmtp(array $params): void
    {
        if (!$this->requireAdmin()) return;

        $body = $this->requestBody();
        $to   = trim($body['to'] ?? '');

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Ungültige Empfänger-E-Mail-Adresse.', 422);
            return;
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT settings_json FROM tenants WHERE id = ?');
        $stmt->execute([$this->tenantId()]);
        $row      = $stmt->fetch();
        $settings = json_decode($row['settings_json'] ?? '{}', true) ?? [];

        try {
            MailService::send(
                $settings,
                $to,
                'GS++ Manager — SMTP-Testmail',
                "Diese Testmail wurde vom GS++ Manager gesendet.\n\nWenn Sie diese E-Mail erhalten, ist Ihre SMTP-Konfiguration korrekt.",
            );
        } catch (\RuntimeException $e) {
            $this->error('SMTP-Test fehlgeschlagen: ' . $e->getMessage(), 502);
            return;
        }

        $this->json(['message' => "Testmail an {$to} gesendet."]);
    }
}
