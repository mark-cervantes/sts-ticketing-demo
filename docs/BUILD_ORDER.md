# Build Order — Sprint Execution Guide

> Concrete step-by-step implementation guide. Each step is a commit checkpoint.
> At any point, stopping produces a *working* demo — never a broken half-state.

---

## Philosophy: Layered Cake, Not Feature Flags

Every step builds on the last. The app is functional after Step 3. Each subsequent step makes it *more impressive*, but never breaks what exists.

```
Step 10: Polish + Tests ..................... icing
Step 9:  Analytics Dashboard ............... cherry
Step 8:  Real-Time Notifications ........... wow factor
Step 7:  SLA Engine ........................ domain depth
Step 6:  Agent Dashboard + Assignment ...... operational
Step 5:  Activity Timeline ................. audit trail
Step 4:  Comments System ................... interaction
Step 3:  Ticket CRUD + Status Workflow ..... core product  ← MINIMUM VIABLE DEMO
Step 2:  Auth + RBAC ....................... access control
Step 1:  Scaffold + Models + Seeders ....... foundation
```

---

## Step 1: Foundation (Hour 0–1)

### 1.1 Create Laravel Project
```bash
composer create-project laravel/laravel helpdesk-pro
cd helpdesk-pro
composer require laravel/breeze --dev
php artisan breeze:install react --typescript
npm install
```

### 1.2 Create Enums
- `app/Enums/UserRole.php` — admin, agent, customer
- `app/Enums/TicketStatus.php` — with `canTransitionTo()` + `allowedTransitions()`
- `app/Enums/TicketPriority.php` — low, medium, high, critical with `color()` + `label()`
- `app/Enums/TicketCategory.php` — bug, feature_request, question, infrastructure

### 1.3 Create Models + Migrations
- Modify `users` migration: add `role` enum column, default 'customer'
- Create `tickets` migration with all fields from PRD
- Create `comments` migration
- Create `activity_logs` migration
- Add relationships to all models
- Add indexes

### 1.4 Create Seeders
- `UserSeeder` — 1 admin, 5 agents, 10 customers with realistic names
- `TicketSeeder` — 50 tickets across all statuses/priorities, assigned to agents
- `CommentSeeder` — 2-4 comments per ticket
- `ActivityLogSeeder` — status change history for each ticket

**Commit:** `feat: scaffold project with models, migrations, enums, and seeders`

---

## Step 2: Auth + RBAC (Hour 1–2)

### 2.1 Role Middleware
```php
// app/Http/Middleware/RoleMiddleware.php
class RoleMiddleware
{
    public function handle($request, Closure $next, string ...$roles)
    {
        if (!in_array($request->user()->role->value, $roles)) {
            abort(403);
        }
        return $next($request);
    }
}
```

### 2.2 Shared Inertia Data
```php
// HandleInertiaRequests middleware
'auth' => [
    'user' => $request->user() ? [
        ...$request->user()->toArray(),
        'role' => $request->user()->role->value,
    ] : null,
],
```

### 2.3 Policies
- `TicketPolicy` — viewAny, view (own or assigned), create, update, delete
- `CommentPolicy` — create (on accessible tickets), delete (own only)

### 2.4 Route Structure
```php
// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('tickets', TicketController::class);
    Route::post('tickets/{ticket}/comments', [CommentController::class, 'store']);

    // Agent+ routes
    Route::middleware('role:admin,agent')->group(function () {
        Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign']);
    });

    // Admin-only routes
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::resource('users', Admin\UserController::class);
        Route::get('analytics', [Admin\AnalyticsController::class, 'index']);
    });
});
```

### 2.5 Navigation Component
- Role-aware sidebar: customers see "My Tickets", agents see "Dashboard + Assigned", admin sees everything
- Clean `AppLayout.tsx` wrapper

**Commit:** `feat: implement role-based access control with policies and middleware`

---

## Step 3: Ticket CRUD (Hour 2–4) — MINIMUM VIABLE DEMO

### 3.1 TicketService
- `create(User $user, array $data): Ticket`
- `updateStatus(Ticket $ticket, TicketStatus $newStatus): Ticket` — with transition validation
- `assignAgent(Ticket $ticket, User $agent): Ticket`

### 3.2 Controllers
- `TicketController@index` — filtered list, pagination, Inertia render
- `TicketController@create` — form with enums passed as props
- `TicketController@store` — via StoreTicketRequest
- `TicketController@show` — with comments, activity log eager-loaded
- `TicketController@update` — status change via service

### 3.3 React Pages
- `Tickets/Index.tsx` — Table with status/priority badges, filter dropdowns, pagination
- `Tickets/Create.tsx` — Form with validation errors from Inertia
- `Tickets/Show.tsx` — Full detail view (comments come in Step 4)

### 3.4 Shared Components
- `StatusBadge.tsx` — Color-coded pill based on status enum
- `PriorityBadge.tsx` — Priority indicator with icon
- `TicketCard.tsx` — Summary card for dashboard use

**Commit:** `feat: ticket CRUD with status workflow and filters`

---

## Steps 4–10: See TECHNICAL_PLAN.md Sections 4–7

Each follows the same pattern:
1. Backend (model/service/controller)
2. Frontend (page/components)
3. Wire up (routes, middleware, shared data)
4. Verify (seed, test, visual check)
5. Commit

---

## Quick Reference: Commands

```bash
# Setup
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed

# Development
php artisan serve          # Backend
npm run dev                # Vite + HMR
php artisan reverb:start   # WebSocket server (when ready)

# Testing
php artisan test           # Run Pest tests
php artisan test --filter=TicketStatusTransitionTest  # Specific test

# Reset demo state
php artisan migrate:fresh --seed
```
