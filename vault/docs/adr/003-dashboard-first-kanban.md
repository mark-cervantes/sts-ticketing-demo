# ADR-003: Dashboard-First Kanban UI

**Status:** Accepted
**Date:** 2026-05-22
**Context:** Assessment says UI is optional. We're building one. Need to decide the interaction model.

## Decision

The **dashboard IS the app**. A Kanban board is the primary and only main view.
All CRUD operations happen via modals and drag-and-drop from the dashboard.

### Layout
- Kanban columns = issue statuses (open, in_progress, resolved)
- Sidebar = filters (status, priority, category) + stats + "New" button
- Click card → right-side slide-over panel (detail, comments, sharing)
- Drag card between columns → status change (optimistic update)

### Secondary Routes
Full-page versions of issue detail exist at `/issues/{id}` for direct linking
and bookmarking, but normal usage stays on the dashboard.

### URL State
Opening a slide-over updates the URL (`/dashboard?issue=5`) so browser back
works and direct links open the right issue.

## Rationale

**Minimal navigation** — user preference is for practical, minimal UI where
everything is reachable from one surface. Kanban naturally maps to the issue
status workflow.

**Drag-and-drop for status** — more intuitive than dropdown/form. Shows
frontend competency. Optimistic updates show understanding of UX responsiveness.

**Slide-over instead of page navigation** — keeps context. User sees the board
while reviewing an issue. No mental context switch.

**URL state** — despite being modal-driven, every state is linkable. Shareable
URLs work. Browser history works. This is the difference between a demo and
a real app.

## Consequences

- Need a solid drag-and-drop library (vue-draggable-plus or SortableJS)
- Optimistic updates require rollback logic on server error
- Slide-over component needs to be responsive (full page on mobile)
- Filter state management in sidebar needs to be reactive

## Alternatives Considered

| Alternative        | Why Not                                                    |
| ------------------ | ---------------------------------------------------------- |
| Table list view    | Works but less visual, doesn't show status workflow        |
| Separate pages     | More navigation, more context switching, less impressive   |
| Kanban + table toggle | More features but violates minimal principle            |
