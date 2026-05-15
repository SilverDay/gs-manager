# GS++ KMU Compliance Manager

Self-hosted LAMP-Webanwendung, die kleine und mittlere Unternehmen (KMU) durch den gesamten Lebenszyklus eines ISMS nach **BSI Grundschutz++** führt.

## Features

- **Katalog-Verwaltung** — Import und Navigation des BSI Grundschutz++ OSCAL-Katalogs
- **Modellierung** — Wizard-geführte Strukturanalyse, Asset-Erfassung und Tailoring
- **Grundschutzcheck** — Dokumentation der Maßnahmen-Umsetzung (SSP)
- **Risikomanagement** — Risikoerfassung, Bewertung und Control-Zuordnung
- **Audit** — Assessment Plan und Results im OSCAL-Format
- **Sanierung** — POA&M-Maßnahmenverfolgung mit Deadlines und Eskalation
- **Dashboard** — Compliance-Ampel, KPIs und Risikolandkarte
- **KI-Assistent** — Verständnishilfe und Umsetzungsvorschläge (Claude/Gemini)

## Voraussetzungen

- PHP 8.3+
- MariaDB 10.11+ (oder MySQL 8.0+)
- Apache 2.4+ mit mod_rewrite
- Node.js 20+ (für Frontend-Build)
- Composer 2

## Schnellstart

```bash
# 1. Repository klonen
git clone <repo-url> gspp-manager
cd gspp-manager

# 2. Konfiguration
cp .env.example .env
# .env bearbeiten: DB-Credentials, APP_SECRET, FIELD_ENCRYPTION_KEY setzen

# 3. Backend
composer install
php migrations/migrate.php

# 4. Frontend
cd frontend && npm install && npm run build && cd ..

# 5. Starten
php -S localhost:8080 -t public/
```

### Mit Docker

```bash
cp .env.example .env
docker-compose up -d
docker-compose exec web php migrations/migrate.php
```

## Standard-Login

- **E-Mail:** admin@localhost
- **Passwort:** changeme!
- ⚠️ Passwort nach dem ersten Login ändern!

## Stack

| Schicht | Technologie |
|---------|-------------|
| Backend | PHP 8.3 (kein Framework) |
| Frontend | Vue 3 + Vite + Tailwind CSS |
| Datenbank | MariaDB 10.11 |
| Webserver | Apache 2.4 |

## OSCAL-Kompatibilität

Import/Export kompatibel mit den [NTT DATA Grundschutz++ Tools](https://github.com/NTT-Data-Deutschland-SE/Grundschutz-Plus-Plus-Tools).

## Lizenz

Proprietär — © SilverDay Media
