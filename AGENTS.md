# AGENTS.md — Project-Specific Agent Configuration

> This file configures how AI agents work on THIS project.
> It supplements the global ~/.config/opencode/AGENTS.md with project-specific rules.

---

## Project Identity

- **Name:** Issue Intake & Smart Summary System
- **Domain:** sts-demo.betamaxgroup.tech
- **Stack:** Laravel 11 + Inertia + Vue 3 + TypeScript + PostgreSQL + Redis
- **Spec:** vault/SPEC.md
- **SRS:** vault/docs/SRS.md
- **ADRs:** vault/docs/adr/

---

## Project-Specific Agents (PRIMARY — always use these)

These agents are in `.opencode/agents/` and are the DEFAULT for this project.
Do NOT use global pipeline agents for this project's work.

| Agent              | Model                         | Role                                      |
| ------------------ | ----------------------------- | ----------------------------------------- |
| **tech-lead**      | `anthropic/claude-opus-4-6`   | Task enrichment, code review, arch judgment |
| **coder-backend**  | `anthropic/claude-sonnet-4-6` | Laravel: services, controllers, jobs, models |
| **coder-frontend** | `anthropic/claude-opus-4-6`   | Vue + Inertia + shadcn-vue + Tailwind     |
| **qa**             | `anthropic/claude-sonnet-4-6` | Test writing, regression audits           |

**Fallback:** tech-lead has `openai/gpt-5.4` variant.

### Workflow Per Task

```
1. tech-lead: enriches task with Technical Guidance
2. qa: writes tests (RED — tests fail, nothing implemented)
3. coder-backend: implements backend until tests pass
4. coder-frontend: implements frontend
5. tech-lead: reviews diff, approves or requests changes
6. On approval: "feat(scope): description - done" → merge to dev
```

### Dispatch Rules
- Backend-only task (API, jobs, services) → skip step 4
- Frontend-only task (UI, components) → skip step 3
- Full-stack task → both coders, sequential (backend first)

---

## Cold-Start Protocol (READ THIS FIRST)

Every new session:
1. Read `vault/sprint/PLAN.md` — understand sprint structure + current state
2. `ls vault/sprint/ongoing/` — active work to resume?
3. `ls vault/sprint/done/` — what's completed?
4. `ls vault/sprint/backlog/` — what's next (filesystem sort order)?
5. `git status && git branch` — uncommitted work? which branch?
6. Resume ongoing task OR pull next from backlog

---

## Git Workflow

### Branching
```
main (stable — deployable checkpoints only)
  └── dev (integration — all feature branches merge here)
        ├── feature/task-slug     (normal work)
        ├── hotfix/fix-slug       (urgent fixes)
        └── feature/new-thing     (unplanned additions)
```

### Rules
- **1 task = 1 feature branch**
- **No-FF merges** to dev: `git merge --no-ff feature/task-slug`
- **Conventional commits:** `feat(scope): description`, `fix(scope): description`
- **Final commit on feature branch:** `feat(scope): description - done`
- **Sprint completion commit on dev:** `feat(sprint-XX): sprint description - done`
- **Never force push.** Never.
- **Worktrees** for parallel work on independent tasks.

### Branch Naming
- `feature/<task-slug>` — from task filename (e.g., `02.01.00-issue-crud` → `feature/issue-crud`)
- `hotfix/<description>` — for urgent fixes
- All branch from `dev`, merge to `dev`

---

## Testing Contract (Non-Negotiable)

1. Run `php artisan test` **BEFORE** starting any implementation
2. Run `php artisan test` **AFTER** every logical change
3. If any previously-passing test fails → **YOUR CHANGE IS WRONG** → fix before continuing
4. Do NOT modify existing tests unless the SPEC explicitly changed
5. Do NOT delete tests — ever
6. New features MUST include integration tests for the full user path
7. Task is NOT done until `php artisan test` passes with 0 failures

### Test Execution
```bash
php artisan test                              # Full suite
php artisan test --filter=Integration         # Integration only
php artisan test --filter=IssueLifecycle      # Specific test class
```

---

## Task Lifecycle

### Task File Format
```yaml
---
title: "Task Title"
priority: high | medium | low
depends_on: "description of what must be in dev first"
branch: "feature/task-slug"
---

## What To Build
(clear description of deliverables)

## Tests Required (Definition of Done)
Integration: [list specific I-XX scenarios]
Feature: [list test groups]

## Done When
- [ ] Implementation complete
- [ ] All required tests written and passing
- [ ] Full suite passes: `php artisan test`
- [ ] Committed: `feat(scope): description - done`
- [ ] Merged to dev (no-ff)
- [ ] Task file moved to done/

## Technical Notes
(architecture guidance, patterns to use, files to touch)
```

