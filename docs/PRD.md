# Product Requirements Document — HelpDesk Pro

> **Purpose:** Tech assessment demo — a production-grade ticketing system showcasing Laravel + Inertia.js + React mastery in a single-day build.
>
> **Assessment Philosophy:** Signal density over feature count. Every feature implemented demonstrates a distinct technical competency. Nothing half-built ships.

---

## 1. Product Vision

**HelpDesk Pro** is an internal IT support ticketing system. Customers submit tickets, agents resolve them, admins oversee SLA compliance and workload distribution.

The demo proves: *"I can architect, build, and ship a real-time, role-based SaaS application with clean patterns, not just CRUD."*

---

## 2. User Roles & Permissions

| Role | Can Do | Cannot Do |
|------|--------|-----------|
| **Customer** | Create tickets, view own tickets, add comments, see status updates | View others' tickets, assign agents, change priority |
| **Agent** | View assigned tickets, change status, comment, reassign to other agents, view dashboard | Delete tickets, manage users, change SLA rules |
| **Admin** | Everything + manage users, view analytics, configure SLA, assign/reassign any ticket | N/A (superuser) |

**Technical signal:** Laravel Policies + Gates, middleware groups, Inertia shared props for role-aware UI.

---

## 3. Core Features (Priority-Ordered)

### Tier 1 — Must Ship (Hours 1–5)

These form the working skeleton. Each one is a commit checkpoint.

#### F1: Authentication & Role-Based Access
- Laravel Breeze + Inertia React scaffold
- 3 roles seeded: admin, agent, customer
- Role middleware protecting routes
- Inertia shared data for `auth.user.role`
- **Demonstrates:** Auth scaffolding, RBAC, middleware, shared props

#### F2: Ticket CRUD with Status Workflow
- Create ticket (title, description, priority, category)
- Status machine: `open` → `in_progress` → `waiting_on_customer` → `resolved` → `closed`
- Only valid transitions allowed (state machine pattern)
- Ticket list with filters (status, priority, assigned agent)
- **Demonstrates:** Eloquent, Form Requests, state machine logic, Inertia forms

#### F3: Ticket Assignment & Agent Dashboard
- Admin/agent can assign tickets to agents
- Agent dashboard: my tickets grouped by status, counts, priority indicators
- Auto-assignment option (round-robin among agents)
- **Demonstrates:** Relationships, query scopes, dashboard composition, business logic in service layer

#### F4: Comments & Activity Log
- Threaded comments on tickets (customer + agent can comment)
- Automatic activity log entries: status changes, assignment changes, priority changes
- Timeline view on ticket detail
- **Demonstrates:** Polymorphic relationships, Observer pattern, audit trail design

### Tier 2 — High Impact (Hours 5–8)

These elevate the demo from "competent" to "impressive."

#### F5: Real-Time Notifications
- Laravel Broadcasting with Reverb (or Pusher)
- Agent gets browser notification on new ticket / assignment
- Customer sees live status change on their ticket
- Notification bell with unread count
- **Demonstrates:** WebSockets, Event Broadcasting, Laravel Echo + React integration

#### F6: SLA Timer & Visual Indicators
- Configurable SLA per priority (e.g., Critical: 1h response, 4h resolution)
- Visual countdown on ticket cards (green → yellow → red)
- "Breached" badge when SLA exceeded
- SLA summary on admin dashboard
- **Demonstrates:** Time-based business logic, computed attributes, visual feedback systems

#### F7: Admin Analytics Dashboard
- Ticket volume over time (chart)
- Average resolution time by priority
- Agent performance: tickets resolved, avg response time
- SLA compliance percentage
- **Demonstrates:** Aggregate queries, data visualization (Chart.js or Recharts), reporting patterns

### Tier 3 — Polish if Time Permits (Hours 8–10)

#### F8: Kanban Board View
- Drag-and-drop columns by status
- Real-time sync (other users see moves instantly)
- **Demonstrates:** Complex UI state, drag-and-drop libraries, optimistic updates

#### F9: Email Notifications
- Queue-based email on ticket creation, status change, assignment
- Markdown mail templates
- **Demonstrates:** Laravel Queues, Mail, job dispatching

#### F10: Search & Filters
- Full-text search across tickets
- Saved filter presets
- **Demonstrates:** Search implementation, UX polish

---

## 4. Data Model (Core Entities)

```
users
├── id, name, email, password, role (enum: admin/agent/customer)
├── timestamps

tickets
├── id, ticket_number (auto: HD-0001)
├── title, description (text)
├── status (enum: open/in_progress/waiting_on_customer/resolved/closed)
├── priority (enum: low/medium/high/critical)
├── category (enum: bug/feature_request/question/infrastructure)
├── user_id (FK → creator)
├── assigned_agent_id (FK → users, nullable)
├── resolved_at, closed_at (timestamps, nullable)
├── sla_response_deadline, sla_resolution_deadline (timestamps, nullable)
├── timestamps

comments
├── id, ticket_id (FK), user_id (FK)
├── body (text), is_internal (bool — agent-only notes)
├── timestamps

activity_logs
├── id, ticket_id (FK), user_id (FK)
├── action (string), description (text)
├── old_value, new_value (nullable)
├── timestamps
```

---

## 5. Non-Functional Requirements

| Concern | Approach |
|---------|----------|
| **Code Quality** | Form Requests for validation, Service classes for business logic, Policies for authorization |
| **Database** | Proper migrations with indexes on FK columns and status/priority, seeders with realistic data (50+ tickets, 5 agents, 10 customers) |
| **Testing** | Feature tests for critical paths: ticket creation, status transitions, authorization |
| **UI/UX** | Tailwind CSS, consistent component library, responsive layout, loading states |
| **Performance** | Eager loading (no N+1), pagination on lists, debounced search |
| **Security** | CSRF (Inertia default), mass-assignment protection, policy-based authorization, input sanitization |

---

## 6. What Makes This "Extraordinary"

| Signal | What Evaluator Sees |
|--------|-------------------|
| **State Machine** | Not just updating a string column — proper transition validation with guard logic |
| **Service Layer** | Business logic in `TicketService`, not controllers — shows architectural thinking |
| **Observer Pattern** | Activity logs auto-generated via Eloquent Observers — clean separation |
| **Real-Time** | Live updates prove WebSocket competency, not just HTTP request/response |
| **SLA Engine** | Time-based business rules show domain modeling maturity |
| **Seeder Quality** | Realistic data with proper relationships, not "test1, test2, test3" |
| **Git Discipline** | Clean, atomic commits with conventional messages — shows professional habits |
| **Test Coverage** | Even 5-10 focused tests show the *discipline*, not just the *ability* |

---

## 7. Out of Scope (Explicit)

- Multi-tenancy / organizations
- File attachments on tickets
- Custom ticket fields
- Reporting exports (PDF/CSV)
- i18n / localization
- OAuth / social login
- Rate limiting (beyond Laravel defaults)
- CI/CD pipeline

These are mentioned explicitly to show the candidate *considered and intentionally excluded them* — a sign of mature scoping.

---

## 8. Success Criteria

The demo is "done" when:
1. A customer can create a ticket and track its progress
2. An agent can see assigned work, update status, and comment
3. An admin can view a dashboard with real metrics and manage users
4. At least one real-time feature works live (notification or live status update)
5. SLA indicators are visible and accurate
6. The codebase is clean enough that an evaluator reading any random file sees intent, not haste
