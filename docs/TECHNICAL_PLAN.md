# Technical Plan — HelpDesk Pro

> Architecture decisions, stack configuration, and build timeline for a 1-day tech assessment.

---

## 1. Stack & Tooling

| Layer | Choice | Rationale |
|-------|--------|-----------|
| **Backend** | Laravel 11 | Latest stable, built-in features reduce boilerplate |
| **Frontend Bridge** | Inertia.js 2.x | SPA feel without API boilerplate — the whole point of the assessment |
| **Frontend** | React 18 + TypeScript | Type safety shows discipline; React is the Inertia adapter specified |
| **Styling** | Tailwind CSS 3 | Rapid prototyping with consistent design; ships with Breeze |
| **Real-Time** | Laravel Reverb | First-party WebSocket server — no external service needed |
| **Charts** | Recharts | React-native charting, lightweight, composable |
| **Drag & Drop** | @dnd-kit/core | Modern, accessible, React-first DnD (for Kanban, Tier 3) |
| **Database** | SQLite (dev) / MySQL (prod-like) | SQLite for zero-setup demo; migrations are DB-agnostic |
| **Testing** | Pest PHP | Fluent syntax, faster to write, shows modern PHP testing |
| **Auth Scaffold** | Laravel Breeze (React + Inertia) | Gets auth + layout + Tailwind out of the box |

---

## 2. Architecture Patterns

### 2.1 Directory Structure (Laravel Conventions + Service Layer)

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── TicketController.php        # CRUD + assignment
│   │   ├── CommentController.php       # Ticket comments
│   │   ├── DashboardController.php     # Role-specific dashboards
│   │   └── Admin/
│   │       ├── UserController.php      # User management
│   │       └── AnalyticsController.php # Reports & charts data
│   ├── Requests/
│   │   ├── StoreTicketRequest.php
│   │   ├── UpdateTicketRequest.php
│   │   └── StoreCommentRequest.php
│   └── Middleware/
│       ├── RoleMiddleware.php          # role:admin,agent
│       └── EnsureTicketAccess.php      # Owner or assigned agent
├── Models/
│   ├── User.php
│   ├── Ticket.php
│   ├── Comment.php
│   └── ActivityLog.php
├── Services/
│   ├── TicketService.php               # State transitions, SLA calc, assignment
│   └── SlaService.php                  # SLA deadline computation, breach check
├── Policies/
│   ├── TicketPolicy.php
│   └── CommentPolicy.php
├── Observers/
│   └── TicketObserver.php              # Auto activity log on status/assignment change
├── Events/
│   ├── TicketCreated.php
│   ├── TicketStatusChanged.php
│   └── TicketAssigned.php
├── Listeners/
│   ├── NotifyAgentOfAssignment.php
│   └── CalculateSlaDeadlines.php
├── Enums/
│   ├── TicketStatus.php                # Backed enum with transition rules
│   ├── TicketPriority.php
│   ├── TicketCategory.php
│   └── UserRole.php
└── Notifications/
    ├── TicketAssignedNotification.php
    └── TicketStatusChangedNotification.php

resources/js/
├── Pages/
│   ├── Dashboard/
│   │   ├── CustomerDashboard.tsx       # My tickets overview
│   │   ├── AgentDashboard.tsx          # Assigned tickets, stats
│   │   └── AdminDashboard.tsx          # Analytics, SLA, team view
│   ├── Tickets/
│   │   ├── Index.tsx                   # List with filters
│   │   ├── Show.tsx                    # Detail + comments + timeline
│   │   ├── Create.tsx                  # New ticket form
│   │   └── Kanban.tsx                  # Board view (Tier 3)
│   └── Admin/
│       └── Users/
│           └── Index.tsx               # User management
├── Components/
│   ├── Tickets/
│   │   ├── TicketCard.tsx              # Reusable ticket summary card
│   │   ├── StatusBadge.tsx             # Color-coded status pill
│   │   ├── PriorityBadge.tsx           # Priority indicator
│   │   ├── SlaIndicator.tsx            # Countdown timer component
│   │   ├── ActivityTimeline.tsx        # Ticket event history
│   │   └── CommentThread.tsx           # Comments list + form
│   ├── Dashboard/
│   │   ├── StatsCard.tsx               # Metric card (count + trend)
│   │   └── TicketChart.tsx             # Recharts wrapper
│   └── Layout/
│       ├── AppLayout.tsx               # Main layout with nav
│       ├── NotificationBell.tsx        # Real-time notification UI
│       └── Sidebar.tsx                 # Navigation sidebar
├── Hooks/
│   ├── useTicketFilters.ts             # Filter state management
│   └── useEcho.ts                      # Laravel Echo React hook
├── Types/
│   └── index.ts                        # Shared TypeScript types
└── Lib/
    └── helpers.ts                      # Formatting, SLA calculations
```

### 2.2 Key Design Decisions

#### State Machine for Ticket Status (Enum-Based)

```php
// app/Enums/TicketStatus.php
enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingOnCustomer = 'waiting_on_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions());
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Closed],
            self::InProgress => [self::WaitingOnCustomer, self::Resolved, self::Closed],
            self::WaitingOnCustomer => [self::InProgress, self::Closed],
            self::Resolved => [self::Closed, self::InProgress], // reopen
            self::Closed => [], // terminal
        };
    }
}
```

**Why:** Evaluator sees domain modeling, not stringly-typed logic. The transition rules are self-documenting.

#### Service Layer Pattern

```php
// Controllers stay thin:
public function update(UpdateTicketRequest $request, Ticket $ticket)
{
    $this->ticketService->updateStatus($ticket, TicketStatus::from($request->status));
    return back();
}

