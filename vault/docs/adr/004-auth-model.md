# ADR-004: Authentication Model

**Status:** Accepted
**Date:** 2026-05-22
**Context:** Assessment says auth is optional stretch work. We're including it.

## Decision

**Free-tier SaaS model:** anyone can register and log in. No roles. Every user
is equal. Authorization is per-issue (ownership + sharing), not per-role.

### Access Rules
1. Owner of an issue → full access (CRUD)
2. Shared user with `edit` → can update issue, add comments
3. Shared user with `view` → can view issue, add comments
4. Public issue + logged in → view-only, can add comments
5. Private issue + no share → 403

### Implementation
- Laravel Breeze (session-based, Inertia-compatible)
- No role column on users table
- Authorization via `IssuePolicy` checking ownership + shares + visibility
- Comments: any user with access to the issue can comment

## Rationale

**No roles** — the assessment doesn't require roles. Adding admin/agent/customer
would add middleware, separate dashboards, and UI branching that isn't evaluated.
The assessment's data model uses `author_name` (a string), implying no complex
user relationships.

**Ownership + sharing** — this is the access model. Simple, Google Docs-like.
Shows auth is genuine (not just login/logout) without RBAC overhead.

**Session-based** — Inertia uses session auth naturally. No need for token-based
(Sanctum tokens) when the client is the same-origin SPA.

## Consequences

- No admin panel, no user management
- All users see the same UI (their dashboard)
- Sharing is the only way to collaborate on private issues
- Public issues are a "broadcast" mechanism

## Assessment Alignment

The assessment schema shows `author_name` as a string on comments. We use
`user_id` FK internally (proper relational design) and display `user.name`
as the author in the UI and API responses. The README Architecture & Decisions
section will explain this upgrade from the suggested schema.
