# ── Stage 1: Composer dependencies ──────────────────────────────────────────
FROM composer:2.7 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ── Stage 2: Runtime ──────────────────────────────────────────────────────────
FROM php:8.2-cli-alpine

# System deps
RUN apk add --no-cache \
    libpng-dev \
    libzip-dev \
    oniguruma-dev \
    curl \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        zip \
        gd \
        bcmath \
        opcache \
    && rm -rf /var/cache/apk/*

# PHP tuning (ringan, cukup untuk artisan serve)
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini \
 && echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/opcache.ini \
 && echo "upload_max_filesize=20M" >> /usr/local/etc/php/conf.d/uploads.ini \
 && echo "post_max_size=20M" >> /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

# Copy app
COPY . .
COPY --from=vendor /app/vendor ./vendor

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# Entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 4019

ENTRYPOINT ["docker-entrypoint.sh"]
