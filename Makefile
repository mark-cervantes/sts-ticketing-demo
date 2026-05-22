# Issue Intake & Smart Summary System — developer workflow
# Single source of truth for "how do I run this project".
#
# Adaptive: reads ports/URL from .env so changing one place changes everything.
# Defensive: port-conflict preflight fails LOUDLY with diagnosis instead of
# starting a half-working environment.

# ──────────────────────────────────────────────────────────────────────────
# Source of truth: .env (with sane defaults for first-time clones)
# ──────────────────────────────────────────────────────────────────────────

# Read from .env if present, fall back to .env.example, fall back to defaults.
# `awk -F=` keeps it portable (no `grep -P`, no `sed -E`).
define env_get
$(or $(shell awk -F= '/^$(1)=/{print $$2; exit}' .env 2>/dev/null | tr -d '"' | tr -d "'"),$(shell awk -F= '/^$(1)=/{print $$2; exit}' .env.example 2>/dev/null | tr -d '"' | tr -d "'"),$(2))
endef

APP_PORT         := $(call env_get,APP_PORT,80)
VITE_PORT        := $(call env_get,VITE_PORT,5173)
FORWARD_DB_PORT  := $(call env_get,FORWARD_DB_PORT,5432)
FORWARD_REDIS    := $(call env_get,FORWARD_REDIS_PORT,6379)
APP_URL          := $(call env_get,APP_URL,http://localhost)

SAIL             := ./vendor/bin/sail
APP_CONTAINER    := laravel.test
DB_CONTAINER     := pgsql
REDIS_CONTAINER  := redis
VITE_LOG         := storage/logs/vite.log
QUEUE_LOG        := storage/logs/queue.log

# Status URL: APP_URL works as-is when APP_PORT=80 because APP_URL already
# includes ":80" implicitly; for non-default ports we append explicitly.
STATUS_URL := $(if $(filter 80,$(APP_PORT)),$(APP_URL),$(APP_URL):$(APP_PORT))

.DEFAULT_GOAL := help
.PHONY: help dev up down restart vite vite-stop queue queue-stop status \
        logs stop test test-filter pint pint-check fresh seed migrate \
        tinker shell npm-install composer-install setup clean-logs \
        preflight port-check ports config

# ──────────────────────────────────────────────────────────────────────────
# Help
# ──────────────────────────────────────────────────────────────────────────

help: ## Show this help (default)
	@echo "Issue Intake & Smart Summary System — make targets"
	@echo ""
	@echo "Daily workflow:"
	@echo "  make dev         — start everything (preflight + containers + vite + queue)"
	@echo "  make status      — health check: containers, vite, queue, http"
	@echo "  make config      — show resolved env (ports, URLs) for this session"
	@echo "  make logs        — tail vite + queue logs (Ctrl-C to exit)"
	@echo "  make stop        — stop vite + queue (containers stay up)"
	@echo "  make down        — stop everything including containers"
	@echo ""
	@echo "All targets:"
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST) | sort

config: ## Show resolved configuration (debug: am I reading .env correctly?)
	@echo "Resolved dev configuration:"
	@echo "  APP_URL          = $(APP_URL)"
	@echo "  APP_PORT         = $(APP_PORT)"
	@echo "  STATUS_URL       = $(STATUS_URL)"
	@echo "  VITE_PORT        = $(VITE_PORT)"
	@echo "  FORWARD_DB_PORT  = $(FORWARD_DB_PORT)"
	@echo "  FORWARD_REDIS    = $(FORWARD_REDIS)"
	@echo "  APP_CONTAINER    = $(APP_CONTAINER)"
	@echo ""
	@if [ ! -f .env ]; then \
		echo "  ⚠  .env missing — using .env.example + defaults. Run 'make setup' to create it."; \
	fi

# ──────────────────────────────────────────────────────────────────────────
# Preflight — port conflict detection with actionable diagnosis
# ──────────────────────────────────────────────────────────────────────────
# port-check: takes a port and a human label. Three outcomes:
#   1. Port free        → OK
#   2. Port held by US  → OK (our own container)
#   3. Port held by SOMEONE ELSE → FAIL with PID + command, halt make
#
# "Ours" is detected by checking if docker is listening on the port for any
# of our containers (laravel.test, pgsql, redis).

preflight: ## Verify ports 80/5175/5434/6379 are free or owned by us
	@$(MAKE) -s port-check PORT=$(APP_PORT) LABEL="app ($(APP_CONTAINER))"
	@$(MAKE) -s port-check PORT=$(VITE_PORT) LABEL="vite (HMR)"
	@$(MAKE) -s port-check PORT=$(FORWARD_DB_PORT) LABEL="postgres ($(DB_CONTAINER))"
	@$(MAKE) -s port-check PORT=$(FORWARD_REDIS) LABEL="redis ($(REDIS_CONTAINER))"
	@echo "✓ preflight: all ports clear"

