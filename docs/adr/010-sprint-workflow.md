# ADR-010: Sprint-as-Deployable-Unit Workflow

**Status:** Accepted
**Date:** 2026-05-23
**Context:** Need a task/sprint model that is adaptive (hotfixes, new features mid-flow),
deterministic for AI agent cold starts, and maps cleanly to git branches.

## Decision

### Sprint = Deployable Feature Unit (Not Time Box)

A sprint is a logical group of tasks that together produce one complete,
deployable capability. When all tasks in a sprint are done, a merge commit
to dev marks a deployable checkpoint.

### Numbering: `XX.XX.XX` (Sprint.Task.Subtask)

- Files: `vault/sprint/{backlog,ongoing,done}/XX.XX.XX-title-slug.md`
- Filesystem sort gives correct execution order
- Insert new tasks with intermediate numbers (e.g., 02.02.50 between 02 and 03)
- No renumbering ever needed

### Movement: `backlog/ → ongoing/ → done/`

- `backlog/`: prioritized queue, sorted by filename
- `ongoing/`: actively being worked (max 2-3 with worktrees)
- `done/`: completed, merged to dev

### Git Mapping

| Concept        | Git                                              |
| -------------- | ------------------------------------------------ |
| 1 task         | 1 feature branch                                 |
| Task done      | Branch merged to dev (no-ff), task file → done/  |
| Sprint done    | All XX.* in done/ → `feat(sprint-XX): desc - done` |
| Hotfix         | `hotfix/desc` branch, merges to dev immediately  |
| Release        | dev → main when stable                           |

### Cold-Start Protocol

Any new agent session:
1. Read `vault/sprint/PLAN.md` — big picture
2. `ls vault/sprint/ongoing/` — resume active work
3. `ls vault/sprint/done/` — understand what's built
4. `ls vault/sprint/backlog/` — next task by sort order
5. `git status` + `git branch` — uncommitted work?
6. Resume ongoing OR pull next from backlog

### Hotfix Insertion
- File: `XX.00.50-hotfix-desc.md` (prefix = current sprint)
- Goes to ongoing/ immediately, highest priority
- Branch: `hotfix/desc`
- Merges to dev fast, moves to done/

### New Feature Insertion
- File: intermediate number (e.g., 02.02.50)
- Placed in backlog/
- Filesystem sort handles ordering

## Rationale

**Not time-boxed sprints** — time boxes create artificial urgency and don't map
to "one deployable unit." A sprint ends when its feature is complete and stable,
not when a calendar date arrives.

**File-based state** — no external tool (Jira, Linear) needed. Any agent reads
the filesystem and knows exactly what's happening. Cold start takes < 30 seconds.

**Numeric prefixes** — deterministic sort order without maintaining an index file.
PLAN.md exists for humans and high-level overview, but the source of truth is
the filesystem.

**No renumbering** — intermediate numbers (XX.XX.50) allow insertion without
touching existing files. Important when multiple agents might be reading task
files concurrently.

## Consequences

- Task files must have correct numeric prefixes on creation
- PLAN.md should stay in sync with actual task files (but filesystem wins on conflict)
- Sprint completion requires manual verification that all XX.* tasks are in done/
- Hotfixes use .00.50 convention — agents must know this

## Alternatives Considered

| Alternative         | Why Not                                                     |
| ------------------- | ----------------------------------------------------------- |
| Time-boxed sprints  | Artificial; doesn't map to "deployable unit"                |
| Flat backlog (no sprints) | No deployable checkpoints; harder to track progress   |
| PLAN.md as only source | PLAN.md can drift; filesystem is always current          |
| Jira/Linear         | External dependency; agents can't read it natively          |
