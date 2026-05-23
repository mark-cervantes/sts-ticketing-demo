#!/usr/bin/env bash
set -euo pipefail

# ─── Ensure storage dirs exist ───────────────────────────────────────────────
mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache

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
# Config cache reads env at cache time — only cache if .env exists in container.
# When using docker-compose env_file, env vars are already in the environment,
# so config:cache bakes the correct values from the running environment.
echo "[entrypoint] Caching config, routes..."
php artisan config:cache
php artisan route:cache
# view:cache is optional — skip if views path not found (Inertia uses Vue, not Blade)
php artisan view:cache 2>/dev/null || echo "[entrypoint] view:cache skipped (no Blade views to cache)"

# ─── Optional seed ───────────────────────────────────────────────────────────
if [ "${SEED_ON_STARTUP:-false}" = "true" ]; then
    echo "[entrypoint] Seeding database..."
    php artisan db:seed --force
fi

# ─── Start PHP-FPM ───────────────────────────────────────────────────────────
echo "[entrypoint] Starting PHP-FPM..."
exec php-fpm
