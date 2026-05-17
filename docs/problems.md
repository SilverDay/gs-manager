# GS++ Manager — Code vs. Spec: Inconsistencies, Problems & Bugs

**Stand:** 17.05.2026  
**Basis:** Vergleich Quellcode gegen `docs/SPEC.md`

---

## Legende

| Symbol | Bedeutung |
|--------|-----------|
| 🔴 | Sicherheitsproblem (High/Critical) |
| 🟠 | Mittleres Problem / fehlende Pflichtfunktion |
| 🟡 | Kleines Problem / Low-severity Bug |
| 🔵 | Fehlende Implementierung (Spec-Anforderung nicht erfüllt) |
| ⚪ | Code-Smell / Stilproblem ohne Funktionsauswirkung |

---

## 1. Sicherheitsprobleme (Security)

### 🔴 S1 — SSRF in `CatalogController::fetchUrl()` (NFA-S11 / OWASP A10)

**Datei:** `src/Controller/CatalogController.php`  
**Spec:** F1.1 (Katalog-Import per GitHub-URL), NFA-S11 (Rate-Limiting / Input-Validierung)

Die URL-Validierung prüft nur das Schema (`https?://`), blockt aber keine internen Adressen. Ein Benutzer mit Rolle `admin` oder `isb` kann damit interne Services ansprechen:

```
POST /api/catalogs/import
{"source": "http://127.0.0.1:3306/"}
{"source": "http://169.254.169.254/latest/meta-data/"}
```

**Fix:** IP-Auflösung nach DNS durchführen und RFC1918-Ranges (`10.x`, `172.16–31.x`, `192.168.x`), Loopback (`127.x`, `::1`) sowie Link-Local (`169.254.x`) vor dem Request blockieren.

---

### 🔴 S2 — TOTP Replay-Angriff möglich (NFA-S4)

**Datei:** `src/Security/TotpService.php`  
**Spec:** F7.3 (TOTP-Authentifizierung), NFA-S4

`TotpService::verify()` prüft ein gültiges TOTP-Fenster von ±1 Schritt (90 Sekunden), speichert aber **keine verbrauchten Codes**. Innerhalb des Fensters kann derselbe Code mehrfach gültig verwendet werden.

**Fix:** Letzten verwendeten TOTP-Zeitschritt pro Benutzer in der DB speichern (Spalte `totp_last_used_step INT`) und Codes ablehnen, die nicht neuer als der gespeicherte Schritt sind.

---

### 🔴 S3 — Kein Rate-Limiting auf Login-Endpunkt (NFA-S11)

**Datei:** `src/Controller/AuthController.php`, `src/Middleware/` (kein `RateLimitMiddleware`)  
**Spec:** NFA-S11 — „Rate-Limiting auf Login und API-Endpunkten"

Es existiert keinerlei Rate-Limiting-Implementierung im gesamten Codebase. Bruteforce auf `POST /api/auth/login` ist ohne Gegenwehr möglich. Spec-Anforderung NFA-S11 ist vollständig unerfüllt.

**Fix:** `RateLimiter`-Middleware implementieren (z.B. auf Basis von `REMOTE_ADDR` + IP-Hash in MariaDB oder einer Counter-Tabelle). Mindestanforderung: max. 5 Fehlversuche pro IP innerhalb von 5 Minuten auf `/api/auth/login`.

---

## 2. Fehlende Pflichtfunktionen (Spec-Lücken)

### 🔵 F1 — Route `POST /api/domains/{id}/assets/import-category` fehlt (§8.3 / F2.1)

**Datei:** `src/Router/routes.php`  
**Spec:** §8.3 — `POST /api/domains/{id}/assets/import-category` (Zielobjektkategorie importieren), F2.1 Schritt 3

Die Route ist weder in `routes.php` registriert noch ist ein zugehöriger Controller-Method implementiert. Der Import von NTT DATA Zielobjektkategorien aus `zielobjektkategorien/` (F1.7) ist dadurch vollständig blockiert.

---

### 🔵 F2 — Route `GET /api/domains/{id}/dashboard` fehlt (§8.9)

