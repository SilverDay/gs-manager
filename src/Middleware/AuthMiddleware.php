<?php

declare(strict_types=1);

namespace GsppManager\Middleware;

class AuthMiddleware
{
    public static function handle(): bool
    {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['tenant_id'])) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Nicht authentifiziert. Bitte anmelden.',
            ], JSON_UNESCAPED_UNICODE);
            return false;
        }

        // Check session timeout
        $lastActivity = $_SESSION['last_activity'] ?? 0;
        $lifetime = (int) ($_ENV['SESSION_LIFETIME'] ?? 30) * 60;

        if (time() - $lastActivity > $lifetime) {
            session_destroy();
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Sitzung abgelaufen. Bitte erneut anmelden.',
            ], JSON_UNESCAPED_UNICODE);
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    /**
     * Check if current user has one of the required roles
     *
     * @param string[] $allowedRoles
     */
    public static function requireRole(array $allowedRoles): bool
    {
        $userRole = $_SESSION['user_role'] ?? '';

        if (!in_array($userRole, $allowedRoles, true)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Keine Berechtigung für diese Aktion.',
            ], JSON_UNESCAPED_UNICODE);
            return false;
        }

        return true;
    }

    public static function currentUserId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function currentTenantId(): ?int
    {
        return isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : null;
    }

    public static function currentRole(): ?string
    {
        return $_SESSION['user_role'] ?? null;
    }
}
