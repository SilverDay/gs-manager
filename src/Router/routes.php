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
use GsppManager\Middleware\RateLimitMiddleware;
use GsppManager\Controller\AdminController;
use GsppManager\Controller\AiController;
use GsppManager\Controller\AssessmentController;
use GsppManager\Controller\AuthController;
use GsppManager\Controller\CatalogController;
use GsppManager\Controller\DashboardController;
use GsppManager\Controller\DomainController;
use GsppManager\Controller\ImplementationController;
use GsppManager\Controller\PoamController;
use GsppManager\Controller\ProfileController;
use GsppManager\Controller\RiskController;
use GsppManager\Controller\SspController;
use GsppManager\Controller\TenantController;

// Register middleware
$router->registerMiddleware('auth',       [AuthMiddleware::class, 'handle']);
$router->registerMiddleware('csrf',       [CsrfMiddleware::class, 'handle']);
$router->registerMiddleware('rate_login', static function (): bool {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $max = (int) ($_ENV['RATE_LIMIT_LOGIN_MAX'] ?? 10);
    $win = (int) ($_ENV['RATE_LIMIT_LOGIN_WINDOW'] ?? 60);
    if (!RateLimitMiddleware::check('login', $ip, $max, $win)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Zu viele Anmeldeversuche. Bitte warten Sie eine Minute.'], JSON_UNESCAPED_UNICODE);
        return false;
    }
    return true;
});
$router->registerMiddleware('rate_reset', static function (): bool {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $max = (int) ($_ENV['RATE_LIMIT_RESET_MAX'] ?? 5);
    $win = (int) ($_ENV['RATE_LIMIT_RESET_WINDOW'] ?? 900);
    if (!RateLimitMiddleware::check('password_reset', $ip, $max, $win)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Zu viele Anfragen. Bitte warten Sie 15 Minuten.'], JSON_UNESCAPED_UNICODE);
        return false;
    }
    return true;
});
$router->registerMiddleware('superadmin', static function (): bool {
    if (empty($_SESSION['is_superadmin'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Kein Zugriff. Nur Plattform-Administratoren dürfen diese Funktion nutzen.'], JSON_UNESCAPED_UNICODE);
        return false;
    }
    return true;
});
$router->registerMiddleware('rate_ai', static function (): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!RateLimitMiddleware::check('ai', $ip, 30, 60)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'KI-Rate-Limit überschritten. Bitte warten Sie eine Minute.'], JSON_UNESCAPED_UNICODE);
        return false;
    }
    return true;
});

// ─── Authentication ─────────────────────────────────────────────
$router->post('/api/auth/login', AuthController::class, 'login', ['rate_login']);
$router->post('/api/auth/logout', AuthController::class, 'logout', ['auth', 'csrf']);
$router->get('/api/auth/me', AuthController::class, 'me', ['auth']);
$router->get('/api/auth/csrf-token', AuthController::class, 'csrfToken');
$router->post('/api/auth/password-reset/request', AuthController::class, 'passwordResetRequest', ['rate_reset']);
$router->post('/api/auth/password-reset/confirm', AuthController::class, 'passwordResetConfirm', ['rate_reset']);

// ─── User Profile (self-service) ────────────────────────────────
$router->get('/api/profile',                      ProfileController::class, 'show',          ['auth']);
$router->put('/api/profile',                      ProfileController::class, 'update',        ['auth', 'csrf']);
$router->post('/api/profile/change-password',     ProfileController::class, 'changePassword', ['auth', 'csrf']);
$router->get('/api/profile/sessions',             ProfileController::class, 'sessions',      ['auth']);
$router->post('/api/profile/totp/setup',          ProfileController::class, 'totpSetup',     ['auth', 'csrf']);
$router->post('/api/profile/totp/confirm',        ProfileController::class, 'totpConfirm',   ['auth', 'csrf']);
$router->delete('/api/profile/totp',              ProfileController::class, 'totpDelete',    ['auth', 'csrf']);
// Alias routes for SPEC §8.1 compatibility (ADR-002)
$router->post('/api/auth/totp/setup',             ProfileController::class, 'totpSetup',     ['auth', 'csrf']);
$router->post('/api/auth/totp/confirm',           ProfileController::class, 'totpConfirm',   ['auth', 'csrf']);
$router->delete('/api/auth/totp',                 ProfileController::class, 'totpDelete',    ['auth', 'csrf']);

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
$router->get('/api/dashboard',                   DashboardController::class, 'index',     ['auth']);
$router->get('/api/domains/{id}/dashboard',      DashboardController::class, 'domainKpis', ['auth']);

