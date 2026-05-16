<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Config\Database;
use GsppManager\Middleware\AuditLogger;
use GsppManager\Security\FieldEncryptor;
use GsppManager\Security\PasswordHasher;
use GsppManager\Security\TotpService;

class ProfileController extends BaseController
{
    /**
     * GET /api/profile
     * Return the current user's profile data.
     */
    public function show(array $params): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT id, email, display_name, role, totp_enabled, last_login_at, created_at
            FROM users
            WHERE id = ? AND tenant_id = ?
        ');
        $stmt->execute([$this->userId(), $this->tenantId()]);
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
            'totp_enabled' => (bool) $user['totp_enabled'],
            'last_login_at' => $user['last_login_at'],
            'created_at'   => $user['created_at'],
        ]);
    }

    /**
     * PUT /api/profile
     * Update display_name and/or email. Email change requires password confirmation.
     */
    public function update(array $params): void
    {
        $body = $this->requestBody();

        $displayName = isset($body['display_name']) ? trim($body['display_name']) : null;
        $email       = isset($body['email'])        ? trim($body['email'])        : null;
        $password    = $body['password'] ?? '';

        if ($displayName === null && $email === null) {
            $this->error('Kein Feld zum Aktualisieren angegeben.', 422);
            return;
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT password_hash, email FROM users WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$this->userId(), $this->tenantId()]);
        $user = $stmt->fetch();

        // Email change requires current password
        if ($email !== null && $email !== $user['email']) {
            if ($password === '' || !PasswordHasher::verify($password, $user['password_hash'])) {
                $this->error('Passwort ist erforderlich, um die E-Mail-Adresse zu ändern.', 403);
                return;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('Ungültige E-Mail-Adresse.', 422);
                return;
            }
            // Check uniqueness within tenant
            $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND tenant_id = ? AND id != ?');
            $chk->execute([$email, $this->tenantId(), $this->userId()]);
            if ($chk->fetch() !== false) {
                $this->error('Diese E-Mail-Adresse ist bereits vergeben.', 409);
                return;
            }
        }

        $fields = [];
        $binds  = [];

        if ($displayName !== null && $displayName !== '') {
            $fields[] = 'display_name = ?';
            $binds[]  = $displayName;
        }
        if ($email !== null && $email !== '' && $email !== $user['email']) {
            $fields[] = 'email = ?';
            $binds[]  = $email;
        }

        if (empty($fields)) {
            $this->error('Keine Änderung erkannt.', 422);
            return;
        }

        $binds[] = $this->userId();
        $binds[] = $this->tenantId();
        $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ? AND tenant_id = ?')
            ->execute($binds);

        // Keep session display name in sync
        if ($displayName !== null) {
            $_SESSION['user_name'] = $displayName;
        }
        if ($email !== null) {
            $_SESSION['user_email'] = $email;
        }

        AuditLogger::log('profile.update', 'users', $this->userId(), ['fields' => array_keys(array_flip($fields))]);

        $this->json(['message' => 'Profil aktualisiert.']);
    }

    /**
     * POST /api/profile/change-password
     * Change the current user's password (requires current password).
     */
    public function changePassword(array $params): void
    {
        $body = $this->requestBody();

        $err = $this->validateRequired($body, ['current_password', 'new_password', 'new_password_confirm']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        if ($body['new_password'] !== $body['new_password_confirm']) {
            $this->error('Neues Passwort und Bestätigung stimmen nicht überein.', 422);
            return;
        }

        if (strlen($body['new_password']) < 12) {
            $this->error('Das neue Passwort muss mindestens 12 Zeichen lang sein.', 422);
            return;
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$this->userId(), $this->tenantId()]);
        $user = $stmt->fetch();

        if (!PasswordHasher::verify($body['current_password'], $user['password_hash'])) {
            $this->error('Aktuelles Passwort ist falsch.', 403);
            return;
        }

        $newHash = PasswordHasher::hash($body['new_password']);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$newHash, $this->userId(), $this->tenantId()]);

        AuditLogger::log('profile.change_password', 'users', $this->userId());

        $this->json(['message' => 'Passwort erfolgreich geändert.']);
    }

    /**
     * GET /api/profile/sessions
     * Return information about the current active session.
     */
    public function sessions(array $params): void
    {
        $this->json([
            'sessions' => [
                [
                    'id'            => session_id(),
                    'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent'    => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'last_activity' => isset($_SESSION['last_activity'])
                        ? date('Y-m-d\TH:i:sP', $_SESSION['last_activity'])
                        : null,
                    'current'       => true,
                ],
            ],
        ]);
    }

    /**
     * POST /api/profile/totp/setup
     * Generate a new TOTP secret and return the otpauth URI for QR-code rendering.
     * The secret is stored as pending (totp_enabled remains false until confirmed).
     */
    public function totpSetup(array $params): void
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT email, totp_enabled FROM users WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$this->userId(), $this->tenantId()]);
        $user = $stmt->fetch();

        if ((bool) $user['totp_enabled']) {
            $this->error('TOTP ist bereits aktiviert. Erst deaktivieren.', 409);
            return;
        }

        $secret    = TotpService::generateSecret();
        $encryptor = new FieldEncryptor();
        $encrypted = $encryptor->encrypt($secret);

        // Store the pending secret (not yet enabled)
        $pdo->prepare('UPDATE users SET totp_secret_enc = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$encrypted, $this->userId(), $this->tenantId()]);

        $issuer = 'GS++ Manager';
        $uri    = TotpService::getOtpAuthUri($secret, $user['email'], $issuer);

        $this->json([
            'secret'     => $secret,
            'otpauth_uri' => $uri,
        ]);
    }

    /**
     * POST /api/profile/totp/confirm
     * Confirm TOTP setup with the first valid code. Enables TOTP for the account.
     */
    public function totpConfirm(array $params): void
    {
        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['code']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT totp_secret_enc, totp_enabled FROM users WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$this->userId(), $this->tenantId()]);
        $user = $stmt->fetch();

        if ($user['totp_secret_enc'] === null) {
            $this->error('Kein TOTP-Secret vorhanden. Zuerst Setup aufrufen.', 400);
            return;
        }

        if ((bool) $user['totp_enabled']) {
            $this->error('TOTP ist bereits aktiviert.', 409);
            return;
        }

        $encryptor = new FieldEncryptor();
        $secret    = $encryptor->decrypt($user['totp_secret_enc']);

        if (!TotpService::verify($secret, trim($body['code']))) {
            $this->error('Ungültiger TOTP-Code.', 403);
            return;
        }

        $pdo->prepare('UPDATE users SET totp_enabled = TRUE WHERE id = ? AND tenant_id = ?')
            ->execute([$this->userId(), $this->tenantId()]);

        AuditLogger::log('profile.totp_enabled', 'users', $this->userId());

        $this->json(['message' => 'Zwei-Faktor-Authentifizierung aktiviert.']);
    }

    /**
     * DELETE /api/profile/totp
     * Disable TOTP. Requires current password for confirmation.
     */
    public function totpDelete(array $params): void
    {
        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['password']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT password_hash, totp_enabled FROM users WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$this->userId(), $this->tenantId()]);
        $user = $stmt->fetch();

        if (!PasswordHasher::verify($body['password'], $user['password_hash'])) {
            $this->error('Passwort falsch.', 403);
            return;
        }

        if (!(bool) $user['totp_enabled']) {
            $this->error('TOTP ist nicht aktiviert.', 400);
            return;
        }

        $pdo->prepare('UPDATE users SET totp_enabled = FALSE, totp_secret_enc = NULL WHERE id = ? AND tenant_id = ?')
            ->execute([$this->userId(), $this->tenantId()]);

        AuditLogger::log('profile.totp_disabled', 'users', $this->userId());

        $this->json(['message' => 'Zwei-Faktor-Authentifizierung deaktiviert.']);
    }
}