// Business logic lives in service:
class TicketService
{
    public function updateStatus(Ticket $ticket, TicketStatus $newStatus): Ticket
    {
        if (!$ticket->status->canTransitionTo($newStatus)) {
            throw new InvalidStatusTransitionException($ticket->status, $newStatus);
        }
        // ... update, fire event, return
    }
}
```

#### SLA Computation

```php
class SlaService
{
    private const RESPONSE_HOURS = [
        'critical' => 1,
        'high'     => 4,
        'medium'   => 8,
        'low'      => 24,
    ];

    private const RESOLUTION_HOURS = [
        'critical' => 4,
        'high'     => 8,
        'medium'   => 24,
        'low'      => 72,
    ];

    public function computeDeadlines(Ticket $ticket): void { /* ... */ }
    public function isBreached(Ticket $ticket): bool { /* ... */ }
    public function remainingTime(Ticket $ticket): ?CarbonInterval { /* ... */ }
}
```

---

## 3. Database Design Notes

### Indexes Strategy
```
tickets: index on [status], [priority], [assigned_agent_id], [user_id]
         composite: [status, assigned_agent_id] (agent dashboard)
         composite: [status, priority] (admin triage)
comments: index on [ticket_id], [user_id]
activity_logs: index on [ticket_id]
```

### Ticket Number Generation
Auto-incrementing `HD-XXXX` format via a custom Eloquent boot trait:
```php
protected static function booted()
{
    static::creating(function (Ticket $ticket) {
        $latest = static::max('id') ?? 0;
        $ticket->ticket_number = 'HD-' . str_pad($latest + 1, 4, '0', STR_PAD_LEFT);
    });
}
```

---

## 4. Build Timeline (10-Hour Sprint)

| Hour | Phase | Deliverable | Commit Message |
|------|-------|-------------|----------------|
| 0–0.5 | **Bootstrap** | Laravel + Breeze + Inertia + React scaffold | `feat: initialize laravel project with breeze inertia-react` |
| 0.5–1 | **Models & Migrations** | All models, migrations, enums, relationships | `feat: add ticket, comment, activity_log models with enums` |
| 1–1.5 | **Seeders** | Realistic seed data: users, tickets, comments | `feat: add database seeders with realistic demo data` |
| 1.5–2.5 | **Auth & RBAC** | Role middleware, policies, role-aware navigation | `feat: implement role-based access control` |
| 2.5–4 | **Ticket CRUD** | Create, list, show, update status, filters | `feat: ticket CRUD with status workflow and filters` |
| 4–5 | **Comments & Activity** | Comment system, observer-based activity log | `feat: add comments and automatic activity logging` |
| 5–6 | **Assignment & Dashboard** | Agent assignment, per-role dashboards | `feat: ticket assignment and role-specific dashboards` |
| 6–7 | **SLA Engine** | SLA computation, visual indicators, breach detection | `feat: SLA timer engine with visual indicators` |
| 7–8 | **Real-Time** | Broadcasting setup, live notifications, Echo integration | `feat: real-time notifications with Laravel Reverb` |
| 8–9 | **Analytics** | Admin dashboard with charts and metrics | `feat: admin analytics dashboard with charts` |
| 9–10 | **Polish** | Kanban (if time), UI polish, test suite, final seed verification | `feat: polish UI and add critical-path tests` |

### Checkpoint Discipline
- Every phase above ends with a working commit
- `php artisan test` must pass at every checkpoint
- Seed + migrate must produce a usable demo state at any checkpoint

---

## 5. Testing Strategy (Targeted, Not Exhaustive)

Focus tests on the code paths that show engineering discipline:

```
tests/Feature/
├── TicketCreationTest.php          # Customer can create, fields validated
├── TicketStatusTransitionTest.php  # Valid transitions pass, invalid throw
├── TicketAuthorizationTest.php     # Customer can't see other's tickets
├── AssignmentTest.php              # Only admin/agent can assign
└── SlaComputationTest.php          # Deadlines computed correctly
```

~15-20 test cases. Each one demonstrates a distinct concern: validation, authorization, state machine, business logic.

---

## 6. Demo Script (For Evaluator Walkthrough)

1. **Login as Customer** → Create a ticket with "Critical" priority → See SLA countdown start
2. **Login as Agent** → See notification badge → Open assigned ticket → Change status to "In Progress" → Add internal comment
3. **Login as Admin** → View analytics dashboard → See SLA compliance chart → Reassign a ticket → View activity timeline
4. **Open 2 browser tabs** → Change ticket status in one → See update in the other (real-time proof)
5. **Show codebase** → Point out: Enum state machine, Service layer, Observer pattern, TypeScript types, Pest tests

---

## 7. Risk Mitigations

| Risk | Mitigation |
|------|-----------|
| Reverb setup takes too long | Fallback: Polling every 5s + note that WebSocket version exists but needs env config |
| TypeScript slows development | Strict types on shared interfaces only; components can use `any` escape hatch temporarily |
| Charting library issues | Fallback: Server-rendered stats cards without charts (still impressive as raw metrics) |
| Time runs out at Tier 2 | Tier 1 alone is a complete, shippable product — Tier 2 is enhancement, not completion |
| Complex seeder bugs | Seed in order: users → tickets → comments → activity_logs with explicit IDs |