**Datei:** `src/Router/routes.php`  
**Spec:** §8.9 — `GET /api/domains/{id}/dashboard` (Aggregierte KPIs pro Informationsverbund)

Nur `GET /api/dashboard` (global) ist registriert. Die Spec verlangt jedoch **domänenspezifische** KPIs (`/api/domains/{id}/dashboard`). Die vorhandenen `risks`- und `timeline`-Sub-Routen haben keinen übergeordneten KPI-Endpunkt.

---

### 🔵 F3 — `DiffService.php` fehlt vollständig (§6.2 / NFA-D3)

**Datei:** nicht vorhanden  
**Spec:** §6.2 Verzeichnisstruktur (`src/Service/DiffService.php`), NFA-D3 (automatische Versionierung aller OSCAL-Dokumente mit Changelog)

Die Versionierungslogik für SSP, Profile, AP, AR und POAM ist laut Spec ein eigener Service (`DiffService`). Die `document_versions`-Tabelle existiert in der DB (Migration 004), wird aber nirgendwo befüllt — weder im `OscalExporter` noch in einem Controller.

---

### 🔵 F4 — `MappingService.php` fehlt (§6.2 / F1.7 / F3.7)

**Datei:** nicht vorhanden  
**Spec:** §6.2 (`src/Service/MappingService.php`), F1.7 (Import NTT DATA Hilfsdateien: Mapping Baustein→Zielobjekt, Controls→Anforderungen), F3.7 (Edition-2023-Mapping)

Kein Service und keine Datenbank-Tabellen für die Mapping-Daten (`mapping_baustein_zo`, `mapping_controls_anf`, die §10.1 erwähnt). Die Migrations erstellen diese Tabellen nicht.

---

### 🔵 F5 — `NotificationService` ist nicht an Scheduler gebunden (F5.4 / §12 Phase 6)

**Datei:** `src/Service/NotificationService.php`  
**Spec:** F5.4 — „E-Mail-Erinnerung bei nahenden Deadlines (konfigurierbar)"

`NotificationService::sendDeadlineReminder()` ist implementiert, aber es gibt **keinen Cron-Job**, keine Console-Command-Klasse und keinen Scheduler-Einstiegspunkt, der diese Methode aufruft. Die Funktion existiert als toter Code. Kein einziger E-Mail-Reminder wird je versendet.

---

### 🔵 F6 — `CatalogController::checkUpdate()` aktualisiert Katalog nicht (F1.6)

**Datei:** `src/Controller/CatalogController.php`, `src/Repository/CatalogRepository.php`  
**Spec:** F1.6 — „Automatisches Update-Check gegen die BSI-Bibliothek (Git-Hash-Vergleich)"

`checkUpdate()` liefert korrekt zurück, ob ein Update verfügbar ist, ruft danach aber **nie** `CatalogRepository::updateAfterReimport()` auf. Die Methode existiert, wird aber nirgendwo aufgerufen — der Katalog kann über diesen Flow nicht aktualisiert werden. Dead Code.

---

### 🔵 F7 — OSCAL-Schema-Validierung bei Import fehlt (NFA-D1 / §17 Phase 2)

**Datei:** `src/Service/OscalParser.php`  
**Spec:** NFA-D1 — „OSCAL-Artefakte werden als valides JSON gespeichert und exportiert (OSCAL 1.1.3 Schema-konform)", Testanforderung Phase 2: `OscalParser` Catalog-Import mit BSI-Fixture

`OscalParser::parse()` prüft nur strukturell ob `catalog`-Root-Key vorhanden ist. Eine Validierung gegen das OSCAL 1.1.3 JSON-Schema (das laut CLAUDE.md unter `tests/Fixtures/oscal/` liegt) findet nicht statt. Fehlerhafte oder inkompatible Kataloge werden ohne Warnung importiert.

---

### 🔵 F8 — `OscalExporter::exportSsp()`: `security-sensitivity-level` hardcoded (F3.2)

