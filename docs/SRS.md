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

## 7. Acceptance Criteria Summary

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
11. README is sufficient to run the project without questions
