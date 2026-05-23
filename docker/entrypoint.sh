#!/usr/bin/env bash
set -euo pipefail

# ─── Wait for Postgres ────────────────────────────────────────────────────────
echo "[entrypoint] Waiting for Postgres at ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
until pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-sail}" -q; do
    sleep 1
done
echo "[entrypoint] Postgres is ready."

# ─── Migrations ───────────────────────────────────────────────────────────────
echo "[entrypoint] Running migrations..."
php artisan migrate --force

# ─── Laravel caches ──────────────────────────────────────────────────────────
echo "[entrypoint] Caching config, routes, views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ─── Optional seed ───────────────────────────────────────────────────────────
if [ "${SEED_ON_STARTUP:-false}" = "true" ]; then
    echo "[entrypoint] Seeding database..."
    php artisan db:seed --force
fi

# ─── Start PHP-FPM ───────────────────────────────────────────────────────────
echo "[entrypoint] Starting PHP-FPM..."
exec php-fpm
