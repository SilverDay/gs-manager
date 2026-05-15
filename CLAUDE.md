# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

GS++ KMU Compliance Manager — self-hosted LAMP application guiding SMEs through BSI Grundschutz++ ISMS lifecycle. Converts the NTT DATA Grundschutz++ one-page-apps (Profile/SSP/AP/AR/POAM) into a persistent, multi-user, server-backed application.

**Stack:** PHP 8.3 (no framework) · MariaDB 10.11 · Vue 3 + Vite + Tailwind CSS · OSCAL 1.1.3 JSON

## Commands

```bash
# Backend
composer install
php -S localhost:8080 -t public/          # Dev server
php tests/run.php                          # Full test suite (~3s)
php tests/run.php --testsuite Unit         # Unit tests only
php tests/run.php --testsuite Integration  # Integration tests only
php tests/run.php --filter ClassName       # Single test class
php tests/run.php --filter test_method     # Single test method

# Database
php migrations/migrate.php                 # Run pending migrations
php migrations/migrate.php --status        # Show applied/pending
php migrations/migrate.php --rollback      # Roll back last migration

# Frontend (run from frontend/)
npm install
npm run dev    # Vite dev server on :5173 (proxies /api → :8080)
npm run build  # Compile to public/assets/

# Docker
docker-compose up -d
docker-compose exec web php migrations/migrate.php
```

## Architecture

### Request lifecycle

```
Browser → Apache → public/index.php (front-controller)
  → AppConfig::load()  (reads .env, validates required keys)
  → session_start()
  → Router::dispatch()
      → registered middleware (auth, csrf) in order
      → Controller::method($params)
          → Service layer (business logic)
          → Repository layer (PDO, prepared statements)
          → BaseController::json() / error() / paginated()
```

All HTTP responses are JSON. No Blade/Twig — the Vue SPA handles all rendering.

### Key design decisions

- **No framework** — every layer is hand-written. No magic, no container, no ORM.
- **Dual storage pattern** — OSCAL artefacts (Profile, SSP, AP, AR, POAM) are stored twice: full JSON in a `*_json` column for import/export fidelity, and decomposed into relational tables (`scoped_controls`, `implementations`, etc.) for querying, filtering, and progress tracking.
- **Multi-tenancy from day one** — every table has `tenant_id`. `$_SESSION['tenant_id']` is the active tenant; always filter by it.
- **Field encryption** — sensitive DB columns (API keys, TOTP secrets) use `FieldEncryptor` (AES-256-GCM). Key lives in `FIELD_ENCRYPTION_KEY` env var (64 hex chars = 32 bytes).

### Source layout

```
src/
  Config/     AppConfig (env loader), Database (PDO singleton)
  Router/     Router (dispatch + param matching), routes.php
  Middleware/ AuthMiddleware, CsrfMiddleware, AuditLogger
  Controller/ BaseController + one file per module
  Service/    Business logic; OSCAL parsing/export; AI client
  Repository/ DB queries — prepared statements only, always filtered by tenant_id
  Model/      Value objects / DTOs
  Security/   FieldEncryptor, PasswordHasher, CsrfGuard, TotpService
```

### Frontend layout

```
frontend/src/
  main.js           Vue app bootstrap, vue-router, Pinia, nav guards
  App.vue           Root — splits into authenticated layout (sidebar) vs public (login)
  composables/
    useApi.js       Central fetch wrapper: auto CSRF header, 401→login redirect
  stores/
    useAuthStore.js Pinia store: user, isAuthenticated, login/logout/fetchUser
  components/
    LayoutSidebar.vue  Role-filtered navigation
    GlossaryTooltip.vue  OSCAL term tooltips (term + explanation props)
  views/            One file per route (LoginView, DashboardView, CatalogView, …)
```

**Vite dev proxy:** `npm run dev` proxies `/api/*` to `http://localhost:8080`, so the PHP dev server and Vite dev server can run together without CORS issues.

**Tailwind custom tokens:**
- `primary-{50..900}` — brand blue palette
- `status-{implemented|partial|planned|open|na}` — compliance traffic-light colors

## Critical Rules

