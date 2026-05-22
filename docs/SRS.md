# Software Requirements Specification (SRS)

> **Project:** Issue Intake & Smart Summary System
> **Domain:** sts-demo.betamaxgroup.tech
> **Version:** 1.0
> **Date:** 2026-05-22
> **Parent:** SPEC.md (approved specification)

This document is the comprehensive technical ground truth. It expands every
SPEC section into implementable detail. Agents and developers reference this
as the authoritative source for behavior, constraints, and edge cases.

---

## 1. System Context

```
┌─────────────┐     HTTPS      ┌──────────────────┐
│   Browser   │ ◄────────────► │   Caddy (proxy)  │
│  (Vue SPA)  │                │   :443 → :8000   │
└─────────────┘                └────────┬─────────┘
                                        │
                               ┌────────▼─────────┐
                               │   Laravel App     │
                               │   (Inertia SSR)   │
                               │   Port 8000       │
                               ├───────────────────┤
                               │   Horizon Worker  │
                               │   (queue jobs)    │
                               ├───────────────────┤
                               │   Scheduler       │
                               │   (cron: * * * *) │
                               └──┬──────────┬─────┘
                                  │          │
                          ┌───────▼──┐  ┌────▼─────┐
                          │ Postgres │  │  Redis   │
                          │  :5432   │  │  :6379   │
                          └──────────┘  └──────────┘
                                  │
                          ┌───────▼──────────┐
                          │  Ollama Cloud /  │
                          │  OpenRouter API  │
                          │  (external)      │
                          └──────────────────┘
```

---

## 2. Functional Requirements

### FR-01: User Registration & Login
| ID       | FR-01                                           |
| -------- | ----------------------------------------------- |
| Priority | Must                                            |
| Input    | name, email, password                           |
| Behavior | Standard Laravel Breeze registration/login flow |
| Output   | Authenticated session, redirect to dashboard    |
| Edge     | Duplicate email → 422 with clear message        |

### FR-02: Issue Creation
| ID       | FR-02                                                                     |
| -------- | ------------------------------------------------------------------------- |
| Priority | Must                                                                      |
| Input    | title, description, priority, category_id, visibility?, deadline_at?      |
| Behavior | Validate → create → set defaults → compute needs_attention → dispatch job |
| Defaults | status=open, visibility=private, summary_status=pending                   |
| Output   | 201 with issue (summary_status=pending)                                   |
| Trigger  | `GenerateSummaryJob` dispatched to Redis queue                            |
| Edge     | Empty title after trim → 422. Missing priority → 422.                     |
| Edge     | deadline_at in the past → 422                                             |
| Edge     | category_id not in DB → 422                                               |