**Datei:** `src/Service/OscalExporter.php`  
**Spec:** F2.1 Schritt 5 (Schutzbedarf pro Information/Asset), F3.2 (Maturity-Level, Reifegrad)

`security-sensitivity-level` ist auf `'moderate'` hardcoded, anstatt den tatsächlichen Schutzbedarf aus den `assets`-Feldern (`protection_need_c/i/a`) zu aggregieren. Damit stimmt der OSCAL-Export nie mit der modellierten Schutzbedarfsbewertung überein.

---

### 🔵 F9 — `DomainController::update()` synchronisiert Controls bei ISMS-Typ-Wechsel nicht (F2.1)

**Datei:** `src/Controller/DomainController.php`  
**Spec:** F2.1 Schritt 4 — ISMS-Typ wählen (Standard / Enhanced) und Basis-Controls laden

Wird `isms_type` eines Verbunds von `standard` auf `enhanced` (oder umgekehrt) geändert, werden die `scoped_controls` **nicht neu geladen**. Die Tabelle bleibt mit dem alten Scope. Dies führt zu einer dauerhaften Inkonsistenz zwischen ISMS-Typ und tatsächlich vorhandenen Controls.

---

## 3. Bugs

### 🟠 B1 — Off-by-one in `AuthController::passwordResetConfirm()` (F4.9 §4.9)

**Datei:** `src/Controller/AuthController.php`  
**Spec:** §4.9 — „Nach 5 Fehlversuchen wird das Token invalidiert (Brute-Force-Schutz)"

Der Code inkrementiert `failed_attempts` zuerst und prüft danach `>= 5`. Das bedeutet: Attempt 4 ist gültig, Attempt 5 schlägt mit "zu viele Versuche" fehl — auch wenn das Passwort korrekt ist. Der Schwellwert muss `> 5` lauten (oder der Inkrement darf nur bei ungültigem Token erfolgen).

---

### 🟠 B2 — `AdminController::updateUser()` schreibt ungefilterten Request-Body in Audit-Log

**Datei:** `src/Controller/AdminController.php`, `src/Middleware/AuditLogger.php`  
**Spec:** NFA-S7 — Audit-Trail; §CLAUDE.md — „Jede datenverändernde Aktion muss `AuditLogger::log()` mit Diff-Array aufrufen"

`AuditLogger::log('admin.user_updated', 'users', $userId, $body)` übergibt den rohen `$_POST`/JSON-Body als `changes_json`. `AuditLogger::diff()` wird nicht verwendet. Folgen:
1. Der Audit-Log enthält einen unstrukturierten Blob statt Vorher/Nachher-Werten.
2. Felder wie Passwort-Hashes oder andere unerwartete Felder können im Audit-Log landen.

**Fix:** `AuditLogger::diff($oldUser, $newUser, ['display_name', 'role', 'is_active'])` verwenden.

---

### 🟡 B3 — `AuditLogger::log()` loggt nicht die echte Client-IP (NFA-S7)

**Datei:** `src/Middleware/AuditLogger.php`  
**Spec:** NFA-S7 (Audit-Trail: Wer, Wann, Was)

`$_SERVER['REMOTE_ADDR']` wird direkt geloggt. Hinter einem Reverse-Proxy (wie Apache mit mod_proxy) ist das immer die Proxy-IP. In einem Docker/LAMP-Setup ist das der übliche Deploymentpfad. `HTTP_X_FORWARDED_FOR` wird nicht berücksichtigt.

---

### 🟡 B4 — `MailService::send()` nutzt `SERVER_NAME` für EHLO

**Datei:** `src/Service/MailService.php`

`$_SERVER['SERVER_NAME']` wird für den SMTP `EHLO`-Befehl verwendet. Dieser Wert stammt aus dem HTTP-`Host`-Header und ist theoretisch durch den Client beeinflussbar. Für EHLO ist dies nicht sicherheitskritisch, aber inkorrekt. Empfehlung: `APP_HOSTNAME`-Env-Variable verwenden.

---

### 🟡 B5 — AI-Cache hat kein TTL / Ablauf (F6 / NFA-P4)

