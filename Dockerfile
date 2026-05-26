# ─── Stage 1: Composer — install PHP dependencies (no dev) ───────────────────
# Runs first so the node stage can COPY vendor/tightenco/ziggy for Vite build.
FROM composer:2 AS composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist \
    --ignore-platform-req=ext-pcntl \
    --no-scripts

# ─── Stage 2: Node — build frontend assets ───────────────────────────────────
FROM node:22-alpine AS node

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources/ resources/
COPY public/ public/
COPY vite.config.js tsconfig.json ./

# Ziggy is imported in app.ts from vendor/ — copy it from the composer stage
COPY --from=composer /app/vendor/tightenco ./vendor/tightenco

# Skip vue-tsc in Docker (full vendor tree not available for type resolution).
# Type checking is done in CI and local dev (`make verify-visual`).
RUN npx vite build

# ─── Stage 3: Final — PHP 8.4-FPM production image ──────────────────────────
FROM php:8.4-fpm-alpine AS final

LABEL maintainer="ticketing-system"

# System dependencies
RUN apk add --no-cache \
    bash \
    curl \
    libpq-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    linux-headers \
    postgresql-client

# Build-time dependencies (removed after extension compile)
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    openssl-dev \
    pcre-dev

# PHP extensions (core)
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        pcntl \
        bcmath \
        gd \
        intl \
        zip \
        opcache

# ext-redis (required for REDIS_CLIENT=phpredis)
# Build from source — PECL CDN unreliable as of 2026-05
RUN mkdir -p /tmp/redis \
    && curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.3.0.tar.gz \
       | tar xz -C /tmp/redis --strip-components=1 \
    && cd /tmp/redis \
    && phpize && ./configure && make -j"$(nproc)" && make install \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/redis

# Clean build deps
RUN apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/pear

# PHP-FPM + OPcache production config
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

# Copy application code
COPY --chown=www-data:www-data . .

# Copy built assets from node stage
COPY --from=node --chown=www-data:www-data /app/public/build ./public/build

# Copy vendor from composer stage
COPY --from=composer --chown=www-data:www-data /app/vendor ./vendor

# Copy entrypoint
COPY --chown=www-data:www-data docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Snapshot public/ so entrypoint can sync into the shared volume on every boot.
# Without this, a Docker named volume retains stale assets from a previous build.
RUN cp -a public public-build

# Storage directories writable
RUN mkdir -p storage/framework/{sessions,views,cache} \
             storage/logs \
             bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Health check — FPM responds on 9000
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php-fpm -t || exit 1

EXPOSE 9000

# Entrypoint runs as root for migrations/cache, then FPM drops to www-data via pool config
ENTRYPOINT ["/entrypoint.sh"]
