# Issue Intake & Smart Summary System

An issue intake + AI summary system for support/operations teams. Laravel 13 +
Inertia + Vue 3 + PostgreSQL + Redis + Horizon, fully containerized via
**Laravel Sail**.

> **Live demo:** sts-demo.betamaxgroup.tech

---

## TL;DR — Daily Workflow

```bash
make dev       # start everything (containers + vite + queue), idempotent
make status    # verify all services are healthy
make logs      # tail vite + queue logs
make test      # run the full PHPUnit suite
make down      # stop everything when you're done for the day
```

That's it. Every other command is also a `make` target — run `make` alone
to see all of them.

---

## Why Make + Sail (and not bare commands)

| Concern | Solution |
|---|---|
| Host PHP is 8.1; the app needs PHP 8.4 | Everything runs inside the Sail container — host PHP is never invoked |
| Composer's built-in `dev` script collides with Sail's nginx on port 80 | Replaced by `make dev`, which starts only what Sail doesn't already provide |
| Vite must run *alongside* Sail (not replace it) on port 5175 | `make vite` starts it in the background inside the container with logging |
| Queue worker must be running for `GenerateSummaryJob` to fire | `make queue` runs `queue:work` in the background |
| Agents need to verify dev state cheaply | `make status` is a single command, parseable output |

**Rule:** never run bare `php`, `composer`, `npm`, `npx`, or `vue-tsc` on the
host. Always use `make <target>` or `./vendor/bin/sail <command>`.

---

## Architecture (high level)

```
┌─────────────┐     HTTPS      ┌──────────────────┐
│   Browser   │ ◄────────────► │   Sail (nginx)   │
│  (Vue SPA)  │                │   :80            │
└─────────────┘                └────────┬─────────┘
                                        │
                               ┌────────▼─────────┐
                               │   Laravel App    │  ◄── make up
                               │   (PHP 8.4)      │
                               │   Inertia SSR    │
                               ├──────────────────┤
                               │   Vite :5175     │  ◄── make vite
                               │   (HMR)          │
                               ├──────────────────┤
                               │   Queue Worker   │  ◄── make queue
                               │   (jobs)         │
                               └──┬───────────┬───┘
                                  │           │
                          ┌───────▼──┐   ┌────▼─────┐
                          │ Postgres │   │  Redis   │
                          │  :5434   │   │  :6379   │
                          └──────────┘   └──────────┘
```

### Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 13 (PHP 8.4) |
| Frontend | Inertia.js + Vue 3 + TypeScript |
| UI Kit | shadcn-vue + Tailwind CSS v4 |
| Database | PostgreSQL 18 |
| Queue | Redis + Horizon |
| AI | Ollama Cloud (primary), OpenRouter (backup), rules-based fallback |
| Real-time | SSE (Server-Sent Events) |
| Auth | Laravel Breeze, session-based |
| Testing | **PHPUnit 12** (not Pest, despite SPEC §2) |
| Drag & Drop | vue-draggable-plus |

### Ports (on the host)

| Service | Host port | Notes |
|---|---|---|
| Laravel app | **80** | http://localhost |
| Vite (HMR) | **5175** | Background; you don't visit this directly |
| PostgreSQL | **5434** | mapped from container's 5432; override via `FORWARD_DB_PORT` |
| Redis | **6379** | |

---

## Adaptive Configuration

The dev stack is built around **one source of truth: `.env`**. Change a port
once, and the Makefile, Vite, and Laravel all adapt.

### What's adaptive

| Variable | Drives |
|---|---|
| `APP_URL` | Laravel's URL generation, Vite's HMR host, `make status` health check |
| `APP_PORT` | Sail's nginx, `make status` HTTP probe |
| `VITE_PORT` | Vite dev server, Sail port forward, `make status` |
| `FORWARD_DB_PORT` | Postgres host port, `make status` |
| `FORWARD_REDIS_PORT` | Redis host port, `make status` |

### Port conflicts (running multiple projects)

If another project is using port 80 / 5175 / 5434, edit `.env`:

```dotenv
APP_PORT=8080
APP_URL=http://localhost:8080
VITE_PORT=5176
FORWARD_DB_PORT=5435
```

Then `make config` to verify, `make down && make dev` to apply. The preflight
in `make dev` will refuse to start if a port is held by a non-project process,
showing you the PID and command line holding it.

### Same-origin policy

This stack is **same-origin**: the Vue SPA and the Laravel API are served
from the same domain via Sail's nginx. This means:

- **No `config/cors.php`** — Laravel doesn't need it
- **No Sanctum stateful domains** — Breeze session auth on a single origin
- **No `VITE_API_URL`** — frontend uses `route()` from Ziggy and relative paths
- **No `axios.create({ baseURL })`** — `useForm()` from Inertia handles mutations

If a future change introduces a second origin (separate SPA host, mobile app,
public webhook receiver), CORS + stateful domains must be added as a deliberate
architectural change — `tech-lead` flags this in review.

### What's NOT adaptive (by design)

