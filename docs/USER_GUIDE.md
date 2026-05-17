# GS++ KMU Compliance Manager — Benutzer-Handbuch

**Version:** 0.1 · **Stand:** Mai 2026  
**Zielgruppe:** ISB, Fachverantwortliche, Auditoren, Geschäftsleitung

---

## Inhalt

1. [Einführung und ISMS-Lebenszyklus](#1-einführung-und-isms-lebenszyklus)
2. [Erste Schritte](#2-erste-schritte)
3. [Rollen und Berechtigungen](#3-rollen-und-berechtigungen)
4. [Modul 1: Katalog-Verwaltung](#4-modul-1-katalog-verwaltung)
5. [Modul 2: Modellierung (Strukturanalyse & Tailoring)](#5-modul-2-modellierung)
6. [Modul 3: Grundschutzcheck (SSP-Editor)](#6-modul-3-grundschutzcheck)
7. [Modul 4: Risikomanagement](#7-modul-4-risikomanagement)
8. [Modul 5: Audit](#8-modul-5-audit)
9. [Modul 6: Sanierung (POA&M)](#9-modul-6-sanierung)
10. [Dashboard](#10-dashboard)
11. [KI-Assistent](#11-ki-assistent)
12. [OSCAL-Glossar](#12-oscal-glossar)

---

## 1. Einführung und ISMS-Lebenszyklus

Der **GS++ KMU Compliance Manager** begleitet Ihr Unternehmen durch alle Phasen des Informationssicherheitsmanagementsystems (ISMS) nach BSI Grundschutz++. Das Tool bildet den PDCA-Zyklus (Plan–Do–Check–Act) digital ab:

```
Plan:    Modellierung + Tailoring   →  Welche Anforderungen gelten für mein Unternehmen?
Do:      Grundschutzcheck (SSP)     →  Wie gut setze ich die Anforderungen um?
Check:   Audit (AP + AR)            →  Hat eine unabhängige Prüfung meine Selbsteinschätzung bestätigt?
Act:     Sanierung (POA&M)          →  Welche Maßnahmen behebe ich bis wann?
```

### Die sechs Module im Überblick

| # | Modul | Zweck | Hauptnutzer |
|---|-------|-------|-------------|
| 1 | Katalog-Verwaltung | BSI-Anforderungskatalog importieren | ISB, Admin |
| 2 | Modellierung | Informationsverbund definieren, Controls auswählen | ISB |
| 3 | Grundschutzcheck | Umsetzungsstatus je Control dokumentieren | Fachverantwortliche |
| 4 | Risikomanagement | Restrisiken erfassen und bewerten | ISB |
| 5 | Audit | Prüfplan erstellen, Befunde dokumentieren | Auditor |
| 6 | Sanierung | Maßnahmenplan (POA&M) verwalten | ISB, Fachverantwortliche |

---

## 2. Erste Schritte

### 2.1 Login

Rufen Sie die Anwendungs-URL in Ihrem Browser auf. Melden Sie sich mit Ihrer E-Mail-Adresse und Ihrem Passwort an.

> **Tipp:** Wenn Multi-Faktor-Authentifizierung (TOTP) für Ihren Account aktiviert ist, werden Sie nach dem Passwort nach einem 6-stelligen Code aus Ihrer Authenticator-App gefragt.

### 2.2 Dashboard

Nach dem Login sehen Sie das zentrale Dashboard mit:

- **Compliance-Ampel** — Gesamtstatus Ihres ISMS (Grün/Gelb/Rot)
- **KPIs** — Implementierungsgrad, offene Befunde, überfällige Maßnahmen
- **Risikolandkarte** — Heatmap aller erfassten Risiken

### 2.3 Ersten Informationsverbund anlegen

Ein **Informationsverbund** ist die Klammer um alle Zielobjekte, Geschäftsprozesse und Controls Ihres ISMS. So legen Sie einen an:

1. Klicken Sie in der linken Navigation auf **„Modellierung"**
2. Klicken Sie auf **„Neuer Informationsverbund"**
3. Geben Sie Name und Beschreibung ein
4. Wählen Sie den **ISMS-Typ**: `standard` (empfohlen für die meisten KMU) oder `erhöht`
5. Klicken Sie **„Erstellen"**

---

## 3. Rollen und Berechtigungen

| Rolle | Bereich | Kann lesen | Kann schreiben |
|-------|---------|------------|----------------|
| **admin** | Alles | ✓ | ✓ |
| **isb** | Alles außer Admin | ✓ | ✓ |
| **fachverantwortlich** | SSP, Risiken, POA&M | ✓ | ✓ (eigene Controls) |
| **auditor** | Audit, SSP (lesend) | ✓ | Nur Audit-Artefakte |
| **management** | Dashboard | ✓ (nur Dashboard) | ✗ |
| **readonly** | Alles außer Admin | ✓ | ✗ |

> **Hinweis:** Die Rolle `management` kann ausschließlich das Dashboard einsehen — alle anderen Menüpunkte sind ausgeblendet oder gesperrt.

---

## 4. Modul 1: Katalog-Verwaltung

### Was es tut

Der Katalog enthält alle BSI-Anforderungen (Controls) als maschinenlesbare OSCAL-Daten. Er ist die Grundlage für das Tailoring in Modul 2.

### BSI-Katalog importieren

1. Navigieren Sie zu **„Katalog"**
2. Klicken Sie **„Katalog importieren"**
3. Wählen Sie eine der angebotenen Quellen:
   - **BSI GitHub (automatisch)** — lädt den aktuellen BSI Grundschutz++ Katalog direkt herunter
   - **Datei-Upload** — wenn Sie eine heruntergeladene OSCAL-JSON-Datei lokal gespeichert haben
4. Bestätigen Sie den Import

> **Wichtig:** Der Import überschreibt keinen bestehenden Katalog. Ein neuer Katalog-Eintrag wird angelegt. Wenn Sie auf eine neue BSI-Version wechseln wollen, nutzen Sie **„Auf Update prüfen"**.

### Controls durchsuchen

Über die **Suchleiste** im Katalog-Bereich können Sie Controls nach ID (z.B. `PERS.3`) oder Stichwort suchen. Die hierarchische Gliederung zeigt Gruppen → Untergruppen → einzelne Controls.

---

## 5. Modul 2: Modellierung

### Was es tut

In der Modellierung legen Sie fest, **welche Controls für Ihren Informationsverbund gelten** (Tailoring). Das Ergebnis ist ein OSCAL-Profil, das die Grundlage für den Grundschutzcheck bildet.

### Schritt-für-Schritt

**Schritt 1: Zielobjekte (Assets) erfassen**

1. Wählen Sie Ihren Informationsverbund aus
2. Klicken Sie auf **„Assets"** → **„Asset hinzufügen"**
3. Geben Sie Name, Typ (Server, Anwendung, Prozess, …) und Schutzbedarf (C/I/A: normal / hoch / sehr hoch) ein
4. Wiederholen Sie dies für alle relevanten Zielobjekte

**Schritt 2: Geschäftsprozesse erfassen**

1. Klicken Sie auf **„Prozesse"** → **„Prozess hinzufügen"**
2. Beschreiben Sie den Geschäftsprozess und seine Abhängigkeiten von den Assets

**Schritt 3: Tailoring durchführen**

1. Klicken Sie auf **„Tailoring"**
2. Das System schlägt automatisch Controls basierend auf ISMS-Typ und Schutzbedarf vor
3. Sie können einzelne Controls manuell ausschließen — eine **Begründung ist Pflicht**
4. Klicken Sie **„Tailoring übernehmen"** — die Controls werden für den Grundschutzcheck vorbereitet

**Schritt 4: Profil exportieren (optional)**

Über **„OSCAL-Profil exportieren"** erhalten Sie eine NTT DATA-kompatible `{Name}_Profile.json`.

---

## 6. Modul 3: Grundschutzcheck

### Was es tut

Der Grundschutzcheck ist das Herzstück des ISMS: Hier dokumentieren Sie, wie gut jede Anforderung in Ihrem Unternehmen umgesetzt ist.

### Umsetzungsstatus erfassen

Für jedes Control wählen Sie einen der folgenden Status:

| Status | Bedeutung |
|--------|-----------|
| **Umgesetzt** | Die Anforderung ist vollständig erfüllt |
| **Teilweise** | Die Umsetzung ist begonnen, aber unvollständig |
| **Geplant** | Die Umsetzung ist beschlossen, aber noch nicht gestartet |
| **Offen** | Es gibt keinen Umsetzungsplan |
| **Nicht anwendbar** | Die Anforderung trifft auf Ihr Unternehmen nicht zu |

> **Empfehlung:** Füllen Sie das Feld **„Beschreibung"** immer aus — es dokumentiert, was konkret umgesetzt wurde und dient als Nachweis gegenüber Auditoren.

### Nachweise hochladen

Zu jedem Control können Sie Dokumente als Nachweis hochladen (z.B. Richtlinien, Screenshots, Protokolle). Erlaubte Formate: PDF, DOCX, PNG, JPG — max. 20 MB pro Datei.

### Fortschrittsanzeige

Die Fortschrittsbalken oben auf der Seite zeigen auf einen Blick: wie viele Controls sind bereits bearbeitet, wie viele noch offen.

### SSP exportieren/importieren

- **Export:** `{Name}_SSP-edited.json` (NTT DATA-kompatibel)
- **Import:** Sie können einen bestehenden SSP importieren, um Daten aus den NTT DATA One-Page-Apps zu übernehmen

---

## 7. Modul 4: Risikomanagement

### Was es tut

Risiken, die durch offene oder nur teilweise umgesetzte Controls entstehen, werden hier erfasst, bewertet und priorisiert.

### Risiko erfassen

1. Navigieren Sie zu **„Risiken"**
2. Klicken Sie **„Risiko hinzufügen"**
3. Füllen Sie aus:
   - **Bezeichnung** und **Beschreibung** des Risikos
   - **Wahrscheinlichkeit** (1–5) und **Auswirkung** (1–5)
   - **Risikobehandlung**: Mitigieren / Akzeptieren / Transferieren / Vermeiden
   - **Verknüpfte Controls**: Welche offenen Controls sind Ursache dieses Risikos?
4. Klicken Sie **„Speichern"**

Das **Risikolevel** (niedrig / mittel / hoch / kritisch) wird automatisch aus Wahrscheinlichkeit × Auswirkung berechnet.

### Risikolandkarte

Die Heatmap auf dem Dashboard zeigt alle Risiken in einer 5×5-Matrix. Risiken im roten Bereich (oben rechts) erfordern sofortige Maßnahmen.

> **Hinweis:** Eine Risikoakzeptanz erfordert immer eine schriftliche Begründung — das ist eine Compliance-Anforderung des BSI Grundschutz++.

---

## 8. Modul 5: Audit

### Was es tut

Das Audit-Modul unterstützt interne und externe Prüfungen des ISMS. Es erzeugt OSCAL-konforme Assessment-Artefakte (AP und AR).

### Assessment Plan erstellen

> **Nur für Auditoren und ISB.**

1. Navigieren Sie zu **„Audit"**
2. Klicken Sie **„Neuer Assessment Plan"**
3. Geben Sie Name, Zeitraum und Prüfmethodik ein
4. Speichern Sie den Plan — er steht nun für die Befunderfassung bereit

### Befunde erfassen

1. Öffnen Sie einen Assessment Plan
2. Klicken Sie **„Befund hinzufügen"**
3. Wählen Sie das geprüfte Control
4. Wählen Sie das Ergebnis: `Erfüllt` / `Nicht erfüllt` / `Teilweise`
5. Beschreiben Sie den Befund und empfohlene Maßnahmen

### AP und AR exportieren

- **Assessment Plan:** `{Name}_AP.json`
- **Assessment Results:** `{Name}_AR.json`

Beide Dateien sind kompatibel mit den NTT DATA Tools und können direkt an den BSI-Prüfer übermittelt werden.

---

## 9. Modul 6: Sanierung

### Was es tut

Aus den Befunden des Audits werden automatisch **POA&M-Items** (Plan of Action & Milestones) generiert — konkrete Maßnahmen mit Zuständigkeit, Deadline und Meilensteinen.

### POA&M generieren

1. Navigieren Sie zu **„Sanierung"**
2. Klicken Sie **„POA&M aus Audit-Ergebnissen generieren"**
3. Das System erstellt für jeden nicht erfüllten Befund automatisch ein POA&M-Item

### Maßnahmen verwalten

Für jedes Item können Sie:
- **Status** aktualisieren: Offen / In Bearbeitung / Erledigt / Akzeptiert
- **Deadline** setzen und Meilensteine hinzufügen
- **Verantwortliche Person** zuweisen

### Farbcodierung

| Farbe | Bedeutung |
|-------|-----------|
| 🔴 Rot | Überfällig — Deadline überschritten |
| 🟡 Gelb | Bald fällig — weniger als 7 Tage |
| 🟢 Grün | Fristgerecht |

### POA&M exportieren

`{Name}_POAM.json` — NTT DATA-kompatibles OSCAL-Format.

---

## 10. Dashboard

Das Dashboard aggregiert den aktuellen ISMS-Status:

- **Gesamtfortschritt** — Anteil umgesetzter Controls in %
- **Offene Befunde** — Anzahl nicht erfüllter Controls aus dem letzten Audit
- **Überfällige Maßnahmen** — POA&M-Items mit überschrittener Deadline
- **Risikolandkarte** — visuelle Übersicht aller Risiken
- **Letzte Aktivitäten** — Audit-Trail der letzten Änderungen

Für die **Geschäftsleitung** ist das Dashboard die einzige Ansicht — alle technischen Details sind ausgeblendet.

---

## 11. KI-Assistent

### Was er kann

Der KI-Assistent (Claude oder Gemini) hilft Ihnen bei:

- **Control-Erklärungen** — Was bedeutet diese Anforderung in einfacher Sprache?
- **Umsetzungsvorschläge** — Wie könnte ich diese Anforderung in meinem Unternehmen umsetzen?
- **Risikoanalyse** — Welche Risiken ergeben sich aus diesem nicht umgesetzten Control?

### Aktivierung

Der KI-Assistent muss vom Administrator mit einem API-Key aktiviert werden (in den Mandant-Einstellungen). Wenn kein Key konfiguriert ist, steht der Assistent nicht zur Verfügung.

### Datenschutz

> **Wichtig:** Wenn Sie den KI-Assistenten verwenden, werden Ihre Eingaben und der Kontext (z.B. Control-Texte) an den jeweiligen KI-Anbieter (Anthropic/Google) übertragen. Übermitteln Sie **keine personenbezogenen Daten oder vertraulichen Unternehmensinformationen** an den Assistenten.

Die Antworten werden in der Datenbank gecacht, um redundante API-Anfragen zu vermeiden.

---

## 12. OSCAL-Glossar

| Begriff | Bedeutung |
|---------|-----------|
| **OSCAL** | Open Security Controls Assessment Language — maschinenlesbares Format für Sicherheitsanforderungen (NIST-Standard) |
| **Catalog** | Anforderungskatalog des BSI — enthält alle Controls (Anforderungen) |
| **Control** | Einzelne Sicherheitsanforderung (z.B. `PERS.3.1` — Sicherheitstraining) |
| **Profile** | Auswahl der Controls, die für Ihren Informationsverbund gelten (Ergebnis des Tailorings) |
| **SSP** | System Security Plan — Dokumentation der Ist-Umsetzung aller Controls |
| **AP** | Assessment Plan — Prüfplan mit Methodik, Prüfer und Zeitplan |
| **AR** | Assessment Results — Ergebnisse der Prüfung mit Befunden |
| **POA&M** | Plan of Action & Milestones — Maßnahmenplan für offene Befunde |
| **Tailoring** | Anpassung des Katalogs auf den eigenen Informationsverbund (Controls auswählen/ausschließen) |
| **Informationsverbund** | Die Gesamtheit aller Zielobjekte, Prozesse und Controls Ihres ISMS |
| **Zielobjekt (Asset)** | IT-System, Anwendung, Raum oder Prozess, der im ISMS betrachtet wird |
| **PDCA** | Plan–Do–Check–Act — Managementmethode für kontinuierliche Verbesserung |
