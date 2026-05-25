# Course Part 2 — Laravel Architecture in Practice

> **Purpose:** A section-by-section deep study of this project's architecture and
> design patterns, grounded in the real files of this repo. Unlike
> `technical-assessment-course.md` (which teaches you to *defend* the project),
> this course teaches you to *read and reason about* a production-grade Laravel
> codebase as a learner.
>
> **Companion doc:** `vault/docs/technical-assessment-course.md` covers the
> elevator pitch and interview Q&A. This course covers the *how* and *why* at
> code level.

---

## How to Use This Course

1. **Read each section header first** — the section map below tells you what
   each section covers and its dependency on earlier sections.
2. **Open the referenced files alongside this doc.** Every claim is pinned to
   an exact file/line you can verify.
3. **Do the exercises at the end of each section** before moving to the next.
   They are not optional: the architecture only clicks when you trace it yourself.
4. **Know the three-tier taxonomy** used throughout:

   | Tier | What it means |
   |------|---------------|
   | **Laravel-native** | Provided by the framework, used with minimal custom code |
   | **Package-provided** | Comes from a Composer or npm package (e.g., Breeze, Horizon, shadcn-vue) |
   | **Custom app architecture** | Code we wrote specifically for this domain |

5. Iterate with a tutor or reviewer one section at a time; do not try to read
   everything in one sitting.

### Reference Frame for NestJS Developers

If your strongest backend reference is **NestJS**, use this translation layer
while reading the project:

| Laravel concept | Rough NestJS equivalent | Important difference |
|---|---|---|
| `routes/*.php` | controller route decorators / module route wiring | Laravel routing is more file-driven and less module-centric |
| Controller | Controller | Similar orchestration role |
| Form Request | DTO + `class-validator` pipe | Laravel validation is usually a dedicated request class, not decorators on DTOs |
| Policy | Guard + ability/permission layer | Laravel policies are model-oriented and usually called inside controllers |
| Service class | Provider / service | Similar role, but Laravel does not force provider/module structure for every service |
| Eloquent Model | TypeORM/Prisma model entity concept | Eloquent is Active Record, not repository-first |
| Provider | Module/provider registration | Laravel providers are app bootstrap units, not feature modules |
| Job / Queue | Bull queue processor / background worker | Laravel jobs are framework-native and serializable by convention |
| Resource | response mapper / serializer / presenter | Explicit API response shaping layer |
| Facade | static helper over DI token | More common and idiomatic in Laravel than in Nest |
| Middleware | middleware | Similar concept |
| Event | event emitter event | Similar concept |

The biggest mindset shift is this:

> **NestJS pushes you to think in modules. Laravel pushes you to think in request lifecycle + domain boundaries.**

So while Nest often starts with `IssueModule`, Laravel often starts with:

- route
- request validation
- policy
- controller
- service
- model

Same architectural concerns, different default organizing principle.

---

## Recommended Study Order

