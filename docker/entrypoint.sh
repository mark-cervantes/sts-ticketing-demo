#!/usr/bin/env bash
set -euo pipefail

# ─── Ensure storage dirs exist ───────────────────────────────────────────────
mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache

# ─── Sync public assets into shared volume ───────────────────────────────────
# The public/ dir is a Docker volume shared with nginx. On rebuild the image
# has fresh Vite-built assets but the volume may retain stale files.
# Copy from the build snapshot (saved in Dockerfile) into the live volume.
if [ -d /var/www/html/public-build ]; then
    echo "[entrypoint] Syncing public assets into shared volume..."
    cp -a /var/www/html/public-build/. /var/www/html/public/
fi

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

# ─── Start the requested process ─────────────────────────────────────────────
# If docker-compose passes a CMD (e.g. "php artisan horizon"), exec it.
# Otherwise default to php-fpm for the main app container.
if [ $# -gt 0 ]; then
    echo "[entrypoint] Executing CMD: $*"
    exec "$@"
else
    echo "[entrypoint] Starting PHP-FPM (default)..."
    exec php-fpm
fi
