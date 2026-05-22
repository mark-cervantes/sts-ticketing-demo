# Thread: Project Setup

**Status:** IN_PROGRESS
**Created:** 2026-05-22
**Owner:** orchestrator

## Context
Setting up the Issue Intake & Smart Summary System project from assessment PRD.

## Timeline
- 2026-05-22 23:05 — Assessment file analyzed, gaps identified vs old inflated PRD
- 2026-05-22 23:20 — Stack decisions finalized (Laravel, Vue, Postgres, Redis, Horizon)
- 2026-05-22 23:31 — Auth, categories, sharing, deployment decisions locked
- 2026-05-22 23:37 — Priority ≠ deadline insight captured in ADR-005
- 2026-05-22 00:01 — Dashboard-first Kanban UI decision (ADR-003)
- 2026-05-23 00:07 — SPEC.md, SRS, and ADRs created

## Open Items
- [ ] User approves SPEC.md
- [ ] Delete old PRD (done)
- [ ] Initialize project AGENTS.md with project-specific config
- [ ] Setup MCP servers / skills for Laravel development
- [ ] Create sprint plan from SPEC
- [ ] Begin implementation

## Key Decisions
- No roles — ownership + sharing model (ADR-004)
- Priority and deadline are independent (ADR-005)
- Dynamic categories with inline creation (ADR-006)
- Google Docs sharing model (ADR-007)
- Simple Docker Compose, no custom images (ADR-008)
- AI via Facade + Strategy, OpenAI-compatible (ADR-002)
- Kanban dashboard as primary view (ADR-003)
