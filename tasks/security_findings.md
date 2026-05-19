# GS++ Manager — Security Findings

---

## [FINDING] SSRF in CatalogController::fetchUrl()
- **Date**: 2026-05-17
- **Severity**: High
- **Location**: `src/Controller/CatalogController.php` — `fetchUrl()` / `import()` / `checkUpdate()`
- **Type**: Server-Side Request Forgery (SSRF) — OWASP A10
- **Description**: The URL validation only checks for an `https?://` scheme prefix but does not resolve the hostname and block internal/private IP ranges. An authenticated user with role `admin` or `isb` can supply a URL such as `http://127.0.0.1:3306/`, `http://192.168.1.1/`, or `http://169.254.169.254/latest/meta-data/` to probe internal network services or cloud instance metadata endpoints. The stream context uses `verify_peer => true`, so external HTTPS is safe, but the internal-network attack surface is open.
- **Recommendation**: After resolving the hostname to an IP (via `gethostbyname()`), block requests to Loopback (`127.0.0.0/8`, `::1`), RFC 1918 private ranges (`10/8`, `172.16/12`, `192.168/16`), Link-Local (`169.254.0.0/16`, `fe80::/10`), and the unspecified address (`0.0.0.0`). Only allow `https://` scheme (reject plain `http://`). Throw `InvalidArgumentException` on violation so the controller returns HTTP 400.
- **Status**: Fixed
- **References**: `docs/problems.md` §S1, `tasks/todo.md` §S1

---

## [FINDING] TOTP Replay Attack — No Used-Code Tracking
- **Date**: 2026-05-17
- **Severity**: Medium
- **Location**: `src/Security/TotpService.php` — `verify()`, `src/Controller/AuthController.php` — `login()`
- **Type**: Authentication Bypass / Replay Attack (OWASP A7)
- **Description**: `TotpService::verify()` accepts any valid TOTP code within a ±1 time-step window (90 seconds total) but does not record the time-step of the last accepted code. An attacker who observes a valid authentication (e.g. via shoulder surfing, network intercept, or phishing) can replay the same 6-digit code against the login endpoint within the same 90-second window and gain authenticated access without possessing the TOTP secret.
- **Recommendation**: Add a `totp_last_used_step INT DEFAULT NULL` column to the `users` table. Extend `TotpService::verify()` to return the matched step (or `false`). In `AuthController::login()` and `ProfileController::totpConfirm()`, persist the returned step to the DB. Reject any code whose matched step is ≤ the stored `totp_last_used_step`.
- **Status**: Fixed
- **References**: `docs/problems.md` §S2, `tasks/todo.md` §S2

---

## [FINDING] No Rate-Limiting on Login or AI Endpoints
- **Date**: 2026-05-17
- **Severity**: High
- **Location**: `src/Controller/AuthController.php` — `login()`, `src/Controller/AiController.php` — all 7 methods, `src/Middleware/` (no `RateLimitMiddleware` exists)
- **Type**: Missing Brute-Force / Abuse Protection (OWASP A7, CWE-307)
- **Description**: There is no rate-limiting middleware or any per-IP attempt counter anywhere in the codebase. `POST /api/auth/login` can be brute-forced indefinitely with no lockout, delay, or 429 response. All 7 AI endpoints (`/api/ai/*`) are equally unprotected — a single tenant can exhaust API quotas or incur unbounded costs by sending thousands of requests. Spec requirement NFA-S11 is entirely unimplemented.
- **Recommendation**: Implement `RateLimitMiddleware` backed by a `rate_limit_attempts` DB table (bucket = SHA-256 of action + IP, sliding window). Apply `5 attempts / 5 minutes` to `POST /api/auth/login` and `20 requests / 1 minute` per user to all `/api/ai/*` endpoints.
- **Status**: Fixed
- **References**: `docs/problems.md` §S3, `tasks/todo.md` §S3, Spec NFA-S11

---

## [FINDING] .env File World-Readable (664 permissions)
- **Date**: 2026-05-19
- **Severity**: High
- **Location**: `/srv/vhosts/gsm.silverday.de/.env` (filesystem)
- **Type**: Secret Handling / Information Disclosure
- **Description**: The `.env` file (containing DB password, `APP_SECRET`, `FIELD_ENCRYPTION_KEY` AES-256 master key, SMTP password, and AI API keys) has permissions `664` (-rw-rw-r--), readable by all local OS users on the server.
- **Recommendation**: `chown silverday:www-data .env && chmod 640 .env` — owner gets rw, Apache group gets r, others get nothing.
- **Status**: Fixed — applied `640 silverday:www-data` (600 broke Apache since file is owned by the dev user, not www-data)

