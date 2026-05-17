# GS++ Manager — Fix-Plan für problems.md Befunde

**Erstellt:** 17.05.2026  
**Quelle:** `docs/problems.md`  
**Priorität:** Security → Bugs → Fehlende Features → Tests → Code-Smells

Status: `[ ]` offen · `[x]` erledigt · `[!]` blockiert

---

## Vorstufe — Pflichtdokumentation (vor Beginn aller Wellen)

### DOC1 — `tasks/security_findings.md` befüllen (CLAUDE.md-Pflicht)

- [x] **DOC1.1** `tasks/security_findings.md`: Befund für S1 (SSRF) mit Status `Open` eintragen
- [x] **DOC1.2** `tasks/security_findings.md`: Befund für S2 (TOTP Replay) mit Status `Open` eintragen
- [x] **DOC1.3** `tasks/security_findings.md`: Befund für S3 (fehlendes Rate-Limiting) mit Status `Open` eintragen
- [x] **DOC1.4** Nach Abschluss von Welle 1: alle drei Einträge auf Status `Fixed` setzen und Fix-Commit referenzieren

---

## Welle 1 — Sicherheitskritisch (sofort, vor jedem Deployment)

### S1 — SSRF-Schutz in `CatalogController::fetchUrl()` 🔴

- [x] **S1.1** `src/Controller/CatalogController.php`: Private Methode `validateUrl(string $url): void` extrahieren.
  - Prüft Schema (nur `https://` — HTTP ablehnen)
  - Hostnamen per `gethostbyname()` / `dns_get_record()` auflösen
  - Aufgelöste IP gegen Blocklist prüfen: Loopback (`127.0.0.0/8`, `::1`), RFC1918 (`10/8`, `172.16/12`, `192.168/16`), Link-Local (`169.254/16`, `fe80::/10`), Broadcast (`0.0.0.0`)
  - Bei Treffer: `InvalidArgumentException` werfen → Controller antwortet mit 400
- [x] **S1.2** `fetchUrl()` ruft `validateUrl()` vor jedem `file_get_contents`-Stream-Aufruf auf
- [x] **S1.3** Integration-Test: `CatalogControllerTest::testImportBlocksInternalUrls()` — testet `127.0.0.1`, `192.168.1.1`, `169.254.169.254`
- [x] **S1.4** Eintrag in `tasks/security_findings.md` aktualisieren (Status → Fixed)

### S2 — TOTP Replay-Schutz 🔴

- [x] **S2.1** Migration `20260518_007_totp_last_used_step.sql` erstellen:
  ```sql
  ALTER TABLE users ADD COLUMN totp_last_used_step INT DEFAULT NULL;
  ```
- [x] **S2.2** `src/Security/TotpService.php`: Signatur von `verify()` erweitern auf `verify(string $secret, string $code, int $currentStep, ?int $lastUsedStep): bool`
  - Ablehnen wenn `$matchedStep <= $lastUsedStep`
  - Rückgabe: nicht `bool` sondern `int|false` (den gematchten Step zurückgeben, damit der Aufrufer ihn persistieren kann)
- [x] **S2.3** `src/Controller/AuthController.php` `login()`: Nach erfolgreichem TOTP-Verify `totp_last_used_step` in DB schreiben (via UserRepository-Methode oder direktes PDO im AuthController)
- [x] **S2.4** `src/Controller/ProfileController.php` `totpConfirm()`: Ebenso `totp_last_used_step = 0` beim ersten Confirm setzen
- [x] **S2.5** Unit-Test `tests/Unit/Security/TotpServiceTest.php` erstellen:
  - Happy-Path: gültiger Code, neuer Step
  - Replay-Block: selber Step wie `lastUsedStep`
  - Expired window: Step zu alt
  - Format-Validation: nicht 6-stellig
- [x] **S2.6** Eintrag in `tasks/security_findings.md` aktualisieren (Status → Fixed)

### S3 — Rate-Limiting auf Login-Endpunkt 🔴

