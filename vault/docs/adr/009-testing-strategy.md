# ADR-009: Integration-First Testing Strategy

**Status:** Accepted
**Date:** 2026-05-23
**Context:** Project is AI-assisted development. Need a regression-proof testing strategy.

## Decision

**Integration tests are the primary test layer.** They are the largest group
and the first line of defense against regressions.

### Priority Order
```
1. Integration tests (~35) — cross-layer regression firewall
2. Feature tests (~45)     — endpoint behavior + validation
3. Unit tests (~20)        — isolated logic correctness
```

### Why Integration-First

When an AI agent implements "update issue," it touches: controller, form
request, service layer, model events, job dispatch, policy, and database.
A unit test on the service passes. A feature test on the endpoint passes.
But the service now silently skips the job dispatch because the agent
refactored an event hook.

Only an integration test that walks the full path — "create issue, verify
job ran, verify summary appeared" — catches this.

**Unit tests verify components. Integration tests verify the system works.**

### Integration Test Characteristics
- Real database (RefreshDatabase per test)
- Real queue (sync driver — jobs execute inline)
- Real Eloquent events, observers, policies
- Mocked external APIs only (LLM endpoint)
- Each test walks a full user workflow end-to-end
- No shared state between tests

### Agent Contract
Every AI agent implementing code must:
1. Run `php artisan test` before AND after changes
2. Never modify existing tests unless SPEC changed
3. Never delete tests
4. Include integration tests for new features
5. If a previously-passing test fails → the change is wrong

## Rationale

**AI-assisted development has a specific failure mode:** AI tends to "fix" one
thing by quietly breaking cross-cutting concerns. It refactors a method
signature and doesn't realize an observer was depending on the old event.
It changes a policy and doesn't realize three endpoints relied on it.

Feature tests catch endpoint-level regressions. Unit tests catch logic bugs.
But neither catches the seam between layers — that's where AI breaks things most.

**Integration tests are the immovable contract.** The agent can refactor
internals however it wants, as long as the full user path still works.

## Consequences

- Integration tests are slower (DB + queue per test) — ~35 tests × ~200ms ≈ 7s
- Still fast enough for run-on-every-change workflow
- Factories must produce realistic, consistent data
- Time-dependent tests need `Carbon::setTestNow()` for determinism

## Assessment Alignment

Assessment mandates 7 specific tests. 6 of those 7 are naturally integration
tests (they test behaviors that span multiple layers). We satisfy all 7 as
a subset of our ~100-test suite.

## Alternatives Considered

| Alternative            | Why Not                                                        |
| ---------------------- | -------------------------------------------------------------- |
| Feature-first          | Misses cross-layer regressions; AI's main failure mode         |
| Unit-first             | Fast but tests components in isolation; misses integration bugs |
| Minimal (just the 7)   | Assessment minimum, but no regression safety for AI dev        |
| E2E browser tests      | Too slow, too brittle for rapid AI-assisted iteration          |
