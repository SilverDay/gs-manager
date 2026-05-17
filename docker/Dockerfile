FROM php:8.3-apache AS prod

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-install \
    pdo_mysql \
    intl \
    zip \
    opcache \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers

# Configure Apache
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf
RUN echo '<Directory /var/www/html/public>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first for layer caching
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts 2>/dev/null || true

# Copy application
COPY . .

# Set permissions
RUN chown -R www-data:www-data storage/ \
    && chmod -R 775 storage/

# PHP production config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "display_errors = Off" >> "$PHP_INI_DIR/conf.d/app.ini" \
    && echo "log_errors = On" >> "$PHP_INI_DIR/conf.d/app.ini" \
    && echo "error_log = /var/log/php_errors.log" >> "$PHP_INI_DIR/conf.d/app.ini" \
    && echo "upload_max_filesize = 20M" >> "$PHP_INI_DIR/conf.d/app.ini" \
    && echo "post_max_size = 25M" >> "$PHP_INI_DIR/conf.d/app.ini" \
    && echo "session.cookie_httponly = 1" >> "$PHP_INI_DIR/conf.d/app.ini" \
    && echo "session.cookie_samesite = Strict" >> "$PHP_INI_DIR/conf.d/app.ini"

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# ── dev stage: adds pcov for coverage reports ────────────────────────────────
FROM prod AS dev
RUN pecl install pcov \
    && docker-php-ext-enable pcov \
    && echo "pcov.enabled=1" >> "$PHP_INI_DIR/conf.d/pcov.ini" \
    && echo "pcov.directory=/var/www/html/src" >> "$PHP_INI_DIR/conf.d/pcov.ini"
