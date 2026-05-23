# Issue Intake & Smart Summary System — Reviewer Guide

**Candidate:** Mark Cervantes
**Repository:** [github.com/mark-cervantes/sts-ticketing-demo](https://github.com/mark-cervantes/sts-ticketing-demo)
**Live Demo:** [sts-demo.betamaxgroup.tech](https://sts-demo.betamaxgroup.tech)

---

## Quick Access

| | |
|---|---|
| **Demo login** | `demo@example.com` / `password` |
| **Other users** | `alice@example.com`, `bob@example.com`, `carol@example.com`, `david@example.com` — all use password `password` |
| **Run tests** | `make test` (346 tests, 814 assertions) |
| **Start locally** | `git clone` → `make setup` → `make fresh` → `make dev` → open `http://localhost` |

---

## Features at a Glance

### Core Requirements (all implemented)

| Requirement | Where to see it |
|---|---|
| **Issue CRUD** — create, list, view, update | Kanban board → click "New Issue" or any card |
| **Comments** — add comments to any issue | Open an issue → scroll to comment thread |
| **Combinable filters** — status, priority, category | Kanban board top bar → filter dropdowns stack |
| **AI-generated summary + suggested next action** | Create an issue → watch the shimmer → summary appears in ~3s |
| **Async generation** — background job, not in request | Summary status shows "pending" → "ready" via SSE push |
| **LLM + rules-based fallback** — clean interface | Settings → AI Settings → toggle between providers or disconnect API key to see rules fallback |
| **needs_attention flag** — high-priority identification | Create an issue with priority "High" or "Critical" → flag sets automatically |
| **Validation** — reject missing/invalid fields | Try submitting empty title or invalid priority via API |
| **Migrations + seeds** — 5+ issues, comments | `make fresh` seeds 18 issues, 5 users, 6 categories, ~30 comments |

### Bonus Features (all implemented)

| Feature | Where to see it |
|---|---|
| **One-command Docker setup** | `make dev` starts everything |
| **Pagination + sorting** | Issue list API supports `?page=`, `?sort=`, `?per_page=` |
| **Overdue scheduler** | `issues:flag-attention` runs hourly — recomputes needs_attention for deadline proximity |
| **Retry policy + failed jobs** | `summaries:retry-stuck` command + Laravel Horizon dashboard at `/horizon` |
| **SSE real-time push** | Summary completion pushes to browser — no polling |
| **Optimistic locking** | Edit an issue in two tabs → second save shows conflict warning |
| **Authentication** | Laravel Breeze, session-based, with custom branded login/register pages |
| **Full frontend** | Kanban board, detail slide-over, comments, emoji reactions, drag-drop status |

### Extra (beyond spec)

| Feature | Where to see it |
|---|---|
| **Google Docs-style sharing** | Issue detail → Share tab → invite by email with view/comment/edit permissions |
| **Emoji reactions on comments** | Click the emoji button on any comment |
| **AI triage suggestions** | Create dialog → type a title/description → priority + category suggestions appear |
| **AI Settings UI** | Settings → AI Settings → switch providers, pick models, test connection |
| **Category management** | Settings → Categories → create/delete categories |
| **Drag-drop Kanban** | Drag cards between columns to change status |
| **Creator avatars** | Initials badge on each Kanban card + "You" badge on your own |

---

## Suggested Testing Flow (5 minutes)

### 1. Open the live demo

Go to [sts-demo.betamaxgroup.tech](https://sts-demo.betamaxgroup.tech) and log in as `demo@example.com` / `password`.

### 2. Explore the Kanban board

- You'll see issues organized by status columns (Open → In Progress → Resolved)
- Cards show priority badges, creator initials, comment counts, and AI summary snippets
- Issues flagged with `needs_attention` have a visual indicator

### 3. Create a new issue

- Click **"New Issue"** in the top bar
- Fill in a title (e.g., "Payment gateway returning 502 errors") and a description
- Notice the **AI triage suggestions** — as you type, priority and category are suggested
- Submit → the card appears with a shimmer animation ("AI analyzing…")
- Within 3–5 seconds, the summary and suggested next action appear (pushed via SSE)

### 4. View issue detail

- Click any issue card → the detail slide-over opens
- See the **AI summary** and **"Suggested Next Action"** banner
- Click **"Regenerate"** to re-run the AI summary
- Scroll down to see the **comment thread**

### 5. Add a comment

- Type a comment in the input box and submit
- Try adding an **emoji reaction** to any comment (click the smiley icon)

### 6. Test sharing

- In the detail slide-over → click the **Share** tab
- Invite `alice@example.com` with "Comment" permission
- Log out → log in as Alice → she can see and comment on that issue

### 7. Drag-drop status change

- Back on the Kanban board, **drag a card** from "Open" to "In Progress"
- The status updates immediately (persisted to DB)

### 8. AI Settings

- Go to **Settings → AI Settings** (top-right menu)
- See the current provider configuration
- Click **"Test Connection"** to verify the LLM is reachable

---

## Running Locally

```bash
git clone git@github.com:mark-cervantes/sts-ticketing-demo.git
cd sts-ticketing-demo
make setup    # composer install, npm install, .env, key:generate, migrate
make fresh    # seed demo data (18 issues, 5 users, 30+ comments)
make dev      # starts containers + Vite + queue worker
```

Open `http://localhost` → login as `demo@example.com` / `password`.

**Prerequisites:** Docker and Docker Compose. That's it — everything else runs inside Sail containers.

### Running the test suite

```bash
make test                                  # full suite (346 tests)
make test-filter FILTER=IssueCrudApiTest   # single test class
make test-filter FILTER=test_store_issue   # single test method
```

---

## API Quick Test (curl)

After logging in via browser (session cookie), or using the XSRF pattern:

```bash
# Create an issue
curl -s http://localhost/api/issues \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --cookie "laravel_session=..." \
  -d '{"title":"Server CPU spike","description":"CPU at 98% on web-03 since 14:00","priority":"high","category_id":1,"status":"open"}'

# List issues with combined filters
curl -s "http://localhost/api/issues?status=open&priority=high&category=billing" \
  -H "Accept: application/json" \
  --cookie "laravel_session=..."

# View single issue (with comments, eager-loaded)
curl -s http://localhost/api/issues/1 \
  -H "Accept: application/json" \
  --cookie "laravel_session=..."

# Add a comment
curl -s http://localhost/api/issues/1/comments \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --cookie "laravel_session=..." \
  -d '{"body":"Restarted the service, monitoring now."}'
```

---

## Architecture Highlights

| Decision | Rationale |
|---|---|
| **Laravel Manager pattern** for AI drivers | Same pattern Laravel uses for queues, mail, cache — swap LLM/rules with a config change |
| **PostgreSQL** over SQLite | Native enum validation, JSON operators, advisory locks for Horizon, dev-prod parity |
| **SSE** over WebSockets | Zero infrastructure (no Pusher, no Soketi) — native PHP streaming |
| **Inertia + Vue** over API + SPA | Single codebase, no CORS, session auth just works — same-origin by design |
| **`user_id` FK** over `author_name` string | Referential integrity, permission checks, no orphaned comments |
| **`critical` priority** added | Real-world triage needs more than 3 levels — additive, non-breaking |

**10 Architecture Decision Records** document every significant trade-off in `vault/docs/adr/`.

---

## Code Quality Markers

- **No N+1 queries** — eager loading verified by tests (`assertQueryCount`)
- **Form Request validation** — every endpoint has a dedicated `FormRequest` class
- **Policy authorization** — every controller action goes through a `Policy`
- **Service layer** — controllers are thin; business logic lives in `IssueService`, `SummaryManager`, `TriageService`
- **250 commits** — incremental, descriptive history across 8 sprints
- **Zero Pest** — pure PHPUnit 12 as specified
- **Pint-formatted** — consistent code style enforced via `vendor/bin/pint`

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13, PHP 8.4 |
| Frontend | Vue 3 + TypeScript + Inertia.js |
| UI | shadcn-vue + Tailwind CSS v4 |
| Database | PostgreSQL 18 |
| Queue | Redis + Laravel Horizon |
| AI | OpenRouter (Gemini 2.5 Flash) + rules-based fallback |
| Real-time | Server-Sent Events |
| Auth | Laravel Breeze (session-based) |
| Testing | PHPUnit 12 — 346 tests, 814 assertions |
| Dev | Laravel Sail + Make |