- **SQL** — prepared statements only, never string concatenation. Always filter by `tenant_id`.
- **Output** — all user-supplied data goes through `json_encode()` or `htmlspecialchars()` before output.
- **Secrets** — never hardcode; use `.env` + `FieldEncryptor` for DB-stored secrets.
- **CSRF** — every state-changing endpoint (`POST`/`PUT`/`DELETE`) must have the `'csrf'` middleware in `routes.php`, or the controller must call `CsrfMiddleware::handle()` explicitly.
- **Audit log** — every data-changing action must call `AuditLogger::log()` with action, entity type, entity ID, and a diff array.
- `declare(strict_types=1);` at the top of every PHP file.
- Namespace pattern: `GsppManager\{Layer}\{ClassName}`.
- Security findings → `tasks/security_findings.md` (never silently fix without documenting).

## Testing

### Structure

```
tests/
  bootstrap.php          Loads .env, overrides DB_DATABASE=gsm-db-test,
                         runs migrations, seeds fixture once per suite
  phpunit.xml
  run.php                Entry point: php tests/run.php [phpunit args]
  Unit/                  No DB, no HTTP — pure logic tests
    UnitTestCase.php     Base: resets $_SESSION and $_SERVER before each test
  Integration/           Real DB (gsm-db-test), controller calls via callController()
    IntegrationTestCase.php
  Fixtures/db/
    test_seed.sql        1 tenant + 7 users (one per role + 1 inactive), password: test-password
```

### Integration test pattern

Each integration test wraps in a **transaction** (`beginTransaction` in setUp, `rollBack` in tearDown). This replaces per-test `TRUNCATE TABLE` with a single rollback — keeping the suite under 5 seconds regardless of test count. Do not add TRUNCATE-based cleanup; use this pattern.

`callController($class, $method, $body, $params, $httpMethod)` — instantiates the controller, sets `$_SERVER`/`$_POST`/`$_SESSION`, captures JSON output via `ob_start()`. In CLI, `php://input` is empty so `BaseController::requestBody()` falls back to `$_POST` — set `$_POST` before calling.

`loginAs($role)` — sets `$_SESSION` as if logged in with the fixture user for that role (IDs 1–6 map to admin/isb/fachverantwortlich/auditor/management/readonly).

### Test DB setup (one-time)

```sql
CREATE DATABASE IF NOT EXISTS `gsm-db-test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `gsm-db-test`.* TO 'gsm-db'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## API Conventions

- All endpoints: `/api/`
- Response: `{"success": true, "data": {...}}` or `{"success": false, "error": "message"}`
- Pagination params: `?page=1&per_page=25` → response includes `meta: {total, page, per_page, last_page}`
- Auth/CSRF required on all endpoints except `POST /api/auth/login` and `GET /api/auth/csrf-token`

## Database Conventions

- `snake_case` table and column names; plural table names
- FK columns: `{singular_table}_id`
- JSON columns: `*_json` suffix (store full OSCAL documents here)
- Timestamps: `created_at` / `updated_at` as `DATETIME` (not `TIMESTAMP`)
- No soft deletes — use `status` ENUM or `is_active` BOOLEAN
- All tables: `ENGINE=InnoDB`

## OSCAL Specifics

- Catalog source: BSI Stand-der-Technik-Bibliothek (GitHub, OSCAL 1.1.3 JSON)
- Control IDs follow GS++ format: `PERS.3.1`, `BES.7.4.10`, `ASST.3.1`
- Export filenames must match NTT DATA convention: `{Name}_Profile.json`, `{Name}_SSP.json`, `{Name}_SSP-edited.json`, `{Name}_AP.json`, `{Name}_AR.json`, `{Name}_POAM.json`
- OSCAL artefacts are validated against OSCAL 1.1.3 schema before export (schema stored as fixture under `tests/Fixtures/oscal/`)

## Key References

- `docs/SPEC.md` — full functional spec, phase plan, and OSCAL compatibility requirements
- `docs/DATABASE.md` — complete schema with all `CREATE TABLE` statements
- `docs/API.md` — REST API endpoint documentation
- `docs/SPEC.md §17` — testing strategy, coverage targets, anti-patterns
