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