- [x] **S3.1** Migration `20260518_008_rate_limit.sql` erstellen:
  ```sql
  CREATE TABLE rate_limit_attempts (
      id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      bucket     VARCHAR(128) NOT NULL,   -- SHA-256(action + ':' + ip)
      hits       SMALLINT UNSIGNED DEFAULT 1,
      window_start DATETIME NOT NULL,
      INDEX (bucket, window_start)
  ) ENGINE=InnoDB;
  ```
- [x] **S3.2** `src/Middleware/RateLimitMiddleware.php` implementieren:
  - Methode `check(string $action, string $ip, int $maxHits, int $windowSeconds): void`
  - Bucket-Key: `hash('sha256', $action . ':' . $ip)`
  - Zählt Hits in der DB innerhalb des Fensters; bei Überschreitung: 429 mit `Retry-After`-Header
  - Cleanup: Rows älter als `$windowSeconds` löschen (bei jedem Check oder per separater Prune-Methode)
- [x] **S3.3** `src/Router/routes.php`: `'rate_limit'`-Middleware registrieren
- [x] **S3.4** `src/Router/routes.php` und `AuthController::login()`: Rate-Limit 5/5min auf Login-Route anwenden
- [x] **S3.5** `src/Router/routes.php`: Rate-Limit 20/min auf alle 7 AI-Routen anwenden (→ B2 in problems.md)
- [x] **S3.6** Unit-Test `tests/Unit/Middleware/RateLimitMiddlewareTest.php` (mit In-Memory-Stub)
- [x] **S3.7** Integration-Test: `AuthControllerTest::testLoginIsRateLimited()` — 6 Fehlversuche → 429
- [x] **S3.8** Eintrag in `tasks/security_findings.md` aktualisieren (Status → Fixed)

---

## Welle 2 — Bugs (vor nächstem Feature-Release)

### B1 — Off-by-one Password-Reset Brute-Force 🟠

- [x] **B1.1** `src/Controller/AuthController.php` `passwordResetConfirm()`:
  - Inkrement `failed_attempts` nur ausführen, wenn Token **ungültig** ist (nach Hash-Vergleich, vor Token-Löschen)
  - Schwellwert-Check von `>= 5` auf `>= 5` belassen, aber Inkrement-Position korrigieren (Inkrement nur bei falschem Token, nicht vor der Prüfung)
- [x] **B1.2** Integration-Test erweitern: `AuthControllerTest::testPasswordResetAllows5thAttempt()` — stellt sicher, dass korrektes Token bei Attempt 5 noch funktioniert

### B2 — Audit-Log für `updateUser()` bereinigen 🟠

- [x] **B2.1** `src/Controller/AdminController.php` `updateUser()`:
  - Alten Benutzer-Datensatz **vor** dem Update laden (`AdminRepository::findById()` oder direkt PDO)
  - `AuditLogger::diff($oldUser, $newUser, ['display_name', 'role', 'is_active'])` verwenden
  - `$body` nicht mehr direkt an `AuditLogger::log()` übergeben

### B3 — Client-IP im Audit-Log 🟡

- [x] **B3.1** `src/Middleware/AuditLogger.php`: Neue private statische Methode `resolveClientIp(): string`:
  - Liest `HTTP_X_FORWARDED_FOR` nur wenn `TRUST_PROXY=true` in `.env` gesetzt ist
  - Fällt auf `REMOTE_ADDR` zurück
  - Nimmt nur das **erste** (linkeste) Element aus `X-Forwarded-For` (der tatsächliche Client)
- [x] **B3.2** `.env.example`: `TRUST_PROXY=false` dokumentieren

### B4 — MailService EHLO Server-Name 🟡

- [x] **B4.1** `src/Service/MailService.php`: `EHLO`-Hostname aus `AppConfig::get('APP_HOSTNAME', 'localhost')` lesen statt `$_SERVER['SERVER_NAME']`
- [x] **B4.2** `.env.example`: `APP_HOSTNAME=` eintragen

