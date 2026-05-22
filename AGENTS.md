# AGENTS.md

Project-specific agent configuration. Supplements `~/.config/opencode/AGENTS.md`.

## Project

- **Stack:** Laravel 11 + Inertia + Vue 3 + TypeScript + PostgreSQL + Redis
- **Spec:** `vault/SPEC.md` (what to build)
- **SRS:** `vault/docs/SRS.md` (how to build it — ground truth)
- **ADRs:** `vault/docs/adr/` (why decisions were made)
- **Sprint state:** `vault/sprint/PLAN.md`

## Agents

Project agents in `.opencode/agents/` are the DEFAULT for this project. Do not use global pipeline agents.

| Agent | Role |
|---|---|
| tech-lead | Task enrichment, code review |
| coder-backend | Laravel implementation |
| coder-frontend | Vue + Inertia implementation |
| qa | Test writing, regression audits |

### Workflow per task

1. tech-lead → enriches task with `## Technical Guidance`
2. qa → writes RED tests
3. coder-backend → implements until tests pass
4. coder-frontend → implements UI (skip if backend-only)
5. tech-lead → reviews diff, approves or requests changes
6. On approval: `feat(scope): description - done` → merge to dev

## Cold-Start Protocol

Every new session:
1. `cat vault/sprint/PLAN.md`
2. `ls vault/sprint/ongoing/` — resume?
3. `ls vault/sprint/backlog/` — what's next?
4. `git status && git branch`

## Git

- `main` (stable) → `dev` (integration) → `feature/<task-slug>`
- No-FF merges to dev: `git merge --no-ff feature/<task-slug>`
- Conventional commits: `feat(scope): description`
- Final commit on feature branch: `feat(scope): description - done`
- Never force push.

## Testing Contract

1. `php artisan test` before any change → record baseline
2. `php artisan test` after every logical change
3. Previously-passing test fails → YOUR change is wrong → fix code, not the test
4. Never modify or delete existing tests
5. Task not done until full suite passes

## Tool Usage — Prefer Generators

Always use framework CLIs to generate boilerplate. Never hand-write what a generator produces.

- Laravel: `php artisan make:{model,controller,request,policy,job,event,observer,test,seeder,factory}`
- Composer: `composer require <package>` (don't manually configure)
- Frontend: `npx shadcn-vue@latest add <component>`, `npm install <package>`

Customize the generated file after generation. Never skip generation to write boilerplate manually.

## MCP Servers

Globally available: Playwright, Serena.
Project-specific MCPs are configured in `opencode.json` (root) when needed.