---

## [FINDING] SMTP Password Stored Plaintext in Database
- **Date**: 2026-05-19
- **Severity**: High
- **Location**: `src/Controller/AdminController.php:311-327`
- **Type**: Secret Handling / Insecure Storage
- **Description**: The AI API key is encrypted with AES-256-GCM (`ai_api_key_enc`) before storing in `tenants.settings_json`, but the SMTP password (`smtp_pass`) is stored in the same JSON column entirely in plaintext. Any DB read (SQL injection, backup leak, DB admin access) exposes SMTP credentials.
- **Recommendation**: Encrypt `smtp_pass` via `FieldEncryptor` before persisting, exactly as done for `ai_api_key_enc`.
- **Status**: Fixed

---

## [FINDING] SMTP Exception Message Leaked in HTTP Response
- **Date**: 2026-05-19
- **Severity**: High
- **Location**: `src/Controller/AdminController.php:371-372`
- **Type**: Error Handling / Information Disclosure
- **Description**: `$this->error('SMTP-Test fehlgeschlagen: ' . $e->getMessage(), 502)` passes the raw exception message to the HTTP response. SMTP exceptions can expose internal hostnames, IP addresses, SMTP banners, and credential-related debug strings.
- **Recommendation**: Log `$e->getMessage()` internally; return only a generic error string to the client.
- **Status**: Fixed

---