### B5 — AI-Cache TTL 🟡

- [x] **B5.1** Migration `20260518_009_ai_cache_ttl.sql`:
  ```sql
  ALTER TABLE ai_cache ADD COLUMN expires_at DATETIME NOT NULL DEFAULT (NOW() + INTERVAL 30 DAY),
                       ADD INDEX (expires_at);
  ```
- [x] **B5.2** `src/Repository/AiCacheRepository.php`:
  - `store()`: `expires_at = NOW() + INTERVAL {$ttlDays} DAY` (konfigurierbarer TTL, default 30 Tage)
  - `get()`: `AND expires_at > NOW()` zur Query hinzufügen
  - Neue Methode `prune()`: löscht abgelaufene Einträge (für Cron-Aufruf)

### B6 — SSP-Import Größenlimit 🟡

- [x] **B6.1** `src/Controller/SspController.php` `import()`:
  - Vor dem Lesen von `php://input`: `Content-Length`-Header prüfen (falls gesetzt, bei > 20 MB → 413)
  - Nach dem Lesen: `strlen($raw) > 20 * 1024 * 1024` → 413 mit Fehlermeldung

### B7 — CSRF-Token-Rotation nach Login 🟡

- [x] **B7.1** `src/Controller/AuthController.php` `login()`: Nach erfolgreichem Login `CsrfMiddleware::rotateToken()` aufrufen, um den Pre-Login-Token zu invalidieren
- [x] **B7.2** Integration-Test: `AuthControllerTest::testCsrfTokenRotatesOnLogin()` — Token vor und nach Login vergleichen

---

## Welle 3 — Fehlende Pflichtfunktionen

### F1 — Route + Implementierung `import-category` 🔵

- [x] **F1.1** `src/Repository/DomainRepository.php`: Methode `importAssetCategory(int $domainId, array $categoryData): int` — legt Asset anhand der Zielobjektkategorie-Daten (UUID, Name, Typ) an
- [x] **F1.2** `src/Controller/DomainController.php`: Methode `importAssetCategory(array $params)` — liest JSON-Body (`category_uuid`, `category_name`, `asset_type`, optionale Felder), ruft Repository auf, gibt 201 zurück; nur `isb`/`admin` erlaubt
- [x] **F1.3** `src/Router/routes.php`: Route `POST /api/domains/{id}/assets/import-category` registrieren
- [x] **F1.4** Integration-Test: `DomainControllerTest::testImportAssetCategory()`

### F2 — Domain-spezifischer Dashboard-Endpunkt 🔵

- [x] **F2.1** `src/Controller/DashboardController.php`: Neue Methode `domainKpis(array $params)`:
  - Validiert Domain gehört zu Tenant
  - Aggregiert: Controls gesamt/umgesetzt/offen/geplant, Umsetzungsquote %, offene POA&M-Items, überfällige Items, letzter Audit-Status
  - Rollenfilter: `management` und `readonly` dürfen lesen; alle anderen auch
- [x] **F2.2** `src/Router/routes.php`: Route `GET /api/domains/{id}/dashboard` registrieren
- [x] **F2.3** Integration-Test: `DashboardControllerTest::testDomainKpis()`

### F3 — `DiffService` + Versionierung (NFA-D3) 🔵

- [x] **F3.1** `src/Service/DiffService.php` implementieren:
  - `diff(array $old, array $new): array` — gibt `{field: {old: ..., new: ...}}` zurück (rekursiv für Arrays)
  - `snapshotDocument(string $type, int $domainId, array $oscalJson, string $changelog, int $userId): void` — schreibt Zeile in `document_versions`
- [x] **F3.2** `src/Service/OscalExporter.php`: Nach jedem Export (`exportSsp`, `exportAp`, `exportAr`, `exportPoam`) `DiffService::snapshotDocument()` aufrufen
- [x] **F3.3** Unit-Test `tests/Unit/Service/DiffServiceTest.php`:
  - Happy-Path: zwei Arrays mit Änderungen
  - Keine Änderungen → leeres Array
  - Nested Arrays

