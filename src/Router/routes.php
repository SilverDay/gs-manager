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
use GsppManager\Controller\AdminController;
use GsppManager\Controller\AuthController;
use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DashboardController;
use GsppManager\Controller\DomainController;
use GsppManager\Controller\ImplementationController;
use GsppManager\Controller\ProfileController;
use GsppManager\Controller\SspController;

// Register middleware
$router->registerMiddleware('auth', [AuthMiddleware::class, 'handle']);
$router->registerMiddleware('csrf', [CsrfMiddleware::class, 'handle']);

// ─── Authentication ─────────────────────────────────────────────
$router->post('/api/auth/login', AuthController::class, 'login');
$router->post('/api/auth/logout', AuthController::class, 'logout', ['auth']);
$router->get('/api/auth/me', AuthController::class, 'me', ['auth']);
$router->get('/api/auth/csrf-token', AuthController::class, 'csrfToken');
$router->post('/api/auth/password-reset/request', AuthController::class, 'passwordResetRequest');
$router->post('/api/auth/password-reset/confirm', AuthController::class, 'passwordResetConfirm');

// ─── User Profile (self-service) ────────────────────────────────
$router->get('/api/profile',                      ProfileController::class, 'show',          ['auth']);
$router->put('/api/profile',                      ProfileController::class, 'update',        ['auth', 'csrf']);
$router->post('/api/profile/change-password',     ProfileController::class, 'changePassword',['auth', 'csrf']);
$router->get('/api/profile/sessions',             ProfileController::class, 'sessions',      ['auth']);
$router->post('/api/profile/totp/setup',          ProfileController::class, 'totpSetup',     ['auth', 'csrf']);
$router->post('/api/profile/totp/confirm',        ProfileController::class, 'totpConfirm',   ['auth', 'csrf']);
$router->delete('/api/profile/totp',              ProfileController::class, 'totpDelete',    ['auth', 'csrf']);

// ─── Admin ──────────────────────────────────────────────────────
$router->get('/api/admin/users',                        AdminController::class, 'listUsers',         ['auth']);
$router->post('/api/admin/users',                       AdminController::class, 'createUser',        ['auth', 'csrf']);
$router->get('/api/admin/users/{id}',                   AdminController::class, 'showUser',          ['auth']);
$router->put('/api/admin/users/{id}',                   AdminController::class, 'updateUser',        ['auth', 'csrf']);
$router->post('/api/admin/users/{id}/reset-password',   AdminController::class, 'resetUserPassword', ['auth', 'csrf']);
$router->get('/api/admin/settings',                     AdminController::class, 'getSettings',       ['auth']);
$router->put('/api/admin/settings',                     AdminController::class, 'updateSettings',    ['auth', 'csrf']);
$router->post('/api/admin/settings/smtp/test',          AdminController::class, 'testSmtp',          ['auth', 'csrf']);

// ─── Dashboard ──────────────────────────────────────────────────
$router->get('/api/dashboard', DashboardController::class, 'index', ['auth']);

// ─── Catalogs ───────────────────────────────────────────────────
$router->get('/api/catalogs', CatalogController::class, 'list', ['auth']);
$router->get('/api/catalogs/library', CatalogController::class, 'library', ['auth']);
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
$router->get('/api/domains/{id}/implementations',       ImplementationController::class, 'list',           ['auth']);
$router->put('/api/implementations/{implId}',           ImplementationController::class, 'update',         ['auth', 'csrf']);
$router->post('/api/implementations/{implId}/evidence', ImplementationController::class, 'uploadEvidence', ['auth', 'csrf']);
$router->get('/api/domains/{id}/ssp/export',            SspController::class,            'export',         ['auth']);
$router->post('/api/domains/{id}/ssp/import',           SspController::class,            'import',         ['auth', 'csrf']);
$router->post('/api/domains/{id}/generate-ssp',         SspController::class,            'generateSsp',    ['auth', 'csrf']);

// ─── Risks ──────────────────────────────────────────────────────
// TODO: Phase 4 implementation

// ─── Assessments (Audit) ────────────────────────────────────────
// TODO: Phase 5 implementation

// ─── POA&M (Sanierung) ─────────────────────────────────────────
// TODO: Phase 6 implementation

// ─── AI Assistant ───────────────────────────────────────────────
// TODO: Phase 7 implementation
