# GS++ KMU Compliance Manager — Administrator-Handbuch

**Version:** 0.1 · **Stand:** Mai 2026  
**Zielgruppe:** System-Administratoren, IT-Verantwortliche

---

## Inhalt

1. [Systemvoraussetzungen](#1-systemvoraussetzungen)
2. [Installation: Bare-Metal LAMP](#2-installation-bare-metal-lamp)
3. [Installation: Docker](#3-installation-docker)
4. [Konfiguration (.env Referenz)](#4-konfiguration-env-referenz)
5. [Erster Start](#5-erster-start)
6. [Backup & Restore](#6-backup--restore)
7. [Upgrade-Prozedur](#7-upgrade-prozedur)
8. [Cron-Jobs](#8-cron-jobs)
9. [Troubleshooting](#9-troubleshooting)
10. [Sicherheits-Checkliste (Production)](#10-sicherheits-checkliste-production)

---

## 1. Systemvoraussetzungen

### Bare-Metal

| Komponente | Mindestversion |
|------------|---------------|
| PHP | 8.3+ |
| MariaDB | 10.11+ (oder MySQL 8.0+) |
| Apache | 2.4+ mit `mod_rewrite`, `mod_headers` |
| Composer | 2.x |
| Node.js | 20+ (nur für Frontend-Build) |

**PHP-Extensions:** `pdo_mysql`, `intl`, `zip`, `json`, `openssl`, `mbstring`

### Docker

- Docker Engine 24+
- Docker Compose 2.20+

### Ressourcen (empfohlen)

- 2 vCPU, 2 GB RAM, 20 GB Disk
- Für Produktionsbetrieb: separater DB-Server oder verwalteter Datenbankdienst

---

## 2. Installation: Bare-Metal LAMP

### 2.1 Repository klonen

```bash
git clone https://github.com/SilverDay/gs-manager.git /var/www/gsm
cd /var/www/gsm
```

### 2.2 Konfiguration

```bash
cp .env.example .env
# .env bearbeiten — alle CHANGE_ME-Werte ersetzen (siehe §4)
nano .env
```

Schlüssel generieren:
```bash
# APP_SECRET (64 Zeichen hex):
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

# FIELD_ENCRYPTION_KEY (64 Zeichen hex):
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

### 2.3 Backend-Abhängigkeiten installieren

```bash
composer install --no-dev --optimize-autoloader
```

### 2.4 Datenbank anlegen

```sql
CREATE DATABASE gsm_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gsm'@'127.0.0.1' IDENTIFIED BY 'IHR_SICHERES_PASSWORT';
GRANT ALL PRIVILEGES ON gsm_prod.* TO 'gsm'@'127.0.0.1';
FLUSH PRIVILEGES;
```

### 2.5 Migrationen ausführen

```bash
php migrations/migrate.php
```

### 2.6 Frontend bauen

```bash
cd frontend
npm install --production=false
npm run build
cd ..
```

### 2.7 Apache konfigurieren

```apache
<VirtualHost *:443>
    ServerName gsm.ihredomaene.de
    DocumentRoot /var/www/gsm/public

    <Directory /var/www/gsm/public>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile     /etc/ssl/certs/ihrcert.crt
    SSLCertificateKeyFile  /etc/ssl/private/ihrkey.key

    ErrorLog  /var/log/apache2/gsm-error.log
    CustomLog /var/log/apache2/gsm-access.log combined
</VirtualHost>
```

```bash
a2enmod rewrite headers ssl
systemctl reload apache2
```

### 2.8 Dateiberechtigungen

```bash
chown -R www-data:www-data /var/www/gsm/storage
chmod -R 775 /var/www/gsm/storage
```

---

## 3. Installation: Docker

### 3.1 Schnellstart (Entwicklung)

```bash
git clone https://github.com/SilverDay/gs-manager.git gsm
cd gsm
cp .env.example .env
# .env bearbeiten
docker-compose up -d
docker-compose exec web php migrations/migrate.php
```

Die Anwendung ist auf `http://localhost:8080` erreichbar.

### 3.2 Production mit Docker

```bash
cp .env.example .env.prod
# .env.prod mit Produktionswerten befüllen
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

Der `prod`-Override:
- Verwendet das **`prod`-Build-Target** (ohne pcov, ohne Dev-Volumes)
- Führt Migrationen **automatisch** beim Container-Start aus (via Entrypoint)
- Liest Secrets aus `.env.prod` statt aus dem `environment:`-Block
- Startet Container **automatisch** nach Systemrestart (`restart: unless-stopped`)

> **Sicherheitshinweis:** Die Datei `.env.prod` enthält Produktions-Credentials. Sie darf **niemals** in Git eingecheckt werden. Stellen Sie sicher, dass `.env*` in der `.gitignore` steht.

### 3.3 Frontend in Docker bauen

Das `docker-compose.prod.yml` enthält keinen `frontend`-Service. Die Assets müssen vor dem Build kompiliert werden:

```bash
cd frontend && npm run build && cd ..
docker-compose -f docker-compose.yml -f docker-compose.prod.yml build
```

---

## 4. Konfiguration (.env Referenz)

| Variable | Pflicht | Beispiel | Beschreibung |
|----------|---------|---------|-------------|
| `APP_NAME` | ✓ | `GS++ Manager` | Anzeigename der Anwendung |
| `APP_ENV` | ✓ | `production` | `development` oder `production` |
| `APP_DEBUG` | ✓ | `false` | Detaillierte Fehlerausgabe (niemals `true` in Produktion) |
| `APP_URL` | ✓ | `https://gsm.firma.de` | Öffentliche URL (für E-Mail-Links) |
| `APP_SECRET` | ✓ | *(64 hex Zeichen)* | Anwendungs-Secret; `php -r "echo bin2hex(random_bytes(32));"` |
| `APP_HOSTNAME` | — | `gsm.firma.de` | Hostname für SMTP-EHLO (default: `localhost`) |
| `DB_HOST` | ✓ | `127.0.0.1` | Datenbankhost |
| `DB_PORT` | — | `3306` | Datenbankport (default: 3306) |
| `DB_DATABASE` | ✓ | `gsm_prod` | Datenbankname |
| `DB_USERNAME` | ✓ | `gsm` | Datenbankbenutzer |
| `DB_PASSWORD` | ✓ | *(sicheres Passwort)* | Datenbankpasswort |
| `SESSION_LIFETIME` | — | `30` | Session-Timeout in Minuten (default: 30) |
| `SESSION_NAME` | — | `gsm_session` | Name des Session-Cookies |
| `FIELD_ENCRYPTION_KEY` | ✓ | *(64 hex Zeichen)* | AES-256-GCM-Schlüssel für API-Keys in DB; `php -r "echo bin2hex(random_bytes(32));"` |
| `AI_PROVIDER` | — | `claude` | KI-Anbieter: `claude` oder `gemini` |
| `ANTHROPIC_API_KEY` | — | `sk-ant-…` | Claude API Key (verschlüsselt in DB gespeichert) |
| `ANTHROPIC_MODEL` | — | `claude-sonnet-4-20250514` | Claude-Modell |
| `GEMINI_API_KEY` | — | `AIza…` | Gemini API Key |
| `GEMINI_MODEL` | — | `gemini-2.0-flash` | Gemini-Modell |
| `MAIL_HOST` | — | `smtp.firma.de` | SMTP-Server |
| `MAIL_PORT` | — | `587` | SMTP-Port |
| `MAIL_USERNAME` | — | `gsm@firma.de` | SMTP-Benutzername |
| `MAIL_PASSWORD` | — | *(Passwort)* | SMTP-Passwort |
| `MAIL_FROM_ADDRESS` | — | `gsm@firma.de` | Absenderadresse |
| `MAIL_FROM_NAME` | — | `GS++ Manager` | Absendername |
| `RATE_LIMIT_LOGIN_MAX` | — | `5` | Max. Login-Versuche im Fenster |
| `RATE_LIMIT_LOGIN_WINDOW` | — | `900` | Fenster in Sekunden (900 = 15 min) |
| `RATE_LIMIT_API_MAX` | — | `100` | Max. API-Anfragen je Fenster |
| `RATE_LIMIT_API_WINDOW` | — | `60` | API-Fenster in Sekunden |
| `TRUST_PROXY` | — | `false` | `true` nur hinter einem vertrauenswürdigen Load Balancer |
| `NOTIFY_DAYS_AHEAD` | — | `7` | Tage vor POA&M-Deadline für Erinnerungs-E-Mail |

---

## 5. Erster Start

### 5.1 Standard-Admin-Account

Nach der ersten Migration ist ein Standard-Administrator angelegt:

| Feld | Wert |
|------|------|
| E-Mail | `admin@localhost` |
| Passwort | `changeme!` |

> **⚠️ Ändern Sie das Passwort sofort nach dem ersten Login!**  
> Profil → Passwort ändern

### 5.2 SMTP konfigurieren

Ohne SMTP-Konfiguration funktioniert der Passwort-Vergessen-Flow nicht. Tragen Sie die SMTP-Parameter in `.env` ein und starten Sie die Anwendung neu.

### 5.3 Ersten BSI-Katalog importieren

1. Melden Sie sich als Admin an
2. Navigieren Sie zu **Katalog → Katalog importieren**
3. Wählen Sie **„BSI GitHub (automatisch)"**
4. Warten Sie auf die Bestätigung

### 5.4 Benutzer anlegen

1. Navigieren Sie zu **Admin → Benutzerverwaltung**
2. Klicken Sie **„Benutzer hinzufügen"**
3. Vergeben Sie Rolle und temporäres Passwort
4. Der Benutzer erhält eine Einladungs-E-Mail (sofern SMTP konfiguriert ist)

---

## 6. Backup & Restore

### 6.1 Datenbank sichern

```bash
# Vollständiges Dump
mysqldump -u gsm -p gsm_prod | gzip > /backup/gsm_$(date +%Y%m%d).sql.gz

# Nur Schema (für Disaster-Recovery-Planung)
mysqldump -u gsm -p --no-data gsm_prod > /backup/gsm_schema.sql
```

### 6.2 Upload-Verzeichnis sichern

```bash
tar -czf /backup/gsm_storage_$(date +%Y%m%d).tar.gz /var/www/gsm/storage/
```

### 6.3 Restore

```bash
# DB
gunzip -c /backup/gsm_20260501.sql.gz | mysql -u gsm -p gsm_prod

# Storage
tar -xzf /backup/gsm_storage_20260501.tar.gz -C /
chown -R www-data:www-data /var/www/gsm/storage
```

### 6.4 Docker-Backup

```bash
# DB aus Container
docker-compose exec db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" gsm_prod' | gzip > backup.sql.gz

# Named Volume
docker run --rm -v gsm_db_data:/data -v $(pwd):/backup alpine \
  tar czf /backup/db_data_$(date +%Y%m%d).tar.gz /data
```

---

## 7. Upgrade-Prozedur

```bash
# 1. Backup erstellen (siehe §6)

# 2. Anwendung stoppen (optional — Lese-/Schreib-Lock vermeiden)
a2dissite gsm.conf && systemctl reload apache2

# 3. Neuen Code holen
git pull origin main

# 4. Abhängigkeiten aktualisieren
composer install --no-dev --optimize-autoloader

# 5. Neue Migrationen einspielen
php migrations/migrate.php

# 6. Frontend neu bauen (falls sich Frontend-Dateien geändert haben)
cd frontend && npm install && npm run build && cd ..

# 7. Anwendung starten
a2ensite gsm.conf && systemctl reload apache2
```

**Docker:**
```bash
docker-compose -f docker-compose.yml -f docker-compose.prod.yml pull
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```
Migrationen laufen automatisch beim Container-Start.

---

## 8. Cron-Jobs

### Deadline-Benachrichtigungen

Das Skript `bin/notify-deadlines.php` versendet Erinnerungs-E-Mails für bald fällige POA&M-Items.

```bash
# Crontab-Eintrag (täglich um 07:00 Uhr)
0 7 * * * /usr/bin/php /var/www/gsm/bin/notify-deadlines.php >> /var/log/gsm-notify.log 2>&1
```

**Docker** (via `docker exec`):
```bash
0 7 * * * docker exec gsm_web_1 php /var/www/html/bin/notify-deadlines.php >> /var/log/gsm-notify.log 2>&1
```

Konfigurierbar über `.env`:
- `NOTIFY_DAYS_AHEAD=7` — wie viele Tage im Voraus benachrichtigt wird

### Rate-Limit-Bereinigung

Veraltete Rate-Limit-Einträge werden bei jedem Login-Versuch automatisch bereinigt. Ein expliziter Cron-Job ist nicht erforderlich.

---

## 9. Troubleshooting

### Login funktioniert nicht

**Symptom:** Login schlägt fehl, Fehlermeldung „Invalid credentials"

1. Prüfen Sie, ob die DB-Verbindung funktioniert: `php migrations/migrate.php --status`
2. Prüfen Sie, ob der `admin@localhost`-Account aktiv ist:  
   `SELECT email, is_active, failed_attempts FROM users WHERE email='admin@localhost';`
3. Setzen Sie das Passwort zurück (falls gesperrt):  
   ```sql
   UPDATE users SET failed_attempts=0, is_active=1 WHERE email='admin@localhost';
   ```

### Rate-Limit-Sperre nach zu vielen Fehlversuchen

```sql
DELETE FROM rate_limit_attempts WHERE bucket = SHA2(CONCAT('login:', '1.2.3.4'), 256);
```
Ersetzen Sie `1.2.3.4` durch die Client-IP-Adresse.

### Session-Probleme

Prüfen Sie `SESSION_LIFETIME` und `SESSION_NAME` in `.env`. Nach einer Konfigurationsänderung müssen bestehende Sessions neu aufgebaut werden (Browser-Cache leeren).

### E-Mail-Versand schlägt fehl

1. Testen Sie SMTP-Verbindung: `telnet $MAIL_HOST $MAIL_PORT`
2. Prüfen Sie die PHP-Fehlerprotokolle: `/var/log/apache2/error.log` oder `/var/log/php_errors.log`
3. Stellen Sie sicher, dass `MAIL_FROM_ADDRESS` eine gültige Absenderadresse ist

### Fehler 500 — Internal Server Error

1. Aktivieren Sie temporär `APP_DEBUG=true` in `.env` (nur in einem isolierten Kontext!)
2. Lesen Sie `/var/log/apache2/error.log` oder die PHP-Fehlerprotokolle
3. Prüfen Sie Datei- und Verzeichnisberechtigungen auf `storage/`

### Docker: Migrationen schlagen beim Start fehl

Der Entrypoint wartet auf die DB (via `healthcheck`). Wenn der DB-Container sich zu lange initialisiert:

```bash
# Logs des web-Containers prüfen
docker-compose logs web

# Manuell ausführen
docker-compose exec web php /var/www/html/migrations/migrate.php
```

---

## 10. Sicherheits-Checkliste (Production)

Gehen Sie diese Liste vor jedem Produktionsdeployment durch:

- [ ] `APP_ENV=production` und `APP_DEBUG=false` in `.env`
- [ ] `APP_SECRET` und `FIELD_ENCRYPTION_KEY` sind zufällig generiert (64 hex Zeichen)
- [ ] Standard-Admin-Passwort `changeme!` wurde geändert
- [ ] HTTPS ist aktiviert und HTTP wird auf HTTPS weitergeleitet
- [ ] HSTS-Header in `.htaccess` ist auskommentiert → Zeile entfernen, wenn HTTPS dauerhaft aktiv ist
- [ ] `storage/` ist nicht über den Webroot erreichbar (Apache-Konfiguration prüfen)
- [ ] Datenbankbenutzer hat nur `SELECT/INSERT/UPDATE/DELETE`-Rechte, kein `DROP/CREATE`
- [ ] Regelmäßige Backups sind eingerichtet und getestet (§6)
- [ ] Cron-Job für `notify-deadlines.php` ist eingerichtet (§8)
- [ ] PHP-Fehler werden nur in die Logdatei geschrieben, nicht angezeigt
- [ ] `composer audit` — keine bekannten CVEs in Dependencies
- [ ] `TRUST_PROXY=true` nur wenn die Anwendung hinter einem vertrauenswürdigen Reverse Proxy läuft
- [ ] MFA (TOTP) ist für Admin- und ISB-Accounts aktiviert
- [ ] Firewall: DB-Port (3306) ist nicht öffentlich erreichbar
- [ ] Log-Rotation für Apache- und PHP-Fehlerprotokolle ist eingerichtet