**Datei:** `src/Repository/AiCacheRepository.php`  
**Spec:** NFA-P4 — „KI-Antwort-Caching in DB zur Vermeidung redundanter API-Calls"

Cache-Einträge werden niemals gelöscht oder invalidiert. Veraltete KI-Antworten (z.B. nach Katalog-Updates) bleiben dauerhaft bestehen. Für ein Compliance-Tool ist das problematisch — ein veralteter Erklärtext zu einer geänderten Anforderung könnte zur Falschdokumentation führen.

---

### 🟡 B6 — `SspController::import()` hat kein Größenlimit auf JSON-Body

**Datei:** `src/Controller/SspController.php`  
**Spec:** NFA-S9 (Datei-Upload-Validierung)

Der SSP-Import liest `php://input` ohne Größenbeschränkung. Ein sehr großes SSP-JSON (oder bewusst malformiertes Dokument) kann PHP-Memory-Limits erschöpfen. `ImplementationController::uploadEvidence()` hat ein 10-MB-Limit; derselbe Schutz fehlt hier.

---

### 🟡 B7 — `CsrfMiddleware::rotateToken()` wird nie aufgerufen

**Datei:** `src/Middleware/CsrfMiddleware.php`

`rotateToken()` ist implementiert, aber nirgendwo aufgerufen — insbesondere nicht nach Login (Session-Fixation-Risiko für CSRF-Token bleibt). Der Token wird nach `generateToken()` einmalig gesetzt und für die gesamte Session verwendet. Rotation nach Privilege Escalation (Login) wäre Best Practice.

---

## 4. Fehlende Tests

### 🔵 T1 — `OscalExporterTest.php` fehlt (§17.3 / Phase 3)

**Spec:** §17.3 Testverzeichnis-Struktur, Phase 3 Testanforderung: „`OscalExporter` SSP-Roundtrip (Import→Export→Schema-Vergleich, NTT DATA-Dateinamen)"

`tests/Unit/Service/OscalExporterTest.php` ist weder vorhanden noch gibt es einen Roundtrip-Test für den Exporter. NFA-T7 (OSCAL Import/Export Roundtrip-Test) ist damit unerfüllt.

---

### 🔵 T2 — `DiffServiceTest.php` fehlt (§17.3)

**Spec:** §17.3 Testverzeichnis-Struktur: `tests/Unit/Service/DiffServiceTest.php`

Fehlt, da auch `DiffService.php` nicht existiert (→ F3). Beide müssen zusammen implementiert werden.

---

### 🔵 T3 — `TotpServiceTest.php` fehlt (NFA-T2)

**Spec:** NFA-T2 — „Alle sicherheitsrelevanten Pfade … müssen durch dedizierte Unit-Tests abgedeckt sein"

`TotpService` ist eine Security-Klasse, hat aber keinen dedizierten Unit-Test. Weder Happy-Path (gültiger Code) noch Fehlerfall (abgelaufenes Fenster, Replay) sind getestet.

---

### 🔵 T4 — `CsrfMiddlewareTest.php` falsch verortet

**Datei:** `tests/Unit/Middleware/CsrfMiddlewareTest.php`  
**Spec:** §17.3 — `tests/Unit/Security/CsrfMiddlewareTest.php`

Spec listet die Datei unter `Unit/Security/`. Sie liegt tatsächlich unter `Unit/Middleware/`. Kleinigkeit, aber der Spec-konforme Pfad weicht ab.

---

### 🔵 T5 — Keine Repository-Tests für Domains, Risks, Assessments, POAM (NFA-T3 / Phase 4–6)

**Vorhandene Repository-Tests:** nur `tests/Integration/Repository/ImplementationRepositoryTest.php`

Spec Phase 4–6 fordert Repository-Integration-Tests für:
- `DomainRepository` / `AssetRepository` CRUD (Phase 2)
- `RiskRepository` inkl. Risk-Control-Verknüpfung (Phase 4)
- `AssessmentRepository` Befund-Aggregation (Phase 5)
- `PoamRepository` Deadline-Eskalationslogik (Phase 6)