| # | Section | Prerequisite | Time |
|---|---------|-------------|------|
| 1 | [Project Structure Tour](#1-project-structure-tour) | none | 20 min |
| 2 | [The Request Lifecycle](#2-the-request-lifecycle) | §1 | 25 min |
| 3 | [Validation — Form Requests](#3-validation--form-requests) | §2 | 20 min |
| 4 | [Authorization — Policies](#4-authorization--policies) | §2 | 25 min |
| 5 | [The Eloquent Model Layer](#5-the-eloquent-model-layer) | §2 | 30 min |
| 6 | [The Service Layer](#6-the-service-layer) | §4, §5 | 25 min |
| 7 | [Enums as Domain Language](#7-enums-as-domain-language) | §5 | 15 min |
| 8 | [API Resources](#8-api-resources) | §5, §6 | 20 min |
| 9 | [The AI Summary Pipeline](#9-the-ai-summary-pipeline) | §6 | 35 min |
| 10 | [Routes and Middleware](#10-routes-and-middleware) | §2 | 20 min |
| 11 | [Design Patterns Audit](#11-design-patterns-audit) | §1–10 | 30 min |
| 12 | [Best Practices Audit](#12-best-practices-audit) | §1–10 | 20 min |
| 13 | [What's Framework vs. Custom](#13-whats-framework-vs-custom) | §1–10 | 15 min |

*Future sections to add in subsequent iterations:*
- §14 Frontend Architecture (Inertia, Vue composables, TypeScript contracts)
- §15 Real-time with SSE (IssueSseController, useSummaryStream)
- §16 Queue, Jobs, and Horizon
- §17 Testing Architecture
- §18 Production Deployment and Docker

---

## Section Map (at a glance)

```
app/
├── Contracts/          §9   (interface for the AI seam)
├── Enums/              §7   (domain language)
├── Events/             §9   (SummaryCompleted)
├── Exceptions/         §9   (SummaryGenerationException)
├── Facades/            §9   (Summary facade)
├── Http/
│   ├── Controllers/    §2   (thin orchestration)
│   ├── Requests/       §3   (all validation)
│   └── Resources/      §8   (API response shaping)
├── Jobs/               §9   (async AI summary)
├── Models/             §5   (Eloquent + domain events)
├── Policies/           §4   (all authorization)
├── Providers/          §9   (service registration)
└── Services/           §6, §9 (business logic + AI drivers)

routes/
├── api.php             §10  (all API routes)
└── web.php             §10  (Inertia pages + auth)
```

---

## §1 Project Structure Tour

### What the directory layout tells a Laravel reader

Open `README.md` at "Project Layout" and this file side by side.

A Laravel app's `app/` directory is deliberately organized by *role*, not by
feature. That is a key convention difference from frameworks like NestJS or
Spring that organize by module/domain first.

```
app/
├── Console/       # Artisan commands (custom CLI tasks)
├── Contracts/     # PHP interfaces ("seams" between subsystems)
├── Enums/         # PHP 8.1+ enums for domain values
├── Events/        # Event objects (fired, not handled here)
├── Exceptions/    # Custom exception types
├── Facades/       # Static-style proxies into the service container
├── Http/          # Everything HTTP: controllers, requests, resources, middleware
├── Jobs/          # Queued work (async tasks)
├── Models/        # Eloquent models (Active Record)
├── Policies/      # Authorization rules
├── Providers/     # Service registration (application bootstrap)
└── Services/      # Custom business logic (not in controllers or models)
```

#### What is framework-native here?

- `Http/`, `Models/`, `Providers/`, `Jobs/`, `Events/` — these are
  **standard Laravel conventions**. The framework generates them with
  `artisan make:` commands and expects them in these locations.
- `Console/` — also framework-native. Laravel's scheduler calls commands
  registered here.

#### What is custom to this project?

- `Contracts/` — we added this ourselves to hold `SummaryGeneratorInterface`.
  Laravel does not require this folder; it is a deliberate architectural choice
  to create an explicit interface as a *seam* between the app and the AI
  subsystem. (§9 covers why.)
- `Enums/` — PHP 8.1+ enums, not a Laravel convention per se (Laravel
  understands them natively), but organizing them in their own folder is a
  team decision.
- `Services/` — a very common Laravel pattern, but not auto-generated by the
  framework. We put business logic here that is too complex for a controller but
  does not belong in a model.
- `Facades/` — the framework provides `Illuminate\Support\Facades\Facade`, but
  *our* `App\Facades\Summary` is a custom facade we wrote. (§9)

#### The `vault/` directory

Not PHP code — this is project documentation:

```
vault/
├── SPEC.md            # What to build (product spec)
├── docs/
│   ├── SRS.md         # How to build it (software requirements)
│   └── adr/           # Why decisions were made (10 ADRs)
└── sprint/            # Task management artifacts
```

This is a **living documentation pattern**: all architectural decisions are
recorded in Architecture Decision Records (ADRs) so future readers (and AI
agents) know the *why* behind every choice. See
`vault/docs/adr/001-stack-selection.md` for an example.

### Reading exercise for §1

1. Run `ls app/Http/` and note the three subdirectories: `Controllers`,
   `Requests`, `Resources`. These three map cleanly to: "receive", "validate",
   "respond".
2. Open `vault/docs/adr/003-dashboard-first-kanban.md`. Read the "Decision" and
   "Rationale" sections. Notice how an ADR captures the *rejected alternatives*
   alongside the chosen approach — that is the key value of an ADR.
3. Count the files in `app/Services/`. Note that there is a nested
   `Services/Summary/` subdirectory. This nesting is intentional — it groups the
   AI subsystem as a coherent unit within the service layer.

---

## §2 The Request Lifecycle

### The path a request walks

Every HTTP request in this app walks the same pipeline. Understanding it lets
you trace any bug or feature to the right file.

If you come from **NestJS**, translate the request path like this:

```text
NestJS mental model:
middleware -> guard -> validation pipe / DTO -> controller -> service -> repository -> serializer

This Laravel project:
middleware -> Form Request -> Policy -> controller -> service -> Eloquent model -> Resource
```

That is the single most important bridge between the two frameworks. Laravel
distributes responsibilities differently, but the architectural concerns are the
same.

```
Browser
  │
  ▼
nginx (Sail container, port 80)
  │
  ▼
PHP-FPM → public/index.php        ← framework bootstrap (Laravel-native)
  │
  ▼
Illuminate\Http\Kernel            ← the HTTP kernel (Laravel-native)
  │  Global middleware stack
  ▼
Route::middleware('auth')         ← Laravel's auth middleware
  │
  ▼
StoreIssueRequest::rules()        ← our Form Request (custom, §3)
  │  422 if validation fails
  ▼
IssuePolicy::create()             ← our Policy (custom, §4)
  │  403 if unauthorized
  ▼
IssueController::store()          ← our thin controller (custom)
  │
  ▼
IssueService::create()            ← our service (custom, §6)
  │
  ▼
Issue::create()                   ← Eloquent (Laravel-native)
  │  + saving event fires (custom, §5)
  ▼
dispatch(GenerateSummaryJob)      ← Laravel queue dispatch (Laravel-native)
  │
  ▼
IssueResource::toArray()          ← our resource transformer (custom, §8)
  │
  ▼
JSON response → browser
```

### Web route path vs API route path

This project has **two request styles**.

#### 1. Web/Inertia page requests

Example from `routes/web.php`:

```php
Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');
```

This does **not** return raw JSON. It returns an Inertia page definition that
mounts a Vue page component in the browser.

**NestJS comparison:** think of this more like server-delivered app shell
rendering than a pure REST endpoint. It is closer to returning a web page with
state than exposing a JSON API controller.

#### 2. API requests

Example from `routes/api.php`:

```php
Route::middleware('auth')->apiResource('issues', IssueController::class);
```

This is the traditional JSON API path. The frontend uses these endpoints for
create/update/delete/fetch interactions after the page has loaded.

**NestJS comparison:** this is the closest equivalent to your normal Nest
controller methods (`@Post()`, `@Patch(':id')`, etc.).

### Start at the route, not at the controller

Laravel learners often open a controller first. That is too late.

The correct question is:

> **Which route matched, under which middleware, and with which parameter binding?**

For example, `POST /api/issues` is declared indirectly through
`Route::apiResource('issues', IssueController::class)`. That single line creates
multiple route-method mappings, including:

- `GET /api/issues` -> `index`
- `POST /api/issues` -> `store`
- `GET /api/issues/{issue}` -> `show`
- `PATCH /api/issues/{issue}` -> `update`
- `DELETE /api/issues/{issue}` -> `destroy`

**Why this matters:** Laravel codebases often look smaller than NestJS codebases
because route declarations can be more compact and more conventional. One line
may imply five endpoints.

### Middleware comes before your business logic

In `bootstrap/app.php` this project configures middleware like this:

```php
$middleware->web(append: [
    HandleInertiaRequests::class,
    AddLinkHeadersForPreloadedAssets::class,
]);

$middleware->statefulApi();
```

And in route files it uses route-level middleware such as:

```php
Route::middleware('auth')->apiResource('issues', IssueController::class);
```

Two important lessons here:

1. **Authentication is not controller code.** The route declares that the user
   must already be authenticated before controller logic runs.
2. **This app uses same-origin, stateful browser auth.** `statefulApi()` is a
   strong signal that this is not a detached JWT API architecture.

**NestJS comparison:** middleware + auth guard usually fill this role. Laravel
just expresses it with route middleware and app bootstrap configuration.

### Route model binding removes boilerplate

Look at methods like:

```php
public function show(Issue $issue): IssueResource
public function update(UpdateIssueRequest $request, Issue $issue): IssueResource
public function destroy(Issue $issue): Response
```

Laravel automatically converts `{issue}` from the URL into an `Issue` model
instance before the controller body executes.

**NestJS comparison:** in many Nest projects you would manually:

1. read `id` from `@Param()`
2. pass it to a service
3. fetch the entity
4. throw a 404 yourself

Laravel's implicit binding collapses that ceremony into the method signature.

**Architectural effect:** controller code reads closer to domain language and
further from transport plumbing.

### The exact create-issue flow in this project

Let us trace the most important path: creating an issue.

#### Step 1 — Browser submits to `POST /api/issues`

The Vue frontend sends the request after the user fills the create-issue UI.

#### Step 2 — Route match

`routes/api.php` maps that request to `IssueController::store()` through
`apiResource()`.

#### Step 3 — Auth middleware

The `auth` middleware ensures there is an authenticated user attached to the
request. If not, the request never reaches the controller.

#### Step 4 — Form Request validation

Laravel resolves `StoreIssueRequest` before entering the controller body.
Validation failures return **422 Unprocessable Entity** automatically.

#### Step 5 — Policy authorization

Inside the controller, this line runs:

```php
$this->authorize('create', Issue::class);
```

That calls `IssuePolicy::create()` and returns **403 Forbidden** if the user is
not allowed.

#### Step 6 — Controller orchestration

The controller delegates immediately:

```php
$issue = $this->service->create($request->user(), $request->validated());
```

The controller does not implement business rules directly.

#### Step 7 — Service owns the write workflow

`IssueService::create()`:

- sets defaults like `status=open`
- creates the Eloquent model
- dispatches `GenerateSummaryJob`
- loads relations for response shaping

#### Step 8 — Model event computes derived state

`Issue::booted()` hooks `saving`, which computes `needs_attention` before the row
is persisted.

#### Step 9 — Async side effect is queued

The summary generation does not happen inline; the service dispatches a queued
job. That means the request stays fast even when the AI system is slow.

#### Step 10 — Resource shapes the JSON response

The controller wraps the model in `IssueResource`, so the response is an API
contract, not raw model serialization.

### Why the controller is intentionally thin

### The thin controller pattern

Open `app/Http/Controllers/IssueController.php`.

Look at `store()`:

```php
public function store(StoreIssueRequest $request): JsonResponse
{
    $this->authorize('create', Issue::class);

    $issue = $this->service->create($request->user(), $request->validated());

    return (new IssueResource($issue))
        ->response()
        ->setStatusCode(201);
}
```

**Every line has a single job:**
1. `StoreIssueRequest $request` — type-hinted Form Request runs validation
   *before* this method body even executes (Laravel-native).
2. `$this->authorize('create', Issue::class)` — delegates to `IssuePolicy`
   (Laravel-native mechanism, custom policy logic).
3. `$this->service->create(...)` — delegates all business logic to the service
   (custom).
4. `new IssueResource(...)` — shapes the response (custom, §8).

What is **not** in the controller: validation rules, authorization logic, SQL
queries, business rules, conditional branches about domain state. Those all live
in their dedicated layers.

**Why this matters for learners:** When you see a fat controller (validation +
auth + business logic all in one method), it is a code smell in Laravel. This
project uses the opposite pattern deliberately.

**NestJS comparison:** if you prefer clean Nest controllers where the transport
layer delegates to a service, you should recognize exactly the same instinct
here.

### Constructor injection

Note the constructor:

```php
public function __construct(
    private readonly IssueService $service,
    private readonly TriageService $triageService,
) {}
```

This is **PHP 8 constructor property promotion** (`private readonly`) combined
with **Laravel's automatic dependency injection**. When Laravel creates the
controller, it reads the constructor signature, resolves both services from its
container, and injects them automatically. You never call `new IssueController`
yourself.

**Laravel-native** feature: the service container handles resolution.
**Framework-agnostic convention**: constructor injection (not a Laravel
invention; this is SOLID's Dependency Inversion Principle).

### Why the service layer exists in this request path

Some Laravel apps skip services entirely and let controllers talk directly to
models. That can work for trivial CRUD, but this project already has enough
business rules that a service layer improves clarity.

In `IssueService::create()` and `IssueService::update()`, the service owns:

- default status and summary state assignment
- optimistic locking checks
- whether summary regeneration should be requeued
- what side effects happen after persistence

This is the same decision you would make in NestJS when you decide a controller
should not directly call the ORM.

### Where 422, 403, 404, and 409 come from

As a learner, you should know which layer emits which class of error:

| Status | Source in this project | Meaning |
|---|---|---|
| **422** | Form Request validation | Input shape/value invalid |
| **403** | Policy authorization | User is authenticated but not allowed |
| **404** | Route model binding | `{issue}` not found in DB |
| **409** | `IssueService::update()` | Optimistic lock conflict |

This is architecturally beautiful because each layer owns *its own failure mode*.
The controller does not manually manufacture all of them.

### Why this request path is good Laravel architecture

This request lifecycle demonstrates several good practices at once:

1. **Transport concerns are separated from business rules.**
2. **Validation is declarative and reusable.**
3. **Authorization is policy-driven, not ad hoc.**
4. **Persistence is handled through Eloquent conventions.**
5. **Slow side effects are async.**
6. **Responses are explicitly shaped.**

That is why this app feels structured instead of "controller soup."

### Reading exercise for §2

1. Open `routes/api.php` and manually list every endpoint generated by
   `apiResource('issues', IssueController::class)`.
2. Open `IssueController::update()` and `IssueService::update()` side by side.
   Mark which lines are transport/orchestration and which lines are business
   workflow.
3. In your own words, explain why **409 Conflict** is a service-layer concern in
   this project rather than a controller concern.
4. Translate the create-issue lifecycle into NestJS vocabulary:

   ```text
   route -> middleware -> validation -> authz -> controller -> service -> ORM -> response mapper
   ```

5. Optional: use `php artisan route:list --path=issues` inside Sail and compare
   the generated routes to what you inferred from `apiResource()`.

1. Open `IssueController::update()`. Trace the same layered path manually:
   which class handles validation? Which handles authorization? Where does the
   actual database write happen?
2. Find `IssueController::show()`. Why does it call `$issue->load(...)` but
   `store()` does not? Think about what data the detail view needs vs. what the
   creation response needs.
3. Open `routes/api.php`. Find the line:
   ```php
   Route::middleware('auth')->apiResource('issues', IssueController::class);
   ```
   The `apiResource` helper is Laravel-native — it registers the 5 standard
   RESTful routes (index, store, show, update, destroy) in one line. List those
   5 routes and map each to an `IssueController` method.

---

## §3 Validation — Form Requests

### NestJS translation first

If you think in NestJS, a Laravel Form Request is closest to this bundle of
responsibilities:

- DTO shape definition
- validation pipe
- optional pre-validation transform/normalization step

The difference is that Laravel does **not** usually put validation decorators on
the DTO/class itself. Instead, it creates a dedicated request object that the
framework resolves before your controller method body runs.

That means this:

```php
public function store(StoreIssueRequest $request)
```

is not just a typed parameter. It is a contract saying:

> "Laravel, please normalize this input, validate it, and only then enter the controller."

### Why not validate in the controller?

The naive approach is:

```php
// BAD — validation in controller (do not do this)
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'title' => ['required', 'string', 'max:255'],
        // ... more rules
    ]);
}
```

This works but has problems:
- Validation rules are buried inside controller methods, making them hard to
  find and audit.
- The same rules cannot be reused across multiple endpoints.
- The controller method grows as rules grow.
- Testing validation in isolation requires booting the whole controller.

It also makes it harder to answer architectural questions like:

- what exactly is accepted on create vs update?
- where is input trimmed?
- which fields are optional vs nullable?
- which errors are framework-native vs business-rule errors?

In a disciplined Laravel app, validation should be one of the easiest things to
find. This project follows that rule.

### The Form Request pattern

Open `app/Http/Requests/StoreIssueRequest.php`.

```php
class StoreIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;  // authorization is handled by IssuePolicy, not here
    }

    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority'    => ['required', 'string', Rule::enum(Priority::class)],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'visibility'  => ['sometimes', 'nullable', 'string', Rule::enum(Visibility::class)],
            'deadline_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            $this->merge(['title' => trim((string) $this->input('title'))]);
        }
        // ...
    }
}
```

The critical architectural point is that the request class owns **input
correctness**, not **business permission** and not **workflow side effects**.

That separation is the whole reason Form Requests are valuable.

**Three things to notice:**

1. **`Rule::enum(Priority::class)`** — Laravel 9+ ships with a rule that
   validates a value against a PHP enum. No custom code required; it reads the
   enum's `cases()` and checks membership. This is **Laravel-native**.

2. **`exists:categories,id`** — validates that `category_id` actually exists in
   the `categories` table. This is a **database-aware rule** built into Laravel's
   validation system. No manual query needed.

3. **`prepareForValidation()`** — runs *before* rules fire. This is where we
   trim whitespace from text fields so "  hello  " is treated as "hello". This
   is a **Laravel-native hook**, invoked automatically when the Form Request is
   resolved.

### What `rules()` is really doing

When Laravel resolves a Form Request, it calls `rules()` and builds a validator
 from the returned array. Each key maps to an input field; each value is a list
 of constraints.

Example:

```php
'priority' => ['required', 'string', Rule::enum(Priority::class)],
```

This means:

1. the field must exist (`required`)
2. it must be a string (`string`)
3. it must map to one of the `Priority` enum cases (`Rule::enum(...)`)

That is already more expressive than many manual validator implementations. It
is also **declarative** — you read the rule list and know the contract.

### `sometimes` vs `nullable` — extremely important small detail

This project uses combinations like:

```php
'visibility' => ['sometimes', 'nullable', 'string', Rule::enum(Visibility::class)],
```

These words mean different things:

- **`sometimes`** = validate this field only if it is present in the payload
- **`nullable`** = if it is present, `null` is allowed

That distinction matters a lot in update endpoints.

For example, in `UpdateIssueRequest`:

```php
'title' => ['sometimes', 'nullable', 'string', 'max:255'],
```

This allows three different client behaviors:

1. omit `title` entirely -> do not change it
2. include `title` with a string -> validate and update it
3. include `title` with `null` -> accepted by validation, but later service
   logic may decide how nulls are handled

**NestJS comparison:** this is similar to the distinction between optional DTO
properties and nullable values in a PATCH route.

### `prepareForValidation()` is an architectural boundary, not just a helper

This project trims strings before validation:

```php
if ($this->has('title')) {
    $this->merge(['title' => trim((string) $this->input('title'))]);
}
```

Why is this good?

Because it makes validation rules operate on **normalized input** instead of raw
browser payloads.

That has several benefits:

- whitespace-only user mistakes get corrected early
- service layer can trust the incoming data more
- tests become simpler because normalization happens in one place
- controllers do not repeat trimming logic

This is the Laravel equivalent of a transform layer before your Nest validation
pipe or DTO processing.

### Validation knows the database when appropriate

This rule appears in `StoreIssueRequest`:

```php
'category_id' => ['required', 'integer', 'exists:categories,id'],
```

This is worth pausing on.

Laravel validation is not limited to type/shape checks. It can also verify
referential correctness against the database.

That means the request layer can reject obviously invalid foreign keys before
business logic runs.

**Why that is good architecture:**

- the service does not need to manually query for category existence just to
  produce a user-facing validation error
- the API contract remains explicit
- validation errors stay 422 instead of becoming ad hoc controller logic

### Why `authorize()` returns `true` here

Notice again:

```php
public function authorize(): bool
{
    return true;
}
```

A Laravel learner often asks: if Form Requests support authorization, why is it
always true here?

Because this project intentionally separates two concerns:

- **Form Request** = “Is the payload structurally valid?”
- **Policy** = “May this user do this action?”

That split is especially useful in this app because authorization is not simple.
Issue access depends on:

- ownership
- visibility
- issue shares
- permission ladder (`view -> comment -> edit`)

That belongs in `IssuePolicy`, not in a request class.

**NestJS comparison:** if you put both DTO validation and access-control logic
inside one pipe/decorator layer, the concerns blur. This project chooses the
cleaner separation.

### Store vs Update requests teach you endpoint semantics

Compare:

- `StoreIssueRequest.php`
- `UpdateIssueRequest.php`

This is one of the best educational seams in the project.

#### Create (`StoreIssueRequest`)

Uses rules like:

- `required`
- `exists:...`
- `after:now`

Because a create endpoint defines the full minimum valid object.

#### Update (`UpdateIssueRequest`)

Uses rules like:

- `updated_at` is required
- most business fields are `sometimes|nullable`

Because update endpoints have different semantics:

- they patch an existing object
- they support partial changes
- they must participate in optimistic locking

This difference is not incidental. It teaches an important API design lesson:

> **Create validation defines object birth. Update validation defines legal mutation.**

### Why `updated_at` is required on update

In `UpdateIssueRequest`:

```php
'updated_at' => ['required', 'date'],
```

This is not a normal CRUD rule. It exists because the project implements
**optimistic locking** in `IssueService::update()`.

The client must send the timestamp it last saw. The service compares that to the
current DB timestamp. If they differ, the update is rejected with **409
Conflict**.

So the request layer validates that the field exists and is a date, and the
service layer decides whether the value is still current.

That is a beautiful separation of responsibilities:

- request validates shape
- service validates concurrency semantics

### Validation vs business rules — where is the line?

This is one of the most important architecture instincts to develop.

#### Belongs in Form Request

- required vs optional fields
- string/integer/date shape
- enum membership
- foreign-key existence
- simple temporal validity like `after:now`
- normalization like trimming

#### Does **not** belong in Form Request

- ownership checks
- permission ladder logic
- summary regeneration side effects
- optimistic lock conflict decisions
- whether a field change should enqueue a job

Those belong in policies, services, jobs, or models.

### What error shape should you expect from this layer?

When Form Request validation fails, Laravel returns **422** with a structured
error payload. That is framework-native behavior.

Architecturally, this is good because callers can distinguish:

- **422** = malformed or invalid input
- **403** = not allowed
- **409** = valid input, but stale state conflict

That clarity is a sign of good HTTP architecture.

### Why this project's validation style is strong

This repo follows several good practices here:

1. validation classes are easy to find
2. create and update semantics are separated
3. enums are validated against actual enum types
4. database-aware validation is used where appropriate
5. normalization happens before rule execution
6. authorization is intentionally kept elsewhere

For a Laravel learner, that is much better than learning from a controller-heavy
codebase.

### The `authorize()` method in Form Requests

Notice `StoreIssueRequest::authorize()` returns `true`. This is a deliberate
choice: authorization is handled in the controller via `$this->authorize()` and
the Policy, not in the Form Request. Some teams use Form Request authorization
for simpler apps, but separating validation from authorization is cleaner when
the auth rules are complex (as they are here, with the sharing permission
ladder).

### A NestJS cross-check for your intuition

If you want a quick translation table for this section:

| Laravel Form Request concern | Rough NestJS analogue |
|---|---|
| `rules()` | validation decorators / schema rules |
| `prepareForValidation()` | transform pipe / preprocessing step |
| `authorize()` | sometimes guard-ish, but intentionally unused here |
| automatic 422 response | validation pipe throwing structured HTTP error |
| separate `Store...` / `Update...` classes | create DTO vs patch DTO |

The biggest difference is that Laravel bundles this into a request object that
the framework resolves around the route lifecycle.

### Reading exercise for §3

1. Open `app/Http/Requests/UpdateIssueRequest.php`. Find the `updated_at`
   field. Why does the update endpoint require `updated_at` from the client?
   (Hint: §6 — optimistic locking.)
2. Find the rule that validates `status` on update. Notice it uses
   `Rule::enum(Status::class)`. Open `app/Enums/Status.php`. How many valid
   statuses are there? What happens if the client sends `status: "deleted"`?
3. Why is `deadline_at` validated as `'after:now'` on creation but what would
   happen if a user tried to set a deadline in the past? Test your reasoning by
   finding the matching test in `tests/Feature/`.
4. Make a two-column note for yourself:

   - **Request-layer concerns**
   - **Policy/service-layer concerns**

   Then place each of these into the correct column: `Rule::enum`, share
   permission ladder, `updated_at` date format, optimistic locking conflict,
   trimming title, owner-only delete.
5. Optional: compare `StoreCategoryRequest` and `StoreCommentRequest` to
   `StoreIssueRequest`. Notice how the same pattern scales across multiple
   features without inventing new validation architecture each time.

---

## §4 Authorization — Policies

### NestJS translation first

If your auth intuition comes from NestJS, Laravel Policies are closest to an
**ability-based authorization layer** that sits alongside guards.

Rough translation:

| Laravel | Rough NestJS analogue |
|---|---|
| `auth` middleware | auth guard |
| `$this->authorize('update', $issue)` | ability check after authentication |
| `IssuePolicy` | CASL-style ability rules / dedicated authorization service |
| `viewAny`, `view`, `update`, `delete` | per-action authorization decisions |

The important difference is that Laravel Policies are usually **model-centric**.
Instead of asking only “does this user have permission X?”, Laravel often asks:

> “May this user perform action Y on this specific model instance?”

That mindset becomes very natural once you see it in code.

### The problem with authorization in controllers

If authorization logic were in the controller, it would look like:

```php
// BAD — auth scattered in controllers
public function update(Request $request, Issue $issue)
{
    if ($request->user()->id !== $issue->user_id) {
        // but wait, what if they have an edit share?
        $share = IssueShare::where('issue_id', $issue->id)
                           ->where('user_id', $request->user()->id)
                           ->first();
        if (!$share || $share->permission !== 'edit') {
            abort(403);
        }
    }
    // ... update logic
}
```

This spreads the same logic across every method, and worse, the logic becomes
*invisible* — you cannot look at authorization as a whole anywhere.

It also creates a second problem: controller methods start mixing transport
concerns with domain access rules. Then you can no longer answer questions like:

- who can view a public issue?
- who can comment on a shared issue?
- does owner access override share access?

without scanning multiple endpoints.

### The Policy pattern

Laravel's Policy pattern centralizes all authorization decisions for a model in
one file: `app/Policies/IssuePolicy.php`.

```php
public function update(User $user, Issue $issue): bool
{
    if ($issue->user_id === $user->id) {
        return true;  // owner has full access
    }

    $share = $this->getShare($user, $issue);

    return $share !== null && $share->permission->canEdit();
}
```

**What makes this a strong pattern:**

1. **One place to read authorization.** Any developer who asks "who can edit an
   issue?" opens `IssuePolicy` and has a complete answer immediately.

2. **`$share->permission->canEdit()`** — authorization delegates to the Enum
   method (§7). The Policy does not hardcode string comparisons like
   `$share->permission === 'edit'`. This is important: if we ever rename a
   permission level, we change the Enum, and the Policy automatically reflects
   it.

### Policy methods correspond to user intents

Look at the methods in `IssuePolicy`:

- `viewAny`
- `view`
- `create`
- `update`
- `comment`
- `delete`
- `share`
- `restore`
- `forceDelete`

These are not arbitrary names. They model the actual intents the application
cares about.

That means the policy layer is not just “security glue.” It is also a compact
map of the app's domain actions.

### `viewAny` vs `view` — subtle and important

This is one of the easiest places for a beginner to get confused.

In `IssuePolicy`:

```php
public function viewAny(User $user): bool
{
    return true;
}
```

That does **not** mean every user can view every issue.

It means:

> every authenticated user may access the **listing endpoint itself**.

The actual row-level filtering happens in the query scope:

```php
->accessibleBy($request->user())
```

inside `IssueController@index()`.

This is a great design choice. It separates:

- **endpoint access** (`viewAny`)
- **record visibility** (`scopeAccessibleBy`)

If those were collapsed into a single layer, the intent would be harder to
read.

**NestJS comparison:** imagine a guard that allows entry to `/issues`, but the
service/repository layer filters records based on the authenticated user. Same
idea.

### The actual access model in this app

This project does not use roles like:

- admin
- manager
- agent

Instead it uses a **per-issue access model** with three inputs:

1. **Ownership** — the creator owns the issue
2. **Visibility** — an issue can be public or private
3. **Shares** — an issue may be shared to another user with a permission level

This means access is contextual and object-specific.

That is why policies are such a good fit here.

### `view()` shows the full access resolution clearly

From `IssuePolicy::view()`:

```php
if ($issue->user_id === $user->id) {
    return true;
}

if ($this->getShare($user, $issue) !== null) {
    return true;
}

if ($issue->visibility === Visibility::Public) {
    return true;
}

return false;
```

This is compact, but it teaches a lot:

- owner access is first-class
- any share row grants view access
- public visibility grants view access even without sharing
- default is deny

That last point matters. The policy is permissive only through explicit rules;
otherwise it returns false.

### `update()` and `comment()` depend on the permission ladder

The project's permission system is not role-based. It is **ladderized**:

```text
view -> comment -> edit
```

That means each higher permission implies the lower ones.

`IssuePolicy::update()`:

```php
return $share !== null && $share->permission->canEdit();
```

`IssuePolicy::comment()`:

```php
return $share !== null && $share->permission->canComment();
```

And the enum methods in `Permission` hold the actual ladder logic.

This is elegant because the policy asks a semantic question:

- `canEdit()`
- `canComment()`

instead of hardcoding permission strings repeatedly.

### Why this is better than role-based thinking for this app

If you come from Nest apps with role guards, it is tempting to ask:

> Why not just make roles like `owner`, `viewer`, `editor`?

Because in this app those are not global user roles. They are **issue-specific
relationships**.

The same user can be:

- owner of issue A
- commenter on issue B
- viewer of issue C
- no-access user for issue D

That is exactly the kind of case where object-level policies beat simple role
guards.

### The private helper is a nice small design detail

At the bottom of `IssuePolicy`:

```php
private function getShare(User $user, Issue $issue): ?IssueShare
{
    return $issue->shares()->where('user_id', $user->id)->first();
}
```

This is a small but good detail:

- the share lookup is centralized
- repeated query fragments are removed
- policy methods stay easier to scan

This is not a huge pattern, but it is an example of disciplined local design.

### Where the policy is actually used

The policy does nothing unless the app calls it.

Examples from controllers:

```php
$this->authorize('viewAny', Issue::class);
$this->authorize('create', Issue::class);
$this->authorize('view', $issue);
$this->authorize('update', $issue);
$this->authorize('delete', $issue);
```

That means the request lifecycle is:

1. route/middleware authenticates the user
2. controller calls `authorize(...)`
3. Laravel dispatches that to the policy
4. failure becomes **403 Forbidden** automatically

This is very Laravel-native and worth internalizing.

### Policy vs middleware vs query scope

Laravel learners often blur these three. Do not.

#### Middleware answers:
- is the request allowed into this route pipeline at all?

Example: `auth`

#### Policy answers:
- may this user perform this action on this model?

Example: update this issue, comment on this issue

#### Query scope answers:
- which records should even be returned from the DB query?

Example: `accessibleBy($user)`

This app uses all three correctly and separately.

### Why this policy layer is strong Laravel design

This project's authorization is good because:

1. it is centralized
2. it is model-aware
3. it is enum-backed
4. it separates endpoint access from record filtering
5. it avoids controller duplication
6. it matches the app's real domain (issue-level sharing)

That is much better than slapping role checks across controllers.

### Common mistakes this project avoids

It avoids several classic mistakes:

- **No inline ownership checks everywhere**
- **No frontend-only authorization assumptions**
- **No role system forced onto an object-specific permission problem**
- **No mixing of validation and authorization**
- **No giant `if/else` authorization blocks in controllers**

As a learner, these absences are as educational as the code that is present.

### NestJS comparison — the cleanest mapping

If you want a compact translation:

| Concern | NestJS-ish interpretation |
|---|---|
| route `auth` middleware | authentication guard |
| `IssuePolicy` | ability evaluator / authorization service |
| `$this->authorize('update', $issue)` | ability check on a concrete domain object |
| `accessibleBy($user)` scope | repository/service-level record filtering |
| `Permission` enum methods | typed permission vocabulary |

The most important difference is that Laravel makes model-oriented policy files
a first-class convention, while many Nest projects invent their own pattern for
this.

### Reading exercise for §4

1. Open `IssuePolicy::view()`, `update()`, and `comment()` side by side. Write a
   one-sentence rule for each in plain English.
2. Open `app/Enums/Permission.php`. Explain why `canComment()` returning true
   for both `Comment` and `Edit` is an example of the permission ladder.
3. Find where `IssueController@index()` uses `viewAny` and where it also uses
   `accessibleBy($request->user())`. Why are both needed?
4. Compare this authorization model to a simple role-based system (`admin`,
   `user`). Which project requirements would be harder to express with only
   roles?
5. Optional: inspect `CommentPolicy.php` and compare its style to
   `IssuePolicy.php`. Notice whether the project keeps a consistent authorization
   approach across domain objects.

3. **Private helper `getShare()`** — the share lookup is extracted into a
   private method so each policy method calls it in one line rather than
   repeating the query. This is a DRY practice within the Policy class.

### How the controller invokes the Policy

```php
// Controller — declarative, no auth logic
$this->authorize('update', $issue);
```

Laravel's `authorize()` finds `IssuePolicy::update()` automatically based on
naming convention: the Policy for `App\Models\Issue` is
`App\Policies\IssuePolicy`. This is **convention over configuration** — a
core Laravel principle. No registration code needed beyond what Laravel
auto-discovers.

### The permission ladder in the Policy

Open `IssuePolicy` and read all the methods in order:

- `viewAny` — always true (any authenticated user can call index)
- `view` — owner OR any share row OR public
- `create` — any authenticated user
- `update` — owner OR edit-level share
- `comment` — owner OR comment-or-edit-level share
- `delete` — owner only
- `share` — owner only

The ladder is encoded in the Policy methods and the Permission enum. Note what
`viewAny` does: it always returns `true`, but the list endpoint is still
secured because `accessibleBy()` scope in the model filters which issues appear
in results (§5). The Policy gates entry to the endpoint; the scope gates which
rows you see. These are two different security layers with two different jobs.

### Reading exercise for §4

1. What happens when a user tries to delete an issue they only have `edit`
   permission on (shared, not owner)? Trace: `IssueController::destroy()` →
   `$this->authorize('delete', $issue)` → `IssuePolicy::delete()`.
2. Open `app/Policies/CommentPolicy.php`. What rule governs who can delete a
   comment? Is it the same as issue deletion?
3. In `IssuePolicy::view()`, the check order matters. Why is the owner check
   first, before the share lookup? What would break if you reversed the order?
   (Think about database queries.)

---

## §5 The Eloquent Model Layer

### What Eloquent is (and what it isn't)

Eloquent implements the **Active Record** pattern: each model class represents
a database table, and each model instance represents a row. It bundles:
- Column accessors and mutators
- Relationship definitions
- Event hooks (`saving`, `creating`, etc.)
- Query builder integration (scopes)
- Casting (convert DB strings to PHP types)

This is **entirely Laravel-native**. Everything in this section is provided by
`Illuminate\Database\Eloquent\Model`.

### The Issue model — a deep read

Open `app/Models/Issue.php` and read it with the following anatomy in mind.

#### 1. The `#[Fillable]` attribute

```php
#[Fillable([
    'user_id', 'title', 'description', 'priority',
    'category_id', 'status', 'visibility', 'summary',
    ...
])]
class Issue extends Model
```

Using PHP 8's attribute syntax (`#[...]`) instead of a `$fillable` property is a
**newer Laravel convention** (Laravel 12+). It is functionally identical:
it tells Eloquent which columns may be mass-assigned (i.e., set via an array
in `Issue::create([...])` or `$issue->fill([...])`) and which are protected
against mass-assignment for security.

**Why mass-assignment protection matters:** without it, a malicious client could
send `user_id: 999` in a POST body and Eloquent would set it — bypassing
ownership. The `Fillable` list is the explicit whitelist.

#### 2. Traits

```php
use HasFactory, SoftDeletes;
```

- **`HasFactory`** — Laravel-native. Enables `Issue::factory()` for test data
  generation.
- **`SoftDeletes`** — Laravel-native. Adds `deleted_at` column logic: deleted
  rows are not truly removed from the database; they get a timestamp in
  `deleted_at` and are excluded from normal queries automatically.

#### 3. Casting

```php
protected function casts(): array
{
    return [
        'priority'       => Priority::class,
        'status'         => Status::class,
        'visibility'     => Visibility::class,
        'summary_status' => SummaryStatus::class,
        'deadline_at'    => 'immutable_datetime',
        'needs_attention' => 'boolean',
    ];
}
```

Casting is **Laravel-native** and very powerful. It means:
- When you read `$issue->priority`, you get a `Priority` enum, not the string
  `"high"`. You can call `$issue->priority->needsAttention()` directly.
- When you read `$issue->deadline_at`, you get a `CarbonImmutable` instance,
  not a raw string. You can call `$issue->deadline_at->isBefore(now())`.
- When you read `$issue->needs_attention`, you get `true` or `false`, not `1`
  or `0` from the database.

The benefit: application code never needs to parse strings or integers from the
DB. Types are correct at the model boundary.

#### 4. The `booted()` event hook — a critical custom pattern

```php
protected static function booted(): void
{
    static::saving(function (Issue $issue): void {
        $issue->needs_attention = self::computeNeedsAttention(
            $issue->priority,
            $issue->deadline_at,
        );
    });
}
```

This is an **Observer-like model event** (§11). The `saving` event fires before
every insert *and* every update. This means `needs_attention` is *always*
recomputed when the issue is saved — by any code path, not just the service.

**Why this is the right place for this logic:**
- It is automatic — callers cannot forget to recompute it.
- It is consistent — whether the save comes from the service, an Artisan
  command, or a test, the flag is always correct.
- It is database-agnostic — it fires at the PHP level, not via a DB trigger.

Compare with the `Category` model's `creating` event:

```php
// app/Models/Category.php
static::creating(function (Category $category): void {
    $category->slug = static::generateUniqueSlug($category->name);
});
```

Same pattern, different event: `creating` fires only on insert (not on updates),
which is correct for slugs (you set them once at creation).

#### 5. Relationships

```php
public function user(): BelongsTo    // issue belongs to a user (owner)
public function category(): BelongsTo // issue belongs to a category
public function comments(): HasMany   // issue has many comments
public function shares(): HasMany     // issue has many share records
```

These are **Laravel-native Eloquent relationships**. They define how models
connect. They are used for:
- Eager loading (`$issue->load('category')` — prevents N+1 queries)
- Lazy loading (`$issue->comments` — loads when accessed)
- Relationship queries (`$issue->shares()->where('user_id', $id)->first()`)

#### 6. Query scopes — composable filtering

```php
public function scopeFilterByStatus(Builder $query, ?string $value): Builder
public function scopeFilterByPriority(Builder $query, ?string $value): Builder
public function scopeFilterByCategory(Builder $query, ?string $slug): Builder
public function scopeAccessibleBy(Builder $query, User $user): Builder
```

Laravel's `scope*` convention (any method starting with `scope`) registers a
query macro callable without the prefix:

```php
// In controller:
Issue::query()->filterByStatus($request->query('status'))
              ->filterByPriority($request->query('priority'))
              ->accessibleBy($request->user());
```

**Why scopes are a best practice:**
- Query logic lives on the model, not scattered in controllers.
- Scopes are composable — chain as many as needed.
- Each scope is individually testable.
- Scopes silently ignore null or invalid inputs, keeping the caller clean.

Look at `scopeFilterByStatus`:

```php
public function scopeFilterByStatus(Builder $query, ?string $value): Builder
{
    if ($value === null) { return $query; }

    $status = Status::tryFrom($value);     // safely convert string to enum
    if ($status === null) { return $query; } // unknown value → no-op

    return $query->where('status', $status);
}
```

`Status::tryFrom()` is a PHP 8.1+ enum method that returns `null` for unknown
values instead of throwing. This is **safe input handling**: invalid filter
values are silently ignored rather than surfacing an error to the user.

### The `computeNeedsAttention` method — pure static logic

```php
public static function computeNeedsAttention(Priority $priority, ?CarbonImmutable $deadlineAt): bool
{
    if ($priority->needsAttention()) { return true; }

    if ($deadlineAt !== null) {
        $threshold = config('issues.attention_threshold_minutes', 60);
        return $deadlineAt->lte(now()->addMinutes($threshold));
    }

    return false;
}
```

This is **pure static logic** — no database queries, no side effects. The
comment in the original code even says "testable without DB". Pure functions
like this are highly testable: you can call them with any inputs and assert the
output without any framework machinery. This is a **deliberate custom
architecture choice**: pulling the computation out of the event hook into a
named, testable static method.

### Reading exercise for §5

1. Open `app/Models/Issue.php`. The `scopeAccessibleBy` method uses
   `orWhereHas('shares', ...)`. Explain in plain English what that query does.
   How does it combine with the `visibility` check to implement the sharing
   model from ADR-007?
2. Why does the model use `CarbonImmutable` for `deadline_at` instead of the
   mutable `Carbon`? (Hint: what problem does an immutable date object solve
   in methods that call `.addMinutes()`?)
3. The `Category` model has `public const UPDATED_AT = null;`. What does this
   tell Laravel? Why would categories not need an `updated_at` timestamp?

---

## §6 The Service Layer

### When to use a service class

This project uses one primary service: `IssueService`. There is no
`CategoryService` or `CommentService`. That is a deliberate choice, not an
omission.

The decision rule:
- Use a service when there is **non-trivial workflow logic** involving multiple
  steps, side effects, or domain rules that do not belong in a single model.
- Do **not** use a service for simple CRUD operations where the controller can
  call Eloquent directly without becoming fat.

Category creation: `Category::create(['name' => $data['name']])`. No service
needed. Comment creation: `Comment::create([...])`. No service needed.

Issue creation and update: non-trivial. Needs optimistic locking, conditional
job dispatch, default setting, event wiring. Lives in `IssueService`.

### Reading IssueService::create()

```php
public function create(User $user, array $data): Issue
{
    $issue = Issue::create([
        'user_id'        => $user->id,
        'title'          => $data['title'],
        'description'    => $data['description'],
        'priority'       => $data['priority'],
        'category_id'    => $data['category_id'],
        'visibility'     => $data['visibility'] ?? 'private',
        'deadline_at'    => $data['deadline_at'] ?? null,
        // Defaults
        'status'         => 'open',
        'summary_status' => 'pending',
    ]);

    dispatch(new GenerateSummaryJob($issue));

    return $issue->load(['category', 'user']);
}
```

**Three things the service owns that the controller must not:**

1. **Applying defaults** — `status: 'open'`, `summary_status: 'pending'`. The
   client does not send these; the service sets them. If this logic were in the
   controller, changing a default would mean changing the controller.

2. **Dispatching the job** — `dispatch(new GenerateSummaryJob($issue))`. This is
   a side effect. Side effects belong in services, not in controllers.
   Separating the dispatch here also makes testing clean: in tests, use
   `Queue::fake()` to assert the job was dispatched without actually running it.

3. **Loading relationships** — `$issue->load(['category', 'user'])`. The
   response needs the category name and user name. Loading happens here so the
   controller just passes the issue to `IssueResource` without knowing what
   needs to be loaded.

### Reading IssueService::update() — optimistic locking

```php
public function update(Issue $issue, array $data): Issue
{
    // Optimistic locking check
    $clientTimestamp = Carbon::parse($data['updated_at'])->utc();
    $dbTimestamp     = $issue->updated_at->utc();

    if (! $clientTimestamp->equalTo($dbTimestamp)) {
        abort(Response::HTTP_CONFLICT, 'Conflict: ...');
    }
    // ...
}
```

**Optimistic locking without a library:**

The client sends the `updated_at` it last saw. The service compares it to the
database's current `updated_at`. If they differ, another request modified the
record between the client's fetch and this update — a **lost update** scenario.
The service aborts with `409 Conflict`.

This is a **custom architecture pattern** (§11). Laravel does not provide
optimistic locking built-in. The service implements it directly using timestamps
as the concurrency token.

**Why `updated_at` instead of a dedicated `lock_version` column?**

See the reality check in `technical-assessment-course.md §16`: some early docs
planned `lock_version`. The implementation uses `updated_at` because it is
already on every Eloquent model (no migration needed) and provides the same
guarantee.

**The manual timestamp advance:**

```php
$newUpdatedAt = $issue->updated_at->addSecond();
$issue->timestamps = false;
$issue->updated_at = $newUpdatedAt;
$issue->save();
$issue->timestamps = true;
```

This is a subtle implementation detail. In PostgreSQL test transactions,
`NOW()` is pinned for the entire transaction, so two saves within the same
transaction would produce the same `updated_at`. Manually advancing by one
second ensures the update always produces a strictly later timestamp.

### Reading exercise for §6

1. `IssueService::update()` iterates over a `$fillable` array of field names
   and conditionally sets each. Why is `updated_at` not in this list? What
   would happen if it were?
2. The service calls `abort(Response::HTTP_CONFLICT, ...)` when the lock check
   fails. How does Laravel translate this `abort()` into a JSON `409` response?
   (Hint: look at `app/Exceptions/` and the exception handler, or search how
   Laravel's `abort()` and JSON exception rendering work.)
3. In `create()`, the service calls `$issue->load(['category', 'user'])` at the
   end. What SQL queries does this trigger? How many queries total does
   `IssueService::create()` execute?

---

## §7 Enums as Domain Language

### What PHP enums are

PHP 8.1 introduced backed enums: enums where each case has an associated string
or integer value. Before enums existed, developers used constants or strings
directly, which produced stringly-typed bugs.

```php
// BAD — pre-enum style
if ($issue->priority === 'hihg') { ... }  // typo — PHP doesn't catch this
```

With enums:

```php
// GOOD — enum-based
if ($issue->priority === Priority::High) { ... }  // IDE catches typos
```

### The enums in this project

Open each file in `app/Enums/` and read the cases and methods.

**`Priority`** — defines `Low`, `Medium`, `High`, `Critical`:
```php
public function needsAttention(): bool
{
    return $this === self::High || $this === self::Critical;
}
```

The enum carries behavior. When you call `$priority->needsAttention()`, the
logic is colocated with the type. You do not need an external helper.

**`Permission`** — defines `View`, `Comment`, `Edit`:
```php
public function canComment(): bool { return $this === self::Comment || $this === self::Edit; }
public function canEdit(): bool    { return $this === self::Edit; }
```

This is the **permission ladder** encoded in the type system. The Policy
calls `$share->permission->canEdit()` — the answer is self-contained in the
enum. You cannot accidentally check the wrong condition.

**`SummaryStatus`** — defines `Pending`, `Processing`, `Ready`, `Failed`.
These represent the state machine of the async AI summary pipeline. The four
states map to database transitions managed by the job.

### Why enums matter architecturally

1. **Type safety at the PHP level** — Invalid values are impossible to create
   at runtime when the type system is enforced.
2. **Database casting** — `'priority' => Priority::class` in `casts()` means
   Eloquent automatically converts DB strings to enum cases when reading and
   back to strings when writing. No manual conversion anywhere.
3. **Rule validation** — `Rule::enum(Priority::class)` in Form Requests
   validates incoming API values against the enum's cases automatically.
4. **Behavior encapsulation** — `needsAttention()`, `canEdit()` are not
   helper functions; they are methods of the type.

### Reading exercise for §7

1. What does `Status::tryFrom('invalid')` return? Why is `tryFrom()` safer
   than `from()` in the context of user-supplied input? Trace how this is used
   in `scopeFilterByStatus`.
2. The `SummaryStatus` enum has four cases. Draw a state diagram: which
   transitions are valid? (Hint: read `GenerateSummaryJob::handle()` to see the
   actual transitions.)
3. The `Visibility` enum only has `Private` and `Public`. Why is that enough?
   What does the `shares` table add that a third visibility level (e.g.,
   `Shared`) would not?

---

## §8 API Resources

### The problem without resources

Without an API resource, you might return `$issue->toArray()` or `$issue` directly.
Problems:
- Exposes every column, including internal ones (e.g., `deleted_at`).
- Exposes enum values as whatever Eloquent returns (could be enum instances, not strings).
- No consistent structure between list and detail responses.
- No computed fields (like `can_comment`).
- Cannot conditionally include related data.

### IssueResource — the response contract

Open `app/Http/Resources/IssueResource.php`.

```php
return [
    'id'             => $this->id,
    'priority'       => $this->priority->value,   // "high", not Priority::High
    'summary_status' => $this->summary_status->value,
    'deadline_at'    => $this->deadline_at?->toIso8601String(),
    'user'           => ['id' => ..., 'name' => ...],
    'category'       => ['id' => ..., 'name' => ..., 'slug' => ...],
    // ...
    'can_comment' => Gate::allows('comment', $this->resource),
];
```

**Key design decisions in this resource:**

1. **`$this->priority->value`** — converts the enum case to its string value
   (`"high"`) before sending. The frontend receives a clean string, not a PHP
   object.

2. **`$this->deadline_at?->toIso8601String()`** — the null-safe operator (`?->`)
   handles the fact that `deadline_at` is nullable. If null, the field appears
   as `null` in JSON. `toIso8601String()` produces `"2026-06-01T12:00:00+00:00"`
   — a format any frontend can parse reliably.

3. **Inline related data** — `'user'` and `'category'` are embedded as small
   objects, not as IDs requiring a second API call. The frontend gets everything
   it needs in one response.

4. **`can_comment`** — a **computed permission field** exposed to the client.
   The frontend uses this to show or hide the comment input without making a
   separate authorization check. Notice it calls `Gate::allows('comment', ...)`,
   which invokes `IssuePolicy::comment()` — the same logic used server-side.

5. **Conditional inclusion** — `$this->whenLoaded('comments', ...)` only
   includes comments if they were eager-loaded. The list endpoint does not
   load comments (`withCount` only); the show endpoint does. One Resource class
   handles both without branching.

### Reading exercise for §8

1. What does `$this->mergeWhen($this->comments_count !== null, ...)` do? Why
   is checking `!== null` safer than a truthy check here?
2. The `comments` block inside `whenLoaded` maps each comment to a custom
   array. Why not use a `CommentResource` class? (There are valid arguments
   both ways — what is the trade-off?)
3. Open `IssueController::index()`. What relationships does it eager-load?
   What does `withCount('comments')` add? Now open `show()`. What extra
   relationship does it load that `index()` does not?

---

## §9 The AI Summary Pipeline

This is the most architecturally interesting part of the project. It uses four
patterns together: Manager, Strategy, Facade, and Value Object. Read this
section carefully — it mirrors how Laravel itself builds its cache, queue, and
mail systems.

### The anatomy of the AI subsystem

```
app/
├── Contracts/
│   └── SummaryGeneratorInterface.php    ← the contract (interface)
├── Facades/
│   └── Summary.php                      ← the entry point for app code
├── Services/
│   └── Summary/
│       ├── SummaryManager.php           ← driver resolution
│       ├── SummaryResult.php            ← value object (DTO)
│       └── Drivers/
│           ├── LlmDriver.php            ← OpenAI-compatible HTTP call
│           └── RulesDriver.php          ← deterministic fallback
├── Jobs/
│   └── GenerateSummaryJob.php           ← async execution
└── Providers/
    └── SummaryServiceProvider.php       ← registration
```

### The interface — defining the seam

```php
// app/Contracts/SummaryGeneratorInterface.php
interface SummaryGeneratorInterface
{
    /** @throws SummaryGenerationException */
    public function generate(Issue $issue): SummaryResult;
}
```

This interface is the **seam** between the application and the summary
implementation. Application code (the job, tests) depends on this interface,
not on `LlmDriver` or `RulesDriver` directly. That is the **Dependency
Inversion Principle**: depend on abstractions, not concretions.

The interface says: "any driver must accept an Issue and return a SummaryResult,
or throw SummaryGenerationException if something goes wrong."

### The Manager — driver resolution

Open `app/Services/Summary/SummaryManager.php`:

```php
class SummaryManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $configured = (string) config('summary.default', 'rules');

        if ($configured === 'llm' && empty(config('summary.drivers.llm.api_key'))) {
            return 'rules';  // silent auto-fallback when no key is configured
        }

        return $configured;
    }

    public function createLlmDriver(): SummaryGeneratorInterface { ... }
    public function createRulesDriver(): SummaryGeneratorInterface { ... }
}
```

`Illuminate\Support\Manager` is a **Laravel-native base class** that implements
the Manager pattern. Laravel itself uses it for `CacheManager`, `QueueManager`,
`MailManager`, `FilesystemManager`. Extending it gives you:
- `driver($name)` — resolves and caches a driver instance
- Auto-discovery of `create{Name}Driver()` methods (Laravel calls
  `createLlmDriver()` when you request the `llm` driver)
- The `getDefaultDriver()` hook for the default

**The no-key auto-fallback** in `getDefaultDriver()` is a **custom addition** to
the Laravel Manager pattern. It transparently downgrades to `rules` if the LLM
driver is configured but no API key is present. The job never needs to check
for missing keys — that concern is isolated here.

### The Facade — the public entry point

```php
// app/Facades/Summary.php
class Summary extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SummaryManager::class;
    }
}
```

A **Laravel Facade** provides a static-style API into the service container.
`Summary::generate($issue)` looks like a static call but actually:
1. Finds `SummaryManager` in the container (registered by `SummaryServiceProvider`)
2. Calls `->generate($issue)` on the manager instance
3. The manager delegates to the resolved driver

**Why a Facade here?** The job uses `Summary::generate($issue)`. Without the
Facade, it would need `app(SummaryManager::class)->generate($issue)` or
constructor injection. The Facade is a readability choice — it is the most
common approach in Laravel's own codebase.

**What `SummaryServiceProvider` does:**

```php
$this->app->singleton(SummaryManager::class, function ($app): SummaryManager {
    return new SummaryManager($app);
});
```

This registers `SummaryManager` as a singleton in the container. "Singleton"
means Laravel creates one instance and reuses it for the entire request. The
Facade resolves it by class name.

**AppServiceProvider bridges DB settings to config:**

```php
// AppServiceProvider::bootAiSettings()
$settings = AiSetting::current();
config([
    'summary.default'           => $settings->effective_driver,
    'summary.drivers.llm.api_key' => $settings->api_key,
    // ...
]);
```

This is a runtime config override: user-configurable AI settings are stored in
the `ai_settings` table and pushed into `config()` at boot time. `SummaryManager`
reads only from `config()`, keeping it ignorant of the database. The
`AppServiceProvider` is the bridge — a **separation of concerns** between where
settings are stored and where they are read.

### The Value Object — SummaryResult

```php
final readonly class SummaryResult
{
    public function __construct(
        public string $summary,
        public string $suggestedNextAction,
        public string $driver,  // 'llm' or 'rules'
    ) {}
}
```

`readonly` (PHP 8.2) means all properties are set once in the constructor and
cannot be mutated. This is a **Value Object** (§11): a data carrier with no
identity, no methods, and immutable state. Drivers return `SummaryResult`; the
job reads from it. No mutable state leaks between subsystems.

### The Job — putting it all together

Open `app/Jobs/GenerateSummaryJob.php` and read the `handle()` method:

```php
public function handle(): void
{
    $this->issue->refresh();              // 1. re-read from DB (stale guard)
    $this->issue->load('category');       // 2. reload lost relation

    $this->issue->summary_status = SummaryStatus::Processing;
    $this->issue->save();                 // 3. mark as in-progress

    $result = $this->generateWithFallback(); // 4. attempt driver(s)

    $this->issue->summary = $result->summary;
    $this->issue->suggested_next_action = $result->suggestedNextAction;
    $this->issue->summary_status = SummaryStatus::Ready;
    $this->issue->save();                 // 5. persist result

    event(new SummaryCompleted($this->issue)); // 6. fire event for SSE
}
```

**Notable patterns in the job:**
- `refresh()` is called first because the issue may have changed between when
  the job was dispatched and when it runs on the queue worker.
- The `load('category')` call is needed because Eloquent relations are
  *not* serialized when the job is pushed to the queue — only the Issue's ID
  is stored; the model is re-hydrated from the DB.
- State transitions (`Pending → Processing → Ready/Failed`) are made explicit
  by setting `summary_status`. This is the state machine discussed in §7.

The `generateWithFallback()` private method handles retries vs. fallback:

```php
private function generateWithFallback(): SummaryResult
{
    try {
        return Summary::generate($this->issue);
    } catch (SummaryGenerationException $e) {
        $isSyncQueue = config('queue.default') === 'sync';
        if (! $isSyncQueue && $this->attempts() < $this->tries) {
            throw $e;  // still have retries — rethrow for Laravel to re-queue
        }
        // Final attempt or sync queue — use rules driver
        return Summary::driver('rules')->generate($this->issue);
    }
}
```

On a real async queue, rethrowing `SummaryGenerationException` causes Laravel
to re-queue the job with backoff. On the sync queue (used in tests), there are
no re-queues, so it falls back immediately. This dual behavior is the reason for
the `config('queue.default') === 'sync'` check.

### Reading exercise for §9

1. Read `app/Providers/SummaryServiceProvider.php`. The singleton binding uses
   `SummaryManager::class` as the key. Read `app/Facades/Summary.php`. Confirm
   that `getFacadeAccessor()` returns the same string. This is how the Facade
   finds the right singleton.
2. The `GenerateSummaryJob` fires `SummaryCompleted` at the end. Where is
   this event handled? Search the codebase for `SummaryCompleted` — find the
   listener and trace what it does (SSE push).
3. What prevents the rules driver from ever throwing? Read
   `app/Services/Summary/Drivers/RulesDriver.php`. What is its fallback if
   no category or description matches a known pattern?

---

## §10 Routes and Middleware

### routes/api.php — five lines that register twelve endpoints

Open `routes/api.php`:

```php
Route::middleware('auth')->apiResource('issues', IssueController::class);
```

`apiResource` is **Laravel-native** and registers:
- `GET  /api/issues`          → `index`
- `POST /api/issues`          → `store`
- `GET  /api/issues/{issue}`  → `show`
- `PUT|PATCH /api/issues/{issue}` → `update`
- `DELETE /api/issues/{issue}` → `destroy`

No `create` or `edit` routes (those are for HTML forms) — `apiResource` skips
them automatically for API use.

The `{issue}` segment is a **route model binding** — Laravel-native. When a
request comes in for `/api/issues/42`, Laravel automatically looks up `Issue`
with `id = 42` and injects the instance into the controller method. If not
found, it returns `404` before the controller runs. This eliminates:

```php
// You never write this anymore
$issue = Issue::findOrFail($request->issue_id);
```

### Shallow nesting for shares

```php
Route::middleware('auth')->apiResource('issues.shares', ShareController::class)->shallow();
```

`shallow()` is a Laravel-native shorthand. Without it, you would need
`/api/issues/{issue}/shares/{share}` for every share operation. With `shallow()`,
nested routes for individual resources flatten to `/api/shares/{share}` — the
issue context is only needed for creation (where you need the parent issue ID).

### The `triage-suggest` ordering problem

```php
Route::post('issues/triage-suggest', [IssueController::class, 'triageSuggest']);
Route::middleware('auth')->apiResource('issues', IssueController::class);
```

This ordering is deliberate and documented with a comment:

```php
// triage-suggest must be declared BEFORE the apiResource wildcard to avoid
// the {issue} segment swallowing "triage-suggest" as an issue ID.
```

If `apiResource` were declared first, Laravel's router would match
`/api/issues/triage-suggest` as `show(issue=triage-suggest)` and fail with a
model-not-found error. Route declaration order matters; this is a classic
Laravel routing gotcha.

### Reading exercise for §10

1. Run `./vendor/bin/sail artisan route:list --path=api` (or check the README
   for the equivalent make target). Count the routes. Match each route to a
   controller method.
2. What does `->only(['index', 'store', 'destroy'])` do on the categories
   resource route? Why would categories not need `show` or `update`?
3. The SSE endpoint is `Route::get('issues/{issue}/stream', IssueSseController::class)`.
   Note that `IssueSseController` is referenced directly as the second argument,
   not as `[IssueSseController::class, 'method']`. Why? (Hint: look at what
   an invokable controller is in Laravel.)

---

## §11 Design Patterns Audit

### Patterns used, where, and why

| Pattern | File(s) | What it achieves |
|---------|---------|-----------------|
| **MVC** | Controllers + Models + Inertia views | Baseline organization: routes dispatch to thin controllers, models hold data, Inertia renders views |
| **Form Request** | `app/Http/Requests/*` | Moves all validation out of controllers; makes rules explicit and discoverable |
| **Policy** | `app/Policies/*` | Centralizes all authorization; never duplicate auth logic across endpoints |
| **Service Layer** | `app/Services/IssueService.php` | Separates complex workflows from controllers and models |
| **Active Record** | `app/Models/*` | Eloquent: each model = table, each instance = row; good fit for a CRUD-heavy app at this scale |
| **Observer-like Model Events** | `Issue::booted()`, `Category::booted()` | Automatic side effects on save: recompute `needs_attention`, auto-generate slugs |
| **Query Object via Scopes** | `Issue::scopeFilter*`, `scopeAccessibleBy` | Composable, reusable query building on the model; keeps SQL out of controllers |
| **Manager** | `SummaryManager extends Manager` | Laravel-idiomatic driver resolution; mirrors `CacheManager`, `QueueManager` |
| **Strategy** | `LlmDriver`, `RulesDriver` implement `SummaryGeneratorInterface` | Pluggable algorithm behind a stable contract; add a new AI provider without changing the job |
| **Facade** | `App\Facades\Summary` | Static-style entry point into the container-managed manager; standard Laravel idiom |
| **Value Object / DTO** | `SummaryResult` | Immutable data carrier returned by drivers; no shared mutable state between subsystems |
| **Resource Transformer** | `IssueResource` | Stable API contract; separates JSON shape from internal model shape |
| **Optimistic UI** | `useKanbanBoard.ts` | Client-side: instant drag-drop response with server rollback on failure |
| **Composable State** | Frontend composables | Vue equivalent of a service layer: isolates async logic and state from UI components |

### The most important patterns to internalize

**Form Request + Policy + Thin Controller (the Laravel triad):**
These three patterns together define "clean Laravel." If you understand why they
exist and can trace the request pipeline through them, you understand the
dominant architectural style of modern Laravel applications.

**Manager + Strategy + Facade (the AI triad):**
These three patterns together show how to build an extensible subsystem that is
testable, configuration-driven, and transparent to callers. This is not
theoretical — it is the same pattern Laravel uses internally for every pluggable
system.

---

## §12 Best Practices Audit

### Practices used — grounded in this repo's actual code

| Practice | Where | Laravel-native? |
|----------|-------|----------------|
| Constructor injection via type hints | All controllers | Yes — Laravel DI container |
| PHP 8 constructor property promotion (`private readonly`) | `IssueController`, others | No — PHP 8+ language feature, not Laravel-specific |
| Enum casting in models | `Issue::casts()` | Yes — Laravel 9+ |
| `Rule::enum()` in Form Requests | `StoreIssueRequest`, `UpdateIssueRequest` | Yes — Laravel 9+ |
| Route model binding | All resource controller methods | Yes — auto-resolved by Laravel |
| Eager loading to prevent N+1 | `IssueController::index()` uses `->with([...])` | Best practice using Laravel tools |
| `withCount()` for counts without loading collections | `index()` adds `withCount('comments')` | Yes — Eloquent |
| Soft deletes | `Issue` uses `SoftDeletes` trait | Yes — Laravel trait |
| `SoftDeletes` scoping | Deleted issues excluded from all queries automatically | Yes — built into the trait |
| Factory-based test data | `tests/` use `IssueFactory`, `UserFactory` | Yes — Laravel factories |
| `RefreshDatabase` in tests | All feature/integration tests | Yes — rolls back DB between tests |
| Queue::fake() in tests | Summary job dispatch tests | Yes — Laravel test helper |
| Http::fake() in tests | LLM driver tests | Yes — Laravel test helper |
| Pagination via `paginate()` | `IssueController::index()` | Yes — Eloquent |
| `appends()` to preserve query params in pagination | `index()` | Yes — Laravel |
| `response()->noContent()` for 204 responses | `destroy()` | Yes — Laravel response helper |
| Typed exceptions | `SummaryGenerationException` | No — pure PHP pattern |
| `@throws` PHPDoc on interfaces | `SummaryGeneratorInterface` | No — documentation convention |

### Practices notable for their absence (intentional)

| Not used | Why not |
|----------|---------|
| Repository pattern | Eloquent models with scopes serve the same query composition purpose; a Repository layer would be ceremony without value at this scale |
| Dedicated DTO for every model | Only `SummaryResult` is a DTO because it crosses a subsystem boundary; internal model data moves as Eloquent instances |
| Vuex/Pinia state management | `useKanbanBoard.ts` uses module-scoped refs (singleton-like pattern); a full store is overkill for a single dashboard surface |
| Custom exception handler | Laravel's default exception handler correctly serializes API exceptions to JSON without customization |
| API versioning | Single audience, single API contract; versioning would be over-engineering for this scope |

---

## §13 What's Framework vs. Custom

### Full classification table for this project

| Component | File(s) | Classification |
|-----------|---------|----------------|
| HTTP Kernel | `bootstrap/app.php`, `public/index.php` | **Framework-native** — not modified |
| Session, auth middleware | `config/session.php`, `routes/web.php` | **Framework-native** — configured only |
| Eloquent `Model`, `HasFactory`, `SoftDeletes` | `app/Models/*.php` | **Framework-native** — traits/base class |
| `FormRequest`, `Rule::enum()`, `exists:` rule | `app/Http/Requests/*.php` | **Framework-native** — extended, not modified |
| `Policy`, `Gate::allows()` | `app/Policies/*.php` | **Framework-native** mechanism, **custom** logic |
| `Route::apiResource()`, route model binding | `routes/api.php` | **Framework-native** |
| `JsonResource`, `whenLoaded()`, `mergeWhen()` | `app/Http/Resources/*` | **Framework-native** base, **custom** field mapping |
| `Manager` base class | `app/Services/Summary/SummaryManager.php` | **Framework-native** base, **custom** driver logic |
| `Facade` base class | `app/Facades/Summary.php` | **Framework-native** base, **custom** accessor |
| `ServiceProvider`, `singleton()` | `app/Providers/*.php` | **Framework-native** mechanism, **custom** bindings |
| `ShouldQueue`, `Queueable`, `$tries`, `$backoff` | `app/Jobs/GenerateSummaryJob.php` | **Framework-native** interface + trait, **custom** handle() |
| `dispatch()`, `Queue::fake()` | throughout | **Framework-native** |
| `SoftDeletes`, `RefreshDatabase` | Models, tests | **Framework-native** |
| Breeze auth routes/views | `routes/auth.php`, `resources/js/Pages/Auth/*` | **Package-provided** (Laravel Breeze) |
| Horizon UI, `HorizonServiceProvider` | `/horizon`, `app/Providers/Horizon*` | **Package-provided** (Laravel Horizon) |
| shadcn-vue components | `resources/js/components/ui/*` | **Package-provided** (shadcn-vue CLI) |
| Inertia middleware, `usePage()` | `app/Http/Middleware/HandleInertiaRequests.php` | **Package-provided** (Inertia) |
| Tailwind v4 tokens | `resources/css/app.css` | **Package-provided** (Tailwind), **custom** token values |
| `IssueService` | `app/Services/IssueService.php` | **Custom app architecture** |
| `SummaryGeneratorInterface` | `app/Contracts/*.php` | **Custom app architecture** |
| `SummaryResult` | `app/Services/Summary/SummaryResult.php` | **Custom app architecture** |
| `LlmDriver`, `RulesDriver` | `app/Services/Summary/Drivers/*` | **Custom app architecture** |
| Enum behavior methods (`needsAttention`, `canEdit`) | `app/Enums/*` | **Custom app architecture** |
| Model scopes (`scopeAccessibleBy`, etc.) | `app/Models/Issue.php` | **Custom app architecture** |
| `computeNeedsAttention()` | `app/Models/Issue.php` | **Custom app architecture** |
| Optimistic locking in `IssueService::update()` | `app/Services/IssueService.php` | **Custom app architecture** |
| `AppServiceProvider::bootAiSettings()` | `app/Providers/AppServiceProvider.php` | **Custom app architecture** |
| Frontend composables | `resources/js/composables/*` | **Custom app architecture** |

---

## Next Steps for Iterative Study

### What is fully drafted in this document

- §1 Project Structure Tour
- §2 The Request Lifecycle
- §3 Validation — Form Requests
- §4 Authorization — Policies
- §5 The Eloquent Model Layer
- §6 The Service Layer
- §7 Enums as Domain Language
- §8 API Resources
- §9 The AI Summary Pipeline
- §10 Routes and Middleware
- §11 Design Patterns Audit
- §12 Best Practices Audit
- §13 What's Framework vs. Custom

### Recommended next section to iterate

**§14 Frontend Architecture** is the natural next section.

It would cover:
- How Inertia bridges Laravel routes and Vue components (no separate API)
- The `resources/js/` directory structure (Pages, Components, composables, Types)
- How TypeScript contracts mirror the backend's `IssueResource` shape
- Composables as the frontend service layer (`useKanbanBoard`, `useIssueDetail`,
  `useSummaryStream`)
- The singleton-like board state pattern and why Pinia was not needed
- Optimistic UI in `useKanbanBoard.ts` with rollback on 409
- URL-aware slide-over in `useIssueDetail.ts` (back button, deep linking)

This section requires reading the Vue/TypeScript code alongside the PHP, which
is why it is deferred for a separate iteration session.

---

*Source of truth: if this document and the code disagree, trust the code. File
any discovered contradictions in a PR comment or update this section.*
