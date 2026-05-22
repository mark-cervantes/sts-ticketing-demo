# ADR-007: Issue Sharing & Visibility Model

**Status:** Accepted
**Date:** 2026-05-22
**Context:** Extensibility requirement — Google Docs-like sharing for future growth.

## Decision

### Visibility (per-issue)
Binary toggle: `private` or `public`

| Visibility | Default Access for Non-Shared Users |
| ---------- | ----------------------------------- |
| private    | No access                           |
| public     | View-only                           |

### Sharing (independent layer)
Explicit permission grant via email. Works identically on private and public issues.

```
issue_shares:
  issue_id   FK
  user_id    FK (resolved from email)
  permission enum: view | edit
```

### Behavior
- **Private issue:** only owner + shared users can see it. Sharing is required for others.
- **Public issue:** any logged-in user can view. Sharing grants edit permission or
  sends a notification ("hey, look at this").
- **Share mechanism is identical** regardless of visibility. No branching logic.

### Notification
Sharing always notifies the target user (in-app or however notifications are
implemented). On public issues, the share is primarily a "hey look at this"
notification + optional edit upgrade.

## Rationale

**No third visibility state** — "shared" is not a visibility level. It's an
independent permission layer. This avoids a 3-way state machine for access
resolution.

**Google Docs model** — familiar mental model. Private = only people I invite.
Public = anyone with the link (in our case, anyone logged in).

**Upsert on re-share** — sharing with someone already shared updates their
permission instead of erroring. Practical behavior.

## Access Resolution (ordered)
```
1. User is owner → full access
2. User has issue_share → access per permission level
3. Issue is public → view-only
4. Else → 403
```

## Consequences

- `issue_shares` table with unique(issue_id, user_id) constraint
- Share creation needs user lookup by email
- Notification system needed (even minimal — can be in-app only)
- Policy must check shares on every issue access