Keiner dieser Tests existiert.

---

### 🔵 T6 — Keine Frontend-Tests (NFA-T6)

**Spec:** NFA-T6 — „Frontend-Composables und Pinia-Stores werden mit Vitest unit-getestet; Abdeckung ≥ 70 %"

Es gibt keine `frontend/src/**/*.test.js`-Dateien und kein `vitest.config.js`. Das Vitest-Setup aus dem Package.json ist nicht eingerichtet.

---

## 5. Fehlende Service-Klassen vs. Spec §6.2

| Klasse (Spec §6.2) | Status |
|--------------------|--------|
| `src/Service/DiffService.php` | ❌ Fehlt |
| `src/Service/MappingService.php` | ❌ Fehlt |
| `src/Security/CsrfGuard.php` | ⚠️ Als `src/Middleware/CsrfMiddleware.php` implementiert (abweichender Pfad/Name) |

---

## 6. Fehlende Routen vs. Spec §8

| Route (Spec) | Status |
|---|---|
| `POST /api/domains/{id}/assets/import-category` (§8.3) | ❌ Fehlt |
| `GET /api/domains/{id}/dashboard` (§8.9) | ❌ Fehlt (nur `/api/dashboard` global) |
| `POST /api/auth/totp/setup` (§8.1) | ⚠️ Unter `/api/profile/totp/setup` implementiert (Pfad-Abweichung) |
| `POST /api/auth/totp/verify` (§8.1) | ⚠️ Unter `/api/profile/totp/confirm` implementiert (Pfad-Abweichung) |

---

## 7. Code-Smells / Stilprobleme

### ⚪ CS1 — `use`-Statements in `routes.php` mitten in der Datei

**Datei:** `src/Router/routes.php`

`AssessmentController`, `PoamController` und `AiController` werden per `use`-Statement mitten im Routing-File importiert, nicht am Anfang. PHP erlaubt dies technisch, aber es ist ein Stilverstoß und erschwerst Lesbarkeit.

---

### ⚪ CS2 — `safeFilename()` dupliziert in `AssessmentController` und `PoamController`

**Dateien:** `src/Controller/AssessmentController.php`, `src/Controller/PoamController.php`

Identische private Methode in zwei Controllern. Gehört in `BaseController` als `protected` Utility-Methode.

---

### ⚪ CS3 — `AiController::$testClientOverride` ist `public static`

**Datei:** `src/Controller/AiController.php`

Öffentliche statische Property für Test-Injection ist ein Code-Smell. Besser wäre ein Service-Locator oder Dependency Injection über den Konstruktor.

---

### ⚪ CS4 — `OscalParser::findControl()` ist O(n) — kein Index

**Datei:** `src/Service/OscalParser.php`

Linearer Scan durch alle Controls. Für den aktuellen BSI-Katalog (< 1000 Controls) akzeptabel, aber eine indexierte Map (`array_column($controls, null, 'id')`) wäre trivial und deutlich effizienter.

---

## 8. NFA-Verletzungen (Zusammenfassung)

| NFA | Anforderung | Status |
|-----|-------------|--------|
| NFA-S3 | Content Security Policy Headers | ✅ In `.htaccess` (korrekt) |
| NFA-S4 | TOTP MFA | ⚠️ Implementiert, aber Replay-Angriff möglich (→ S2) |
| NFA-S11 | Rate-Limiting Login + API | ❌ Nicht implementiert (→ S3) |
| NFA-D1 | OSCAL 1.1.3 Schema-Validierung | ❌ Fehlt beim Import (→ F7) |
| NFA-D3 | Automatische Versionierung mit Changelog | ❌ `DiffService` fehlt, `document_versions` wird nie befüllt (→ F3) |
| NFA-T6 | Vitest Frontend-Tests ≥ 70 % | ❌ Keine Frontend-Tests vorhanden (→ T6) |
| NFA-T7 | OSCAL Roundtrip-Tests | ❌ `OscalExporterTest` fehlt (→ T1) |

---

*Letzte Aktualisierung: 17.05.2026*