// ─── Catalogs ───────────────────────────────────────────────────
$router->get('/api/catalogs', CatalogController::class, 'list', ['auth']);
$router->get('/api/catalogs/library', CatalogController::class, 'library', ['auth']);
$router->post('/api/catalogs/import', CatalogController::class, 'import', ['auth', 'csrf']);
$router->get('/api/catalogs/{id}/controls', CatalogController::class, 'controls', ['auth']);
$router->get('/api/catalogs/{id}/controls/{controlId}', CatalogController::class, 'control', ['auth']);
$router->post('/api/catalogs/{id}/check-update', CatalogController::class, 'checkUpdate', ['auth', 'csrf']);
$router->get('/api/catalogs/{id}/mappings',  CatalogController::class, 'getMappings',    ['auth']);
$router->post('/api/catalogs/{id}/mappings', CatalogController::class, 'importMappings', ['auth', 'csrf']);

// ─── Domains (Informationsverbund) ──────────────────────────────
$router->get('/api/domains',                                   DomainController::class, 'list',           ['auth']);
$router->post('/api/domains',                                  DomainController::class, 'create',         ['auth', 'csrf']);
$router->get('/api/domains/{id}',                              DomainController::class, 'show',           ['auth']);
$router->put('/api/domains/{id}',                              DomainController::class, 'update',         ['auth', 'csrf']);
$router->get('/api/domains/{id}/assets',                       DomainController::class, 'assets',               ['auth']);
$router->post('/api/domains/{id}/assets',                      DomainController::class, 'createAsset',          ['auth', 'csrf']);
$router->put('/api/domains/{id}/assets/{assetId}',             DomainController::class, 'updateAsset',          ['auth', 'csrf']);
$router->delete('/api/domains/{id}/assets/{assetId}',          DomainController::class, 'deleteAsset',          ['auth', 'csrf']);
$router->post('/api/domains/{id}/assets/import-category',      DomainController::class, 'importAssetCategory',  ['auth', 'csrf']);
$router->get('/api/domains/{id}/processes',                    DomainController::class, 'processes',            ['auth']);
$router->post('/api/domains/{id}/processes',                   DomainController::class, 'createProcess',        ['auth', 'csrf']);
$router->put('/api/domains/{id}/processes/{processId}',        DomainController::class, 'updateProcess',        ['auth', 'csrf']);
$router->delete('/api/domains/{id}/processes/{processId}',     DomainController::class, 'deleteProcess',        ['auth', 'csrf']);
$router->get('/api/domains/{id}/scoped-controls',              DomainController::class, 'scopedControls', ['auth']);
$router->post('/api/domains/{id}/tailoring',                   DomainController::class, 'tailoring',      ['auth', 'csrf']);
$router->post('/api/domains/{id}/generate-profile',            DomainController::class, 'generateProfile', ['auth', 'csrf']);

// ─── Implementations (SSP / Grundschutzcheck) ───────────────────
$router->get('/api/domains/{id}/implementations',       ImplementationController::class, 'list',           ['auth']);
$router->put('/api/implementations/{implId}',           ImplementationController::class, 'update',         ['auth', 'csrf']);
$router->post('/api/implementations/{implId}/evidence', ImplementationController::class, 'uploadEvidence', ['auth', 'csrf']);
$router->get('/api/domains/{id}/ssp/export',            SspController::class,            'export',         ['auth']);
$router->post('/api/domains/{id}/ssp/import',           SspController::class,            'import',         ['auth', 'csrf']);
$router->post('/api/domains/{id}/generate-ssp',         SspController::class,            'generateSsp',    ['auth', 'csrf']);

