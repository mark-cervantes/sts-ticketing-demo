# Sprint Plan — Issue Intake & Smart Summary System

> **Last updated:** 2026-05-23
> **Status:** Sprint 01 COMPLETE ✅ (all 5 tasks + 4 hygiene follow-ups filed; deployable checkpoint on main)

## Dev Environment Reminder

All commands run through Laravel Sail: `./vendor/bin/sail <command>`. Never bare `php artisan`.
Cold-start verifies Sail is up via `docker compose ps` (see AGENTS.md).

---

## Cold-Start

See `AGENTS.md` → "Cold-Start Protocol" + "Dev Environment — Laravel Sail".

---

## Definitions

- **Sprint** = a logical group of tasks that produce ONE complete, deployable feature.
  Not a time box. When all tasks in a sprint are done → merge commit → deployable checkpoint.
- **Task** = one unit of work = one feature branch. Has tests baked in (not separate).
- **Subtask** = a breakdown within a task (optional granularity).

## Numbering: `XX.XX.XX`

```
Sprint.Task.Subtask
01.01.00 = Sprint 1, Task 1
02.03.50 = Sprint 2, Task inserted between 03 and 04
```

- Filesystem sort gives correct execution order
- Insert new tasks with intermediate numbers (e.g., 02.02.50 between 02.02 and 02.03)
- No renumbering needed

## File Locations

```
vault/sprint/
├── backlog/     ← ordered by filename, pull from top
│   └── XX.XX.XX-title-slug.md
├── ongoing/     ← currently being worked (max 2-3 parallel with worktrees)
│   └── XX.XX.XX-title-slug.md
├── done/        ← completed, merged to dev
│   └── XX.XX.XX-title-slug.md
└── PLAN.md      ← this file (overview + ordering + dependencies)
```

## Sprint Completion

When all `XX.*` tasks are in `done/`:
1. Verify all feature branches merged to dev
2. Final merge commit: `feat(sprint-XX): <sprint description> - done`
3. That commit = deployable checkpoint
4. Optionally merge dev → main if stable

---

## Sprint Overview

### Sprint 01: Foundation
> One deployable outcome: app boots, migrations run, seed data visible, auth works.

| Task ID  | Title                                       | Status     | Depends On  | Branch                  |
|----------|---------------------------------------------|------------|-------------|-------------------------|
| 01.01.00 | MCP server setup (Boost + Postgres + wiring)| ✅ done    | —           | main (pre-sprint)       |
| 01.02.00 | Inertia + Vue + Breeze + shadcn-vue + Horizon | ✅ done | 01.01       | feature/frontend-scaffold |
| 01.02.50 | Gitignore .obsidian editor state            | ✅ done    | —           | chore/gitignore-obsidian |
| 01.02.60 | Clean components.json tailwind.config       | ✅ done    | 01.02       | chore/components-json-cleanup |
| 01.03.00 | Models + Migrations + Enums                 | ✅ done    | 01.02       | feature/models          |
| 01.03.50 | Restore categories.name unique + test fix   | backlog    | 01.03       | chore/category-name-unique |
| 01.04.00 | Factories + Seeders                         | ✅ done    | 01.03       | feature/seeders         |
| 01.05.00 | Auth customization + Policies               | ✅ done    | 01.03       | feature/auth            |
| 01.05.50 | Policy coverage cleanup                     | backlog    | 01.05, 02.01 | chore/policy-coverage-cleanup |

### Sprint 02: Core API + AI Pipeline
> One deployable outcome: full issue CRUD via API, comments, categories, AI summaries generated.

| Task ID  | Title                        | Depends On  | Branch                    | Parallel? |
|----------|------------------------------|-------------|---------------------------|-----------|
| 02.01.00 | Issue CRUD API + tests       | Sprint 01   | feature/issue-crud        | —         |
| 02.02.00 | Comments API + tests         | 02.01       | feature/comments          | —         |
| 02.03.00 | Categories API + tests       | 02.01       | feature/categories        | w/ 02.02  |
| 02.04.00 | AI Summary Pipeline + tests  | 02.01       | feature/ai-pipeline       | w/ 02.02  |
| 02.05.00 | Filters + Pagination + Sort  | 02.01       | feature/filters           | w/ 02.04  |

### Sprint 03: Frontend (Kanban Dashboard)
> One deployable outcome: full Kanban UI with modals, drag-drop, real-time summary.

| Task ID  | Title                        | Depends On  | Branch                    | Parallel? |
|----------|------------------------------|-------------|---------------------------|-----------|
| 03.01.00 | Design system + layout       | Sprint 02   | feature/design-system     | —         |
| 03.02.00 | Kanban board + drag-drop     | 03.01       | feature/kanban            | —         |
| 03.03.00 | Issue create modal           | 03.01       | feature/create-modal      | w/ 03.02  |
| 03.04.00 | Issue detail slide-over      | 03.02       | feature/issue-detail      | —         |
| 03.05.00 | Comment thread UI            | 03.04       | feature/comment-ui        | —         |
| 03.06.00 | SSE client (summary live)    | 03.04, 02.04| feature/sse-client        | w/ 03.05  |

### Sprint 04: Stretch Features
> One deployable outcome: sharing, scheduler, optimistic locking — all stretch items.

| Task ID  | Title                        | Depends On  | Branch                    | Parallel? |
|----------|------------------------------|-------------|---------------------------|-----------|
| 04.01.00 | Sharing + Visibility         | Sprint 03   | feature/sharing           | —         |
| 04.02.00 | Scheduler (needs_attention)  | Sprint 02   | feature/scheduler         | w/ 04.01  |
| 04.03.00 | Optimistic Locking           | Sprint 02   | feature/optimistic-lock   | w/ 04.01  |
| 04.04.00 | Share UI (modal section)     | 04.01, 03.04| feature/share-ui          | —         |

### Sprint 05: Deployment + Documentation
> One deployable outcome: production Docker setup works, README complete, submitted.
> Note: Sail's `compose.yaml` is DEV-only. Production needs its own setup.

| Task ID  | Title                            | Depends On  | Branch                    | Parallel? |
|----------|----------------------------------|-------------|---------------------------|-----------|
| 05.01.00 | Production Dockerfile + compose  | Sprint 04   | feature/prod-docker       | —         |
| 05.02.00 | Caddy + domain config            | 05.01       | feature/deployment        | —         |
| 05.03.00 | README + Architecture doc        | All         | feature/readme            | —         |
| 05.04.00 | Final seed verification          | 05.01       | feature/final-polish      | w/ 05.03  |

---

## Dependency Rules

- "Depends On" means: that task must be merged to `dev` before this one starts
- Agent verifies: `git log dev --oneline | grep <dependency-slug>`
- If dependency not met → skip, pull next satisfiable task
- Parallel tasks (marked "w/ XX.XX") can run simultaneously in separate worktrees

## Hotfix Protocol

1. Create `XX.00.50-hotfix-description.md` with prefix matching current sprint
2. Move to `ongoing/` immediately (highest priority)
3. Branch: `hotfix/description`
4. Fix → test → commit → merge to dev
5. Move to `done/`
6. Resume previous ongoing task

## New Feature Insertion

1. Create task file with number between existing tasks (e.g., 02.02.50)
2. Place in `backlog/`
3. It automatically sorts into correct position
4. Filesystem sort = execution order
