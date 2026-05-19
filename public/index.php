<?php

declare(strict_types=1);

/**
 * GS++ KMU Compliance Manager — Front Controller
 *
 * All HTTP requests are routed through this file.
 */

// Strict error reporting in development
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Never display to client
ini_set('log_errors', '1');

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use GsppManager\Config\AppConfig;
use GsppManager\Router\Router;

// Load environment
AppConfig::load(__DIR__ . '/..');

// Session configuration
$sessionLifetime = (int) ($_ENV['SESSION_LIFETIME'] ?? 30) * 60;
$sessionName = $_ENV['SESSION_NAME'] ?? 'gspp_session';
$isSecure = ($_ENV['APP_ENV'] ?? 'production') === 'production';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_secure', $isSecure ? '1' : '0');
ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
ini_set('session.cookie_lifetime', (string) $sessionLifetime);
session_name($sessionName);
session_start();

// CORS headers for Vite dev server
if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
    header('Access-Control-Allow-Origin: http://localhost:5173');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Global exception handler — returns standard JSON envelope instead of blank 500
set_exception_handler(static function (\Throwable $e): void {
    error_log('[uncaught] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
    }
    echo json_encode(['success' => false, 'error' => 'Interner Serverfehler.'], JSON_UNESCAPED_UNICODE);
});

// Route the request
$router = new Router();
require_once __DIR__ . '/../src/Router/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
