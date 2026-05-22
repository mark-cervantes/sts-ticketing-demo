# ADR-005: Priority and Deadline as Independent Dimensions

**Status:** Accepted
**Date:** 2026-05-22
**Context:** Assessment requires `needs_attention` flag for "high-priority or overdue issues."

## Decision

**Priority and deadline are independent fields.** They are not derived from
each other.

- `priority` = importance/urgency signal (low, medium, high, critical)
- `deadline_at` = time-bound commitment (user-set, optional, nullable)

### needs_attention Computation
```
needs_attention = (
    priority IN ('high', 'critical')
    OR (deadline_at IS NOT NULL AND deadline_at <= NOW() + threshold)
)
```

Both signals are ORed. Either alone triggers the flag.

### Default Behavior
- A high-priority issue without a deadline → needs_attention = true (priority signal)
- A low-priority issue with a deadline in 30 min → needs_attention = true (deadline signal)
- A low-priority issue with no deadline → needs_attention = false

### Threshold
Configurable in `config/issues.php`:
```php
'attention_threshold_minutes' => env('ATTENTION_THRESHOLD_MINUTES', 60),
```

## Rationale

Conflating priority with deadline is a common domain modeling mistake:
- A critical bug may have no specific deadline (fix ASAP = always needs attention)
- A low-priority documentation task may be due tomorrow (deadline-driven attention)
- A complex high-priority task may have a generous deadline (2 weeks) but still
  needs attention due to importance

Keeping them separate allows the system to correctly flag both urgency-driven
and deadline-driven issues without conflation.

## Consequences

- Two fields instead of one computed deadline
- Scheduler must recompute: priority is instant, but deadline threshold changes over time
- UI shows both: priority badge + deadline countdown (if set)
- Filter sidebar can filter by both independently

## Assessment Compliance

The assessment says: "Set needs_attention = true for high-priority issues."
We satisfy this AND extend it with deadline awareness. The README will document
exactly how and when the flag is recomputed, as the assessment requires.
