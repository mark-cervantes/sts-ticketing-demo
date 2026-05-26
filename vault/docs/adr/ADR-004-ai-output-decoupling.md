# ADR-004: AI Output Decoupling — JSON Column Over Spread Columns

## Status
Accepted

## Date
2026-05-26

## Context
When `suggested_next_action` was removed from the UI, we discovered it was coupled into 10+ files: migration column, model fillable, LLM prompt, driver parsing, value object, job, resource, SSE controller, test connection handler, and issue controller. Removing one AI-generated field required touching the entire vertical stack.

This happened because each AI output field was stored as a **dedicated database column** on the `issues` table (`summary`, `suggested_next_action`, `suggested_next_ticket`). Every new field the LLM produces requires a migration, model change, resource change, job change, and SSE change.

## Decision
**AI-generated outputs should be stored as a single JSON column** (`ai_synthesis jsonb`) instead of individual columns. The prompt defines what keys the JSON contains. The frontend reads what it needs. Adding or removing a key changes only:
1. The prompt template
2. The frontend component that renders it

Nothing else in the stack needs to change.

### Structure
```sql
-- Instead of:
ALTER TABLE issues ADD summary TEXT;
ALTER TABLE issues ADD suggested_next_action TEXT;
ALTER TABLE issues ADD suggested_next_ticket TEXT;

-- Use:
ALTER TABLE issues ADD ai_synthesis JSONB DEFAULT '{}';
```

The `ai_synthesis` column holds whatever the LLM produces:
```json
{
    "summary": "Billing portal returns 502...\n\n• Carol Chen: ...",
    "suggested_next_ticket": "Add retry queue — ..."
}
```

### Access Pattern
```php
// Model accessor
$issue->ai_synthesis['summary']
$issue->ai_synthesis['suggested_next_ticket']

// Or typed accessor
$issue->aiSynthesis->summary
$issue->aiSynthesis->suggestedNextTicket
```

### Migration Path
A future task (10.x) consolidates the three existing columns into `ai_synthesis` and drops them.

## Consequences

### Positive
- Adding/removing AI output fields = prompt change + frontend change. Zero backend changes.
- No migrations for new AI features
- The JSON structure is self-documenting — `ai_synthesis` is the single source of truth
- Frontend can gracefully handle missing keys (old issues before a new key was added)

### Negative
- Can't index individual AI fields (rarely needed — AI outputs aren't queried)
- Slightly more complex accessor pattern vs `$issue->summary`
- Migration effort to consolidate existing columns

## Applies To
- All future AI-generated data on any model
- The prompt config → LLM → storage → display pipeline
- **Not** user-entered data or business logic fields — those remain proper columns

## Broader Principle
**Anything that comes from an external, evolving source (LLM, API, plugin) should be stored as structured JSON, not spread columns.** The interface boundary is the JSON schema, not the database schema. The database is a dumb store; the prompt and frontend are the smart consumers.