### FR-03: Issue Listing with Filters
| ID       | FR-03                                                                 |
| -------- | --------------------------------------------------------------------- |
| Priority | Must                                                                  |
| Input    | ?status=X&priority=Y&category=slug (all optional, combinable)         |
| Behavior | Query with combined where clauses. Paginate. Eager load category.     |
| Scope    | User sees: own issues + shared issues + public issues                 |
| Sort     | Default: needs_attention desc, priority desc, created_at desc         |
| Output   | Paginated JSON with issue list                                        |
| Edge     | Invalid filter value → ignore that filter (don't 422)                 |
| Edge     | category filter by slug, resolved to category_id internally           |
| N+1      | Eager load: category, user, shares count. No N+1 on list.            |

### FR-04: Issue Detail View
| ID       | FR-04                                                              |
| -------- | ------------------------------------------------------------------ |
| Priority | Must                                                               |
| Input    | issue ID                                                           |
| Behavior | Load issue with comments (eager), category, owner, shares          |
| Auth     | Owner OR shared user OR (public + logged in)                       |
| Output   | Full issue with nested comments (each with user.name)              |
| N+1      | `Issue::with(['comments.user', 'category', 'user', 'shares.user'])` |
| Edge     | Soft-deleted → 404. No access → 403.                               |

### FR-05: Issue Update
| ID       | FR-05                                                         |
| -------- | ------------------------------------------------------------- |
| Priority | Must                                                          |
| Input    | Partial fields: title, description, priority, status, etc.    |
| Auth     | Owner OR shared user with `edit` permission                   |
| Behavior | Validate → optimistic lock check → update → side effects      |
| Lock     | Client sends `updated_at`; if mismatch → 409 Conflict         |
| Trigger  | description changed → re-dispatch GenerateSummaryJob           |
| Trigger  | priority changed → recompute needs_attention                   |
| Trigger  | status-only change → NO summary re-trigger                     |
| Edge     | Concurrent edit by two shared editors → one gets 409           |

### FR-06: Issue Soft Delete
| ID       | FR-06                                         |
| -------- | --------------------------------------------- |
| Priority | Stretch                                       |
| Auth     | Owner only                                    |
| Behavior | Set deleted_at, exclude from all listings     |
| Output   | 204 No Content                                |

### FR-07: Comment Creation
| ID       | FR-07                                              |
| -------- | -------------------------------------------------- |
| Priority | Must                                               |
| Input    | body (text)                                        |
| Auth     | Any user with access to the issue (view or edit)   |
| Behavior | Validate → create with user_id from auth           |
| Output   | 201 with comment (includes user.name for display)  |
| Edge     | Empty body after trim → 422                        |
| Edge     | Issue not found or no access → 404/403             |

### FR-08: Category Management
| ID       | FR-08                                                  |
| -------- | ------------------------------------------------------ |
| Priority | Must                                                   |
| Create   | POST /categories with name → auto-generate slug        |
| Delete   | DELETE /categories/{id} → only if no issues reference it, else 409 |
| List     | GET /categories → all categories, ordered by name      |
| Edge     | Duplicate name (case-insensitive) → 422                |
| UX       | Inline creation during issue create form               |

### FR-09: Sharing
| ID       | FR-09                                                    |
| -------- | -------------------------------------------------------- |
| Priority | Stretch                                                  |
| Input    | email, permission (view/edit)                            |
| Behavior | Resolve email → user_id. Create share. Notify user.      |
| Edge     | Share with self → 422. User not found → 422.              |
| Edge     | Already shared → update permission (upsert behavior)      |
| Output   | 201 with share record                                    |

### FR-10: Visibility Toggle
| ID       | FR-10                                                    |
| -------- | -------------------------------------------------------- |
| Priority | Stretch                                                  |
| Behavior | Owner toggles visibility between private/public          |
| Effect   | public → all logged-in users get read-only access        |
|          | private → only owner + explicitly shared users           |
| Part of  | Issue update (PATCH /issues/{id} with visibility field)  |

### FR-11: AI Summary Generation
| ID       | FR-11                                                           |
| -------- | --------------------------------------------------------------- |
| Priority | Must                                                            |
| Trigger  | Issue create, or description update                             |
| Async    | Job dispatched to Redis, processed by Horizon worker            |
| Flow     | pending → processing → ready/failed                             |
| Drivers  | LLM (Ollama Cloud / OpenRouter) or Rules-based                  |
| Fallback | No API key → rules engine. API failure → retry → rules engine   |
| Output   | summary (1-2 sentences) + suggested_next_action (concrete step) |
| SSE      | On completion, push event to /issues/{id}/stream                |

### FR-12: SSE Summary Stream
| ID       | FR-12                                                 |
| -------- | ----------------------------------------------------- |
| Priority | Stretch                                               |
| Endpoint | GET /issues/{id}/stream                               |
| Auth     | User must have access to the issue                    |
| Behavior | Long-lived connection. Emits on summary_status change |
| Events   | `summary.ready`, `summary.failed`                     |
| Client   | Auto-reconnect on disconnect. Update UI in-place.     |

### FR-13: needs_attention Scheduler
| ID       | FR-13                                                          |
| -------- | -------------------------------------------------------------- |
| Priority | Stretch                                                        |
| Schedule | Every 5 minutes via Laravel scheduler                          |
| Logic    | For all open/in_progress issues: recompute needs_attention     |
|          | = priority is high/critical OR deadline_at approaching/passed  |
| Config   | Threshold in `config/issues.php` (default: 60 minutes before)  |

### FR-14: Kanban Dashboard
| ID       | FR-14                                                       |
| -------- | ----------------------------------------------------------- |
| Priority | Must (primary UI)                                           |
| Columns  | One per status: open, in_progress, resolved                 |
| Cards    | Title, priority badge, needs_attention, category, deadline  |
| Drag     | Move card between columns = status update via PATCH         |
| Optimistic | Card moves instantly; reverts on error                    |
| Filters  | Sidebar: status, priority, category — instantly applied     |
| Modals   | Click card → slide-over with full detail                    |
|          | "+ New" → centered create modal                             |

---

## 3. Non-Functional Requirements

### NFR-01: Performance
- No N+1 queries on any list or detail view
- Issue list loads < 200ms for 100 issues
- Pagination default 15 per page
- Eager load relationships on every query

### NFR-02: Security
- CSRF protection (Inertia default)
- Mass assignment protection (guarded/fillable)
- Authorization on every endpoint (Policy-based)
- Input sanitization: trim all strings, reject empty
- SQL injection protection (Eloquent parameterized queries)

### NFR-03: Reliability
- Summary job retry: 3 attempts, exponential backoff
- Failed jobs visible in Horizon, do not crash API
- Fallback AI driver always available
- Optimistic locking prevents silent data loss

### NFR-04: Observability
- Laravel Horizon dashboard for queue monitoring
- Summary job failures logged with context
- Dead-letter visibility for exhausted retries

### NFR-05: Maintainability
- Service layer for business logic
- Facade over vendor-swappable features (AI)
- Enums for all fixed value sets
- Form Request classes for all validation
- Single-source design system (one config for theme)

### NFR-06: Deployment
- Single `docker compose up -d` from zero to running
- No custom Docker images — generic base images, source-mounted
- Migrations + seeds run automatically on first boot
- Environment configuration via `.env` file

---

## 4. Data Constraints & Business Rules

### BR-01: Issue Defaults
- status = open (on create, always)
- visibility = private (on create)
- summary_status = pending (on create)
- needs_attention = computed immediately on create

### BR-02: Summary Re-trigger Rules
- description changed → reset summary_status to pending, dispatch job
- status changed (without description change) → NO re-trigger
- priority changed → recompute needs_attention only, NO summary re-trigger
- title changed → NO re-trigger (summary is based on description)

### BR-03: needs_attention Computation
```
needs_attention = (
    priority IN ('high', 'critical')
    OR (
        deadline_at IS NOT NULL
        AND deadline_at <= NOW() + INTERVAL threshold
    )
)
```
Where threshold = `config('issues.attention_threshold_minutes', 60)`

### BR-04: Access Resolution Order
```
1. Is user the owner? → full access
2. Is user in issue_shares? → access per permission
3. Is issue public? → view-only
4. Else → 403
```

### BR-05: Category Deletion Guard
Cannot delete a category that has issues referencing it. Return 409 with message indicating how many issues use it.

### BR-06: Optimistic Locking
```
Client sends: { ..fields, updated_at: "2026-05-22T10:00:00Z" }
Server checks: issue.updated_at == request.updated_at
If mismatch → 409 Conflict: "This issue was modified by another user."
```

---

## 5. External Interfaces

### EI-01: LLM API (OpenAI-compatible)
- Protocol: HTTPS REST
- Endpoint: configurable via `LLM_BASE_URL` env
- Auth: Bearer token via `LLM_API_KEY` env
- Model: configurable via `LLM_MODEL` env
- Request: `POST /v1/chat/completions`
- Timeout: 30 seconds
- On failure: retry per policy, then fallback

### EI-02: Caddy Reverse Proxy
- Existing on host 192.168.254.140
- Config appended to `/etc/caddy/Caddyfile`
- Routes sts-demo.betamaxgroup.tech → localhost:{APP_PORT}
- TLS auto-provisioned by Caddy

---

## 6. Seed Data Requirements

Minimum for assessment compliance:
- 3+ users with distinct names/emails
- 5+ issues spanning all priorities, multiple categories, multiple statuses
- 2+ comments per issue (at minimum across 3 issues)
- At least 1 issue with summary_status=ready (pre-generated)
- At least 1 issue with needs_attention=true
- All seeded categories populated

Recommended for demo quality:
- 5 users
- 15-20 issues
- 30+ comments across issues
- Mix of private/public visibility
- 1-2 shared issues between users
- Issues with and without deadlines

---

## 7. AI / Summary Generation — Implementation Detail

### 7.1 File Structure
```
app/
├── Contracts/
│   └── SummaryGeneratorInterface.php
├── Services/Summary/
│   ├── SummaryManager.php           ← extends Illuminate\Support\Manager
│   ├── Drivers/
│   │   ├── LlmDriver.php
│   │   └── RulesDriver.php
│   └── SummaryResult.php
├── Facades/Summary.php
├── Jobs/GenerateSummaryJob.php
├── Events/SummaryCompleted.php
├── Exceptions/SummaryGenerationException.php
└── Providers/SummaryServiceProvider.php
```

### 7.2 SummaryManager Behavior
- `getDefaultDriver()` → reads `config('summary.default')`
- `createLlmDriver()` → builds LlmDriver with HTTP client + config
- `createRulesDriver()` → builds RulesDriver (no dependencies)
- Auto-fallback: if `llm` selected but `LLM_API_KEY` is null/empty → return rules driver
- Usage: `Summary::generate($issue)` or `Summary::driver('rules')->generate($issue)`

### 7.3 LlmDriver Behavior
- Injects Laravel HTTP client (mockable in tests)
- Sends `POST {base_url}/chat/completions` with:
  - `model`: from config
  - `temperature`: 0.3
  - `response_format`: `{ "type": "json_object" }`
  - `messages`: system + user (from prompt template)
- Parses JSON response → extracts `summary` and `suggested_next_action`
- Throws `SummaryGenerationException` on: HTTP error, timeout, malformed JSON, missing keys
- Does NOT retry — retry logic is in the job layer

### 7.4 RulesDriver Behavior
- Deterministic: same input always produces same output
- Category-aware summary templates:
  - billing → "Billing issue reported: {lead_sentence}. Relates to account charges or payment."
  - technical → "Technical issue: {lead_sentence}. Involves system functionality or errors."
  - (etc. for each seeded category, with a generic fallback)
- Priority-aware action suggestions:
  - critical → "Escalate immediately to {category} team lead for urgent triage."
  - high → "Assign to {category} specialist for priority review within 4 hours."
  - medium → "Schedule for review in next {category} team standup."
  - low → "Add to {category} backlog for routine processing."
- Description analysis: extracts first sentence as lead, trims to summary length
- MUST produce output a human would find useful — this is evaluated

### 7.5 GenerateSummaryJob
```
tries: 3
backoff: [10, 30, 90]

handle():
  1. issue.summary_status = 'processing' (save)
  2. try: result = Summary::generate(issue)
  3. catch SummaryGenerationException:
     - if attempts < tries → rethrow (Laravel retries)
     - if attempts >= tries → result = Summary::driver('rules')->generate(issue)
  4. issue.summary = result.summary
  5. issue.suggested_next_action = result.suggestedNextAction
  6. issue.summary_status = 'ready' (save)
  7. event(new SummaryCompleted(issue))

failed(Throwable):
  1. issue.summary_status = 'failed' (save)
  2. Log::error with issue_id + error message
```

### 7.6 Prompt Template
```php
// config/prompts/summary.php
return [
    'system' => 'You are a support ticket analyst. Produce concise, actionable summaries. Respond only in valid JSON.',
    'user' => <<<'PROMPT'
Analyze this support issue:

Title: {title}
Category: {category}
Priority: {priority}
Description:
{description}

Respond in this exact JSON format:
{
  "summary": "1-2 sentence summary of the core issue",
  "suggested_next_action": "One specific, concrete next step to resolve this"
}
PROMPT,
];
```

---

## 8. Testing — Comprehensive Plan

### 8.1 Strategy
Integration-first. Integration tests are the primary regression firewall for
AI-assisted development. They catch cross-layer regressions — the #1 failure
mode when AI agents modify multiple files per feature.

### 8.2 Integration Tests (~35 scenarios)

Real DB (RefreshDatabase), sync queue, mocked external APIs only.

**Issue Lifecycle (I-01 to I-05):**
- I-01: Full lifecycle: register → login → create → defaults verified → job dispatched → job runs → summary populated → view with summary
- I-02: Kanban status transitions: open → in_progress → resolved, each persisted and verified
- I-03: Comment thread: create issue → add 3 comments → view → all loaded with user.name → query count assertion (no N+1)
- I-04: Description update re-triggers: create → job → ready → update desc → status=pending → new job → new summary
- I-05: Status-only update no re-trigger: create → job → ready → update status → still ready → no new job

**Sharing & Access (I-06 to I-08):**
- I-06: Private sharing flow: A creates private → B gets 403 → A shares (view) → B sees → B can't edit (403) → A upgrades to edit → B edits
- I-07: Public sharing: A creates public → B views → B can't edit → A shares (edit) → B edits
- I-08: Visibility toggle: private + shared B → public → C views → private → C loses access

**Categories (I-09):**
- I-09: Create with existing cat → create new inline → use it → delete unused → delete used gets 409

**AI Pipeline (I-10 to I-11):**
- I-10: Fallback path: SUMMARY_DRIVER=llm, no key → create → rules engine → summary populated → ready
- I-11: Retry + fallback: mock LLM fails 3x → retries exhaust → rules fallback → summary populated

**Concurrency & Edge Cases (I-12 to I-18):**
- I-12: Optimistic locking: A fetches → B updates → A submits stale → 409
- I-13: Filter accuracy: seed 15 issues → filter status+priority → exact set → add category → narrowed
- I-14: needs_attention priority: create low → false → update high → true → back to low → false
- I-15: needs_attention deadline: create with future deadline → false → time travel → scheduler → true
- I-16: Soft delete: create → delete → not in list → direct 404 → still in DB
- I-17: Pagination: seed 30 → page 1 (15) → page 2 (15) → no duplicates
- I-18: Access isolation: A creates 3 private → B creates 2 private → A lists → sees only own + public

### 8.3 Feature Tests (~45 scenarios)

Endpoint-level validation and HTTP status code verification.

**Issue CRUD (~20):** create validation (missing fields, invalid enums, empty after trim, past deadline, non-existent category), update (partial, re-trigger rules, optimistic lock), delete (auth, soft), response shape
**Comments (~7):** body validation (empty, trim), auth injection, access on non-existent/inaccessible issue, response shape
**Categories (~5):** list, create with slug, duplicate (case-insensitive), delete unused, delete guard
**Sharing (~8):** valid share, self-share, non-existent email, upsert, remove, permission boundaries
**Auth (~5):** register, login, duplicate email, logout, unauthenticated access

### 8.4 Unit Tests (~20 scenarios)

No DB, no HTTP.

**AI Drivers (~10):** LlmDriver JSON parsing, error throwing (HTTP 500, timeout, malformed JSON), config injection, prompt structure; RulesDriver output varies by category/priority, handles edge descriptions
**SummaryManager (~4):** default driver resolution, explicit driver, auto-fallback (no key)
**Models (~4):** needs_attention computation (all combinations), category slug generation
**Value Objects (~2):** SummaryResult construction, immutability

### 8.5 Test Infrastructure
- `RefreshDatabase` trait on every feature/integration test
- Model factories: `UserFactory`, `IssueFactory`, `CommentFactory`, `CategoryFactory`, `IssueShareFactory`
- `Queue::fake()` for dispatch assertions
- `Http::fake()` with fixtures for LLM responses
- `DB::getQueryLog()` for N+1 assertions
- `Carbon::setTestNow()` for time-dependent tests
- Single command: `php artisan test`

### 8.6 Agent Testing Contract
1. Run `php artisan test` BEFORE and AFTER every change
2. Any previously-passing test now failing → change is wrong → fix before reporting done
3. Do NOT modify existing tests unless SPEC explicitly changed
4. Do NOT delete tests
5. New features MUST include integration tests for full user path
6. Integration tests: real DB, sync queue, mocked external APIs only

---

## 9. Acceptance Criteria Summary

The system is complete when:
1. `docker compose up -d` produces a working app from zero
2. A user can register, login, create an issue, and see it on the Kanban board
3. Dragging a card changes status (optimistic + persisted)
4. Creating an issue triggers async summary generation
5. Summary appears on the issue detail without page reload (SSE)
6. If no LLM key, rules engine produces a real summary
7. Filters narrow the Kanban view correctly
8. Comments work on issue detail
9. Sharing works: share by email, recipient sees the issue
10. All 7 mandated tests pass via `php artisan test`
11. Full test suite (~100 tests) passes
12. README is sufficient to run the project without questions
