# SPEC.md — Issue Intake & Smart Summary System

> **Project:** sts-demo.betamaxgroup.tech
> **Date:** 2026-05-22
> **Status:** Approved
> **PRD Source:** `~/Downloads/Software Developer Practical Assessment.md`

---

## 1. Product Overview

An issue intake and smart summary system for support/operations teams. Users
submit issues, the system generates AI-powered summaries and suggested next
actions asynchronously, and team members collaborate through comments and
shared visibility.

The application is a **dashboard-first Kanban** — all primary interactions
happen on a single view with modals, drag-and-drop status changes, and
real-time SSE updates.

---

## 2. Stack

| Layer        | Choice                           | Rationale                                                            |
| ------------ | -------------------------------- | -------------------------------------------------------------------- |
| Backend      | Laravel 11                       | Assessment prefers PHP/Laravel; built-in queue, events, validation   |
| Frontend     | Inertia.js + Vue 3 + TypeScript  | SPA feel without API boilerplate; Vue is productive for this scope   |
| UI Kit       | shadcn-vue + Tailwind CSS        | Single-source design system; change primary color in one place       |
| Database     | PostgreSQL (Docker)              | Production-grade; robust JSON, full-text, concurrent-safe            |
| Queue/Worker | Redis + Laravel Horizon          | Reliable async jobs with monitoring dashboard                        |
| AI           | Ollama Cloud (OpenAI-compatible) | Primary driver; OpenRouter as backup; rules-based fallback           |
| Real-time    | SSE (Server-Sent Events)         | Summary completion push without WebSocket complexity                 |
| Auth         | Laravel Breeze (session-based)   | Free-tier SaaS model — register/login, no roles                     |
| Testing      | Pest PHP                         | Fluent syntax, modern Laravel testing                                |
| Drag & Drop  | vue-draggable-plus / SortableJS  | Vue 3 compatible, lightweight, Kanban column reordering              |
| Deployment   | Docker Compose                   | Single `docker compose up -d` — Postgres, Redis, Laravel, Horizon   |

---

## 3. Authentication & Authorization

### 3.1 Auth Model
- Free-tier SaaS: anyone can register and log in
- No roles — every user is equal
- Session-based auth via Laravel Breeze + Inertia

### 3.2 Authorization Rules
- Users **own** issues they create → full access
- Shared users → access per `issue_shares.permission` (view or edit)
- Public issues → any logged-in user has view-only access
- Private issues → only owner + explicitly shared users

---

## 4. Data Model

### 4.1 `users`
| Field      | Type            | Notes            |
| ---------- | --------------- | ---------------- |
| id         | bigint PK       | Auto-increment   |
| name       | string          | Required         |
| email      | string          | Required, unique |
| password   | string          | Hashed           |
| timestamps | created/updated |                  |

### 4.2 `issues`
| Field                 | Type                                      | Notes                                                           |
| --------------------- | ----------------------------------------- | --------------------------------------------------------------- |
| id                    | bigint PK                                 | Auto-increment                                                  |
| user_id               | FK → users                                | Creator/owner                                                   |
| title                 | string                                    | Required, max 255                                               |
| description           | text                                      | Required, used for summary generation                           |
| priority              | enum: low, medium, high, critical         | Required                                                        |
| category_id           | FK → categories                           | Required                                                        |
| status                | enum: open, in_progress, resolved         | Default: open                                                   |
| visibility            | enum: private, public                     | Default: private                                                |
| summary               | text, nullable                            | Generated async; null until job completes                       |
| suggested_next_action | text, nullable                            | Generated async; null until job completes                       |
| summary_status        | enum: pending, processing, ready, failed  | Default: pending                                                |
| needs_attention       | boolean                                   | Computed: high/critical priority OR deadline approaching/passed |
| deadline_at           | timestamp, nullable                       | Optional user-set deadline, independent of priority             |
| deleted_at            | timestamp, nullable                       | Soft delete                                                     |
| created_at            | timestamp                                 |                                                                 |
| updated_at            | timestamp                                 |                                                                 |

