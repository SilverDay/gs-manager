<?php

declare(strict_types=1);

namespace GsppManager\Controller;

use GsppManager\Config\Database;
use GsppManager\Middleware\AuditLogger;
use GsppManager\Middleware\CsrfMiddleware;
use GsppManager\Security\PasswordHasher;

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

        // TODO: TOTP verification if totp_enabled

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
}