## [FINDING] No Rate Limiting on Password Reset Endpoints
- **Date**: 2026-05-19
- **Severity**: Medium
- **Location**: `src/Router/routes.php:68-69`
- **Type**: Missing Abuse Protection (OWASP A7)
- **Description**: `POST /api/auth/password-reset/request` and `POST /api/auth/password-reset/confirm` have no rate-limiting middleware. An attacker can flood the request endpoint (burning SMTP quota, DoS-ing a user's inbox) or brute-force the confirm endpoint by creating many tokens via the request endpoint.
- **Recommendation**: Apply the existing `rate_login` (or a dedicated `rate_reset`) middleware to both endpoints.
- **Status**: Fixed

---

## [FINDING] CSRF Missing on Logout Endpoint
- **Date**: 2026-05-19
- **Severity**: Medium
- **Location**: `src/Router/routes.php:65`
- **Type**: Cross-Site Request Forgery (CSRF)
- **Description**: `POST /api/auth/logout` has `['auth']` middleware but no `'csrf'` middleware. A cross-origin page with a form targeting `/api/auth/logout` can force-log out any authenticated user (logout CSRF / session denial-of-service).
- **Recommendation**: Add `'csrf'` to the middleware list: `['auth', 'csrf']`.
- **Status**: Fixed

---

## [FINDING] Session ID Exposed in API Response
- **Date**: 2026-05-19
- **Severity**: Medium
- **Location**: `src/Controller/ProfileController.php:174`
- **Type**: Session Security / Information Disclosure
- **Description**: `GET /api/profile/sessions` returns the raw `session_id()` as the `id` field in the JSON response. The session ID is equivalent to an authentication token; exposing it via API risks theft through XSS, logging, or response caching.
- **Recommendation**: Replace `session_id()` with a non-sensitive opaque identifier (e.g. `hash('sha256', session_id())`), or remove the field entirely.
- **Status**: Fixed

---

## [FINDING] TailoringEngine Reads Catalog Without Tenant Filter
- **Date**: 2026-05-19
- **Severity**: Medium
- **Location**: `src/Service/TailoringEngine.php:37-38`
- **Type**: Authorization / Tenancy Bypass (Defense-in-Depth Gap)
- **Description**: `loadControlsFromCatalog()` queries `SELECT oscal_json FROM catalogs WHERE id = ?` with no `tenant_id` filter. Current callers pre-validate via `CatalogRepository::findByIdAndTenant()`, but the service itself can be called by future code without that guard.
- **Recommendation**: Pass `$tenantId` into `loadControlsFromCatalog()` and add `AND tenant_id = ?` to the SQL query.
- **Status**: Fixed

---

## [FINDING] Sensitive API Responses Missing Cache-Control: no-store
- **Date**: 2026-05-19
- **Severity**: Medium
- **Location**: `src/Controller/BaseController.php` (all JSON responses)
- **Type**: Information Disclosure / Caching
- **Description**: All JSON API responses (including auth tokens, TOTP URIs, user data, OSCAL documents) are served without `Cache-Control: no-store`. Intermediary proxies, CDNs, or browsers could cache sensitive responses.
- **Recommendation**: Add `header('Cache-Control: no-store, private');` in `BaseController::json()` and `BaseController::error()`.
- **Status**: Fixed

---

## [FINDING] HSTS Not Enabled
- **Date**: 2026-05-19
- **Severity**: Medium
- **Location**: `public/.htaccess:26` (commented out)
- **Type**: Transport Security
- **Description**: The HTTPS vhost is live with a valid Let's Encrypt certificate but `Strict-Transport-Security` is explicitly commented out. Without HSTS, downgrade attacks via network manipulation remain possible.
- **Recommendation**: Uncomment `Header set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"` in `.htaccess`.
- **Status**: Fixed

---

## [FINDING] AI Prompt Injection via Unvalidated User-Supplied Fields
- **Date**: 2026-05-19
- **Severity**: Medium
- **Location**: `src/Controller/AiController.php:136-220`
- **Type**: Input Validation / Prompt Injection
- **Description**: Fields like `control_id`, `control_title`, `description`, `industry`, `org_size`, `deadline`, and `implementation_description` are interpolated directly into AI prompts without sanitization or length limits. A malicious authenticated user can inject adversarial instructions to override the system prompt or exfiltrate prompt context.
- **Recommendation**: Validate and truncate all injected fields (e.g. max 500 chars for descriptions, max 100 chars for IDs) before embedding in prompts.
- **Status**: Fixed

---

## [FINDING] No Size Limit on JSON Catalog Import
- **Date**: 2026-05-19
- **Severity**: Medium
- **Location**: `src/Controller/CatalogController.php:83-88`
- **Type**: Input Validation / DoS
- **Description**: When `source='json'`, the raw JSON is taken from `$body['json']` with no explicit size check before parsing. A malicious admin/isb user can submit a multi-megabyte payload causing memory exhaustion.
- **Recommendation**: Add a size check (e.g. `strlen($rawJson) > 20 * 1024 * 1024`) before calling `$this->parser->parse()`.
- **Status**: Fixed

---

## [FINDING] AI API Exception Message Returned to Client
- **Date**: 2026-05-19
- **Severity**: Low
- **Location**: `src/Controller/AiController.php:94-96`
- **Type**: Error Handling / Information Disclosure
- **Description**: `$this->error($e->getMessage(), 502)` returns raw AI API exception messages (Claude/Gemini rate limit details, account status, internal model identifiers) to the client.
- **Recommendation**: Return a generic "KI-Dienst nicht erreichbar" message; log details server-side.
- **Status**: Fixed

---

## [FINDING] Logout Does Not Expire Session Cookie on Client
- **Date**: 2026-05-19
- **Severity**: Low
- **Location**: `src/Controller/AuthController.php:119-127`
- **Type**: Session Management
- **Description**: `logout()` calls `session_destroy()` (invalidates server-side) but does not explicitly expire the session cookie on the client. A second tab with the old cookie may attempt to reuse the destroyed session ID.
- **Recommendation**: Add `setcookie(session_name(), '', ['expires' => time()-3600, 'httponly' => true, 'samesite' => 'Strict', 'secure' => true]);` before `session_destroy()`.
- **Status**: Fixed

---

## [FINDING] unsafe-inline in CSP style-src
- **Date**: 2026-05-19
- **Severity**: Low
- **Location**: `public/.htaccess:24`
- **Type**: Content Security Policy
- **Description**: The CSP includes `style-src 'self' 'unsafe-inline'`, which allows injection of arbitrary inline styles. This can be exploited for CSS-based data exfiltration (though not JS execution).
- **Recommendation**: Replace `'unsafe-inline'` with nonces or use `style-src-elem`/`style-src-attr` directives.
- **Status**: Fixed

---

## [FINDING] Rate Limit Parameters in .env.example Are Dead Code
- **Date**: 2026-05-19
- **Severity**: Informational
- **Location**: `src/Router/routes.php` / `.env.example`
- **Type**: Configuration / Code Quality
- **Description**: `.env.example` documents `RATE_LIMIT_LOGIN_MAX=5` and `RATE_LIMIT_LOGIN_WINDOW=900`, but `RateLimitMiddleware` hardcodes `10 attempts / 60s` and does not read these env vars. The documented config has no effect.
- **Recommendation**: Either read the env vars in the middleware or remove the dead documentation from `.env.example`.
- **Status**: Fixed
