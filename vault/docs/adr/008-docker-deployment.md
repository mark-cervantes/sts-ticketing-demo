# ADR-008: Docker Compose Deployment Strategy

**Status:** Accepted  
**Date:** 2026-05-22  
**Updated:** 2026-05-23 (task 05.02.00 — Caddy config, localhost binding, SSE passthrough)  
**Context:** Deploying to 192.168.254.140 with Caddy reverse proxy. Need quick iteration.

## Decision

**Custom Dockerfile + Docker Compose** with a multi-stage build targeting `final`.
Caddy runs on the **host**, not inside Docker, and reverse-proxies to the nginx container.

### Services (docker-compose.prod.yml)

| Service   | Image                   | Purpose                        |
| --------- | ----------------------- | ------------------------------ |
| app       | ticketing-app:latest    | Laravel PHP-FPM (port 9000)    |
| nginx     | nginx:alpine            | HTTP → FPM proxy (port 80)     |
| postgres  | postgres:18-alpine      | Database                       |
| redis     | redis:7-alpine          | Queue + cache                  |
| horizon   | ticketing-app:latest    | Queue worker (Horizon)         |
| scheduler | ticketing-app:latest    | `schedule:run` loop            |

**Caddy runs on the HOST** — not as a Docker service. It handles TLS termination
and reverse-proxies to `127.0.0.1:8080` (nginx, bound to localhost only).

### Port Binding

nginx is bound to `127.0.0.1:8080:80` — not `0.0.0.0` — so only the host-local
Caddy process can reach it. This prevents direct public access to HTTP.

### Caddy Integration

The authoritative Caddy config is **`Caddyfile.prod`** at the repo root.

Key directives:
- `flush_interval -1` — disables buffering for SSE (`text/event-stream`) on the
  `/api/issues/*/stream` endpoint (`IssueSseController` streams for up to 120s)
- `header_up X-Forwarded-Proto {scheme}` — ensures Laravel/Inertia/Ziggy generate
  correct HTTPS URLs behind the proxy
- `header_up X-Real-IP {remote_host}` — passes real client IP through

To activate:
```bash
sudo tee -a /etc/caddy/Caddyfile < Caddyfile.prod
sudo caddy reload --config /etc/caddy/Caddyfile
```

### Trusted Proxies

Laravel must trust the proxy to honour `X-Forwarded-*` headers.
Set in `docker-compose.prod.yml` app environment and `.env.production.example`:

```
TRUSTED_PROXIES=127.0.0.1,::1
```

Laravel 13 reads this via the `TRUSTED_PROXIES` env var automatically (no
`TrustProxies` middleware class needed).

### Nginx SSE Location

`docker/nginx/default.conf` has a dedicated location block for the SSE endpoint:

```nginx
location ~ ^/api/issues/[0-9]+/stream$ {
    fastcgi_read_timeout 130s;   # > IssueSseController::TIMEOUT (120s)
    fastcgi_buffering off;        # stream chunks immediately
}
```

### Volume Strategy

- Postgres data: named volume (`postgres-data`) for persistence
- Static assets: bind-mounted `./public` into nginx container (read-only)
- Redis: ephemeral (queue data is transient)

### Startup

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

Set `SEED_ON_STARTUP=true` in `.env.prod` for the first deploy; set to `false`
after the initial seed to prevent re-seeding on container restart.

## Rationale

**Custom Dockerfile** — multi-stage build produces a lean `final` image with
compiled assets baked in. No source-mounting in production means the container
is self-contained and reproducible.

**Caddy on host** — the host already runs Caddy for other services. Running a
second Caddy inside Docker would conflict on ports 80/443. The host-level Caddy
is the natural TLS termination point.

**Localhost-only nginx binding** — prevents direct public HTTP access to the app.
All traffic must go through Caddy (which enforces HTTPS and passes correct headers).

**SSE buffering disabled at two layers** — both Caddy (`flush_interval -1`) and
nginx (`fastcgi_buffering off`) disable buffering for the stream endpoint so the
`text/event-stream` protocol works correctly end-to-end.

## Consequences

- Caddy config is a separate step from `docker compose up -d` (one-time setup)
- Nginx timeout must be kept in sync with `IssueSseController::TIMEOUT` if that constant changes
- Production images must be rebuilt (`docker compose build`) when app code changes

## SSH Deployment

```bash
ssh 192.168.254.140 "cd /path/to/ticketing-system && docker compose -f docker-compose.prod.yml --env-file .env.prod up -d"
```

To push Caddy config:
```bash
scp Caddyfile.prod 192.168.254.140:/tmp/ticketing-caddy.conf
ssh 192.168.254.140 "sudo tee -a /etc/caddy/Caddyfile < /tmp/ticketing-caddy.conf && sudo caddy reload --config /etc/caddy/Caddyfile"
```
