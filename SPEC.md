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

### 7.1 Architecture
```
SummaryFacade (app-facing)
  └── resolves driver from config
        ├── LlmSummaryGenerator      ← OpenAI-compatible (Ollama Cloud / OpenRouter)
        └── RuleBasedSummaryGenerator ← Deterministic keyword/category fallback
```

Both implement `SummaryGeneratorInterface`:
```php
interface SummaryGeneratorInterface
{
    public function generate(Issue $issue): SummaryResult;
}
```

### 7.2 Async Flow
1. Issue created → API responds 201 with `summary_status: pending`
2. `GenerateSummaryJob` dispatched to Redis queue
3. Job calls `SummaryFacade::generate($issue)`
4. Facade resolves configured driver
5. On success: issue updated with summary + next_action, `summary_status = ready`
6. On failure: `summary_status = failed`, error logged
7. SSE event pushed to connected clients

### 7.3 Fallback Behavior
- No API key configured → auto-fallback to rules engine
- LLM API failure (timeout, 5xx) → retry, then fallback to rules engine
- Rules engine always succeeds (deterministic)

### 7.4 Retry Policy
- Max 3 attempts, exponential backoff (10s, 30s, 90s)
- After exhaustion: fallback to rules engine result
- Failed jobs visible in Horizon

### 7.5 Configuration
```env
SUMMARY_DRIVER=llm          # llm | rules
LLM_BASE_URL=https://...    # Any OpenAI-compatible endpoint
LLM_API_KEY=xxx
LLM_MODEL=model-name
```

### 7.6 Prompt Template
Committed at `config/prompts/summary.php`. Output:
- `summary`: 1-2 sentences
- `suggested_next_action`: single concrete step

### 7.7 Rules-Based Engine
Uses category keywords, priority, description length/content. Produces genuinely useful output — not placeholder stubs.

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

## 10. Testing

### 10.1 Required (Assessment Mandates 7)

| # | Test                                           | Signal                        |
|---|------------------------------------------------|-------------------------------|
| 1 | Successful issue create                        | CRUD works, defaults applied  |
| 2 | Validation failure (missing/invalid field)     | Input handling thorough       |
| 3 | List filtering with 2+ combined filters        | Query composition works       |
| 4 | Adding comment to existing issue               | Relationship creation works   |
| 5 | Single-issue view loads comments without N+1   | Eager loading verified        |
| 6 | Creating issue dispatches summary job          | Async boundary correct        |
| 7 | Summary job populates fields + status          | Job execution end-to-end      |

### 10.2 Stretch Tests
- Sharing: share issue, verify access
- Visibility: private not visible to unshared user
- Optimistic locking: concurrent update → 409
- Category inline creation, duplicate rejection
- `needs_attention` recomputation on priority change
- Scheduler flags overdue issues
- Soft delete: excluded from list
- Drag-drop status change via API

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

| Pattern       | Application                                            |
| ------------- | ------------------------------------------------------ |
| Facade        | `SummaryFacade` — hides driver resolution              |
| Strategy      | `SummaryGeneratorInterface` + LLM/Rules drivers        |
| Observer      | Model events for `needs_attention` recomputation       |
| Form Request  | All validation in dedicated request classes            |
| Service Layer | Business logic in services, thin controllers           |
| Soft Deletes  | Issues use `SoftDeletes` trait                         |
| Repository    | If needed for complex query composition                |

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
