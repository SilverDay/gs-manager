# Grundschutz++ KMU Compliance Manager — Software-Spezifikation

**Projekt:** GS++ KMU Manager  
**Version:** 0.1 (Entwurf)  
**Autor:** Klaus-E. Klingner / SilverDay Media  
**Datum:** 15.05.2026  
**Stack:** LAMP (Linux, Apache, MySQL/MariaDB, PHP 8.3)  

---

## 1. Zusammenfassung und Vision

Der **Grundschutz++ KMU Compliance Manager** (im Folgenden „GS++ Manager") ist eine self-hosted LAMP-Webanwendung, die kleine und mittlere Unternehmen (KMU) durch den gesamten Lebenszyklus eines Informationssicherheitsmanagementsystems nach BSI Grundschutz++ führt. Das Tool übernimmt die Funktionalität der NTT DATA Grundschutz++ One-Page-Apps (Blaupausen-Generator, SSP-Editor, Assessment Plan/Results, POA&M-Generator) und bettet sie in eine persistente, multi-user-fähige, servergestützte Architektur ein — ergänzt um verständliche Erklärungen, geführte Workflows und KMU-taugliche Sprache.

### 1.1 Problemstellung

Die bestehenden NTT DATA Tools sind rein clientseitige HTML/JS-Anwendungen. Für KMU ergeben sich daraus folgende Hürden:

- **Keine Persistenz:** Alle Daten liegen als JSON-Downloads im lokalen Dateisystem. Geht eine Datei verloren oder wird falsch benannt, bricht die Referenz-Integrität.
- **Kein Mehrbenutzerbetrieb:** Nur eine Person kann gleichzeitig an einem Informationsverbund arbeiten. Es gibt keine Rollen, keine Aufgabenverteilung.
- **Kein Audit-Trail:** Wer wann was geändert hat, ist nicht nachvollziehbar.
- **Fachsprache ohne Erklärung:** OSCAL-Terminologie (SSP, AP, AR, POA&M, Tailoring, Controls, Profile) ist für KMU-Verantwortliche ohne Grundschutz-Erfahrung schwer zugänglich.
- **Kein Fortschritts-Tracking:** Es fehlt ein zentrales Dashboard, das den Umsetzungsgrad über alle Zielobjekte hinweg sichtbar macht.

### 1.2 Lösungsansatz

Der GS++ Manager überführt den bewährten Workflow der NTT DATA Tools (Modellierung → Grundschutzcheck → Audit → Sanierung) in eine persistente Webanwendung mit folgenden Kernmerkmalen:

- Serverseitige Datenhaltung in MariaDB mit vollständiger OSCAL-JSON-Speicherung
- Rollenbasierter Zugang (ISB, Fachverantwortliche, Auditor, Geschäftsleitung)
- Geführter Wizard-Workflow mit KMU-verständlichen Erklärungen
- Zentrales Dashboard mit Fortschrittsanzeige und Ampelsystem
- Import/Export von OSCAL-Artefakten (Kompatibilität zu NTT DATA Tools und BSI Stand-der-Technik-Bibliothek)
- Audit-Trail für alle Änderungen
- Optionale KI-Integration (Claude API oder Gemini) für Erklärungen und Umsetzungsvorschläge

---

## 2. Regulatorischer Kontext

### 2.1 Was ist Grundschutz++?

Grundschutz++ ist die ab 01.01.2026 gültige Weiterentwicklung des BSI IT-Grundschutz. Die wesentlichen Neuerungen:

- **OSCAL-basiert:** Alle Anforderungen liegen als maschinenlesbare JSON-Dateien (NIST OSCAL 1.1.3) in der BSI Stand-der-Technik-Bibliothek auf GitHub.
- **Prozessorientiert:** Der Fokus verschiebt sich von Bausteinen pro Zielobjekt hin zu prozessorientierten Anforderungspaketen, die sich an Geschäftsprozessen und Informationsflüssen orientieren.
- **Flexible Leistungszahlen:** Die bisherigen starren Absicherungsstufen (Basis, Standard, Erhöht) werden durch dynamische Schwellwerte und Leistungszahlen (Stufe 0–5) ersetzt.
- **Maschinenlesbar:** Anforderungen können automatisiert geprüft, importiert und exportiert werden.
- **PDCA-Zyklus:** Die Methodik folgt dem Plan-Do-Check-Act-Modell.

### 2.2 Relevante Normen und Gesetze

- BSI Grundschutz++ Methodik-Leitfaden (April 2026)
- BSI Stand-der-Technik-Bibliothek (Anwenderkatalog Grundschutz++)
- NIS-2-Umsetzungsverordnung (NIS2UmsVO)
- KRITIS-Dachgesetz
- DSGVO (insbesondere Art. 32 TOM)
- ISO/IEC 27001:2022 (Kompatibilität)

### 2.3 OSCAL-Artefakte im Workflow

| OSCAL-Artefakt | Funktion im GS++ | Workflow-Phase |
|---|---|---|
| **Catalog** | Anforderungskatalog (BSI Kompendium) | Referenzdaten |
| **Profile** | Tailoring — welche Controls gelten für den Informationsverbund | 1. Modellierung |
| **SSP** (System Security Plan) | Dokumentation der Ist-Umsetzung pro Control | 2. Grundschutzcheck |
| **Component Definition** | Wiederverwendbare Implementierungsbausteine pro Zielobjektkategorie | 2. Grundschutzcheck |
| **AP** (Assessment Plan) | Prüfplan mit Methodik, Assessor, Zeitplan | 3. Audit |
| **AR** (Assessment Results) | Prüfergebnisse mit Befunden und Risiken | 3. Audit |
| **POA&M** (Plan of Action & Milestones) | Maßnahmenplan für offene Feststellungen | 4. Sanierung |

---

## 3. Zielgruppe und Personas

### 3.1 Primäre Persona: KMU-ISB „Anna"

- ISB (Informationssicherheitsbeauftragte) eines Unternehmens mit 50–250 Mitarbeitenden
- Hat Grundkenntnisse in IT-Sicherheit, aber keine OSCAL-Erfahrung
- Muss den Grundschutz++ mit begrenztem Budget und ohne externe Berater umsetzen
- Erwartet verständliche Sprache und klare Handlungsanweisungen

### 3.2 Sekundäre Personas

- **Fachverantwortlicher „Markus":** IT-Leiter, der einzelne Controls für seine Systeme bearbeitet und Umsetzungsstatus dokumentiert
- **Auditor „Dr. Weber":** Externer oder interner Prüfer, der den Assessment Plan erstellt und Befunde erfasst (kann auch ein externer Berater sein)
- **Geschäftsleitung „Frau Müller":** Möchte ein Dashboard sehen, das den Compliance-Status auf einen Blick zeigt — ohne technische Details

---

## 4. Funktionale Anforderungen

### 4.1 Modul 1: Katalog-Verwaltung (Referenzdaten)

**Zweck:** Import und Pflege der OSCAL-Katalogdaten als Grundlage für alle weiteren Module.

**Funktionen:**

- F1.1: Import des BSI Grundschutz++ Anwenderkatalogs direkt von der BSI GitHub-Bibliothek (JSON) oder per manueller Datei-Upload
- F1.2: Import zusätzlicher Kataloge (DSGVO, KRITIS, C5 aus dem Repository `beispiel-kataloge/` und `kataloge/`)
- F1.3: Katalog-Viewer mit hierarchischer Navigation (Gruppen → Controls → Parameter), vergleichbar dem `GSpp-Viewer.html`
- F1.4: Volltextsuche über Anforderungstexte
- F1.5: Anzeige der Control-Metadaten (Schutzziele CIA, Leistungszahlen/Stufen, Dokumentationspflicht)
- F1.6: Automatisches Update-Check gegen die BSI-Bibliothek (Git-Hash-Vergleich)
- F1.7: Import der NTT DATA Hilfsdateien (Mapping Baustein→Zielobjekt, Controls→Anforderungen, Prozessbausteine-Mapping)

### 4.2 Modul 2: Informationsverbund & Modellierung (≈ Blaupausen-Generator)

**Zweck:** Definition des Geltungsbereichs, Strukturanalyse und Erstellung der Blaupause als OSCAL-Profil.

**Funktionen:**

- F2.1: **Wizard „Informationsverbund anlegen"** — geführter Ablauf in 5 Schritten:
  1. Metadaten (Name, Version, Zweck der Organisation, Branche)
  2. Geschäftsprozesse und Informationsflüsse erfassen
  3. Assets/Zielobjekte anlegen (manuell oder aus der Zielobjektkategorien-Bibliothek importieren)
  4. ISMS-Typ wählen (Standard / Enhanced) und Basis-Controls laden
  5. Schutzbedarf pro Information/Asset bestimmen
- F2.2: **Tailoring** — Anpassung der Controls an die lokale Situation:
  - Parameter-Werte setzen (z.B. Fristen, Rollen, Schwellwerte)
  - Zusätzliche Controls aus dem Katalog hinzufügen
  - Controls begründet ausschließen (mit Dokumentationspflicht)
  - Anforderungstexte mit Präfix/Suffix ergänzen
- F2.3: **Integrierte Risikoanalyse:**
  - Risiko-Einträge erstellen und Assets zuordnen
  - Schadensszenarien und Eintrittswahrscheinlichkeit bewerten
  - Mitigierende Controls zuordnen oder Custom Controls erstellen
  - Risikoakzeptanz-Entscheidungen dokumentieren
- F2.4: **OSCAL-Profile-Export** (kompatibel mit NTT DATA Tools)
- F2.5: **Muster-SSP-Generierung** aus dem Profil (Grundlage für Modul 3)
- F2.6: KMU-Hilfe: Jeder Schritt enthält eine Erklärbox („Was bedeutet das?") mit verständlichen Beispielen

### 4.3 Modul 3: Grundschutzcheck / SSP-Bearbeitung (≈ SSP-Ausfüllen)

**Zweck:** Dokumentation der tatsächlichen Umsetzung aller Controls.

**Funktionen:**

- F3.1: **Aufgabenorientierte Ansicht:** Nicht eine flache Liste von Controls, sondern gruppiert nach Zielobjekt/Komponente mit Fortschrittsbalken
- F3.2: **Pro Control dokumentieren:**
  - Umsetzungsstatus: Umgesetzt / Teilweise umgesetzt / Geplant / Nicht umgesetzt / Nicht anwendbar
  - Verantwortliche Person (Auswahl aus Benutzerliste)
  - Umsetzungsdatum (Ist) und Zieldatum (Soll)
  - Freitext-Beschreibung der Umsetzung
  - Reifegrad-Auswahl (Stufe 0–5) mit automatischem Textvorschlag
  - Nachweis-Upload (Dokumente, Screenshots, Richtlinien-Links)
- F3.3: **Globale Filter und Suche:**
  - Nach Status (offen, geplant, umgesetzt)
  - Nach Schutzziel (C, I, A)
  - Nach Zielobjekt/Komponente
  - Nach Verantwortlicher Person
  - Freitextsuche über Control-ID und Anforderungstext
- F3.4: **Workspace-Konzept:** Arbeitsstände werden automatisch serverseitig gespeichert (kein manueller JSON-Export nötig)
- F3.5: **Parameter-Verwaltung:** Anzeige offener Parameter mit Werten aus Profil und Möglichkeit lokaler Überschreibung
- F3.6: **Risikoverknüpfung:** Anzeige zugehöriger Risiken pro Control mit Link zur Risikoanalyse
- F3.7: **Edition-2023-Mapping:** Anzeige der Zuordnung zum klassischen Grundschutz-Kompendium (über `controls_anforderungen.json`)
- F3.8: **OSCAL-SSP-Import/Export** (kompatibel mit NTT DATA `*_SSP-edited.json`)
- F3.9: **Komponentendefinitionen laden:** Import von OSCAL Component Definitions aus `zielobjektkategorien/komponenten/` als Umsetzungsvorlagen

### 4.4 Modul 4: Audit & Reporting (≈ Assessment Plan/Results)

**Zweck:** Formale Prüfung der Umsetzung und Nachweiserstellung.

**Funktionen:**

- F4.1: **Assessment Plan erstellen:**
  - Metadaten (Titel, Version, Prüfzeitraum)
  - Assessor-Daten (Name, Organisation, Kontakt)
  - Rules of Engagement und Audit-Methodik
  - Prüfumfang festlegen (welche Controls im Scope)
  - Tasks/Meilensteine für das Audit-Team
- F4.2: **Prüfmethoden zuweisen:** Pro Control eine oder mehrere Methoden:
  - EX (Examine / Dokumentenprüfung)
  - IN (Interview)
  - TE (Test)
- F4.3: **Befunde erfassen:**
  - Status: Erfüllt (satisfied) / Nicht erfüllt (not-satisfied) / Teilweise erfüllt / Nicht geprüft
  - Beobachtung (Freitext)
  - Risikobewertung bei Nicht-Erfüllung
  - Nachweise referenzieren
- F4.4: **Dashboard-Ansicht:** Pie-Chart und Tabelle der Ergebnisse (erfüllt/nicht erfüllt/offen) mit Drill-Down
- F4.5: **OSCAL-Export:** Assessment Plan (AP) und Assessment Results (AR) als OSCAL-JSON
- F4.6: **PDF-Report-Generierung:** Zusammenfassung für Management (Ampel-Übersicht, Top-Risiken, Empfehlungen)

### 4.5 Modul 5: Sanierung / POA&M (≈ POA&M-Generator)

**Zweck:** Strukturierte Nachverfolgung und Behebung offener Feststellungen.

**Funktionen:**

- F5.1: **Automatischer Import:** Alle nicht-erfüllten Controls aus den Assessment Results werden als POA&M-Items angelegt
- F5.2: **Pro Maßnahme:**
  - Priorisierung (Hoch / Mittel / Niedrig)
  - Status-Tracking (Offen → In Arbeit → Abgeschlossen → Verifiziert)
  - Verantwortliche Person
  - Deadline mit Eskalationswarnung
  - Meilenstein-Planung (Phasen mit Zwischenterminen)
  - Risikoakzeptanz-Option mit Begründungspflicht
- F5.3: **Dashboard:** Übersicht überfälliger Maßnahmen, Fortschrittstracking, Trend-Anzeige
- F5.4: **Benachrichtigungen:** E-Mail-Erinnerung bei nahenden Deadlines (konfigurierbar)
- F5.5: **OSCAL-POA&M-Export** (kompatibel mit NTT DATA `*_POAM.json`)

### 4.6 Modul 6: KI-Assistent (Optional)

**Zweck:** Verständnishilfe und Umsetzungsunterstützung, analog zur KI-Funktion der NTT DATA Tools.

**Funktionen:**

- F6.1: **Control erklären:** Verständliche Erklärung eines Anforderungstextes in KMU-Sprache
- F6.2: **Umsetzungsvorschlag:** Konkrete Praxisbeispiele für ein Control, kontextualisiert auf Branche und Größe der Organisation
- F6.3: **Risikoanalyse:** Aufzeigen der Gefahren bei Nicht-Umsetzung
- F6.4: **Audit-Befundvorschlag:** KI analysiert SSP-Eintrag und schlägt Prüfbefund vor
- F6.5: **Reifegrad-Analyse:** Generierung von Prüfungshandlungen für verschiedene Reifegrade
- F6.6: **Sanierungsvorschlag:** Konkreter Text für Mängelbeseitigung und Meilenstein-Plan
- F6.7: **Mapping auf Edition 2023:** Erklärung der Zuordnung alter Bausteine zu neuen GS++ Controls

**KI-Provider:**

- Primär: Claude API (Anthropic) — System-Kontext enthält Organisation, Branche, Schutzbedarf
- Alternativ: Google Gemini API (Kompatibilität mit NTT DATA Tools)
- API-Key wird pro Mandant konfiguriert
- KI-Antworten werden gecacht (DB), um Kosten zu reduzieren und offline-Nutzung zu ermöglichen

### 4.7 Modul 7: Benutzerprofil & Selbstverwaltung

**Zweck:** Jeder angemeldete Benutzer verwaltet seine eigenen Zugangsdaten und Präferenzen.

**Funktionen:**

- F7.1: **Eigenes Profil bearbeiten:** Anzeigename und E-Mail-Adresse ändern (E-Mail-Adresse erfordert Passwortbestätigung)
- F7.2: **Passwort ändern:** Aktuelles Passwort + neues Passwort (min. 12 Zeichen) + Wiederholung — bcrypt cost 12
- F7.3: **TOTP-Authentifizierung:** QR-Code anzeigen, TOTP-Setup bestätigen, TOTP deaktivieren (erfordert Passwort)
- F7.4: **Aktive Sitzungen anzeigen:** IP-Adresse, User-Agent, Letzte Aktivität (read-only)
- F7.5: **Letzte Anmeldungen:** Chronologische Liste der letzten 10 Login-Ereignisse aus dem Audit-Log

### 4.8 Modul 8: Administrations-Interface

**Zweck:** Mandant-Administratoren verwalten Benutzer, Rollen und Systemeinstellungen innerhalb ihres Mandanten.

**Funktionen:**

- F8.1: **Benutzerliste:** Alle Benutzer des Mandanten mit Rolle, Status (aktiv/inaktiv), letzter Anmeldung
- F8.2: **Benutzer anlegen:** E-Mail, Anzeigename, Rolle, temporäres Passwort (oder Einladungs-E-Mail-Flow)
- F8.3: **Benutzer bearbeiten:** Anzeigename, Rolle, Aktivierungsstatus ändern
- F8.4: **Passwort zurücksetzen:** Admin setzt ein neues temporäres Passwort für beliebigen Benutzer (Benutzer muss es bei nächstem Login ändern)
- F8.5: **Benutzer deaktivieren/aktivieren:** Kein Löschen — Deaktivierung erhält den Audit-Trail
- F8.6: **Mandant-Einstellungen:** Sprache, Zeitzone, Session-Timeout (aus `tenants.settings_json`)
- F8.7: **SMTP-Konfiguration:** Server, Port, Absender-Adresse für E-Mail-Benachrichtigungen und Passwort-Reset; Testmail-Funktion

### 4.9 Modul 9: Passwort-Vergessen-Flow

**Zweck:** Benutzer, die ihr Passwort vergessen haben, können es über einen verifizierten E-Mail-Link zurücksetzen.

**Voraussetzung:** SMTP muss in den Admin-Einstellungen (F8.7) konfiguriert sein. Ohne SMTP zeigt die Login-Seite keinen „Passwort vergessen"-Link — stattdessen einen Hinweis, dass der Administrator das Passwort zurücksetzen kann.

**Ablauf:**

1. Benutzer klickt „Passwort vergessen" auf der Login-Seite → gibt E-Mail-Adresse ein
2. Server: findet Benutzer, generiert kryptographisch sicheres Reset-Token (32 Byte, `random_bytes()`), speichert SHA-256-Hash in DB mit 1-Stunde-Ablauf
3. Server sendet E-Mail mit einmaligem Reset-Link (enthält Klartext-Token als URL-Parameter)
4. Benutzer klickt Link → Formular für neues Passwort (min. 12 Zeichen, zweifach eingeben)
5. Server: validiert Token (Hash-Vergleich), prüft Ablauf, setzt neues Passwort, invalidiert Token sofort
6. Benutzer wird zur Login-Seite geleitet; erfolgreicher Reset wird geloggt (Audit-Trail)

**Sicherheitsanforderungen:**

- Token wird nur als SHA-256-Hash in der DB gespeichert (nie im Klartext)
- Antwort auf Schritt 2 ist immer identisch, unabhängig davon ob die E-Mail existiert (kein User-Enumeration)
- Nach 5 Fehlversuchen wird das Token invalidiert (Brute-Force-Schutz)
- Tabelle: `password_reset_tokens (id, user_id, token_hash, expires_at, used_at, created_at)`

### 4.10 Modul 10: Zentrales Dashboard

**Zweck:** Cockpit-Ansicht für alle Stakeholder.

**Funktionen:**

- F7.1: **Compliance-Ampel:** Gesamtstatus des Informationsverbunds (Grün/Gelb/Rot) auf Basis der Umsetzungsquote
- F7.2: **Fortschritts-KPIs:**
  - Controls gesamt / umgesetzt / offen / geplant
  - Umsetzungsquote in % (gesamt und pro Zielobjektkategorie)
  - Offene POA&M-Items / überfällige Items
  - Audit-Ergebnis-Zusammenfassung
- F7.3: **Timeline:** Chronologische Ansicht der Meilensteine (nächster Audit-Termin, offene Deadlines)
- F7.4: **Risikolandkarte:** Heatmap der Top-Risiken nach Schadenshöhe × Eintrittswahrscheinlichkeit
- F7.5: **Rollenspezifische Sichten:**
  - Geschäftsleitung: Ampel + Top-5-Risiken + Trend
  - ISB: Voller Detailgrad
  - Fachverantwortliche: Nur eigene zugewiesene Controls

---

## 5. Nicht-funktionale Anforderungen

### 5.1 Security (by Design)

- NFA-S1: CSRF-Protection auf allen schreibenden Endpunkten (Double-Submit-Cookie + Hidden Token)
- NFA-S2: Prepared Statements für alle DB-Queries (kein String-Concatenation-SQL)
- NFA-S3: Content Security Policy Headers
- NFA-S4: TOTP-basierte Multi-Faktor-Authentifizierung (optional aktivierbar, empfohlen für ISB und Auditor)
- NFA-S5: Passwort-Hashing mit bcrypt (cost 12+) oder Argon2id
- NFA-S6: Session-Management mit Secure/HttpOnly/SameSite-Flags, Session-Timeout konfigurierbar (Default 30 Min.)
- NFA-S7: Audit-Trail: Jede datenverändernde Aktion wird protokolliert (Wer, Wann, Was, Vorher/Nachher)
- NFA-S8: Input-Validierung serverseitig, HTML-Ausgabe-Encoding (XSS-Prevention)
- NFA-S9: Datei-Upload-Validierung: MIME-Type-Check, Größenlimit, Speicherung außerhalb des Webroot
- NFA-S10: Verschlüsselung sensibler Felder in der DB (API-Keys) mit AES-256-GCM, Schlüssel in Environment-Variable
- NFA-S11: Rate-Limiting auf Login und API-Endpunkten
- NFA-S12: Keine Klartext-Speicherung von KI-API-Keys — nur verschlüsselt in DB

### 5.2 Performance

- NFA-P1: Seitenladezeit < 2 Sekunden bei normaler Last (bis 20 gleichzeitige Benutzer)
- NFA-P2: Katalog-Import (4 MB JSON) in < 30 Sekunden
- NFA-P3: OSCAL-Export in < 10 Sekunden
- NFA-P4: KI-Antwort-Caching in DB zur Vermeidung redundanter API-Calls

### 5.3 Usability

- NFA-U1: Responsive Design (Desktop-first, aber tablet-nutzbar)
- NFA-U2: Jede Fachbegriff-Erstverwendung mit Tooltip-Erklärung
- NFA-U3: Wizard-Modus für Ersteinrichtung (max. 7 Schritte bis zum ersten Profil)
- NFA-U4: Inline-Hilfetexte in einfacher Sprache (keine OSCAL-Terminologie ohne Erklärung)
- NFA-U5: Breadcrumb-Navigation, die den aktuellen Workflow-Schritt anzeigt

### 5.4 Datenintegrität & Kompatibilität

- NFA-D1: OSCAL-Artefakte werden als valides JSON gespeichert und exportiert (OSCAL 1.1.3 Schema-konform)
- NFA-D2: Import/Export-Kompatibilität mit NTT DATA One-Page-Apps (selbe Dateinamenkonvention)
- NFA-D3: Automatische Versionierung aller OSCAL-Dokumente (SSP, AP, AR, POA&M) mit Changelog
- NFA-D4: Backup-Export der gesamten Mandant-Daten als verschlüsseltes ZIP

### 5.5 Deployment

- NFA-E1: Standard-LAMP-Deployment (Apache 2.4+, PHP 8.3+, MariaDB 10.11+)
- NFA-E2: Keine Framework-Abhängigkeiten jenseits von Composer-Paketen (kein Laravel, Symfony etc.)
- NFA-E3: Docker-Compose-File für einfaches Self-Hosting
- NFA-E4: Konfiguration über `.env`-Datei (12-Factor-App)
- NFA-E5: Migrations-System für DB-Schema-Änderungen (SQL-basiert, versioniert)

### 5.6 Testbarkeit

- NFA-T1: Unit-Test-Abdeckung ≥ 80 % für Security-, Service- und Repository-Schicht (gemessen mit PHPUnit-Coverage)
- NFA-T2: Alle sicherheitsrelevanten Pfade (Auth, CSRF, Verschlüsselung, Eingabevalidierung, Rollen-Prüfung) müssen durch dedizierte Unit-Tests abgedeckt sein — keine Ausnahmen
- NFA-T3: Integrationstests für alle API-Endpunkte gegen eine isolierte Test-Datenbank; Tests müssen nach jedem Run die DB-Fixtures zurücksetzen (kein Seiteneffekt zwischen Tests)
- NFA-T4: Vollständiger Backend-Testlauf (`php tests/run.php`) in < 90 Sekunden
- NFA-T5: Jede neue Service- oder Repository-Klasse muss vor dem Merge mindestens einen Happy-Path- und einen Fehlerfall-Test haben
- NFA-T6: Frontend-Composables und Pinia-Stores werden mit Vitest unit-getestet; Abdeckung ≥ 70 %
- NFA-T7: OSCAL-Import- und Export-Funktionen werden mit realen BSI-Katalogdaten als Fixtures getestet (Roundtrip: Import → Export → Schema-Vergleich)
- NFA-T8: Test-DB wird über dieselbe Migrations-Pipeline wie Produktion aufgebaut — Migrationen gelten als verifiziert erst nach erfolgreichem Testlauf gegen frische Test-DB

---

## 6. Architektur

### 6.1 Systemübersicht

```
┌──────────────────────────────────────────────────┐
│                   Browser (Frontend)              │
│  Vue 3 / Vite  │  Tailwind CSS  │  Chart.js      │
└────────────────────────┬─────────────────────────┘
                         │ HTTPS / REST-API
┌────────────────────────┴─────────────────────────┐
│                  Apache + PHP 8.3                  │
│  ┌──────────┐  ┌──────────┐  ┌────────────────┐  │
│  │  Router   │  │   Auth   │  │  CSRF / Rate   │  │
│  │  (custom) │  │  Guard   │  │  Limiter       │  │
│  └────┬─────┘  └──────────┘  └────────────────┘  │
│       │                                           │
│  ┌────┴─────────────────────────────────────────┐ │
│  │              Controller Layer                 │ │
│  │  Catalog │ Model │ SSP │ Audit │ POAM │ AI   │ │
│  └────┬─────────────────────────────────────────┘ │
│       │                                           │
│  ┌────┴─────────────────────────────────────────┐ │
│  │              Service Layer                    │ │
│  │  OSCAL Parser │ OSCAL Exporter │ Risk Engine  │ │
│  │  Mapping Service │ Diff/Changelog │ AI Client │ │
│  └────┬─────────────────────────────────────────┘ │
│       │                                           │
│  ┌────┴─────────────────────────────────────────┐ │
│  │              Repository Layer (PDO)           │ │
│  └────┬─────────────────────────────────────────┘ │
└───────┼──────────────────────────────────────────┘
        │
┌───────┴──────────┐   ┌──────────────────────────┐
│   MariaDB 10.11  │   │  Datei-Storage            │
│   (OSCAL JSON    │   │  (Nachweise, Uploads,     │
│    + Relational) │   │   Export-Artefakte)        │
└──────────────────┘   └──────────────────────────┘
```

### 6.2 Verzeichnisstruktur

```
gspp-manager/
├── public/                    # Apache DocumentRoot
│   ├── index.php              # Front-Controller
│   ├── assets/                # Compiled Vue/CSS/JS
│   └── .htaccess              # URL-Rewriting
├── src/
│   ├── Config/                # .env Loader, DB-Config
│   ├── Router/                # Custom Router
│   ├── Middleware/             # Auth, CSRF, RateLimit, AuditLog
│   ├── Controller/
│   │   ├── AuthController.php
│   │   ├── CatalogController.php
│   │   ├── ModelController.php
│   │   ├── SspController.php
│   │   ├── AuditController.php
│   │   ├── PoamController.php
│   │   ├── DashboardController.php
│   │   └── AiController.php
│   ├── Service/
│   │   ├── OscalParser.php           # JSON→DB Import
│   │   ├── OscalExporter.php         # DB→JSON Export
│   │   ├── MappingService.php        # Baustein↔Zielobjekt, Controls↔Anforderungen
│   │   ├── RiskEngine.php            # Risikobewertung & Aggregation
│   │   ├── DiffService.php           # Changelog/Versionierung
│   │   ├── NotificationService.php   # E-Mail-Benachrichtigungen
│   │   └── AiClient.php              # Claude/Gemini API Abstraktion
│   ├── Repository/                    # DB-Queries (PDO, Prepared Statements)
│   ├── Model/                         # Value Objects / DTOs
│   └── Security/
│       ├── CsrfGuard.php
│       ├── PasswordHasher.php
│       ├── TotpService.php
│       └── FieldEncryptor.php
├── migrations/                # Versionierte SQL-Migrationen
├── storage/
│   ├── uploads/               # Nachweise (außerhalb Webroot)
│   ├── exports/               # Generierte OSCAL-Artefakte
│   └── cache/                 # KI-Antwort-Cache
├── frontend/                  # Vue 3 / Vite Quellcode
│   ├── src/
│   │   ├── views/
│   │   ├── components/
│   │   ├── composables/
│   │   └── stores/            # Pinia Stores
│   └── vite.config.js
├── tests/
├── docker/
│   ├── Dockerfile
│   └── docker-compose.yml
├── .env.example
├── composer.json
└── CLAUDE.md                  # Claude Code Konventionen
```

### 6.3 Technologie-Stack (Detail)

| Schicht | Technologie | Begründung |
|---|---|---|
| Webserver | Apache 2.4 + mod_rewrite | Standard LAMP, .htaccess-basiertes Routing |
| Backend | PHP 8.3 (kein Framework) | Konsistent mit bestehender Tool-Philosophie (VendorShield, OSGridManager, Wanyanka) |
| Datenbank | MariaDB 10.11+ mit JSON-Spalten | Natives JSON-Handling für OSCAL-Dokumente, relationale Indices für Queries |
| Frontend | Vue 3 (Composition API) + Vite | Reaktiv, SPA-artig, konsistent mit LoreBuilder |
| CSS | Tailwind CSS | Utility-first, schnelles Prototyping |
| Charts | Chart.js | Leichtgewichtig, Dashboard-Visualisierung |
| Auth | Custom (bcrypt/Argon2id + TOTP) | Kein externen AuthN-Abhängigkeiten |
| KI | Anthropic Claude API / Google Gemini | Abstrahiert hinter einheitlichem Client |

---

## 7. Datenmodell

### 7.1 Kern-Tabellen

```sql
-- Mandant (Multi-Tenancy)
CREATE TABLE tenants (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    slug            VARCHAR(100) NOT NULL UNIQUE,
    settings_json   JSON,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Benutzer
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    email           VARCHAR(255) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(255) NOT NULL,
    role            ENUM('admin','isb','fachverantwortlich','auditor','management','readonly') NOT NULL DEFAULT 'readonly',
    totp_secret_enc VARCHAR(512) DEFAULT NULL,  -- AES-256-GCM verschlüsselt
    totp_enabled    BOOLEAN DEFAULT FALSE,
    is_active       BOOLEAN DEFAULT TRUE,
    last_login_at   DATETIME,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (tenant_id, email),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

-- OSCAL-Kataloge (importiert)
CREATE TABLE catalogs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    source_url      VARCHAR(512),
    oscal_json      JSON NOT NULL,              -- Vollständiger OSCAL-Katalog
    version_hash    VARCHAR(64),                -- SHA-256 zur Update-Erkennung
    imported_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

-- Informationsverbund
CREATE TABLE information_domains (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    description     TEXT,
    isms_type       ENUM('standard','enhanced') DEFAULT 'standard',
    metadata_json   JSON,                       -- OSCAL-Metadaten
    status          ENUM('draft','active','archived') DEFAULT 'draft',
    created_by      INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Assets / Zielobjekte
CREATE TABLE assets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    category_uuid   CHAR(36),                   -- GS++ Zielobjektkategorien-UUID
    category_name   VARCHAR(255),
    asset_type      VARCHAR(100),               -- z.B. 'it-systeme', 'netze', 'raeume'
    description     TEXT,
    protection_need_c ENUM('normal','high') DEFAULT 'normal',  -- Vertraulichkeit
    protection_need_i ENUM('normal','high') DEFAULT 'normal',  -- Integrität
    protection_need_a ENUM('normal','high') DEFAULT 'normal',  -- Verfügbarkeit
    metadata_json   JSON,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Geschäftsprozesse
CREATE TABLE business_processes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    description     TEXT,
    criticality     ENUM('low','medium','high','very_high') DEFAULT 'medium',
    owner_user_id   INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Verknüpfung Prozesse ↔ Assets
CREATE TABLE process_assets (
    process_id      INT UNSIGNED NOT NULL,
    asset_id        INT UNSIGNED NOT NULL,
    PRIMARY KEY (process_id, asset_id),
    FOREIGN KEY (process_id) REFERENCES business_processes(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- OSCAL-Profil (Tailoring-Ergebnis)
CREATE TABLE profiles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    version         INT UNSIGNED DEFAULT 1,
    oscal_json      JSON NOT NULL,
    created_by      INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Controls im Scope (abgeleitet aus Profil, arbeitsfähige Einheit)
CREATE TABLE scoped_controls (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    control_id_str  VARCHAR(50) NOT NULL,       -- z.B. "PERS.3.1"
    catalog_id      INT UNSIGNED NOT NULL,
    title           VARCHAR(500),
    description     TEXT,
    parameters_json JSON,                       -- Parameterwerte
    tailoring_json  JSON,                       -- Anpassungen (Präfix, Suffix, Exclude-Begründung)
    is_custom       BOOLEAN DEFAULT FALSE,      -- Eigen-erstelltes Control
    UNIQUE KEY (domain_id, control_id_str),
    FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (catalog_id) REFERENCES catalogs(id)
) ENGINE=InnoDB;

-- SSP-Implementierungsstatus (Kern der Umsetzungsdokumentation)
CREATE TABLE implementations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scoped_control_id INT UNSIGNED NOT NULL,
    asset_id        INT UNSIGNED,               -- NULL = systemweit
    status          ENUM('not_started','planned','partial','implemented','not_applicable') DEFAULT 'not_started',
    maturity_level  TINYINT UNSIGNED DEFAULT 0, -- 0-5
    description     TEXT,                       -- Umsetzungsbeschreibung
    responsible_user_id INT UNSIGNED,
    target_date     DATE,
    completion_date DATE,
    evidence_json   JSON,                       -- Verweise auf Nachweise
    parameters_json JSON,                       -- Lokale Parameter-Überschreibungen
    updated_by      INT UNSIGNED,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (scoped_control_id) REFERENCES scoped_controls(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES assets(id),
    FOREIGN KEY (responsible_user_id) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Risiken
CREATE TABLE risks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    asset_id        INT UNSIGNED,               -- NULL = systemweit
    likelihood      ENUM('very_low','low','medium','high','very_high') DEFAULT 'medium',
    impact          ENUM('negligible','low','medium','high','critical') DEFAULT 'medium',
    risk_level      ENUM('low','medium','high','critical'),  -- berechnet
    treatment       ENUM('mitigate','accept','transfer','avoid') DEFAULT 'mitigate',
    acceptance_justification TEXT,               -- Pflicht bei 'accept'
    owner_user_id   INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES assets(id),
    FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Risiko ↔ Control-Zuordnung (Mitigation)
CREATE TABLE risk_controls (
    risk_id         INT UNSIGNED NOT NULL,
    scoped_control_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (risk_id, scoped_control_id),
    FOREIGN KEY (risk_id) REFERENCES risks(id) ON DELETE CASCADE,
    FOREIGN KEY (scoped_control_id) REFERENCES scoped_controls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Assessment Plan
CREATE TABLE assessment_plans (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(255) NOT NULL,
    version         INT UNSIGNED DEFAULT 1,
    assessor_name   VARCHAR(255),
    assessor_org    VARCHAR(255),
    assessor_email  VARCHAR(255),
    period_start    DATE,
    period_end      DATE,
    methodology     TEXT,
    rules_of_engagement TEXT,
    status          ENUM('draft','active','completed') DEFAULT 'draft',
    oscal_json      JSON,
    created_by      INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Assessment Findings (Prüfbefunde)
CREATE TABLE assessment_findings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id         INT UNSIGNED NOT NULL,
    scoped_control_id INT UNSIGNED NOT NULL,
    method          SET('examine','interview','test'),
    result          ENUM('satisfied','not_satisfied','partial','not_assessed') DEFAULT 'not_assessed',
    observation     TEXT,
    risk_statement  TEXT,                       -- Bei Nicht-Erfüllung
    assessed_by     INT UNSIGNED,
    assessed_at     DATETIME,
    FOREIGN KEY (plan_id) REFERENCES assessment_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (scoped_control_id) REFERENCES scoped_controls(id),
    FOREIGN KEY (assessed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- POA&M Items
CREATE TABLE poam_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    finding_id      INT UNSIGNED,               -- Quelle: Assessment Finding
    scoped_control_id INT UNSIGNED,
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    priority        ENUM('high','medium','low') DEFAULT 'medium',
    status          ENUM('open','in_progress','completed','verified','accepted') DEFAULT 'open',
    responsible_user_id INT UNSIGNED,
    deadline        DATE,
    completion_date DATE,
    deviation_justification TEXT,               -- Bei Risikoakzeptanz
    milestones_json JSON,                       -- Phasenplan
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (finding_id) REFERENCES assessment_findings(id),
    FOREIGN KEY (scoped_control_id) REFERENCES scoped_controls(id),
    FOREIGN KEY (responsible_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- KI-Antwort-Cache
CREATE TABLE ai_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    cache_key       VARCHAR(64) NOT NULL,       -- SHA-256(prompt + context)
    prompt_type     VARCHAR(50),                -- 'explain','suggest','risk','audit','remediate'
    response_text   MEDIUMTEXT,
    provider        VARCHAR(20),                -- 'claude' | 'gemini'
    model           VARCHAR(50),
    tokens_used     INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (tenant_id, cache_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

-- Passwort-Reset-Tokens
CREATE TABLE password_reset_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      VARCHAR(64) NOT NULL,       -- SHA-256 des Klartext-Tokens
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME DEFAULT NULL,
    failed_attempts TINYINT UNSIGNED DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (token_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Audit-Trail
CREATE TABLE audit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED,
    action          VARCHAR(50) NOT NULL,       -- 'create','update','delete','export','login'
    entity_type     VARCHAR(50) NOT NULL,       -- 'implementation','risk','finding','poam_item',...
    entity_id       INT UNSIGNED,
    changes_json    JSON,                       -- {field: {old: ..., new: ...}}
    ip_address      VARCHAR(45),
    user_agent      VARCHAR(500),
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (tenant_id, entity_type, entity_id),
    INDEX (tenant_id, user_id, created_at),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

-- Datei-Nachweise
CREATE TABLE evidence_files (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    stored_path     VARCHAR(512) NOT NULL,      -- Pfad in storage/uploads/
    mime_type       VARCHAR(100),
    file_size       INT UNSIGNED,
    sha256_hash     VARCHAR(64),
    uploaded_by     INT UNSIGNED,
    uploaded_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Dokument-Versionierung (für SSP, Profile, AP, AR, POAM)
CREATE TABLE document_versions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    document_type   ENUM('profile','ssp','ap','ar','poam') NOT NULL,
    domain_id       INT UNSIGNED NOT NULL,
    version         INT UNSIGNED NOT NULL,
    oscal_json      JSON NOT NULL,
    changelog       TEXT,
    created_by      INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (tenant_id, document_type, domain_id, version),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (domain_id) REFERENCES information_domains(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;
```

### 7.2 Indexierungsstrategie

- Volltext-Index auf `scoped_controls.description` und `scoped_controls.title` für die Suchfunktion
- JSON-Pfad-Indices auf häufig abgefragte OSCAL-Felder (z.B. `catalogs.oscal_json->>'$.catalog.metadata.title'`)
- Composite-Indices auf die häufigsten Filter-Kombinationen (tenant_id + status, domain_id + control_id_str)

---

## 8. API-Endpunkte (REST)

Alle Endpunkte erfordern Authentifizierung (Session-Cookie) und CSRF-Token. Antwortformat: JSON.

### 8.1 Authentifizierung & Passwort-Reset

| Methode | Pfad | Beschreibung |
|---|---|---|
| POST | `/api/auth/login` | Login (E-Mail + Passwort + opt. TOTP) |
| POST | `/api/auth/logout` | Session beenden |
| GET | `/api/auth/me` | Aktuellen Benutzer abrufen |
| POST | `/api/auth/totp/setup` | TOTP aktivieren (QR-Code) |
| POST | `/api/auth/totp/verify` | TOTP-Setup bestätigen |
| POST | `/api/auth/password-reset/request` | Reset-Link per E-Mail anfordern (kein Auth erforderlich) |
| POST | `/api/auth/password-reset/confirm` | Neues Passwort mit Token setzen (kein Auth erforderlich) |

### 8.2a Benutzerprofil (eigene Daten)

| Methode | Pfad | Beschreibung |
|---|---|---|
| PUT | `/api/profile` | Anzeigename / E-Mail ändern (erfordert Passwort) |
| POST | `/api/profile/change-password` | Eigenes Passwort ändern |
| GET | `/api/profile/sessions` | Aktive Sitzungen anzeigen |
| POST | `/api/profile/totp/setup` | TOTP-QR-Code anfordern |
| POST | `/api/profile/totp/confirm` | TOTP-Setup bestätigen |
| DELETE | `/api/profile/totp` | TOTP deaktivieren (erfordert Passwort) |

### 8.2b Benutzerverwaltung (Admin only)

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/admin/users` | Alle Benutzer des Mandanten |
| POST | `/api/admin/users` | Benutzer anlegen |
| GET | `/api/admin/users/{id}` | Benutzer-Details |
| PUT | `/api/admin/users/{id}` | Benutzer bearbeiten (Name, Rolle, Status) |
| POST | `/api/admin/users/{id}/reset-password` | Temporäres Passwort setzen |
| GET | `/api/admin/settings` | Mandant-Einstellungen lesen |
| PUT | `/api/admin/settings` | Mandant-Einstellungen speichern (inkl. SMTP) |
| POST | `/api/admin/settings/smtp/test` | Test-E-Mail senden |

### 8.2 Kataloge

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/catalogs` | Alle importierten Kataloge |
| POST | `/api/catalogs/import` | Katalog-Import (JSON-Upload oder GitHub-URL) |
| GET | `/api/catalogs/{id}/controls` | Controls eines Katalogs (paginiert, filterbar) |
| GET | `/api/catalogs/{id}/controls/{controlId}` | Einzelnes Control mit Detaildaten |
| POST | `/api/catalogs/{id}/check-update` | Prüfung auf neue Version |

### 8.3 Informationsverbund & Modellierung

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/domains` | Alle Informationsverbünde |
| POST | `/api/domains` | Neuen Verbund anlegen |
| GET | `/api/domains/{id}` | Verbund-Details |
| PUT | `/api/domains/{id}` | Verbund aktualisieren |
| GET | `/api/domains/{id}/assets` | Assets des Verbunds |
| POST | `/api/domains/{id}/assets` | Asset anlegen |
| POST | `/api/domains/{id}/assets/import-category` | Zielobjektkategorie importieren |
| GET | `/api/domains/{id}/processes` | Geschäftsprozesse |
| POST | `/api/domains/{id}/processes` | Geschäftsprozess anlegen |
| GET | `/api/domains/{id}/scoped-controls` | Controls im Scope |
| POST | `/api/domains/{id}/tailoring` | Tailoring anwenden |
| POST | `/api/domains/{id}/generate-profile` | OSCAL-Profil generieren |
| POST | `/api/domains/{id}/generate-ssp` | Muster-SSP generieren |

### 8.4 Grundschutzcheck / SSP

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/domains/{id}/implementations` | Implementierungsstatus (paginiert, filterbar) |
| PUT | `/api/implementations/{implId}` | Status/Beschreibung aktualisieren |
| POST | `/api/implementations/{implId}/evidence` | Nachweis hochladen |
| POST | `/api/domains/{id}/ssp/import` | SSP-JSON importieren |
| GET | `/api/domains/{id}/ssp/export` | SSP-JSON exportieren |

### 8.5 Risikomanagement

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/domains/{id}/risks` | Risiken des Verbunds |
| POST | `/api/domains/{id}/risks` | Risiko anlegen |
| PUT | `/api/risks/{riskId}` | Risiko aktualisieren |
| POST | `/api/risks/{riskId}/controls` | Control-Zuordnung |

### 8.6 Audit

| Methode | Pfad | Beschreibung |
|---|---|---|
| POST | `/api/domains/{id}/assessments` | Assessment Plan anlegen |
| GET | `/api/assessments/{planId}` | Plan-Details |
| PUT | `/api/assessments/{planId}` | Plan aktualisieren |
| GET | `/api/assessments/{planId}/findings` | Befunde |
| PUT | `/api/findings/{findingId}` | Befund aktualisieren |
| GET | `/api/assessments/{planId}/export/ap` | AP-OSCAL-Export |
| GET | `/api/assessments/{planId}/export/ar` | AR-OSCAL-Export |

### 8.7 POA&M

| Methode | Pfad | Beschreibung |
|---|---|---|
| POST | `/api/domains/{id}/poam/generate` | POA&M aus AR generieren |
| GET | `/api/domains/{id}/poam` | POA&M-Items |
| PUT | `/api/poam/{itemId}` | Item aktualisieren |
| GET | `/api/domains/{id}/poam/export` | POA&M-OSCAL-Export |

### 8.8 KI-Assistent

| Methode | Pfad | Beschreibung |
|---|---|---|
| POST | `/api/ai/explain` | Control erklären |
| POST | `/api/ai/suggest-implementation` | Umsetzungsvorschlag |
| POST | `/api/ai/risk-analysis` | Risikoanalyse |
| POST | `/api/ai/audit-finding` | Audit-Befundvorschlag |
| POST | `/api/ai/remediation-plan` | Sanierungsvorschlag |
| POST | `/api/ai/map-edition-2023` | Mapping auf alten Grundschutz |

### 8.9 Dashboard

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/domains/{id}/dashboard` | Aggregierte KPIs |
| GET | `/api/domains/{id}/dashboard/risks` | Risiko-Heatmap-Daten |
| GET | `/api/domains/{id}/dashboard/timeline` | Meilenstein-Timeline |

---

## 9. Berechtigungskonzept

### 9.1 Rollen

| Rolle | Beschreibung | Berechtigungen |
|---|---|---|
| **admin** | Mandant-Administrator | Vollzugriff, Benutzerverwaltung, Einstellungen |
| **isb** | Informationssicherheitsbeauftragte/r | Alle Module lesen/schreiben, OSCAL-Import/Export, KI-Nutzung |
| **fachverantwortlich** | IT-Leiter, Fachabteilung | Eigene Implementations bearbeiten, zugewiesene Controls sehen |
| **auditor** | Interner/Externer Prüfer | Audit-Modul vollständig, SSP lesen (nicht schreiben) |
| **management** | Geschäftsleitung | Dashboard-Ansicht, Berichte, Risikoübersicht (nur lesen) |
| **readonly** | Beobachter | Nur Leserechte auf alle Bereiche |

### 9.2 Berechtigungsmatrix (Auszug)

| Aktion | admin | isb | fachverantw. | auditor | management | readonly |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Katalog importieren | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Informationsverbund anlegen | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Tailoring | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Implementation bearbeiten | ✅ | ✅ | ✅* | ❌ | ❌ | ❌ |
| Risiken verwalten | ✅ | ✅ | ✅* | ❌ | ❌ | ❌ |
| Assessment Plan erstellen | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| Befunde erfassen | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| POA&M bearbeiten | ✅ | ✅ | ✅* | ❌ | ❌ | ❌ |
| Dashboard sehen | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| KI-Assistent nutzen | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Benutzer verwalten | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

*\* Nur eigene zugewiesene Items*

---

## 10. OSCAL-Kompatibilität

### 10.1 Import-Formate

| Quelle | Format | Ziel im GS++ Manager |
|---|---|---|
| BSI Stand-der-Technik-Bibliothek | OSCAL Catalog JSON | Tabelle `catalogs` |
| NTT DATA `zielobjektkategorien/profile/` | OSCAL Profile JSON | Tabelle `profiles` + `scoped_controls` |
| NTT DATA `zielobjektkategorien/komponenten/` | OSCAL Component Definition JSON | Tabelle `implementations` (Vorlagen) |
| NTT DATA `*_SSP-edited.json` | OSCAL SSP JSON | Tabellen `scoped_controls` + `implementations` |
| NTT DATA `*_AR.json` | OSCAL Assessment Results JSON | Tabelle `assessment_findings` |
| NTT DATA `hilfsdateien/*.json` | Mapping-Dateien | Tabellen `mapping_baustein_zo`, `mapping_controls_anf` |

### 10.2 Export-Formate

Alle Exporte erzeugen OSCAL 1.1.3-konforme JSON-Dokumente mit identischer Struktur wie die NTT DATA Tools, so dass eine Rückwärts-Kompatibilität gewährleistet ist.

### 10.3 Dateinamenkonvention

Exporte folgen der NTT DATA-Konvention:

- `{Verbundname}_Profile.json`
- `{Verbundname}_SSP.json`
- `{Verbundname}_SSP-edited.json`
- `{Verbundname}_AP.json`
- `{Verbundname}_AR.json`
- `{Verbundname}_POAM.json`

---

## 11. KMU-Verständlichkeit: Sprach- und UX-Konzept

### 11.1 Zweisprachige Oberfläche

Die Anwendung wird durchgehend in Deutsch entwickelt (Primärsprache für BSI Grundschutz). Fachbegriffe werden immer mit einer Erklärung versehen.

### 11.2 Glossar-Mapping

| OSCAL / GS++ Term | KMU-verständliche Bezeichnung | Erklärtext (Tooltip) |
|---|---|---|
| System Security Plan (SSP) | Sicherheitskonzept | „Das Dokument, in dem Sie aufschreiben, wie Sie jede Anforderung in Ihrem Unternehmen umsetzen." |
| Control | Anforderung / Maßnahme | „Eine konkrete Sicherheitsanforderung, die Sie umsetzen müssen." |
| Profile / Tailoring | Anforderungsprofil / Anpassung | „Die auf Ihr Unternehmen zugeschnittene Auswahl an Anforderungen." |
| Assessment Plan (AP) | Prüfplan | „Der Plan, wie und wann die Umsetzung geprüft wird." |
| Assessment Results (AR) | Prüfergebnis | „Das Ergebnis der Prüfung: Was wurde bestanden, was nicht?" |
| POA&M | Maßnahmenplan | „Die To-Do-Liste für alle Punkte, die noch offen sind." |
| Component Definition | Umsetzungsvorlage | „Fertige Beispiele, wie andere Unternehmen eine Anforderung umgesetzt haben." |
| Zielobjekt | Schutzobjekt | „Das IT-System, die Anwendung oder der Raum, den Sie absichern." |
| Informationsverbund | Geltungsbereich | „Der Bereich Ihres Unternehmens, für den das Sicherheitskonzept gilt." |
| Maturity Level | Reifegrad | „Wie ausgereift ist Ihre Umsetzung? Von ‚nicht angefangen' (0) bis ‚optimiert' (5)." |

### 11.3 Wizard-Design-Prinzipien

- Maximal 7 Schritte pro Wizard, jeder Schritt auf einem Screen
- Jeder Schritt beginnt mit einer Frage in Alltagssprache (z.B. „Welche IT-Systeme nutzen Sie?")
- Fortschrittsbalken zeigt den aktuellen Stand
- „Was bedeutet das?"-Aufklapper mit Beispiel aus der KMU-Praxis
- Möglichkeit, Schritte zu überspringen und später nachzuholen
- Zusammenfassung am Ende jedes Wizards mit „Was passiert als nächstes?"

---

## 12. Phasenplan (Umsetzung)

### Phase 1: Fundament (Wochen 1–4)

- Projekt-Scaffolding (Verzeichnisstruktur, Router, Auth, CSRF, DB-Migrations)
- Benutzerverwaltung: Admin-Interface (Benutzer anlegen, Rolle ändern, deaktivieren, Passwort zurücksetzen)
- Benutzerprofil: eigenes Passwort und E-Mail ändern, TOTP-Setup
- Passwort-Vergessen-Flow (token-basiert, E-Mail; greift auf SMTP-Konfiguration zurück)
- SMTP-Konfiguration in Mandant-Einstellungen
- Katalog-Import (BSI Grundschutz++ Catalog JSON parsen und persistieren)
- Katalog-Viewer (hierarchische Navigation, Suche)
- **Tests:** `PasswordHasher`, `FieldEncryptor`, `CsrfMiddleware`, `Router` (Unit); Auth-Endpunkte Login/Logout/Me, CSRF-Rejection, Session-Timeout (Integration); Passwort-Reset-Flow (Token-Generierung, Ablauf, Brute-Force-Schutz); Admin-Benutzerverwaltung CRUD; Migration-Roundtrip gegen Test-DB

### Phase 2: Modellierung (Wochen 5–8)

- Informationsverbund-Wizard
- Asset-/Zielobjekt-Verwaltung mit Zielobjektkategorien-Import
- Geschäftsprozess-Erfassung
- Tailoring-Engine (Profil-Generierung)
- Muster-SSP-Generierung
- OSCAL Profile/SSP Export
- **Tests:** `OscalParser` Catalog-Import mit BSI-Fixture (Unit); `TailoringEngine` Parameter-Setzung, Control-Ausschluss mit Begründungspflicht (Unit); Domain-/Asset-Repository CRUD (Integration)

### Phase 3: Grundschutzcheck (Wochen 9–12)

- SSP-Editor (Implementation-Status pro Control)
- Filter/Suche, Fortschrittsanzeige
- Nachweis-Upload
- Komponentendefinitionen-Import als Umsetzungsvorlagen
- Workspace-Konzept (automatisches Speichern)
- OSCAL SSP Import/Export (NTT DATA-kompatibel)
- **Tests:** `OscalExporter` SSP-Roundtrip (Import→Export→Schema-Vergleich, NTT DATA-Dateinamen); Implementation-Repository Status-Übergänge; Nachweis-Upload Validierung (MIME-Type, Größe, Pfad außerhalb Webroot); Vitest für `useOscal`-Composable

### Phase 4: Risikomanagement (Wochen 13–14)

- Risikoerfassung und -bewertung
- Risiko↔Control-Zuordnung
- Risikolandkarte (Heatmap)
- **Tests:** `RiskEngine` Risiko-Level-Kalkulation (alle Likelihood×Impact-Kombinationen); Risikoakzeptanz-Pflichtbegründung bei `accept`; Risk-Control-Verknüpfung Repository (Integration)

### Phase 5: Audit (Wochen 15–18)

- Assessment Plan-Editor
- Befund-Erfassung
- Dashboard für Audit-Ergebnisse
- OSCAL AP/AR Export
- **Tests:** AP/AR OSCAL-Roundtrip; Befund-Aggregation (satisfied/not_satisfied/partial-Zählung); Rollenprüfung — `fachverantwortlich` darf keinen Assessment Plan anlegen (Integration); Vitest für Audit-Dashboard-Komponente

### Phase 6: Sanierung (Wochen 19–20)

- POA&M-Generierung aus AR
- Maßnahmen-Tracking mit Deadlines und Meilensteinen
- Benachrichtigungssystem
- OSCAL POA&M Export
- **Tests:** POA&M-Generierung aus Assessment Results (alle `not_satisfied`-Findings werden zu Items); Deadline-Eskalationslogik (überfällig, nähend, ok); OSCAL POA&M-Roundtrip; `NotificationService` mit gemocktem Mail-Transport

### Phase 7: Dashboard & KI (Wochen 21–24)

- Zentrales Dashboard mit KPIs, Ampelsystem, Trend
- Rollenspezifische Sichten
- KI-Integration (Claude API + Gemini)
- KI-Antwort-Cache
- PDF-Report-Generierung
- **Tests:** `AiClient` mit gemocktem HTTP-Transport (kein echter API-Call in Tests); Cache-Key-Kollision und Cache-Hit-Logik; Dashboard-KPI-Aggregation gegen bekannte Fixture-Daten; Rollenfilter (Management sieht nur Dashboard — 403 auf alle anderen Endpunkte)

### Phase 8: Härtung & Launch (Wochen 25–28)

- Security-Audit der eigenen Anwendung
- Penetrationstest
- Performance-Optimierung
- Docker-Compose-Paketierung
- Dokumentation (Benutzer-Handbuch, Admin-Guide)
- CLAUDE.md und Claude-Code-Integration
- **Tests:** Coverage-Report erstellen und NFA-T1/T6 nachweisen (≥ 80 % Backend, ≥ 70 % Frontend); Regressionstest-Suite für alle bisher implementierten Endpunkte; Performance-Benchmark Katalog-Import und OSCAL-Export gegen NFA-P2/P3

---

## 13. Abgrenzungen und bewusste Entscheidungen

### 13.1 Was der GS++ Manager NICHT ist

- **Kein ISMS-Tool für Großunternehmen:** Zielgruppe sind KMU mit 50–500 Mitarbeitenden, nicht Konzerne mit tausenden Zielobjekten
- **Kein vollautomatisches Compliance-Tool:** Der Mensch bleibt in der Verantwortung. Das Tool unterstützt, ersetzt aber nicht die fachliche Bewertung.
- **Kein BSI-Zertifizierungs-Automatismus:** Das Tool erzeugt kein Zertifikat, sondern die Dokumentation, die für eine Zertifizierung benötigt wird.
- **Kein Ersatz für die NTT DATA Tools:** Sondern eine Weiterentwicklung mit Persistenz, Multi-User und KMU-Fokus. Die OSCAL-Kompatibilität stellt sicher, dass Artefakte zwischen beiden Welten austauschbar bleiben.

### 13.2 Bewusste Entscheidungen

| Entscheidung | Begründung |
|---|---|
| Kein PHP-Framework | Konsistenz mit bestehender Tool-Philosophie (VendorShield, Wanyanka, OSGridManager); volle Kontrolle über Security-Layer; minimale Abhängigkeiten |
| MariaDB JSON-Spalten statt NoSQL | OSCAL-Dokumente profitieren von JSON-Speicherung für vollständige Import/Export-Treue, während relationale Tabellen die Arbeitsebene (Filter, Suche, Aggregation) bedienen |
| Vue 3 Frontend | Konsistenz mit LoreBuilder; reaktive SPA-Erfahrung für den SSP-Editor |
| Multi-Tenancy ab Tag 1 | Ermöglicht spätere Nutzung als SaaS oder durch Berater, die mehrere KMU betreuen |
| KI optional, nicht zentral | Das Tool muss vollständig ohne KI funktionieren. KI ist ein Helfer, kein Gatekeeper. |

---

## 14. Risiken und offene Punkte

| Risiko | Eintritt | Auswirkung | Mitigation |
|---|---|---|---|
| BSI ändert Methodik signifikant vor Abschluss | Mittel | Schema-Änderungen, Re-Import nötig | OSCAL-JSON als Source of Truth beibehalten; relationale Tabellen als Arbeitsschicht; Migrations-System |
| OSCAL-Catalog-Format ändert sich (1.1.3 → 2.0) | Niedrig | Parser-Anpassung | OscalParser als isolierter Service; Versionserkennung im Parser |
| KMU-Akzeptanz bleibt aus (zu komplex) | Mittel | Geringe Nutzung | Extensive Usability-Tests mit realen KMU-ISB in Phase 7 |
| Performance bei großen Katalogen (>1000 Controls) | Niedrig | Langsame Suche | Volltext-Indices; Pagination; Lazy-Loading im Frontend |
| NTT DATA ändert Export-Formate | Niedrig | Import-Inkompatibilität | Exporter/Importer modular; Versionserkennung |

---

## 15. Glossar

| Begriff | Definition |
|---|---|
| **OSCAL** | Open Security Controls Assessment Language — NIST-Standard für maschinenlesbare Sicherheitsdokumentation |
| **BSI** | Bundesamt für Sicherheit in der Informationstechnik |
| **GS++** | Grundschutz++ — Weiterentwicklung des IT-Grundschutz ab 2026 |
| **SSP** | System Security Plan — Dokumentation der Sicherheitsmaßnahmen eines Systems |
| **AP** | Assessment Plan — Prüfplan |
| **AR** | Assessment Results — Prüfergebnisse |
| **POA&M** | Plan of Action and Milestones — Maßnahmenplan für offene Feststellungen |
| **PDCA** | Plan-Do-Check-Act — Kontinuierlicher Verbesserungszyklus |
| **Tailoring** | Anpassung eines generischen Kontrollrahmens an den spezifischen Geltungsbereich |
| **Zielobjekt** | Ein Asset/System, das abgesichert werden soll (z.B. Server, Anwendung, Raum) |
| **Informationsverbund** | Gesamtheit der IT-Infrastruktur, Prozesse und Informationen im ISMS-Geltungsbereich |
| **SdT** | Stand der Technik — Referenzniveau für Sicherheitsanforderungen |

---

## 16. Referenzen

- [BSI Grundschutz++ Portal](https://www.bsi.bund.de/DE/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/Grundschutz-in-der-Informationssicherheit/Grundschutz-Plus-Plus/grundschutz-plus-plus_node.html)
- [BSI Stand-der-Technik-Bibliothek (GitHub)](https://github.com/BSI-Bund/Stand-der-Technik-Bibliothek)
- [BSI Methodik-Leitfaden Grundschutz++](https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Grundschutz/sonstiges/Methodik_Grundschutz_PlusPlus.html)
- [NTT DATA Grundschutz++ Tools (GitHub)](https://github.com/NTT-Data-Deutschland-SE/Grundschutz-Plus-Plus-Tools)
- [NIST OSCAL Spezifikation](https://pages.nist.gov/OSCAL/)
- [OSCAL.io Tool Registry](https://oscal.io/tools/)

---

## 17. Teststrategie

### 17.1 Philosophie

Tests sind kein nachgelagerter Qualitätsscheck — sie sind Teil der Implementierung. Jede Service- und Security-Klasse wird gemeinsam mit ihren Tests entwickelt. Eine Funktion gilt als fertig, wenn ihr Test grün ist.

**Was wir testen:**
- Alle Entscheidungslogiken (Branches, Validierungen, Berechnungen)
- Alle Sicherheitsgrenzen (Auth-Checks, CSRF, Eingabevalidierung, Rollenprüfungen)
- Alle OSCAL-Roundtrips (Import und Export müssen strukturell identische Ausgabe liefern)
- Alle Fehlerpfade die nach außen sichtbar sind (HTTP-Status, Fehlermeldung, kein Stack-Trace)

**Was wir nicht testen:**
- Framework-/Library-Internals (PDO, phpdotenv, Monolog)
- Triviale Getter/Setter ohne Logik
- Die Datenbank-Struktur selbst — das prüfen die Migrations

### 17.2 Test-Stack

| Schicht | Tool | Version |
|---|---|---|
| PHP Backend — Unit | PHPUnit | 11.x (bereits in `composer.json`) |
| PHP Backend — Integration | PHPUnit + In-Memory-Fixture-DB | — |
| Frontend — Unit | Vitest | ^2.x |
| Frontend — Komponenten | Vue Test Utils | ^2.x |
| Coverage Backend | PHPUnit + Xdebug oder PCOV | — |
| Coverage Frontend | Vitest (istanbul) | — |

### 17.3 Verzeichnisstruktur

```
tests/
├── run.php                          # Einstiegspunkt (ruft PHPUnit auf)
├── phpunit.xml                      # PHPUnit-Konfiguration
├── bootstrap.php                    # .env laden, Test-DB aufbauen
├── Unit/
│   ├── Security/
│   │   ├── FieldEncryptorTest.php
│   │   ├── PasswordHasherTest.php
│   │   └── CsrfMiddlewareTest.php
│   ├── Router/
│   │   └── RouterTest.php
│   ├── Service/
│   │   ├── OscalParserTest.php
│   │   ├── OscalExporterTest.php
│   │   ├── TailoringEngineTest.php
│   │   ├── RiskEngineTest.php
│   │   ├── DiffServiceTest.php
│   │   └── AiClientTest.php         # immer gemockt
│   └── Middleware/
│       ├── AuthMiddlewareTest.php
│       └── AuditLoggerTest.php
├── Integration/
│   ├── Api/
│   │   ├── AuthApiTest.php          # Login, Logout, CSRF, Session-Timeout
│   │   ├── CatalogApiTest.php
│   │   ├── DomainApiTest.php
│   │   ├── SspApiTest.php
│   │   ├── RiskApiTest.php
│   │   ├── AssessmentApiTest.php
│   │   └── PoamApiTest.php
│   └── Repository/
│       ├── UserRepositoryTest.php
│       ├── CatalogRepositoryTest.php
│       └── ImplementationRepositoryTest.php
├── Fixtures/
│   ├── db/
│   │   └── test_seed.sql            # Minimaldaten für Test-Tenant + Admin-User
│   └── oscal/
│       ├── sample_catalog.json      # Ausschnitt BSI-Katalog (10 Controls)
│       ├── sample_profile.json
│       ├── sample_ssp.json
│       └── sample_ar.json
└── frontend/                        # Vitest-Tests (im frontend/-Verzeichnis)
    ├── composables/
    │   ├── useAuth.test.js
    │   ├── useApi.test.js
    │   └── useOscal.test.js
    └── stores/
        └── authStore.test.js
```

### 17.4 PHPUnit-Konfiguration (`tests/phpunit.xml`)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory>src</directory>
        </include>
        <report>
            <html outputDirectory="tests/coverage"/>
            <clover outputFile="tests/coverage/clover.xml"/>
        </report>
    </coverage>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_DATABASE" value="gsm-db-test"/>
    </php>
</phpunit>
```

### 17.5 Test-Bootstrap (`tests/bootstrap.php`)

Der Bootstrap führt beim Start der Testsuite genau einmal aus:
1. Composer-Autoloader laden
2. Haupt-`.env` laden (Host, User, Passwort — identisch mit Produktion)
3. PHPUnit überschreibt `DB_DATABASE` mit `gsm-db-test` und `APP_ENV` mit `testing` (via `phpunit.xml` `<env>`-Block) — **keine separate `.env.testing`-Datei nötig**
4. Alle Migrations gegen die Test-DB ausführen (`php migrations/migrate.php`)
5. Fixture-SQL einspielen (`tests/Fixtures/db/test_seed.sql`)

Die Test-DB `gsm-db-test` verwendet denselben MariaDB-Benutzer wie die Produktions-DB. Sie muss einmalig als Voraussetzung angelegt werden:

```sql
CREATE DATABASE IF NOT EXISTS `gsm-db-test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `gsm-db-test`.* TO 'gsm-db'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Nach jedem Integrations-Testfall wird die Test-DB mittels `TRUNCATE` auf den Fixture-Stand zurückgesetzt (via `IntegrationTestCase::tearDown()`). Die Migrations selbst werden nur einmal pro Suite ausgeführt.

### 17.6 Basis-Testklassen

**`Tests\Unit\UnitTestCase`** — schlanke Basis ohne DB-Zugriff; lädt nur Autoloader + Env-Mocks.

**`Tests\Integration\IntegrationTestCase`** — erbt von `PHPUnit\Framework\TestCase`; stellt eine echte PDO-Verbindung zur Test-DB bereit; rollt nach jedem Test Tabellen via `TRUNCATE` zurück; liefert Hilfsmethoden:
- `$this->getDb(): PDO`
- `$this->loginAs(string $role): void` — setzt `$_SESSION` für Rollentest
- `$this->callApi(string $method, string $path, array $body = [], bool $withCsrf = true): array`

### 17.7 Unit-Test-Konventionen

- **Dateiname:** `{KlassenName}Test.php`, Namespace `GsppManager\Tests\Unit\{Layer}`
- **Methoden:** `test_{was_wird_getestet}_{erwartetes_ergebnis}`, z.B. `test_encrypt_decrypt_roundtrip_returns_original_plaintext`
- **AAA-Struktur:** Arrange → Act → Assert, ohne Kommentare (selbsterklärende Methodennamen)
- **Keine globalen Seiteneffekte:** Kein `$_SESSION`, kein echtes DB-Handle in Unit-Tests; PDO wird gemockt oder durch `\PDO`-Stub ersetzt
- **Ein Assert pro Konzept:** Mehrere `assert*`-Aufrufe erlaubt, wenn sie dasselbe Konzept prüfen; separate Testmethode für jede abweichende Erwartung
- **Kein `@dataProvider` mit > 20 Datensätzen** ohne Kommentar warum so viele nötig sind

### 17.8 Mocking-Strategie

| Was wird gemockt | Wie |
|---|---|
| Datenbankzugriff in Unit-Tests | `createMock(PDO::class)` + `createMock(PDOStatement::class)` |
| HTTP-Ausgabe (Controller-Tests) | Output Buffering (`ob_start()` / `ob_get_clean()`) |
| KI-API (`AiClient`) | Interface `AiClientInterface` + Mock-Implementierung; echte API wird nie in Tests aufgerufen |
| E-Mail (`NotificationService`) | Interface `MailTransportInterface` + In-Memory-Mock |
| Datei-System (Upload-Tests) | `sys_get_temp_dir()` als Upload-Pfad; nach Test aufräumen |
| Zeit (`deadline`-Logik) | Statische `Clock`-Klasse mit injiziertem Timestamp; kein `time()` direkt in Services |

Die externen Abhängigkeiten `AiClient` und `NotificationService` werden hinter Interfaces gekapselt, damit sie in Tests ohne Netzwerkzugriff austauschbar sind. Dies ist eine bewusste Architekturanforderung, nicht optional.

### 17.9 Integrations-Testkonventionen

- Jeder API-Test prüft: HTTP-Status, `success`-Flag im JSON, mindestens ein Datenfeld
- Auth-Tests prüfen explizit: 401 ohne Session, 401 bei abgelaufener Session, 403 bei falscher Rolle
- CSRF-Tests prüfen: 403 bei fehlendem Token, 403 bei falschem Token, 200 bei korrektem Token
- Jeder OSCAL-Export-Test führt Schema-Validierung durch (JSON-Schema OSCAL 1.1.3, als Fixture hinterlegt)

### 17.10 Frontend-Tests (Vitest)

```bash
cd frontend
npm install --save-dev vitest @vue/test-utils jsdom
```

Vitest-Konfiguration in `frontend/vite.config.js`:
```js
test: {
  environment: 'jsdom',
  globals: true,
  setupFiles: ['./src/test/setup.js'],
}
```

**Was getestet wird:**
- `useApi.js` — Request-Format, CSRF-Header, Error-Handling bei 401/403/422
- `useAuth.js` — Login-Flow, Session-State, Logout
- `useOscal.js` — Control-Parsing, Status-Mapping
- Pinia Stores — State-Übergänge, Actions, Getters
- Keine Snapshot-Tests; keine End-to-End-Tests in dieser Suite

### 17.11 Coverage-Anforderungen

| Schicht | Ziel | Gemessen mit |
|---|---|---|
| `src/Security/` | 100 % | PHPUnit + PCOV |
| `src/Service/` | ≥ 85 % | PHPUnit + PCOV |
| `src/Repository/` | ≥ 80 % | PHPUnit + PCOV |
| `src/Controller/` | ≥ 70 % | PHPUnit + PCOV |
| `src/Middleware/` | ≥ 90 % | PHPUnit + PCOV |
| `frontend/src/composables/` | ≥ 70 % | Vitest istanbul |
| `frontend/src/stores/` | ≥ 70 % | Vitest istanbul |

Coverage-Unterschreitung in `src/Security/` ist ein Build-Blocker.

### 17.12 Verbotene Testmuster (Anti-Patterns)

- **Echter API-Call in Tests** — `AiClient` immer mocken; Netzwerkzugriff macht Tests flaky und kostet Geld
- **`sleep()` in Tests** — zeitabhängige Logik über injizierte `Clock`-Klasse abstrahieren
- **Test-Daten in Produktion schreiben** — Integrationstests laufen ausschließlich gegen `DB_DATABASE=gsm-db-test`
- **Datenbank-Abhängigkeit in Unit-Tests** — wenn ein Unit-Test eine echte DB-Verbindung braucht, ist es ein Integrationstest und gehört in `tests/Integration/`
- **Kommentare wie `// This tests X`** — der Methodenname soll das beschreiben; Kommentare im Test nur für nicht-offensichtliche Setup-Logik
- **Mehr als ein `System Under Test` pro Testklasse** — eine Testklasse testet genau eine Klasse