### F4 — `MappingService` + Tabellen + Import (F1.7, F3.7) 🔵

- [x] **F4.1** Migration `20260518_010_mapping_tables.sql`:
  ```sql
  CREATE TABLE mapping_baustein_zo (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      baustein_id VARCHAR(50) NOT NULL,
      baustein_name VARCHAR(255),
      zo_category VARCHAR(100),
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX (baustein_id)
  ) ENGINE=InnoDB;

  CREATE TABLE mapping_controls_anf (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      control_id_str VARCHAR(50) NOT NULL,
      edition2023_id VARCHAR(50),
      edition2023_text TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX (control_id_str)
  ) ENGINE=InnoDB;
  ```
- [x] **F4.2** `src/Service/MappingService.php` implementieren:
  - `importBausteinMapping(array $data): int` — bulk-insert in `mapping_baustein_zo`
  - `importControlsMapping(array $data): int` — bulk-insert in `mapping_controls_anf`
  - `findEdition2023(string $controlId): ?array` — sucht Zuordnung für Control-ID
  - `findZoCategory(string $bausteinId): ?string`
- [x] **F4.3** `src/Controller/CatalogController.php`: Methode `importMappings()` für `POST /api/catalogs/mappings/import` (Upload der NTT DATA `hilfsdateien/*.json`)
- [x] **F4.4** Route `POST /api/catalogs/mappings/import` in `routes.php` registrieren
- [x] **F4.5** Unit-Test `tests/Unit/Service/MappingServiceTest.php`

### F5 — Notification-Scheduler (F5.4) 🔵

- [x] **F5.1** `bin/notify-deadlines.php` Console-Skript erstellen:
  - Lädt Konfiguration via `AppConfig::load()`
  - Liest alle POA&M-Items mit Deadline in den nächsten `NOTIFY_DAYS_AHEAD` Tagen (default 7, aus `.env`)
  - Ruft `NotificationService::sendDeadlineReminder()` je Item auf
  - Gibt Summary auf STDOUT aus (für Cron-Logging)
- [x] **F5.2** `docker/crontab` (oder Doku in README): Cron-Eintrag für täglichen Run, z.B.:
  ```
  0 7 * * * php /var/www/bin/notify-deadlines.php >> /var/log/gsm-notify.log 2>&1
  ```
- [x] **F5.3** `.env.example`: `NOTIFY_DAYS_AHEAD=7` eintragen
- [x] **F5.4** Test `NotificationServiceTest.php` prüfen/erweitern: sicherstellen dass gemockter Transport aufgerufen wird

### F6 — `checkUpdate()` führt tatsächliches Update durch (F1.6) 🔵

- [x] **F6.1** `src/Controller/CatalogController.php` `checkUpdate()`:
  - Aktuellen Request-Body um optionales `"apply": true` erweitern
  - Wenn `apply === true` und Update verfügbar: neues JSON herunterladen, `CatalogRepository::updateAfterReimport()` aufrufen, Audit-Log-Eintrag schreiben
  - Response: `{"update_available": true, "applied": true/false}`
- [x] **F6.2** Integration-Test: `CatalogControllerTest::testCheckUpdateAppliesUpdate()` — mit gemockter URL

### F7 — OSCAL Schema-Validierung beim Import (NFA-D1) 🔵

- [x] **F7.1** Sicherstellen, dass Schema-Fixture existiert: `tests/Fixtures/oscal/oscal-catalog-schema-1.1.3.json` (vom NIST-GitHub herunterladen, wenn nicht vorhanden)
- [x] **F7.2** `src/Service/OscalParser.php`: Methode `validateAgainstSchema(array $data, string $schemaPath): void` — prüft JSON gegen Schema (pragmatisch: prüft Pflichtfelder `catalog.uuid`, `catalog.metadata.title`, `catalog.metadata.last-modified`, `catalog.groups`)
- [x] **F7.3** `parse()` ruft `validateAgainstSchema()` auf; bei Fehler: aussagekräftige `InvalidArgumentException` mit Feldname
- [x] **F7.4** `OscalParserTest.php`: Test mit invalider Catalog-Struktur ergänzen → Exception erwartet