port-check: ## Internal — used by preflight. Usage: make port-check PORT=80 LABEL=app
	@if ! command -v ss >/dev/null && ! command -v lsof >/dev/null; then \
		echo "  ⚠ port-check: neither ss nor lsof available — skipping preflight for :$(PORT)"; \
		exit 0; \
	fi; \
	OWNER="$$( (ss -ltnp 2>/dev/null || lsof -iTCP:$(PORT) -sTCP:LISTEN -P 2>/dev/null) | grep -E ':$(PORT)\b|:$(PORT) ' | head -1 )"; \
	if [ -z "$$OWNER" ]; then \
		exit 0; \
	fi; \
	if docker compose ps --status running --format '{{.Service}}' 2>/dev/null | grep -qE '^($(APP_CONTAINER)|$(DB_CONTAINER)|$(REDIS_CONTAINER))$$' \
		&& docker compose port $(APP_CONTAINER) $(PORT) 2>/dev/null >/dev/null \
		|| docker compose port $(DB_CONTAINER) 5432 2>/dev/null | grep -q ":$(PORT)$$" \
		|| docker compose port $(REDIS_CONTAINER) 6379 2>/dev/null | grep -q ":$(PORT)$$"; then \
		exit 0; \
	fi; \
	OUR_STALE="$$(docker ps -a --filter 'status=exited' --filter 'label=com.docker.compose.project' --format '{{.Names}}' 2>/dev/null | grep -E 'ticketing|laravel\.test|pgsql|redis' | head -3)"; \
	if [ -n "$$OUR_STALE" ]; then \
		echo "  ⚠ port $(PORT) ($(LABEL)) appears held by a stopped container of ours:"; \
		echo "$$OUR_STALE" | sed 's/^/      /'; \
		echo "    Cleaning up stale containers and retrying..."; \
		docker compose down --remove-orphans >/dev/null 2>&1 || true; \
		exit 0; \
	fi; \
	echo ""; \
	echo "  ✗ PORT CONFLICT on :$(PORT) ($(LABEL))"; \
	echo "  ────────────────────────────────────────────────────"; \
	echo "  Held by:"; \
	echo "$$OWNER" | sed 's/^/      /'; \
	echo ""; \
	echo "  Fix one of these:"; \
	echo "    A) Kill the conflicting process: kill <PID>"; \
	echo "    B) Change the port in .env (e.g. APP_PORT=8080, VITE_PORT=5176,"; \
	echo "       FORWARD_DB_PORT=5435), then 'make config' to verify"; \
	echo "    C) If it's a different Sail project, 'cd' there and 'make down' first"; \
	echo ""; \
	exit 1

# ──────────────────────────────────────────────────────────────────────────
# Primary dev loop — "keep development running"
# ──────────────────────────────────────────────────────────────────────────

dev: preflight up vite queue status ## Start everything (idempotent — safe to re-run)

up: ## Start Sail containers (Laravel, Postgres, Redis) if not already running
	@if docker compose ps --status running --format '{{.Service}}' 2>/dev/null | grep -q "^$(APP_CONTAINER)$$"; then \
		echo "✓ containers already up"; \
	else \
		echo "→ starting containers..."; \
		$(SAIL) up -d; \
	fi

down: vite-stop queue-stop ## Stop everything including containers
	@echo "→ stopping containers..."
	@$(SAIL) down

restart: down dev ## Full restart

# ──────────────────────────────────────────────────────────────────────────
# Vite (asset HMR server) — runs inside the app container
# ──────────────────────────────────────────────────────────────────────────

vite: ## Start Vite dev server in background (idempotent)
	@if docker compose exec -T $(APP_CONTAINER) pgrep -f 'node.*vite' > /dev/null 2>&1; then \
		echo "✓ vite already running on :$(VITE_PORT)"; \
	else \
		echo "→ starting vite on :$(VITE_PORT)..."; \
		mkdir -p storage/logs; \
		docker compose exec -d $(APP_CONTAINER) sh -c 'npm run dev > /var/www/html/$(VITE_LOG) 2>&1'; \
		sleep 2; \
		echo "  log: $(VITE_LOG)"; \
	fi

vite-stop: ## Stop the Vite dev server
	@docker compose exec -T $(APP_CONTAINER) pkill -f 'node.*vite' 2>/dev/null && echo "✓ vite stopped" || echo "  (vite was not running)"

# ──────────────────────────────────────────────────────────────────────────
# Queue worker — for async jobs (GenerateSummaryJob, etc.)
# ──────────────────────────────────────────────────────────────────────────

queue: ## Start queue worker in background (idempotent)
	@if docker compose exec -T $(APP_CONTAINER) pgrep -f 'artisan queue:work' > /dev/null 2>&1; then \
		echo "✓ queue worker already running"; \
	else \
		echo "→ starting queue worker..."; \
		mkdir -p storage/logs; \
		docker compose exec -d $(APP_CONTAINER) sh -c 'php artisan queue:work --tries=3 --timeout=60 > /var/www/html/$(QUEUE_LOG) 2>&1'; \
		sleep 1; \
		echo "  log: $(QUEUE_LOG)"; \
	fi

