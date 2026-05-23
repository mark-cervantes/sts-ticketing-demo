#!/usr/bin/env bash
#
# Deploy STS Ticketing to production server
# Usage: ./scripts/deploy.sh [--first-run]
#
set -euo pipefail

DEPLOY_HOST="192.168.254.140"
DEPLOY_USER="cmark"
APP_DIR="/home/cmark/projects/sts-ticketing"
REPO="git@github.com:mark-cervantes/sts-ticketing-demo.git"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${GREEN}[deploy]${NC} $*"; }
warn() { echo -e "${YELLOW}[warn]${NC} $*"; }
err()  { echo -e "${RED}[error]${NC} $*" >&2; }

# Check connectivity
log "Checking connectivity to ${DEPLOY_HOST}..."
if ! ssh -o ConnectTimeout=5 "${DEPLOY_USER}@${DEPLOY_HOST}" 'echo ok' &>/dev/null; then
    err "Cannot reach ${DEPLOY_HOST} via SSH. Check VPN (sudo wg-quick up wg0-ssh-only)."
    exit 1
fi
log "Connected."

FIRST_RUN=false
if [[ "${1:-}" == "--first-run" ]]; then
    FIRST_RUN=true
    log "First-run mode: will clone repo and set up everything."
fi

ssh -T "${DEPLOY_USER}@${DEPLOY_HOST}" bash -s -- "$FIRST_RUN" "$APP_DIR" "$REPO" << 'REMOTE_SCRIPT'
set -euo pipefail

FIRST_RUN="$1"
APP_DIR="$2"
REPO="$3"

log()  { echo "[deploy] $*"; }
warn() { echo "[warn] $*"; }
err()  { echo "[error] $*" >&2; }

# ── Step 1: Clone or pull ──
if [ "$FIRST_RUN" = "true" ] || [ ! -d "$APP_DIR" ]; then
    log "Cloning repository..."
    mkdir -p "$(dirname "$APP_DIR")"
    if [ -d "$APP_DIR" ]; then
        rm -rf "$APP_DIR"
    fi
    git clone "$REPO" "$APP_DIR"
else
    log "Pulling latest changes..."
    cd "$APP_DIR"
    git fetch origin main
    git checkout main
    git reset --hard origin/main
fi

cd "$APP_DIR"

# ── Step 2: Environment file ──
if [ ! -f .env ]; then
    log "Creating .env from production template..."
    cp .env.production.example .env

    # Generate app key
    # We'll generate it after the image is built
    log "⚠️  .env created — review and update secrets before continuing."
fi

# ── Step 3: Build Docker images ──
log "Building Docker images (this may take a few minutes)..."
docker compose -f docker-compose.prod.yml build

# ── Step 4: Start services ──
log "Starting services..."
docker compose -f docker-compose.prod.yml down 2>/dev/null || true
docker compose -f docker-compose.prod.yml up -d

# ── Step 5: Wait for health ──
log "Waiting for services to start..."
sleep 15

# Generate app key if needed (check if APP_KEY is empty)
if grep -q 'APP_KEY=$' .env || grep -q 'APP_KEY=base64:CHANGE_ME' .env; then
    log "Generating application key..."
    docker compose -f docker-compose.prod.yml exec -T app php artisan key:generate --force
fi

# ── Step 6: Verify ──
log "Checking service status..."
docker compose -f docker-compose.prod.yml ps

# Check if app responds
if curl -sf -o /dev/null http://127.0.0.1:8080; then
    log "✅ App is responding on port 8080"
else
    warn "App not responding yet on port 8080 — may still be starting"
fi

# ── Step 7: Update Caddy ──
CADDY_CONF="/etc/caddy/Caddyfile"
if ! grep -q "sts-demo" "$CADDY_CONF" 2>/dev/null; then
    log "Adding STS to Caddy configuration..."
    # Insert before the final 'handle {' catch-all block
    sudo sed -i '/handle {/i \
    @sts host sts-demo.betamaxgroup.tech\
    handle @sts {\
        reverse_proxy 127.0.0.1:8080 {\
            header_up X-Forwarded-Proto https\
            header_up X-Forwarded-Port 443\
            flush_interval -1\
        }\
    }\
' "$CADDY_CONF"
    log "Reloading Caddy..."
    sudo caddy reload --config "$CADDY_CONF" --adapter caddyfile 2>/dev/null || sudo systemctl reload caddy
    log "✅ Caddy updated with sts-demo.betamaxgroup.tech"
else
    log "STS already in Caddy config — skipping"
fi

# ── Step 8: Cleanup ──
log "Pruning old Docker images..."
docker image prune -f

log ""
log "═══════════════════════════════════════════"
log "  Deployment complete!"
log "  App: https://sts-demo.betamaxgroup.tech"
log "  Login: demo@example.com / password"
log "═══════════════════════════════════════════"
REMOTE_SCRIPT

log "Done."
