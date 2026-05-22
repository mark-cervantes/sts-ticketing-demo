# Issue Intake & Smart Summary System — developer workflow
# Single source of truth for "how do I run this project".
# All Laravel/PHP/Node commands route through Sail because host PHP is 8.1
# and the app needs 8.4.

SAIL := ./vendor/bin/sail
VITE_LOG := storage/logs/vite.log
QUEUE_LOG := storage/logs/queue.log
APP_URL := http://localhost
VITE_PORT := 5175

.DEFAULT_GOAL := help
.PHONY: help dev up down restart vite vite-stop queue queue-stop status logs \
        stop test test-filter pint pint-check fresh seed migrate tinker shell \
        npm-install composer-install setup clean-logs playwright-install verify-visual

# ──────────────────────────────────────────────────────────────────────────
# Help
# ──────────────────────────────────────────────────────────────────────────

help: ## Show this help (default)
	@echo "Issue Intake & Smart Summary System — make targets"
	@echo ""
	@echo "Daily workflow:"
	@echo "  make dev         — start everything (containers + vite + queue), idempotent"
	@echo "  make status      — health check: containers, vite, queue, http"
	@echo "  make verify-visual — run vue-tsc + Playwright smoke gate against the live app"
	@echo "  make logs        — tail vite + queue logs (Ctrl-C to exit)"
	@echo "  make stop        — stop vite + queue (containers stay up)"
	@echo "  make down        — stop everything including containers"
	@echo ""
	@echo "All targets:"
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST) | sort

# ──────────────────────────────────────────────────────────────────────────
# Primary dev loop — "keep development running"
# ──────────────────────────────────────────────────────────────────────────

dev: up vite queue status ## Start everything (idempotent — safe to re-run)

up: ## Start Sail containers (Laravel, Postgres, Redis) if not already running
	@if docker compose ps --status running --format '{{.Service}}' | grep -q laravel.test; then \
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
# Vite (asset HMR server) — runs inside the app container as a background process
# ──────────────────────────────────────────────────────────────────────────

vite: ## Start Vite dev server in background (idempotent)
	@if docker compose exec -T laravel.test pgrep -f 'node.*vite' > /dev/null 2>&1; then \
		echo "✓ vite already running on :$(VITE_PORT)"; \
	else \
		echo "→ starting vite..."; \
		mkdir -p storage/logs; \
		docker compose exec -d laravel.test sh -c 'npm run dev > /var/www/html/$(VITE_LOG) 2>&1'; \
		sleep 2; \
		echo "  log: $(VITE_LOG)"; \
	fi

vite-stop: ## Stop the Vite dev server
	@docker compose exec -T laravel.test pkill -f 'node.*vite' 2>/dev/null && echo "✓ vite stopped" || echo "  (vite was not running)"

# ──────────────────────────────────────────────────────────────────────────
# Queue worker — for async jobs (GenerateSummaryJob, etc.)
# ──────────────────────────────────────────────────────────────────────────

queue: ## Start queue worker in background (idempotent)
	@if docker compose exec -T laravel.test pgrep -f 'artisan queue:work' > /dev/null 2>&1; then \
		echo "✓ queue worker already running"; \
	else \
		echo "→ starting queue worker..."; \
		mkdir -p storage/logs; \
		docker compose exec -d laravel.test sh -c 'php artisan queue:work --tries=3 --timeout=60 > /var/www/html/$(QUEUE_LOG) 2>&1'; \
		sleep 1; \
		echo "  log: $(QUEUE_LOG)"; \
	fi

queue-stop: ## Stop the queue worker
	@docker compose exec -T laravel.test pkill -f 'artisan queue:work' 2>/dev/null && echo "✓ queue stopped" || echo "  (queue was not running)"

# ──────────────────────────────────────────────────────────────────────────
# Health check — agents and humans should run this to verify dev state
# ──────────────────────────────────────────────────────────────────────────

status: ## Health check — verify every dev service is up
	@echo ""
	@echo "Service Status"
	@echo "─────────────────────────────────────────"
	@if docker compose ps --status running --format '{{.Service}}' | grep -q laravel.test; then \
		echo "  ✓ laravel.test     container up"; \
	else \
		echo "  ✗ laravel.test     DOWN — run: make up"; \
	fi
	@if docker compose ps --status running --format '{{.Service}}' | grep -q pgsql; then \
		echo "  ✓ postgres         container up"; \
	else \
		echo "  ✗ postgres         DOWN"; \
	fi
	@if docker compose ps --status running --format '{{.Service}}' | grep -q redis; then \
		echo "  ✓ redis            container up"; \
	else \
		echo "  ✗ redis            DOWN"; \
	fi
	@if docker compose exec -T laravel.test pgrep -f 'node.*vite' > /dev/null 2>&1; then \
		echo "  ✓ vite             running on :$(VITE_PORT)"; \
	else \
		echo "  ✗ vite             DOWN — run: make vite"; \
	fi
	@if docker compose exec -T laravel.test pgrep -f 'artisan queue:work' > /dev/null 2>&1; then \
		echo "  ✓ queue worker     running"; \
	else \
		echo "  ✗ queue worker     DOWN — run: make queue"; \
	fi
	@if curl -sf -o /dev/null -w '  ✓ HTTP             %{http_code} from $(APP_URL)\n' $(APP_URL); then :; else echo "  ✗ HTTP             no response from $(APP_URL)"; fi
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

playwright-install: ## Install Playwright Chromium browser in the app container
	@$(SAIL) npx playwright install chromium

verify-visual: status playwright-install ## Run the live frontend verification gate
	@echo ""
	@echo "Visual Verification"
	@echo "─────────────────────────────────────────"
	@docker compose exec -T laravel.test sh -lc 'cd /var/www/html && npx vue-tsc --noEmit'
	@curl -fsS -o /dev/null $(APP_URL)/login && echo "  ✓ login page reachable"
	@curl -fsS -o /dev/null $(APP_URL)/horizon && echo "  ✓ horizon reachable"
	@docker compose exec -T laravel.test sh -lc 'cd /var/www/html && PLAYWRIGHT_BASE_URL=$(APP_URL) npx playwright test tests/Playwright/smoke.spec.ts --project=chromium'
	@echo "  ✓ visual verification complete"

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