### F8 — `security-sensitivity-level` aus Asset-Schutzbedarf ableiten (F2.1) 🔵

- [x] **F8.1** `src/Service/OscalExporter.php`: Private Methode `deriveSecurityLevel(array $assets): string`:
  - Aggregiert `protection_need_c/i/a` aller Assets der Domain
  - Wenn mind. ein Asset `high` in einem CIA-Bereich → `'high'`; sonst `'moderate'`
- [x] **F8.2** `exportSsp()` übergibt geladene Assets an `deriveSecurityLevel()` statt Hardcode
- [x] **F8.3** `OscalExporterTest.php` (→ T1 muss zuerst existieren): Test für `high` und `moderate` Ableitung

### F9 — ISMS-Typ-Wechsel synchronisiert Controls (F2.1) 🔵

- [x] **F9.1** `src/Controller/DomainController.php` `update()`:
  - Alten `isms_type` aus DB laden (vor dem Update)
  - Wenn `isms_type` in Body vorhanden **und** unterscheidet sich vom alten Wert:
    - `TailoringEngine::loadControlsFromCatalog()` mit neuem Typ aufrufen
    - `DomainRepository::saveScopedControls()` mit neuem Control-Set aufrufen
    - Audit-Log-Eintrag für `isms_type_changed`
- [x] **F9.2** Integration-Test: `DomainControllerTest::testIsmTypeChangeRescopesControls()`

---

## Welle 4 — Fehlende Tests

### T1 — `OscalExporterTest.php` erstellen (§17.3, NFA-T7) 🔵

- [x] **T1.1** `tests/Unit/Service/OscalExporterTest.php` erstellen:
  - Happy-Path: `exportSsp()` mit Fixture-Daten → valides OSCAL-JSON mit korrekten Schlüsseln
  - Roundtrip: `OscalParser::parse()` auf exportierten SSP anwenden → kein Validierungsfehler
  - Dateinamen-Konvention: `{Name}_SSP-edited.json`-Format prüfen
  - `security-sensitivity-level` korrekt abgeleitet (→ F8)

### T2 — `DiffServiceTest.php` (nach F3 implementiert) 🔵

Abgedeckt durch F3.3 — keine separaten Schritte nötig.

### T3 — `TotpServiceTest.php` (nach S2 implementiert) 🔵

Abgedeckt durch S2.5 — keine separaten Schritte nötig.

### T4 — `CsrfMiddlewareTest.php` in `Unit/Security/` verschieben 🔵

- [x] **T4.1** `tests/Unit/Security/CsrfMiddlewareTest.php` anlegen (Inhalt von `tests/Unit/Middleware/CsrfMiddlewareTest.php` kopieren)
- [x] **T4.2** `tests/Unit/Middleware/CsrfMiddlewareTest.php` löschen
- [x] **T4.3** `phpunit.xml` prüfen, ob Testsuites-Pfade angepasst werden müssen

### T5 — Repository-Integration-Tests (NFA-T3) 🔵

- [x] **T5.1** `tests/Integration/Repository/DomainRepositoryTest.php`:
  - `create`, `findById`, `update`, `findAssets`, `createAsset`, `findProcesses`, `createProcess`
- [x] **T5.2** `tests/Integration/Repository/RiskRepositoryTest.php`:
  - `create`, `findById`, `update`, Risk-Level-Berechnung, `linkControl`, `unlinkControl`
- [x] **T5.3** `tests/Integration/Repository/AssessmentRepositoryTest.php`:
  - `create`, `ensureFindingsExist`, Befund-Aggregation (satisfied/not_satisfied-Zählung)
