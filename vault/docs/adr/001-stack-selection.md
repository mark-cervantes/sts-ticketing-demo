# ADR-001: Stack Selection

**Status:** Accepted
**Date:** 2026-05-22
**Context:** Assessment prefers PHP/Laravel. We need a frontend, async jobs, and real-time capabilities.

## Decision

- **Backend:** Laravel 13 (PHP 8.4, current as of Feb 2026; Boost-optimized)
- **Frontend:** Inertia.js + Vue 3 + TypeScript
- **UI Kit:** shadcn-vue + Tailwind CSS
- **Database:** PostgreSQL (Docker)
- **Queue:** Redis + Laravel Horizon
- **Real-time:** SSE (Server-Sent Events)
- **Auth:** Laravel Breeze (session-based)

## Rationale

**Laravel** — explicitly preferred by assessment. Built-in queue, events, validation,
Eloquent ORM, Horizon — reduces boilerplate for every requirement.

**Inertia + Vue** — assessment says UI is optional but we're building one. Inertia gives
SPA feel without maintaining a separate API layer. Vue chosen over React for speed of
development in this context (popular Inertia pairing).

**PostgreSQL over SQLite** — deploying via Docker anyway, so Postgres is zero extra
setup cost. Gains: proper concurrent access, JSON columns if needed, production parity.
SQLite would require noting limitations in README.

**Redis + Horizon** — assessment requires async jobs. Redis is fast, Horizon gives
monitoring UI and retry visibility. Database queue would work but Horizon's
observability is a positive signal.

**SSE over WebSocket** — summary completion notification is unidirectional (server → client).
SSE is simpler, no WebSocket server needed, works through Caddy without config changes.
WebSocket (Reverb) would be overkill for this use case.

**shadcn-vue** — unstyled, composable components. Tailwind-native. Single-source theming
via CSS custom properties. Changing primary color = one file edit, not 20.

## Consequences

- Must run Docker Compose (Postgres, Redis) even for local dev
- TypeScript adds some overhead but catches bugs early
- Horizon requires a separate process (handled by Docker Compose)

## Alternatives Considered

| Alternative      | Why Not                                                   |
| ---------------- | --------------------------------------------------------- |
| SQLite           | Works but loses production parity; no concurrent writes   |
| React            | Valid but Vue + Inertia is faster for this scope          |
| WebSocket/Reverb | Overkill for one-direction summary push                   |
| Database queue   | Works but no monitoring UI; Horizon is free with Redis    |
| PrimeVue         | Heavier, opinionated styling harder to customize          |
