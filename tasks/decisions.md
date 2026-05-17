# Architectural Decisions

## ADR-001 — CsrfGuard vs. CsrfMiddleware

**Status:** Accepted

**Context:**
The SPEC.md §6.2 references a class called `CsrfGuard` for handling CSRF token validation.

**Decision:**
Implemented as `src/Middleware/CsrfMiddleware.php` (class `CsrfMiddleware`) rather than `CsrfGuard`.

**Rationale:**
- The PHP middleware pattern (a class invoked by the router) is more idiomatic for this architecture.
- Naming it `CsrfMiddleware` is consistent with `AuthMiddleware` and `RateLimitMiddleware` in the same folder.
- Using `Middleware` as the suffix makes the execution order in `routes.php` self-documenting.

**Consequences:**
Any documentation or tests referencing `CsrfGuard` should be updated to `CsrfMiddleware`. No alias class is created.

---

## ADR-002 — TOTP route path: /api/profile/totp/* vs. /api/auth/totp/*

**Status:** Accepted

**Context:**
The SPEC.md §8.1 specifies TOTP management routes under `/api/auth/totp/*`.

**Decision:**
Routes are registered under `/api/profile/totp/*` (e.g. `POST /api/profile/totp/enable`, `POST /api/profile/totp/confirm`, `DELETE /api/profile/totp/disable`).

**Rationale:**
- TOTP management is a profile/account-settings feature, not an authentication flow.
- Grouping it under `/api/profile/` co-locates it with password change and other personal settings.
- The `/api/auth/` namespace is reserved for stateless auth flows (login, logout, password reset, CSRF token endpoint).

**Backward Compatibility:**
Alias routes (`GET/POST /api/auth/totp/*` → `ProfileController`) are registered in `routes.php` to maintain compatibility with any existing API clients referencing the spec paths.

---

## ADR-003 — DiffService document_versions table

**Status:** Accepted (pending migration)

**Context:**
The OscalExporter creates OSCAL SSP/AP/AR/POAM documents. There was no versioning or change-tracking mechanism.

**Decision:**
Created `src/Service/DiffService.php` with `snapshotDocument()` and `diff()`. The snapshot method writes to a `document_versions` table.

**Migration required:**
A migration to create `document_versions` must be added before this feature is used in production.

```sql
CREATE TABLE document_versions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    domain_id       INT UNSIGNED NOT NULL,
    entity_type     VARCHAR(50)  NOT NULL,
    entity_id       INT UNSIGNED NOT NULL,
    version_number  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    document_json   LONGTEXT     NOT NULL,
    changes_json    TEXT         DEFAULT NULL,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME     NOT NULL,
    INDEX idx_dv_entity (tenant_id, entity_type, entity_id),
    INDEX idx_dv_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## ADR-004 — flattenControls return type keyed by control ID

**Status:** Accepted

**Context:**
`OscalParser::flattenControls()` previously returned a numerically indexed list, causing `findControl()` to perform an O(n) linear scan.

**Decision:**
`flattenControls()` now returns a `string → array` map keyed by the control's `id` field. `findControl()` performs a single O(1) key lookup.

**Consequences:**
All callers of `flattenControls()` that iterated over integer indices still work because PHP's `foreach` iterates by value regardless of key type. However, callers that previously used `array_values()` or integer offsets must be reviewed.