### 4.3 `categories`
| Field      | Type      | Notes                            |
| ---------- | --------- | -------------------------------- |
| id         | bigint PK |                                  |
| name       | string    | Required, unique                 |
| slug       | string    | Auto-generated from name, unique |
| created_at | timestamp |                                  |

Seeded defaults: billing, technical, account, general, bug, feature-request

### 4.4 `comments`
| Field      | Type        | Notes                   |
| ---------- | ----------- | ----------------------- |
| id         | bigint PK   |                         |
| issue_id   | FK → issues |                         |
| user_id    | FK → users  | Authenticated commenter |
| body       | text        | Required, non-empty     |
| created_at | timestamp   |                         |

UI displays `user.name` as the comment author.

### 4.5 `issue_shares`
| Field      | Type             | Notes                       |
| ---------- | ---------------- | --------------------------- |
| id         | bigint PK        |                             |
| issue_id   | FK → issues      |                             |
| user_id    | FK → users       | The person receiving access |
| permission | enum: view, edit |                             |
| created_at | timestamp        |                             |

Unique constraint on (issue_id, user_id). Sharing notifies the target user.

### 4.6 Indexes
```
issues:       [user_id], [category_id], [status], [priority], [visibility]
              composite: [status, priority]
              composite: [user_id, status]
comments:     [issue_id], [user_id]
issue_shares: unique [issue_id, user_id]
categories:   unique [slug]
```

---

## 5. API Endpoints

### 5.1 Issues
| Action                     | Method | Endpoint                                             |
| -------------------------- | ------ | ---------------------------------------------------- |
| Create issue               | POST   | /issues                                              |
| List issues (filterable)   | GET    | /issues?status=open&priority=high&category=billing   |
| View issue (with comments) | GET    | /issues/{id}                                         |
| Update issue               | PATCH  | /issues/{id}                                         |
| Delete issue (soft)        | DELETE | /issues/{id}                                         |

### 5.2 Comments
| Action      | Method | Endpoint              |
| ----------- | ------ | --------------------- |
| Add comment | POST   | /issues/{id}/comments |

### 5.3 Categories
| Action          | Method | Endpoint         |
| --------------- | ------ | ---------------- |
| List categories | GET    | /categories      |
| Create category | POST   | /categories      |
| Delete category | DELETE | /categories/{id} |

### 5.4 Sharing
| Action       | Method | Endpoint                      |
| ------------ | ------ | ----------------------------- |
| Share issue  | POST   | /issues/{id}/shares           |
| Remove share | DELETE | /issues/{id}/shares/{shareId} |

### 5.5 SSE
| Action                | Method | Endpoint            |
| --------------------- | ------ | ------------------- |
| Summary status stream | GET    | /issues/{id}/stream |

### 5.6 Pagination & Sorting
- List endpoints paginated (default 15 per page, configurable)
- Sortable by: created_at, updated_at, priority, deadline_at
- Sort direction: asc/desc via `sort` and `direction` query params
- Category filter accepts **slug** (not ID) in URL: `?category=billing`

---

## 6. Validation & Business Logic

### 6.1 Issue Creation
- `title`: required, string, max 255, trimmed, reject empty after trim
- `description`: required, string, trimmed, reject empty after trim
- `priority`: required, must be valid enum value
- `category_id`: required, must exist in categories table
- `status`: defaults to `open`, not user-settable on create
- `visibility`: defaults to `private`
- `deadline_at`: optional, must be future datetime if provided
- `summary_status`: set to `pending` automatically
- `needs_attention`: computed (true if priority high/critical)

### 6.2 Issue Update
- Same validation for editable fields
- Status: must be valid enum value
- **Updating `description` re-triggers summary generation**
- **Updating `status` alone does NOT re-trigger**
- Priority change recomputes `needs_attention`

### 6.3 Comment Creation
- `body`: required, string, trimmed, reject empty after trim
- `user_id`: set from authenticated user (not user-supplied)

### 6.4 Category Creation
- `name`: required, string, trimmed, unique (case-insensitive)
- `slug`: auto-generated from name

### 6.5 Sharing
- `email`: required, must be a registered user's email
- `permission`: required, must be `view` or `edit`
- Cannot share with yourself
- Notifies the target user

