<?php

declare(strict_types=1);

namespace GsppManager\Middleware;

class CsrfMiddleware
{
    public static function handle(): bool
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // Only check state-changing methods
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['_csrf_token']
            ?? null;

        $sessionToken = $_SESSION['csrf_token'] ?? null;

        if ($token === null || $sessionToken === null || !hash_equals($sessionToken, $token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Ungültiger CSRF-Token. Bitte Seite neu laden.',
            ], JSON_UNESCAPED_UNICODE);
            return false;
        }

        return true;
    }

    public static function generateToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function rotateToken(): string
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
}
