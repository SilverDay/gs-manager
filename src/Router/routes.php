<?php

declare(strict_types=1);

/**
 * Route definitions for GS++ Manager
 *
 * $router is injected from public/index.php
 *
 * @var \GsppManager\Router\Router $router
 */

use GsppManager\Middleware\AuthMiddleware;
use GsppManager\Middleware\CsrfMiddleware;
use GsppManager\Controller\AuthController;
use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DashboardController;

// Register middleware
$router->registerMiddleware('auth', [AuthMiddleware::class, 'handle']);
$router->registerMiddleware('csrf', [CsrfMiddleware::class, 'handle']);

// ─── Authentication ─────────────────────────────────────────────
$router->post('/api/auth/login', AuthController::class, 'login');
$router->post('/api/auth/logout', AuthController::class, 'logout', ['auth']);
$router->get('/api/auth/me', AuthController::class, 'me', ['auth']);
$router->get('/api/auth/csrf-token', AuthController::class, 'csrfToken');

// ─── Dashboard ──────────────────────────────────────────────────
$router->get('/api/dashboard', DashboardController::class, 'index', ['auth']);

// ─── Catalogs ───────────────────────────────────────────────────
$router->get('/api/catalogs', CatalogController::class, 'list', ['auth']);
$router->post('/api/catalogs/import', CatalogController::class, 'import', ['auth', 'csrf']);
$router->get('/api/catalogs/{id}/controls', CatalogController::class, 'controls', ['auth']);
$router->get('/api/catalogs/{id}/controls/{controlId}', CatalogController::class, 'control', ['auth']);
$router->post('/api/catalogs/{id}/check-update', CatalogController::class, 'checkUpdate', ['auth', 'csrf']);

// ─── Domains (Informationsverbund) ──────────────────────────────
// TODO: Phase 2 implementation

// ─── Implementations (SSP / Grundschutzcheck) ───────────────────
// TODO: Phase 3 implementation

// ─── Risks ──────────────────────────────────────────────────────
// TODO: Phase 4 implementation

// ─── Assessments (Audit) ────────────────────────────────────────
// TODO: Phase 5 implementation

// ─── POA&M (Sanierung) ─────────────────────────────────────────
// TODO: Phase 6 implementation

// ─── AI Assistant ───────────────────────────────────────────────
// TODO: Phase 7 implementation
