# ADR-006: Dynamic DB-Backed Categories

**Status:** Accepted
**Date:** 2026-05-22
**Context:** Assessment says "free text or a small fixed list" for category.

## Decision

**DB-backed categories table** with seeded defaults and inline creation.

### Schema
```
categories:
  id         bigint PK
  name       string, unique
  slug       string, unique (auto-generated)
  created_at timestamp
```

### Seeded Defaults
billing, technical, account, general, bug, feature-request

### UX
- Issue create form: shows existing categories as selectable list
- Below the list: text input with placeholder "Add category..."
- Typing and submitting creates the category on the fly
- Each category in the list has an "x" button for explicit deletion
- Deletion blocked if category has issues (409 with count)

### API Filter
Category filter in URL uses **slug**: `?category=billing`
Internally resolved to category_id for the query.

## Rationale

**DB-backed vs. enum** — extensibility. User can add categories without code
changes. Assessment says "free text or a small fixed list" — we chose a middle
ground that's both: fixed list that's user-extensible.

**Slug for URLs** — cleaner API than `?category_id=3`. Matches the assessment's
example: `?category=billing`. Slugs are stable even if names get edited later.

**Inline creation** — minimal UX. No separate "manage categories" page needed.
The creation happens where you need it (issue form). Deletion happens in the
same context.

## Consequences

- Need a `categories` table and model
- Issue has `category_id` FK (not a string column)
- API responses include `category.name` and `category.slug`
- Deletion guard: can't delete category with existing issues
- Migration + seeder for defaults

## Assessment Compliance

Assessment shows `category` as a direct field on issues. We normalize it into
a proper table. The API response will include `category` as a string (the name)
in addition to `category_id` for convenience. Filter URL uses slug as shown in
the assessment example.