// ─── Risks ──────────────────────────────────────────────────────
$router->get('/api/domains/{id}/risks',                     RiskController::class, 'list',          ['auth']);
$router->post('/api/domains/{id}/risks',                    RiskController::class, 'create',        ['auth', 'csrf']);
$router->put('/api/risks/{riskId}',                         RiskController::class, 'update',        ['auth', 'csrf']);
$router->post('/api/risks/{riskId}/controls',               RiskController::class, 'linkControl',   ['auth', 'csrf']);
$router->delete('/api/risks/{riskId}/controls/{controlId}', RiskController::class, 'unlinkControl', ['auth', 'csrf']);
$router->get('/api/domains/{id}/dashboard/risks',           RiskController::class, 'heatmap',       ['auth']);

// ─── Assessments (Audit) ────────────────────────────────────────
$router->get('/api/domains/{id}/assessments',               AssessmentController::class, 'listPlans',     ['auth']);
$router->post('/api/domains/{id}/assessments',              AssessmentController::class, 'createPlan',    ['auth', 'csrf']);
$router->get('/api/assessments/{planId}',                   AssessmentController::class, 'showPlan',      ['auth']);
$router->put('/api/assessments/{planId}',                   AssessmentController::class, 'updatePlan',    ['auth', 'csrf']);
$router->get('/api/assessments/{planId}/findings',          AssessmentController::class, 'listFindings',  ['auth']);
$router->put('/api/findings/{findingId}',                   AssessmentController::class, 'updateFinding', ['auth', 'csrf']);
$router->get('/api/assessments/{planId}/export/ap',         AssessmentController::class, 'exportAp',      ['auth']);
$router->get('/api/assessments/{planId}/export/ar',         AssessmentController::class, 'exportAr',      ['auth']);

// ─── POA&M (Sanierung) ─────────────────────────────────────────
$router->post('/api/domains/{id}/poam/generate', PoamController::class, 'generate', ['auth', 'csrf']);
$router->get('/api/domains/{id}/poam',           PoamController::class, 'list',     ['auth']);
$router->put('/api/poam/{itemId}',               PoamController::class, 'update',   ['auth', 'csrf']);
$router->get('/api/domains/{id}/poam/export',    PoamController::class, 'export',   ['auth']);

// ─── Dashboard timeline ─────────────────────────────────────────
$router->get('/api/domains/{id}/dashboard/timeline', DashboardController::class, 'timeline', ['auth']);

// ─── Superadmin — Tenant management ────────────────────────────
$router->get('/api/superadmin/tenants',                    TenantController::class, 'list',       ['auth', 'superadmin']);
$router->post('/api/superadmin/tenants',                   TenantController::class, 'create',     ['auth', 'superadmin', 'csrf']);
$router->put('/api/superadmin/tenants/{id}',               TenantController::class, 'update',     ['auth', 'superadmin', 'csrf']);
$router->get('/api/superadmin/tenants/{id}/users',         TenantController::class, 'listUsers',  ['auth', 'superadmin']);
$router->post('/api/superadmin/tenants/{id}/users',        TenantController::class, 'createUser', ['auth', 'superadmin', 'csrf']);
$router->put('/api/superadmin/users/{userId}',             TenantController::class, 'updateUser', ['auth', 'superadmin', 'csrf']);

// ─── AI Assistant ───────────────────────────────────────────────
$router->post('/api/ai/explain',                AiController::class, 'explain',                ['auth', 'csrf', 'rate_ai']);
$router->post('/api/ai/suggest-implementation', AiController::class, 'suggestImplementation',  ['auth', 'csrf', 'rate_ai']);
$router->post('/api/ai/risk-analysis',          AiController::class, 'riskAnalysis',           ['auth', 'csrf', 'rate_ai']);
$router->post('/api/ai/audit-finding',          AiController::class, 'auditFinding',           ['auth', 'csrf', 'rate_ai']);
$router->post('/api/ai/remediation-plan',       AiController::class, 'remediationPlan',        ['auth', 'csrf', 'rate_ai']);
$router->post('/api/ai/maturity-analysis',      AiController::class, 'maturityAnalysis',       ['auth', 'csrf', 'rate_ai']);
$router->post('/api/ai/map-edition-2023',       AiController::class, 'mapEdition2023',         ['auth', 'csrf', 'rate_ai']);
