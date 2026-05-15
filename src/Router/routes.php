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
use GsppManager\Controller\DomainController;

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
$router->get('/api/domains',                                   DomainController::class, 'list',           ['auth']);
$router->post('/api/domains',                                  DomainController::class, 'create',         ['auth', 'csrf']);
$router->get('/api/domains/{id}',                              DomainController::class, 'show',           ['auth']);
$router->put('/api/domains/{id}',                              DomainController::class, 'update',         ['auth', 'csrf']);
$router->get('/api/domains/{id}/assets',                       DomainController::class, 'assets',         ['auth']);
$router->post('/api/domains/{id}/assets',                      DomainController::class, 'createAsset',    ['auth', 'csrf']);
$router->get('/api/domains/{id}/processes',                    DomainController::class, 'processes',      ['auth']);
$router->post('/api/domains/{id}/processes',                   DomainController::class, 'createProcess',  ['auth', 'csrf']);
$router->get('/api/domains/{id}/scoped-controls',              DomainController::class, 'scopedControls', ['auth']);
$router->post('/api/domains/{id}/tailoring',                   DomainController::class, 'tailoring',      ['auth', 'csrf']);
$router->post('/api/domains/{id}/generate-profile',            DomainController::class, 'generateProfile',['auth', 'csrf']);

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