queue-stop: ## Stop the queue worker
	@docker compose exec -T $(APP_CONTAINER) pkill -f 'artisan queue:work' 2>/dev/null && echo "✓ queue stopped" || echo "  (queue was not running)"

# ──────────────────────────────────────────────────────────────────────────
# Health check — agents and humans run this to verify dev state
# ──────────────────────────────────────────────────────────────────────────

status: ## Health check — verify every dev service is up
	@echo ""
	@echo "Service Status        (config: APP_PORT=$(APP_PORT), VITE_PORT=$(VITE_PORT), DB=$(FORWARD_DB_PORT))"
	@echo "─────────────────────────────────────────────────────────────"
	@if docker compose ps --status running --format '{{.Service}}' 2>/dev/null | grep -q "^$(APP_CONTAINER)$$"; then \
		echo "  ✓ $(APP_CONTAINER)      container up"; \
	else \
		echo "  ✗ $(APP_CONTAINER)      DOWN — run: make up"; \
	fi
	@if docker compose ps --status running --format '{{.Service}}' 2>/dev/null | grep -q "^$(DB_CONTAINER)$$"; then \
		echo "  ✓ postgres          container up on :$(FORWARD_DB_PORT)"; \
	else \
		echo "  ✗ postgres          DOWN"; \
	fi
	@if docker compose ps --status running --format '{{.Service}}' 2>/dev/null | grep -q "^$(REDIS_CONTAINER)$$"; then \
		echo "  ✓ redis             container up on :$(FORWARD_REDIS)"; \
	else \
		echo "  ✗ redis             DOWN"; \
	fi
	@if docker compose exec -T $(APP_CONTAINER) pgrep -f 'node.*vite' > /dev/null 2>&1; then \
		echo "  ✓ vite              running on :$(VITE_PORT)"; \
	else \
		echo "  ✗ vite              DOWN — run: make vite"; \
	fi
	@if docker compose exec -T $(APP_CONTAINER) pgrep -f 'artisan queue:work' > /dev/null 2>&1; then \
		echo "  ✓ queue worker      running"; \
	else \
		echo "  ✗ queue worker      DOWN — run: make queue"; \
	fi
	@if curl -sf -o /dev/null -w '  ✓ HTTP              %{http_code} from $(STATUS_URL)\n' --max-time 3 $(STATUS_URL); then :; else echo "  ✗ HTTP              no response from $(STATUS_URL)"; fi
	@echo ""

logs: ## Tail vite + queue logs (Ctrl-C to exit)
	@mkdir -p storage/logs && touch $(VITE_LOG) $(QUEUE_LOG)
	@tail -f $(VITE_LOG) $(QUEUE_LOG)

stop: vite-stop queue-stop ## Stop vite + queue (containers stay up)

# ──────────────────────────────────────────────────────────────────────────
# Tests + code quality
# ──────────────────────────────────────────────────────────────────────────

test: ## Run full PHPUnit suite
	@$(SAIL) test

test-filter: ## Run filtered test: make test-filter FILTER=ClassName
	@$(SAIL) test --filter=$(FILTER)

pint: ## Auto-fix formatting via Laravel Pint
	@$(SAIL) pint --dirty --format agent

pint-check: ## Check formatting without fixing (CI-style)
	@$(SAIL) pint --test --format agent

# ──────────────────────────────────────────────────────────────────────────
# Database
# ──────────────────────────────────────────────────────────────────────────

migrate: ## Run pending migrations
	@$(SAIL) artisan migrate

fresh: ## Drop all tables, re-migrate, seed
	@$(SAIL) artisan migrate:fresh --seed

seed: ## Run seeders against current DB
	@$(SAIL) artisan db:seed

# ──────────────────────────────────────────────────────────────────────────
# Shells + ad-hoc
# ──────────────────────────────────────────────────────────────────────────

tinker: ## Open Laravel Tinker REPL
	@$(SAIL) artisan tinker

shell: ## Open bash inside the app container
	@$(SAIL) shell

# ──────────────────────────────────────────────────────────────────────────
# Setup / install
# ──────────────────────────────────────────────────────────────────────────

setup: composer-install npm-install ## First-time setup (after clone)
	@cp -n .env.example .env 2>/dev/null || true
	@$(SAIL) artisan key:generate
	@$(SAIL) artisan migrate
	@echo ""
	@echo "✓ setup complete. Now run: make dev"

composer-install: ## Install PHP dependencies (via Sail)
	@$(SAIL) composer install

npm-install: ## Install Node dependencies (via Sail)
	@$(SAIL) npm install

clean-logs: ## Truncate dev log files
	@: > $(VITE_LOG) || true
	@: > $(QUEUE_LOG) || true
	@echo "✓ logs cleaned"
