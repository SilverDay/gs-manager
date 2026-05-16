<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Config\Database;
use GsppManager\Middleware\AuditLogger;
use GsppManager\Middleware\CsrfMiddleware;
use GsppManager\Security\PasswordHasher;
use GsppManager\Security\TotpService;
use GsppManager\Security\FieldEncryptor;
use GsppManager\Service\MailService;

class AuthController extends BaseController
{
    public function login(array $params): void
    {
        $body = $this->requestBody();

        $error = $this->validateRequired($body, ['email', 'password']);
        if ($error !== null) {
            $this->error($error, 422);
            return;
        }

        $email = trim($body['email']);
        $password = $body['password'];

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT id, tenant_id, email, password_hash, display_name, role, is_active, totp_enabled
            FROM users
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user === false || !PasswordHasher::verify($password, $user['password_hash'])) {
            // Don't reveal whether user exists
            $this->error('E-Mail oder Passwort falsch.', 401);
            return;
        }

        if (!$user['is_active']) {
            $this->error('Konto ist deaktiviert. Bitte Administrator kontaktieren.', 403);
            return;
        }

        // TOTP verification
        if ($user['totp_enabled']) {
            $totpCode = trim($body['totp_code'] ?? '');
            if ($totpCode === '') {
                $this->json([
                    'totp_required' => true,
                    'message'       => 'Bitte den Zwei-Faktor-Code eingeben.',
                ]);
                return;
            }
            if ($user['totp_secret_enc'] === null) {
                $this->error('TOTP-Konfiguration fehlerhaft.', 500);
                return;
            }
            $encryptor = new FieldEncryptor();
            $secret    = $encryptor->decrypt($user['totp_secret_enc']);
            if (!TotpService::verify($secret, $totpCode)) {
                AuditLogger::log('login.totp_failed', 'users', (int) $user['id']);
                $this->error('Ungültiger Zwei-Faktor-Code.', 401);
                return;
            }
        }

        // Rehash if needed (bcrypt → argon2id migration)
        if (PasswordHasher::needsRehash($user['password_hash'])) {
            $newHash = PasswordHasher::hash($password);
            $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $updateStmt->execute([$newHash, $user['id']]);
        }

        // Regenerate session ID on login (prevent fixation)
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['tenant_id'] = (int) $user['tenant_id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['display_name'];
        $_SESSION['last_activity'] = time();

        // Update last login
        $loginStmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
        $loginStmt->execute([$user['id']]);

        AuditLogger::log('login', 'users', (int) $user['id']);

        $this->json([
            'user' => [
                'id'           => (int) $user['id'],
                'email'        => $user['email'],
                'display_name' => $user['display_name'],
                'role'         => $user['role'],
            ],
            'csrf_token' => CsrfMiddleware::generateToken(),
        ]);
    }

    public function logout(array $params): void
    {
        AuditLogger::log('logout', 'users', $this->userId());

        $_SESSION = [];
        session_destroy();

        $this->json(['message' => 'Abgemeldet.']);
    }

    public function me(array $params): void
    {
        $this->json([
            'user' => [
                'id'           => $this->userId(),
                'email'        => $_SESSION['user_email'] ?? '',
                'display_name' => $_SESSION['user_name'] ?? '',
                'role'         => $this->userRole(),
                'tenant_id'    => $this->tenantId(),
            ],
        ]);
    }

    public function csrfToken(array $params): void
    {
        $this->json([
            'csrf_token' => CsrfMiddleware::generateToken(),
        ]);
    }

    /**
     * POST /api/auth/password-reset/request
     * Send a password-reset link to the user's email address.
     * Response is always identical regardless of whether the email exists (no enumeration).
     */
    public function passwordResetRequest(array $params): void
    {
        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['email']);
        if ($err !== null) {
            // Still return a generic success to avoid leaking field requirement info
            $this->json(['message' => 'Falls die E-Mail-Adresse registriert ist, wurde ein Rücksetz-Link gesendet.']);
            return;
        }

        $email = trim($body['email']);
        $pdo   = Database::getConnection();