- [x] **T5.4** `tests/Integration/Repository/PoamRepositoryTest.php`:
  - `generateFromPlan`, Deadline-Eskalationslogik (`Clock::setNow()` für überfällige Items)

### T6 — Vitest Frontend-Tests einrichten (NFA-T6) 🔵

- [x] **T6.1** `frontend/package.json`: `vitest` und `@vue/test-utils` als devDependencies hinzufügen
- [x] **T6.2** `frontend/vitest.config.js` erstellen (jsdom environment, coverage mit istanbul)
- [x] **T6.3** `frontend/src/stores/useAuthStore.test.js`: login/logout/fetchUser Happy-Path + 401-Redirect
- [x] **T6.4** `frontend/src/composables/useApi.test.js`: CSRF-Header wird automatisch gesetzt; 401 löst Redirect aus
- [x] **T6.5** `frontend/package.json`: `"test"` und `"test:coverage"` Scripts hinzufügen

---

## Welle 5 — Code-Smells (niedriger Aufwand, sauberer Code)

### CS1 — `use`-Statements in `routes.php` ans Dateiende 🔵

- [x] **CS1.1** `src/Router/routes.php`: `use GsppManager\Controller\AssessmentController`, `PoamController`, `AiController` an den Block der anderen `use`-Statements am Anfang der Datei verschieben

### CS2 — `safeFilename()` in `BaseController` heben ⚪

- [x] **CS2.1** `src/Controller/BaseController.php`: `protected static function safeFilename(string $name): string` einfügen
- [x] **CS2.2** `AssessmentController` und `PoamController`: lokale Methoden entfernen, Elternklasse aufrufen

### CS3 — `AiController::$testClientOverride` kapseln ⚪

- [x] **CS3.1** `src/Controller/AiController.php`: Property auf `private static` ändern, Zugriff über `AiController::setTestClient(?AiClientInterface $client)` — nur im Test-Kontext aufrufen

### CS4 — `OscalParser::findControl()` indexieren ⚪

- [x] **CS4.1** `src/Service/OscalParser.php`: `flattenControls()` gibt assoziatives Array `[id => control]` zurück; `findControl()` macht direkten `$map[$id] ?? null`-Lookup

### CS5 — `CsrfGuard.php` Pfad-Abweichung von Spec §6.2 dokumentieren ⚪

- [x] **CS5.1** `tasks/decisions.md`: Eintrag hinzufügen, der erklärt warum `src/Middleware/CsrfMiddleware.php` statt `src/Security/CsrfGuard.php` (Spec §6.2) gewählt wurde (CSRF ist Middleware, kein Security-Primitive — bewusste Entscheidung)
- [x] **CS5.2** `docs/problems.md` Abschnitt 5: Eintrag mit Verweis auf `tasks/decisions.md` aktualisieren

### CS6 — TOTP-Routen-Pfad-Abweichung von Spec §8.1 klären ⚪

- [x] **CS6.1** Entscheiden: Spec §8.1 listet `POST /api/auth/totp/setup` und `POST /api/auth/totp/verify`; Code verwendet `/api/profile/totp/setup` und `/api/profile/totp/confirm`
  - Option A (empfohlen): `/api/auth/totp/*` als Alias-Routen registrieren, die intern auf dieselben Controller-Methoden zeigen — kein doppelter Code
  - Option B: Abweichung als bewusste Entscheidung in `tasks/decisions.md` dokumentieren und SPEC.md §8.1 entsprechend korrigieren
- [x] **CS6.2** Gewählte Option umsetzen

---

## Abschluss-Checkliste (nach allen Wellen)

- [x] `php tests/run.php` — alle Tests grün
- [x] `tasks/security_findings.md` — alle Findings auf Status `Fixed` oder `Accepted Risk`
- [x] `docs/problems.md` — alle Einträge mit Verweis auf Fix-Commit annotieren
- [x] `CHANGELOG.md` oder Git-Tag für den Fix-Release erstellen
