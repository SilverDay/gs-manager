#!/bin/sh
set -e

echo "[entrypoint] Running database migrations..."
php /var/www/html/migrations/migrate.php

echo "[entrypoint] Starting Apache..."
exec apache2-foreground
