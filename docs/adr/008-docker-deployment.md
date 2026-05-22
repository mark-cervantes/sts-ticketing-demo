# ADR-008: Docker Compose Deployment Strategy

**Status:** Accepted
**Date:** 2026-05-22
**Context:** Deploying to 192.168.254.140 with Caddy reverse proxy. Need quick iteration.

## Decision

**Simple Docker Compose** with generic images and source-mounted volumes.
No custom Dockerfile builds for the application.

### Services
| Service   | Image                     | Purpose              |
| --------- | ------------------------- | -------------------- |
| app       | Generic PHP 8.3 + Node   | Laravel app + Vite   |
| postgres  | postgres:16-alpine        | Database             |
| redis     | redis:7-alpine            | Queue + cache        |
| horizon   | Same image as app         | Horizon worker       |
| scheduler | Same image as app         | schedule:run loop    |

### Volume Strategy
- Application source code: bind-mounted from host
- Postgres data: named volume for persistence
- Redis: ephemeral (queue data is transient)

### Startup
```bash
docker compose up -d
```
Entrypoint script handles:
1. `composer install` (if vendor/ missing)
2. `npm install && npm run build` (if needed)
3. `php artisan migrate --force`
4. `php artisan db:seed --force` (only on empty DB)
5. Start PHP-FPM / artisan serve

### Caddy Integration
Append to `/etc/caddy/Caddyfile`:
```
sts-demo.betamaxgroup.tech {
    reverse_proxy localhost:{APP_PORT}
}
```

## Rationale

**No custom images** — we want `docker compose up -d` and go. Building custom
images adds a build step that slows iteration. Source mounting means code changes
are reflected immediately (with Vite HMR for frontend).

**Generic PHP image** — use `serversideup/php:8.3-fpm-nginx` or similar
all-in-one image that includes PHP + Nginx. Avoids configuring a separate
web server container.

**Entrypoint automation** — first boot runs migrations/seeds. Subsequent boots
skip if already done. This gives the "one-command setup" the assessment wants
as stretch work.

## Consequences

- Slightly larger container (includes dev dependencies)
- Not production-optimized (no multi-stage build, no opcache tuning)
- Fine for assessment demo; would need hardening for real production
- Caddy config is on the host, outside the compose stack

## SSH Deployment
```bash
ssh 192.168.254.140 "cd /path/to/project && docker compose up -d"
```
Sudo for Caddy config:
```bash
ssh 192.168.254.140 "echo Leanza96 | sudo -S tee -a /etc/caddy/Caddyfile"
```