### Movement
```
backlog/ → ongoing/ → done/
```

### Dependency Check
Before starting a task, verify its dependencies are merged:
```bash
git log dev --oneline | grep "<dependency-slug>"
```
If not found → skip, pull next satisfiable task.

---

## Tool Usage (Always Prefer Generators Over Manual Code)

All agents must use framework/ecosystem tools to generate boilerplate. Never hand-write what a generator produces.

### Laravel (Backend)
```bash
php artisan make:model Issue -mf          # Model + migration + factory
php artisan make:controller IssueController --resource  # Resource controller
php artisan make:request StoreIssueRequest   # Form request
php artisan make:policy IssuePolicy --model=Issue  # Policy
php artisan make:job GenerateSummaryJob       # Job
php artisan make:event SummaryCompleted       # Event
php artisan make:observer IssueObserver --model=Issue  # Observer
php artisan make:provider SummaryServiceProvider  # Service provider
php artisan make:test IssueLifecycleTest      # Test
php artisan make:enum Priority                # Enum (if available)
php artisan make:seeder IssueSeeder           # Seeder
php artisan make:factory IssueFactory         # Factory
```

### Composer
```bash
composer require laravel/horizon             # Don't manually configure queue
composer require laravel/breeze --dev        # Don't manually scaffold auth
```

### Frontend (npm / npx)
```bash
npx shadcn-vue@latest add button dialog sheet input select badge skeleton sonner
npm install vue-draggable-plus               # Don't hand-write drag-drop
```

### General Rule
If a CLI command generates it → use the command. Then customize the generated file. This ensures:
- Correct file location every time
- Correct namespace/class boilerplate
- No typos in extends/implements
- Consistent with framework version conventions

---

## Architecture Rules

### Patterns (Always Use)
- **Thin controllers** — validation in Form Requests, logic in Services
- **Service layer** — `app/Services/` for business logic
- **Form Requests** — all validation in dedicated request classes
- **Policies** — all authorization via Laravel Policies
- **Facades** — for vendor-swappable features (AI)
- **Manager pattern** — for driver-based services (Summary)
- **Enums** — for all fixed value sets (status, priority, category)
- **Value Objects** — for structured returns (SummaryResult)
- **Factories** — for all test data generation

### File Organization
```
app/
├── Contracts/        ← Interfaces
├── Enums/            ← Status, Priority, Visibility, Permission
├── Events/           ← Domain events
├── Exceptions/       ← Typed exceptions
├── Facades/          ← Laravel facades
├── Http/
│   ├── Controllers/  ← Thin, delegates to services
│   ├── Requests/     ← Validation
│   └── Middleware/   ← Auth, etc.
├── Jobs/             ← Queued work
├── Models/           ← Eloquent models
├── Observers/        ← Model event listeners
├── Policies/         ← Authorization
├── Providers/        ← Service registration
└── Services/         ← Business logic
    └── Summary/      ← AI summary subsystem
        ├── SummaryManager.php
        ├── Drivers/
        └── SummaryResult.php
```

### Database
- PostgreSQL (Docker)
- Migrations for ALL schema changes
- Indexes on FK columns and filter fields
- Soft deletes on issues

### Queue
- Redis + Laravel Horizon
- Jobs: `GenerateSummaryJob`
- Retry: 3 attempts, backoff [10, 30, 90]
- Fallback: rules driver on exhaustion

---

## Environment

### Required ENV vars
```env
APP_NAME="STS Demo"
APP_URL=https://sts-demo.betamaxgroup.tech
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_DATABASE=sts
QUEUE_CONNECTION=redis
REDIS_HOST=redis
SUMMARY_DRIVER=llm
LLM_BASE_URL=<ollama-cloud-url>
LLM_API_KEY=<key>
LLM_MODEL=<model>
```

### Docker Services
```
app, postgres, redis, horizon, scheduler
```

### Deployment
- Host: 192.168.254.140
- Domain: sts-demo.betamaxgroup.tech
- Proxy: Caddy (existing)
- Deploy: `docker compose up -d`

---

## Key References

| Document           | Location           | Purpose                          |
|--------------------|--------------------|----------------------------------|
| Specification      | `vault/SPEC.md`          | What to build (approved)         |
| Technical Detail   | `vault/docs/SRS.md`      | How to build it (ground truth)   |
| Architecture Decisions | `vault/docs/adr/*.md` | Why decisions were made          |
| Sprint Plan        | `vault/sprint/PLAN.md` | Current state + ordering     |
| Assessment PRD     | `~/Downloads/Software Developer Practical Assessment.md` | Original requirements |