### 6.6 `needs_attention` Computation
Two independent signals, ORed:
1. **Priority signal**: true if priority is `high` or `critical`
2. **Deadline signal**: true if `deadline_at` is set AND (passed OR within threshold)

Recomputed:
- On issue create/update (immediate)
- By scheduler (every 5 minutes) — catches deadline transitions

Threshold configurable via `config/issues.php` (default: 1 hour before deadline).

### 6.7 Soft Deletes
Issues use Laravel `SoftDeletes`. Excluded from listings, recoverable.

### 6.8 Optimistic Locking
PATCH checks `updated_at` — if record modified since client fetch → 409 Conflict.

---

## 7. AI / Automation Layer

### 7.1 Architecture — Laravel Manager Pattern

Uses the **Manager pattern** (same pattern as Laravel's Cache, Queue, Mail, Filesystem managers)
for idiomatic driver-based service resolution.

```
app/
├── Contracts/
│   └── SummaryGeneratorInterface.php    ← The seam (contract)
├── Services/
│   └── Summary/
│       ├── SummaryManager.php           ← Laravel Manager (resolves drivers)
│       ├── Drivers/
│       │   ├── LlmDriver.php           ← OpenAI-compatible HTTP call
│       │   └── RulesDriver.php         ← Deterministic keyword/category fallback
│       └── SummaryResult.php            ← Immutable value object (DTO)
├── Facades/
│   └── Summary.php                      ← Laravel Facade binding
├── Jobs/
│   └── GenerateSummaryJob.php           ← Queued, retryable, fallback-aware
├── Events/
│   └── SummaryCompleted.php             ← Triggers SSE push
├── Exceptions/
│   └── SummaryGenerationException.php   ← Typed exception for driver failures
└── Providers/
    └── SummaryServiceProvider.php       ← Registers manager + drivers
```

### 7.2 Contract

```php
interface SummaryGeneratorInterface
{
    /** @throws SummaryGenerationException */
    public function generate(Issue $issue): SummaryResult;
}
```

### 7.3 Value Object

```php
final readonly class SummaryResult
{
    public function __construct(
        public string $summary,
        public string $suggestedNextAction,
        public string $driver,  // 'llm' or 'rules' — which driver produced this
    ) {}
}
```

### 7.4 Async Flow
1. Issue created → API responds 201 with `summary_status: pending`
2. `GenerateSummaryJob` dispatched to Redis queue
3. Job updates issue `summary_status = processing`
4. Job calls `Summary::generate($issue)` (Facade → Manager → Driver)
5. On success: issue updated with summary + next_action, `summary_status = ready`
6. On failure: exception thrown, job retries per backoff schedule
7. On final retry failure: fallback to rules driver, still mark `ready`
8. `SummaryCompleted` event fired → SSE push to connected clients

### 7.5 Fallback Behavior
- Config `SUMMARY_DRIVER=llm` but no `LLM_API_KEY` → auto-fallback to rules driver
- LLM API returns error / timeout → job retries, then fallback to rules driver
- Rules driver always succeeds (deterministic, no external dependency)
- Fallback is **transparent** — app code doesn't know which driver ran

### 7.6 Retry Policy
- Max 3 attempts with exponential backoff: 10s, 30s, 90s
- On final attempt failure: catch exception, call `Summary::driver('rules')` explicitly
- After rules fallback: mark `summary_status = ready` (not `failed`)
- Only mark `failed` if rules engine also fails (shouldn't — it's deterministic)
- Failed/exhausted jobs visible in Horizon dashboard

### 7.7 LLM Driver Details
- Uses Laravel HTTP client (injectable, mockable)
- Endpoint: `POST {base_url}/chat/completions` (OpenAI-compatible)
- Temperature: 0.3 (low creativity, high consistency)
- Response format: `json_object` (structured, no free-text parsing)
- Timeout: configurable (default 30s)
- Throws `SummaryGenerationException` on any failure — driver does not handle retries

### 7.8 Configuration

```env
SUMMARY_DRIVER=llm          # llm | rules
LLM_BASE_URL=https://...    # Any OpenAI-compatible endpoint
LLM_API_KEY=xxx
LLM_MODEL=model-name
LLM_TIMEOUT=30
```

```php
// config/summary.php
return [
    'default' => env('SUMMARY_DRIVER', 'rules'),
    'drivers' => [
        'llm' => [
            'base_url' => env('LLM_BASE_URL', 'https://api.openai.com/v1'),
            'api_key'  => env('LLM_API_KEY'),
            'model'    => env('LLM_MODEL', 'gpt-4o-mini'),
            'timeout'  => env('LLM_TIMEOUT', 30),
        ],
        'rules' => [],
    ],
];
```

### 7.9 Prompt Template
Committed at `config/prompts/summary.php`. Structured prompt with:
- System message: role definition (support ticket analyst)
- User message: title, category, priority, description
- Output format: JSON with `summary` and `suggested_next_action` keys
- Output constraints: summary = 1-2 sentences, next_action = single concrete step

### 7.10 Rules-Based Engine
Deterministic generator producing genuinely useful output:
- Category + priority matrix → domain-specific summary templates
- Description analysis: extract lead sentence, key terms
- Action suggestions: concrete, category-aware (not generic "review this issue")
- Must pass quality bar: output is something you'd show a human user

---

## 8. Real-Time (SSE)

- `GET /issues/{id}/stream` — auth required
- Emits event on `summary_status` transition to `ready` or `failed`
- Issue detail modal listens, updates summary section live
- Auto-reconnect on client disconnect

---

## 9. Frontend — Dashboard-First Kanban

### 9.1 Design System
- shadcn-vue + Tailwind CSS
- CSS custom properties for theming (single-source)
- Change primary color in ONE file — propagates everywhere
- Dark mode via Tailwind `dark:` variant
- Responsive: mobile-first

### 9.2 Primary View: Dashboard (Kanban)

The dashboard IS the app. All interactions happen here.

```
┌─────────────────────────────────────────────────────┐
│  Header: logo, search, user menu                    │
├───────────┬─────────────────────────────────────────┤
│ Sidebar   │  Kanban Columns                         │
│           │  ┌────────┬───────────┬──────────┐      │
│ Filters   │  │ Open   │ In Prog   │ Resolved │      │
│ - Status  │  │        │           │          │      │
│ - Priority│  │ [card] │ [card]    │ [card]   │      │
│ - Category│  │ [card] │ [card]    │          │      │
│           │  │        │           │          │      │
│ Stats     │  └────────┴───────────┴──────────┘      │
│ [+ New]   │  ← drag cards between columns           │
└───────────┴─────────────────────────────────────────┘
```

### 9.3 Interactions

| Action            | Trigger                                                      |
| ----------------- | ------------------------------------------------------------ |
| Create issue      | "+ New" → centered modal (inline category add)               |
| View issue        | Click card → slide-over panel (detail, comments, sharing)    |
| Edit issue        | Same slide-over, fields editable inline                      |
| Change status     | Drag card between columns (optimistic update)                |
| Add comment       | In slide-over, thread at bottom                              |
| Share issue       | In slide-over, share section                                 |
| Set deadline      | In slide-over                                                |
| Toggle visibility | In slide-over (private/public toggle)                        |
| Delete issue      | "..." menu on card or in slide-over                          |
| Filter/sort       | Sidebar — instant reflection in columns                      |
| Manage categories | Small section in sidebar or settings                         |

### 9.4 Kanban Behavior
- Columns = status enum values
- Drag card between columns = status transition
- Optimistic: card moves immediately, reverts on server rejection
- Card shows: title, priority badge, needs_attention flag, category, summary preview, deadline
- Sort within column: needs_attention first → priority desc → created_at desc

### 9.5 Modal / Slide-over
- Issue detail: right-side slide-over (keeps dashboard visible)
- Create: centered modal
- URL updates on open (`/dashboard?issue=5`) — back button closes, direct links work

### 9.6 Secondary Routes (deep links)
| Route             | Purpose                         |
| ----------------- | ------------------------------- |
| /issues/{id}      | Full page issue detail          |
| /login            | Auth                            |
| /register         | Auth                            |

### 9.7 UX Principles
- Minimal, implicit — placeholders over labels
- Categories: list + text input (placeholder: "Add category...")
- Share: email input + permission dropdown, "x" to remove
- Loading: skeleton loaders, not spinners
- Errors: inline validation, toast for server errors

---

## 10. Testing — Integration-First Strategy

Testing is the primary regression firewall for AI-assisted development.
Integration tests are the largest group — they catch cross-layer regressions
which are AI's #1 failure mode when modifying multiple files per feature.

### 10.1 Test Priority (Highest → Lowest)

```
Integration tests  → catches cross-layer regressions
Feature tests      → catches endpoint behavior + validation edge cases
Unit tests         → catches isolated logic correctness
```

### 10.2 Assessment-Mandated Tests (7 Required)

| # | Test                                           | Signal                        | Layer       |
|---|------------------------------------------------|-------------------------------|-------------|
| 1 | Successful issue create                        | CRUD works, defaults applied  | Integration |
| 2 | Validation failure (missing/invalid field)     | Input handling thorough       | Feature     |
| 3 | List filtering with 2+ combined filters        | Query composition works       | Integration |
| 4 | Adding comment to existing issue               | Relationship creation works   | Integration |
| 5 | Single-issue view loads comments without N+1   | Eager loading verified        | Integration |
| 6 | Creating issue dispatches summary job          | Async boundary correct        | Integration |
| 7 | Summary job populates fields + status          | Job execution end-to-end      | Integration |

### 10.3 Integration Tests (~35 scenarios)

Full user-path workflows. Real DB, real queue (sync driver), mocked external APIs.

| ID   | Scenario                                | Steps                                                                                              |
|------|-----------------------------------------|----------------------------------------------------------------------------------------------------|
| I-01 | Issue full lifecycle                    | Register → login → create → verify defaults → job dispatched → job runs → summary populated → view |
| I-02 | Kanban status drag                      | Create (open) → update to in_progress → update to resolved → verify each persisted                 |
| I-03 | Comment thread                          | Create issue → add 3 comments → view → all loaded with user names → no N+1                         |
| I-04 | Description update re-triggers summary  | Create → job runs → summary ready → update desc → status reset → new job → new summary             |
| I-05 | Status update does NOT re-trigger       | Create → job runs → ready → update status only → status still ready → no new job                   |
| I-06 | Sharing flow (private)                  | A creates private → B can't see (403) → A shares (view) → B sees → B can't edit → upgrade → edits |
| I-07 | Sharing flow (public)                   | A creates public → B views → B can't edit → A shares (edit) → B edits                              |
| I-08 | Visibility toggle                       | Private + shared with B → public → C (unshared) views → private → C loses access                   |
| I-09 | Category lifecycle                      | Create with existing → create new inline → use it → delete unused → delete used (409)               |
| I-10 | AI fallback (no key)                    | SUMMARY_DRIVER=llm, no key → create → rules engine produces summary → status=ready                 |
| I-11 | AI retry + fallback                     | Mock LLM fail 3x → create → retries → falls back to rules → summary populated                      |
| I-12 | Optimistic locking                      | A fetches → B updates → A submits stale updated_at → 409                                           |
| I-13 | Filtering accuracy                      | Seed 15 issues → filter status+priority → exact set → add category → narrowed                       |
| I-14 | Needs attention: priority               | Create low → false → update high → true → update low → false                                       |
| I-15 | Needs attention: deadline               | Create with future deadline → false → time travel to threshold → scheduler → true                   |
| I-16 | Soft delete                             | Create → delete → not in list → direct view 404 → still in DB                                      |
| I-17 | Pagination                              | Seed 30 → page 1 (15) → page 2 (15) → no duplicates                                                |
| I-18 | Access isolation                        | A creates 3 private → B creates 2 private → A lists → sees only own + public                        |

### 10.4 Feature Tests (~45 scenarios)

Endpoint-level validation and auth. Groups:

**Issues CRUD:** create/update/delete validation, defaults, status codes
**Comments:** body validation, auth injection, access checks
**Categories:** uniqueness, slug generation, deletion guard
**Sharing:** self-share guard, non-existent user, upsert behavior
**Auth:** registration, login, duplicate email

### 10.5 Unit Tests (~20 scenarios)

Isolated logic without DB:

**AI Drivers:** LLM response parsing, error throwing, rules engine output quality
**SummaryManager:** driver resolution, auto-fallback logic
**Models:** needs_attention computation, scopes, accessors
**Value Objects:** SummaryResult immutability

### 10.6 Test Infrastructure

- `RefreshDatabase` on every feature/integration test — no shared state
- **Factories** for all models with realistic defaults
- `Queue::fake()` for dispatch assertion tests
- `Http::fake()` for LLM API tests with realistic response fixtures
- **Query count assertions** for N+1 prevention (via `DB::getQueryLog()`)
- `Carbon::setTestNow()` for time-dependent tests (deadline/scheduler)
- All tests runnable via single command: `php artisan test`

### 10.7 Agent Testing Contract

Non-negotiable rules for AI-assisted development:

1. Run `php artisan test` BEFORE and AFTER every change
2. If any previously-passing test fails → the change is wrong → fix it
3. Do NOT modify existing tests unless the SPEC explicitly changed
4. Do NOT delete tests — ever
5. New features MUST include integration tests for the full user path
6. Integration tests use real DB, sync queue, mocked external APIs only

### 10.8 Target Counts

| Layer       | Count | Purpose                         |
|-------------|-------|----------------------------------|
| Integration | ~35   | Cross-layer regression firewall  |
| Feature     | ~45   | Endpoint behavior + validation   |
| Unit        | ~20   | Isolated logic correctness       |
| **Total**   | **~100** | **Full contract**             |

---

## 11. Deployment

### 11.1 Docker Compose Services
| Service   | Purpose                |
| --------- | ---------------------- |
| app       | Laravel + Vite (SSR)   |
| postgres  | Database               |
| redis     | Queue + cache          |
| horizon   | Queue worker           |
| scheduler | `schedule:run` loop    |

### 11.2 One-Command Setup
```bash
docker compose up -d
# Runs: migrations, seeds, frontend build, all services
```

### 11.3 Production Target
- Host: 192.168.254.140
- Domain: sts-demo.betamaxgroup.tech
- Proxy: Caddy (existing on host) → app container

---

## 12. Error Handling

### 12.1 Status Codes
| Code | Usage                     |
| ---- | ------------------------- |
| 200  | Successful read/update    |
| 201  | Successful create         |
| 204  | Successful delete         |
| 400  | Malformed request         |
| 401  | Unauthenticated           |
| 403  | Unauthorized              |
| 404  | Not found                 |
| 409  | Optimistic lock conflict  |
| 422  | Validation failure        |
| 500  | Server error              |

### 12.2 Error Shape
```json
{
  "message": "Human-readable description",
  "errors": {
    "field": ["Specific error"]
  }
}
```

---

## 13. Design Patterns

| Pattern        | Application                                                          |
| -------------- | -------------------------------------------------------------------- |
| Manager        | `SummaryManager` — Laravel-idiomatic driver resolution (like Cache)  |
| Facade         | `Summary` facade — hides manager from application code               |
| Strategy       | `SummaryGeneratorInterface` — contract for LLM/Rules drivers         |
| Value Object   | `SummaryResult` — immutable DTO for driver output                    |
| Observer       | Model events for `needs_attention` recomputation                     |
| Form Request   | All validation in dedicated request classes                          |
| Service Layer  | Business logic in services, thin controllers                         |
| Soft Deletes   | Issues use `SoftDeletes` trait                                       |

---

## 14. Stretch Work (All Planned)

| Feature                        | Status  |
| ------------------------------ | ------- |
| Docker Compose one-command     | Planned |
| Pagination + sorting           | Planned |
| Retry policy + dead-letter     | Planned |
| Overdue scheduler              | Planned |
| SSE on summary complete        | Planned |
| Soft deletes                   | Planned |
| Optimistic locking             | Planned |
| Authentication                 | Planned |
| Frontend (Kanban dashboard)    | Planned |
| Sharing + visibility           | Planned |
| Dynamic categories             | Planned |

---

## 15. Out of Scope

- Multi-tenancy / organizations
- File attachments
- Custom fields on issues
- Reporting exports (PDF/CSV)
- i18n / localization
- OAuth / social login
- CI/CD pipeline
- Email notifications
- Activity/audit log