- The container service names (`laravel.test`, `pgsql`, `redis`) are
  hardcoded in `docker-compose.yml` and the Makefile — changing them would
  break Sail's conventions.
- The `Tests\TestCase` base class — PHPUnit 12 is fixed (see
  `AGENTS.md` for why Pest isn't used).

---

## First-Time Setup

```bash
git clone <repo>
cd ticketing-system
make setup    # composer install, npm install, .env, key:generate, migrate
make dev      # start everything
```

If Boost MCP errors during agent sessions, that means Sail isn't up. Run
`make up` first.

---

## Project Layout

```
.
├── app/                       # PHP source (coder-backend's domain)
│   ├── Enums/                 # Priority, Status, Visibility, Permission, SummaryStatus
│   ├── Http/                  # Controllers, Form Requests, Middleware
│   ├── Models/                # Eloquent models
│   ├── Policies/              # Authorization (ladderized SPEC §3.2)
│   ├── Services/              # Business logic, including Ai/ drivers
│   └── Jobs/                  # Async work (GenerateSummaryJob)
├── resources/
│   ├── js/                    # Vue + Inertia (coder-frontend's domain)
│   │   ├── Pages/             # Inertia pages
│   │   ├── Components/        # Vue components (ui/ added on demand via shadcn-vue CLI)
│   │   ├── Layouts/           # AuthenticatedLayout etc.
│   │   ├── Types/             # Shared TypeScript interfaces (created on demand)
│   │   └── composables/       # Vue composables (SSE, etc.)
│   └── css/app.css            # Tailwind v4 + theme tokens
├── database/
│   ├── migrations/            # Schema (coder-backend)
│   ├── factories/             # Test fixtures (QA agent owns these)
│   └── seeders/               # Demo data (coder-backend)
├── tests/                     # PHPUnit 12 (QA agent's domain)
│   ├── Feature/               # Integration + HTTP tests
│   └── Unit/                  # Pure logic
├── vault/                     # Living docs (single source of truth)
│   ├── SPEC.md                # What to build
│   ├── docs/SRS.md            # How to build it (scenarios I-XX)
│   ├── docs/adr/              # Decision records (why)
│   └── sprint/                # Task management — PLAN.md, backlog/, ongoing/, done/
├── .opencode/agents/          # Project-specific AI agents
├── Makefile                   # All dev workflow commands (this file's bff)
└── AGENTS.md                  # Conventions agents must follow
```

---

## AI-Driven Development

This project uses [OpenCode](https://opencode.ai) with four project-specific agents:

| Agent | Role |
|---|---|
| `tech-lead` | Task enrichment + code review (no code) |
| `qa` | Red-phase test writing + verification (no app code) |
| `coder-backend` | Laravel implementation (no frontend) |
| `coder-frontend` | Vue + Inertia implementation (no PHP) |

Each agent is designed using the 5-phase Agent Design Protocol (see
`.opencode/agents/*.md` and `vault/SPEC.md`). The workflow per task:

```
tech-lead (enrich)
    ↓
qa (RED tests)
    ↓
coder-backend → coder-frontend
    ↓
qa (VERIFY — full suite green)
    ↓
tech-lead (REVIEW — APPROVED or CHANGES_REQUIRED)
    ↓
merge to dev
```

Boost MCP and `@henkey/postgres-mcp-server` are configured in `opencode.json`
and require `make up` to be running.

---

## Common Tasks

```bash
# Iteration
make dev                                # start dev environment
make status                             # is it still working?
make logs                               # what's vite/queue saying?

# Tests
make test                               # full suite
make test-filter FILTER=CreateIssueTest # one class

# Code quality
make pint                               # auto-fix formatting
make pint-check                         # CI-style check, no fix

# Database
make fresh                              # drop, re-migrate, seed (destructive)
make migrate                            # apply pending migrations only
make seed                               # add seed data to current DB

# Ad-hoc
make tinker                             # PHP REPL
make shell                              # bash inside the container
```

---

## When Things Break

| Symptom | Likely cause | Fix |
|---|---|---|
| `make dev` says container is up but http://localhost gives connection refused | Stale containers from a previous broken state | `make down && make dev` |
| Vite changes don't appear in browser | Vite died silently | `make status` then `make vite` to restart |
| Test suite hangs | Queue worker holding a Postgres connection | `make queue-stop && make test` |
| Boost MCP errors in agent session | Sail isn't running | `make up` |
| `composer run dev` doesn't work | It's a non-Sail script; ignore it | Use `make dev` |
| `php artisan ...` fails on host | Host PHP is 8.1, app needs 8.4 | Use `make` targets or `./vendor/bin/sail artisan ...` |

---

## Documentation

- **`vault/SPEC.md`** — product specification
- **`vault/docs/SRS.md`** — software requirements with scenarios
- **`vault/docs/adr/`** — architecture decision records
- **`vault/sprint/PLAN.md`** — current sprint state
- **`AGENTS.md`** — conventions for AI agents (and humans)
