# Session Handoff — Development Phase

> Copy everything below the line into a new session to resume.

---

## Context

You're working on the **Issue Intake & Smart Summary System** at `/home/cmark/projects/ticketing-system`. This is a Laravel 11 + Inertia + Vue 3 + TypeScript + PostgreSQL + Redis project for a software developer practical assessment.

**All planning is done. You are in the BUILD phase.**

## Cold Start

1. Read `AGENTS.md` — project-specific agent config, workflow, architecture rules
2. Read `vault/sprint/PLAN.md` — sprint structure, cold-start protocol, dependency rules
3. `ls vault/sprint/ongoing/` — anything active?
4. `ls vault/sprint/done/` — what's completed?
5. `ls vault/sprint/backlog/` — next task by sort order
6. `git status && git branch && git log --oneline -5` — current git state

## Key Files

| File | Purpose |
|------|---------|
| `AGENTS.md` | How agents work on THIS project (models, workflow, rules) |
| `vault/SPEC.md` | Approved specification — what to build |
| `vault/docs/SRS.md` | Technical ground truth — how to build it |
| `vault/docs/adr/*.md` | Architecture decisions (001-010) — why we decided what |
| `vault/sprint/PLAN.md` | Sprint overview, task ordering, dependencies |
| `vault/sprint/backlog/*.md` | Task files ready to pull |
| `.opencode/agents/*.md` | Project-specific agents (tech-lead, coder-backend, coder-frontend, qa) |

## Project-Specific Agents (USE THESE, not global pipeline agents)

| Agent | Model | Role |
|-------|-------|------|
| **tech-lead** | `anthropic/claude-opus-4-6` | Task enrichment (additive only), code review |
| **coder-backend** | `anthropic/claude-sonnet-4-6` | Laravel PHP implementation |
| **coder-frontend** | `anthropic/claude-opus-4-6` | Vue + Inertia + shadcn-vue |
| **qa** | `anthropic/claude-sonnet-4-6` | Integration-first test writing |

## Workflow Per Task

```
1. Pull next task from backlog/ → ongoing/
2. tech-lead: enriches with additive-only Technical Guidance
3. qa: writes tests (RED — fail before implementation)
4. coder-backend: implements backend until tests pass
5. coder-frontend: implements frontend (if task has UI)
6. tech-lead: reviews diff → approves or requests changes
7. On approval: final commit "feat(scope): description - done" → merge to dev (no-ff)
8. Move task file to done/
```

## Git Rules

- `main` → stable. `dev` → integration. Feature branches → tasks.
- 1 task = 1 feature branch: `feature/<task-slug>`
- No-FF merges to dev: `git merge --no-ff feature/task-slug`
- Conventional commits. Final: `feat(scope): description - done`
- Never force push. Never.
- Create `dev` branch from main if it doesn't exist yet.

## Testing Contract

- `php artisan test` BEFORE and AFTER every change
- Previously-passing test fails → change is wrong → fix it
- Do NOT modify existing tests. Do NOT delete tests.
- New features MUST have integration tests.

## What Needs to Happen First

1. Create `dev` branch from `main` (if not exists)
2. Start with task `01.01.00-mcp-server-setup` — configure MCP servers
3. Then `01.02.00-laravel-scaffold` — bootstrap the Laravel project
4. Continue pulling tasks in order, respecting dependencies

## Credentials (in mempalace: ticketing-system/credentials)

- Ollama Cloud API key available
- Deployment: ssh 192.168.254.140, domain sts-demo.betamaxgroup.tech
- Caddy reverse proxy on host

## Sprint Status

- Sprint 01 (Foundation): 5 tasks in backlog, none started
- Sprint 02 (Core API + AI): 5 tasks in backlog
- Sprint 03-05 (Frontend, Stretch, Deployment): not yet written — write when Sprint 02 nears completion

**Start building.**