        $stmt = $pdo->prepare('SELECT id, tenant_id, is_active FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always respond the same way — no enumeration
        $genericOk = ['message' => 'Falls die E-Mail-Adresse registriert ist, wurde ein Rücksetz-Link gesendet.'];

        if ($user === false || !$user['is_active']) {
            $this->json($genericOk);
            return;
        }

        // Invalidate any existing unused tokens for this user
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL')
            ->execute([$user['id']]);

        // Generate a 32-byte token; store only its SHA-256 hash
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
            ->execute([$user['id'], $tokenHash, $expiresAt]);

        // Load SMTP settings for the user's tenant
        $settingsStmt = $pdo->prepare('SELECT settings_json FROM tenants WHERE id = ?');
        $settingsStmt->execute([$user['tenant_id']]);
        $settings = json_decode($settingsStmt->fetch()['settings_json'] ?? '{}', true) ?? [];

        if (!isset($settings['smtp_host']) || $settings['smtp_host'] === '') {
            // No SMTP configured — log and return the generic message
            // (admin must reset manually)
            AuditLogger::log('password_reset.no_smtp', 'users', (int) $user['id']);
            $this->json($genericOk);
            return;
        }

        $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . '/passwort-zuruecksetzen?token=' . urlencode($token);

        $mailBody = "Hallo,\n\nbitte klicken Sie auf den folgenden Link, um Ihr Passwort zurückzusetzen:\n\n{$resetLink}\n\n"
            . "Dieser Link ist 1 Stunde gültig und kann nur einmal verwendet werden.\n\n"
            . "Falls Sie diese Anfrage nicht gestellt haben, ignorieren Sie diese E-Mail.\n\nGS++ Manager";

        try {
            MailService::send($settings, $email, 'Passwort zurücksetzen — GS++ Manager', $mailBody);
        } catch (\RuntimeException) {
            // Log failure internally; still return generic response to user
            AuditLogger::log('password_reset.mail_failed', 'users', (int) $user['id']);
        }

        AuditLogger::log('password_reset.requested', 'users', (int) $user['id']);
        $this->json($genericOk);
    }

    /**
     * POST /api/auth/password-reset/confirm
     * Set a new password using a valid reset token.
     */
    public function passwordResetConfirm(array $params): void
    {
        $body = $this->requestBody();
        $err  = $this->validateRequired($body, ['token', 'new_password', 'new_password_confirm']);
        if ($err !== null) {
            $this->error($err, 422);
            return;
        }

        if ($body['new_password'] !== $body['new_password_confirm']) {
            $this->error('Passwörter stimmen nicht überein.', 422);
            return;
        }

        if (strlen($body['new_password']) < 12) {
            $this->error('Das neue Passwort muss mindestens 12 Zeichen lang sein.', 422);
            return;
        }

        $tokenHash = hash('sha256', $body['token']);
        $pdo       = Database::getConnection();

        $stmt = $pdo->prepare('
            SELECT id, user_id, expires_at, used_at, attempt_count
            FROM password_reset_tokens
            WHERE token_hash = ?
        ');
        $stmt->execute([$tokenHash]);
        $tokenRow = $stmt->fetch();

        if ($tokenRow === false) {
            $this->error('Ungültiger oder abgelaufener Reset-Link.', 400);
            return;
        }

        // Already used
        if ($tokenRow['used_at'] !== null) {
            $this->error('Dieser Reset-Link wurde bereits verwendet.', 400);
            return;
        }

        // Brute-force: count every presentation; invalidate after 5 total attempts
        $attempts = (int) $tokenRow['attempt_count'] + 1;
        if ($attempts >= 5) {
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE id = ?')->execute([$tokenRow['id']]);
            AuditLogger::log('password_reset.brute_force', 'users', (int) $tokenRow['user_id']);
            $this->error('Zu viele fehlgeschlagene Versuche. Bitte einen neuen Link anfordern.', 429);
            return;
        }
        $pdo->prepare('UPDATE password_reset_tokens SET attempt_count = ? WHERE id = ?')
            ->execute([$attempts, $tokenRow['id']]);

        // Expiry check
        if (strtotime($tokenRow['expires_at']) < time()) {
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE id = ?')->execute([$tokenRow['id']]);
            $this->error('Dieser Reset-Link ist abgelaufen.', 400);
            return;
        }

        // Token is valid — set new password
        $newHash = PasswordHasher::hash($body['new_password']);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$newHash, $tokenRow['user_id']]);

        // Mark token as used
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')
            ->execute([$tokenRow['id']]);

        AuditLogger::log('password_reset.confirmed', 'users', (int) $tokenRow['user_id']);

        $this->json(['message' => 'Passwort erfolgreich zurückgesetzt. Sie können sich jetzt anmelden.']);
    }
}
