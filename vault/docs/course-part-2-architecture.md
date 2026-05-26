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

### NestJS translation first

If your ORM background is more NestJS-flavored, especially with TypeORM or
Prisma, the biggest mental shift is this:

> **Eloquent is Active Record, not repository-first.**

That means the model class is not only a schema representation. It also owns:

- relationships
- query scopes
- attribute casting
- lifecycle hooks
- small domain-adjacent behavior

Rough mapping:

| Laravel Eloquent concept | Rough NestJS analogue | Important difference |
|---|---|---|
| Model class | Entity / Prisma model concept | Eloquent carries richer behavior directly on the model |
| Relationship method | ORM relation metadata | Expressed as methods, not decorators/schema files |
| Scope | reusable query helper / repository filter method | Lives on the model class itself |
| `casts()` | serialization/type mapping layer | Automatic on model hydration |
| `booted()` model event | entity subscriber / lifecycle hook | Common and idiomatic in Laravel models |
| `Issue::create()` | repository create / ORM insert | Active Record style |

If you prefer repository-driven architecture, Laravel can do that — but this
project intentionally uses Eloquent in its native style.

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

What Eloquent is **not** in this project:

- not a repository abstraction hidden behind interfaces
- not an anemic data class with all logic elsewhere
- not just a direct DB row mapper

This project uses a healthy middle ground: the model owns model-shaped logic,
while larger workflows still live in services and jobs.

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

**NestJS comparison:** imagine explicitly whitelisting which DTO properties may
be used to construct/update an entity rather than trusting every incoming field.
Laravel bakes that concern into the model layer.

#### 2. Traits

```php
use HasFactory, SoftDeletes;
```

- **`HasFactory`** — Laravel-native. Enables `Issue::factory()` for test data
  generation.
- **`SoftDeletes`** — Laravel-native. Adds `deleted_at` column logic: deleted
  rows are not truly removed from the database; they get a timestamp in
  `deleted_at` and are excluded from normal queries automatically.

This is an example of Laravel giving you business-friendly persistence behavior
without forcing you to hand-roll archive flags or duplicate “active only”
filters everywhere.

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

This is more important than it looks.

Once casting is configured, the rest of your app can think in domain terms:

- `Priority::High`
- `Status::Resolved`
- `CarbonImmutable`
- `bool`

instead of raw storage primitives.

That means the model layer acts as a **type firewall** between the database and
the rest of the application.

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

This is one of the best examples in the project of putting logic at the lowest
reliable layer that still keeps it understandable.

If this logic lived only in `IssueService`, then any future code path that saves
an `Issue` outside the service could accidentally skip recomputation. By putting
it in the model's `saving` event, the project makes correctness harder to break.

Compare with the `Category` model's `creating` event:

```php
// app/Models/Category.php
static::creating(function (Category $category): void {
    $category->slug = static::generateUniqueSlug($category->name);
});
```

Same pattern, different event: `creating` fires only on insert (not on updates),
which is correct for slugs (you set them once at creation).

That contrast is worth learning:

- use **`creating`** when the rule belongs only to first-time creation
- use **`saving`** when the rule must hold for both create and update

Laravel model events are easy to misuse, but here they are used with good
discipline.

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

### Why relationships matter architecturally

Relationships are not just ORM convenience. They become the **vocabulary of the
domain model**.

For example, saying:

```php
$issue->shares()
```

is much clearer than manually writing a raw query against `issue_shares` every
time. The relationship defines a stable semantic boundary.

It also allows the rest of the system to speak in model terms:

- controller eager-loads relationships
- policy queries shares through the relationship
- resource serializes related models
- tests can reason about ownership and comments naturally

This is one of Eloquent's biggest strengths when used well.

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

For a NestJS developer, scopes are closest to “small reusable repository query
fragments,” except Laravel keeps them on the model class rather than in a
repository object.

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

That is a subtle UX/API choice. The project treats list filters differently from
mutations:

- invalid create/update payloads -> error (422)
- invalid optional filters -> no-op

That is an intentional design distinction.

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

### Why this model is “healthy” instead of a god model

As a learner, you should ask whether the model is too fat.

`Issue.php` is doing quite a bit, but it is still healthy because its
responsibilities are coherent:

- describe persistence shape
- define relationships
- define casts
- define query scopes
- maintain a derived attribute (`needs_attention`)
- provide a pure domain helper used by the event hook

What it is **not** doing:

- no HTTP request handling
- no authorization decisions
- no queue dispatching
- no AI generation workflow
- no response serialization concerns

That boundary is what keeps it from becoming a “god model.”

### Where this project stops before over-abstracting

Some teams react to Active Record by overcorrecting into repositories for every
query. This repo does not do that.

Why that is a good decision here:

- Eloquent already expresses the domain clearly
- the query logic is still understandable
- there is no evidence of repository duplication pressure yet
- model scopes already provide good reuse points

In other words: this project uses Eloquent idiomatically rather than fighting
it with architecture imported from another ecosystem.

### Framework-native vs custom in this model layer

It helps to separate what Laravel gives you from what the project chose.

#### Laravel-native

- `Model`
- relationships (`belongsTo`, `hasMany`)
- casts
- soft deletes
- model events
- query scopes convention
- factories

#### Custom app architecture

- which attributes are fillable
- which derived value is recomputed on save
- the specific `computeNeedsAttention()` rule
- the meaning of `accessibleBy()` for this domain
- enum choices for priority/status/visibility

That distinction helps you avoid attributing everything to “Laravel magic.”

### Small but important design detail: `accessibleBy()` belongs here

`scopeAccessibleBy()` is one of the most important model methods in the app.

Why does it belong on the model?

Because it describes **how issue visibility works as a query concern**.

It is not:

- HTTP logic
- UI logic
- service workflow logic

It is specifically “how to constrain an `Issue` query for a given user.” That
is model-level behavior.

That is a great example of putting logic at the right layer.

### Reading the model with the right questions

When you read an Eloquent model like `Issue`, ask these in order:

1. What table/entity does it represent?
2. Which fields are protected/fillable?
3. Which attributes are cast into richer PHP types?
4. Which relationships define its domain neighborhood?
5. Which scopes define reusable query language?
6. Which lifecycle hooks enforce invariants automatically?
7. Which methods are pure logic vs side-effecting behavior?

If you do that consistently, Eloquent models become much easier to understand.

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
4. Open `IssueController@index()` and highlight every method in the query chain
   that actually comes from the `Issue` model (`filterByStatus`,
   `filterByPriority`, `filterByCategory`, `accessibleBy`). Explain why moving
   those into the controller would make the code worse.
5. Optional: compare Eloquent's style here with how you would model the same
   entity in NestJS + Prisma or NestJS + TypeORM. What responsibilities would
   likely move out of the model in those stacks, and what do you gain/lose by
   doing that?

---

## §6 The Service Layer

### NestJS translation first

If you come from NestJS, this section should feel familiar.

`IssueService` is the closest thing in this repo to a classic Nest service:

- controller delegates to it
- it owns business workflow decisions
- it talks to the persistence layer
- it triggers side effects

The main difference is not *what* it does, but *what sits under it*.

In many Nest projects, a service calls:

- repository
- Prisma client
- TypeORM repository
- another provider

In this Laravel project, the service usually calls **Eloquent models directly**.

So the mental model is:

```text
NestJS: Controller -> Service -> Repository/ORM
Laravel here: Controller -> Service -> Eloquent Model
```

That is not a shortcut or a code smell by itself. It is simply the native style
of an Active Record framework.

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

This is one of the most important architecture judgment calls in a Laravel app:

> **Do not create services just to say you have a service layer. Create them when a workflow has enough moving parts to deserve one.**

This project makes that call well. It does not force every model through an
empty service class just for symmetry.

### What a service owns in this project

The service layer here owns **workflow logic**, not raw data shape and not raw
authorization rules.

That means `IssueService` is responsible for questions like:

- What defaults should be applied when an issue is created?
- What counts as a stale update?
- When should a summary job be requeued?
- Which side effects happen after mutation?

It is **not** responsible for:

- validating whether `priority` is one of the enum values (Form Request)
- deciding whether the user may update the issue (Policy)
- defining relationships (Model)
- shaping response JSON (Resource)

That boundary is what makes the service layer useful instead of redundant.

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

### Why `create()` belongs in a service instead of the model

A reasonable learner question is:

> Why not just put all this into `Issue::createIssue(...)` or into a model method?

Because the workflow is bigger than “how an issue behaves as a model.”

`create()` combines:

- persistence
- defaults
- async side effect dispatch
- response-readiness loading

That is a **use-case workflow**, not just intrinsic entity behavior.

The model should know what an issue *is*.
The service should know what happens when you *perform the create issue use case*.

That is the key distinction.

### Service layer and side effects

The line:

```php
dispatch(new GenerateSummaryJob($issue));
```

is exactly the kind of thing that justifies a service layer.

Why?

Because the controller should not become the system's side-effect scheduler.
If the controller starts owning dispatch decisions, it becomes harder to:

- reuse the workflow elsewhere
- test the workflow cleanly
- evolve side effects later

For example, if later you add:

- analytics event
- audit log record
- notification
- second background job

the service is the right place to compose those mutation consequences.

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

This is a very good example of an app-specific invariant living at the service
layer.

Why not put this in the controller?

- because conflict detection is business workflow, not transport parsing
- because multiple update entrypoints could reuse it
- because the service already owns mutation orchestration

Why not put this in the model?

- because the model does not know the *client's last-seen timestamp*
- because this is a request-time concurrency contract, not a universal model
  invariant like `needs_attention`

That is an excellent boundary decision.

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

### Why the manual `updated_at` bump looks weird — and why it exists

This code is unusual enough that it deserves a teaching note.

```php
$newUpdatedAt = $issue->updated_at->addSecond();
$issue->timestamps = false;
$issue->updated_at = $newUpdatedAt;
$issue->save();
$issue->timestamps = true;
```

At first glance, this can feel like a hack. In a sense, it is a **targeted
technical workaround**, but it is a justified one.

The underlying issue is test/runtime determinism with PostgreSQL timestamps in a
transactional context. If two rapid updates land inside the same timestamp
resolution window, the optimistic-lock token may not advance the way the test
expects.

So the code chooses explicitness over pretending the timing issue does not
exist.

This teaches an important architecture lesson:

> Sometimes the cleanest design still needs a small tactical workaround at the implementation layer. The key is to keep the workaround local and explainable.

This project does that reasonably well.

### Conditional side effects on update

In `IssueService::update()`, one rule matters a lot:

```php
$descriptionChanged = isset($data['description'])
    && $data['description'] !== $issue->description;
```

If the description changed:

- `summary_status` resets to `Pending`
- `GenerateSummaryJob` is dispatched again

If only status/priority/etc. changed, summary regeneration does **not** happen.

This is exactly the kind of business rule that belongs in a service. It is not
just data persistence — it is mutation semantics.

### Service layer vs model layer — the practical boundary in this app

Use this project to memorize a very helpful distinction:

#### Model layer owns

- relationships
- casts
- query scopes
- automatic invariants on save
- small pure domain helpers

#### Service layer owns

- multi-step workflows
- request-time concurrency checks
- deciding which side effects fire
- orchestration across persistence + jobs
- defaults tied to use cases

That distinction is one of the cleanest architecture lessons this repo can
teach you.

### Why this is not just a “fat model moved elsewhere”

Some developers criticize service layers by saying:

> “You just moved logic out of the controller into another class.”

That criticism is fair when services are thin pass-through wrappers.

But `IssueService` is not a pointless wrapper. It owns real workflow decisions:

- create defaults
- update conflict handling
- summary regeneration rules
- post-save job dispatch

That is enough substance to justify the abstraction.

### What this service layer deliberately avoids

The service layer here is also disciplined because it does **not** try to own
everything.

It does not:

- duplicate validation rules
- duplicate policy logic
- serialize API output
- hide Eloquent behind a fake repository abstraction

That restraint is important. Overbuilt service layers become just as messy as
fat controllers.

### Testing implications of the service layer

This architecture makes testing cleaner in at least three ways:

1. **Feature tests** can hit controller endpoints and indirectly verify the
   whole request -> policy -> service -> model path.
2. **Queue fakes** can assert that `GenerateSummaryJob` was dispatched without
   running it.
3. **Conflict scenarios** can be tested by manipulating timestamps and calling
   update flows with stale `updated_at` values.

That is another sign the layer boundaries are doing useful work.

### Framework-native vs custom in this section

#### Laravel-native pieces used by the service

- `dispatch(...)`
- Eloquent mutation APIs (`create`, `save`, `load`)
- `abort(...)`
- queue/job integration

#### Custom project decisions

- using `updated_at` as optimistic-lock token
- resetting summary only when description changes
- setting `status=open` and `summary_status=pending` in create workflow
- explicitly loading `category` and `user` before returning

Again, Laravel gives the tools; the project chooses the workflow.

### Service layer reading heuristic

When you open a service class in Laravel, ask:

1. What use case does this service own?
2. Which invariants here are request-time/business-workflow concerns?
3. Which side effects are triggered here?
4. Which responsibilities are intentionally delegated to models, requests,
   policies, or jobs?

That habit will help you avoid both under- and over-abstracting your own code.

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
4. Explain why summary regeneration belongs in `IssueService::update()` instead
   of in `IssueController::update()` or `Issue::booted()`.
5. Optional: write a short comparison note for yourself titled:

   - "What would this service look like in NestJS?"

   Include: controller delegation, ORM usage, conflict handling, and background
   job dispatch.

---

## §7 Enums as Domain Language

### NestJS translation first

If you are used to TypeScript enums or union types in NestJS DTOs, PHP backed
enums fill a similar role — but in this project they do more than define
allowed values. They also carry **behavior**.

Think of them as:

- type-safe vocabulary
- tiny domain objects
- reusable decision helpers

That third point is the important one.

### The enums that still exist — and the concept that became a model

Current enum files:

- `Priority`
- `Permission`
- `Visibility`
- `SummaryStatus`

Notice what is **not** an enum anymore: issue workflow status. That concept is
now modeled by `IssueStatus` (a database-backed model) instead of a static
enum.

That distinction is architecturally meaningful:

- use an **enum** when the value set is fixed by code
- use a **model/table** when the value set must be configurable at runtime

This repo is a great teaching example because it contains both patterns.

### `Priority` — fixed business vocabulary with behavior

`Priority` is a classic enum fit because the allowed values are stable:

- low
- medium
- high
- critical

And it owns behavior:

```php
public function needsAttention(): bool
{
    return $this === self::High || $this === self::Critical;
}
```

That means the type does not just hold data; it answers a domain question.

### `Permission` — the permission ladder encoded in the type

`Permission` is the best example of enums as domain language in this app.

```php
public function canComment(): bool
public function canEdit(): bool
```

The ladder:

```text
view -> comment -> edit
```

is implemented once, inside the enum, and then reused everywhere:

- `IssuePolicy`
- `IssueShare` casts
- Form Request validation
- tests

That is much better than repeating string comparisons across the codebase.

### `Visibility` and `SummaryStatus` — small but important

`Visibility` is intentionally tiny:

- private
- public

Why no `shared` value? Because sharing is modeled by a separate relationship
table, not by overloading the visibility field.

`SummaryStatus` models the async summary lifecycle:

- pending
- processing
- ready
- failed

This enum is especially useful because it represents a mini state machine.

### Where enums integrate with the framework

Enums in this app participate in three layers at once:

1. **Validation** — `Rule::enum(...)`
2. **Persistence** — Eloquent casts
3. **Behavior** — enum methods like `needsAttention()` / `canEdit()`

This makes them a powerful bridge between transport, storage, and business
logic.

### Why `IssueStatus` is not an enum anymore

This branch introduces configurable statuses via the `statuses` table and
`IssueStatus` model.

That is an excellent architecture lesson:

> **If users/admins need to create, rename, recolor, or reorder values at runtime, the concept probably should not be an enum.**

Status is now runtime-configurable because the app needs:

- CRUD for statuses
- sorting (`sort_order`)
- default status selection (`is_default`)
- color metadata
- migration/deletion workflows

An enum would make all of that rigid and code-bound.

### Reading exercise for §7

1. Open `Priority`, `Permission`, `Visibility`, and `SummaryStatus`. For each,
   answer: “Why is this still an enum instead of a table?”
2. Open `IssueStatus` and compare it mentally to the enums. Which requirements
   force it to be a model?
3. Find one place where `Priority` is used in validation, one place in a model
   cast, and one place in domain logic.
4. Explain why `Permission::canComment()` is better than checking raw strings in
   `IssuePolicy`.

---

## §8 API Resources

### NestJS translation first

Laravel `JsonResource` is closest to a response serializer/presenter in a NestJS
project. It sits between your internal domain objects and the JSON contract sent
to the frontend.

If you skip this layer and just return models directly, you leak persistence
shape into the API.

### The real job of `IssueResource`

`IssueResource` is doing more than “convert model to array.” It is defining the
frontend contract.

That includes:

- exposing enum values as strings
- flattening/correcting dates
- embedding nested user/category/status data
- adding computed permission fields
- including comments only when they are loaded
- preserving some backward compatibility (`status` slug string)

### Important current detail: status is both legacy-friendly and richer

This branch's `IssueResource` returns:

- `status` -> status slug string
- `status_id` -> integer FK
- `status_obj` -> full nested object with name/slug/color/sort order/default

That tells you the API is evolving while keeping consumers stable.

This is a subtle but very realistic architecture pattern:

> The resource layer is where you preserve compatibility while moving internal models forward.

### Why nested objects are returned

Instead of only returning:

```json
{ "user_id": 1, "category_id": 2, "status_id": 3 }
```

the resource also returns nested objects.

That reduces extra round trips and keeps the UI simple. The Kanban board does
not need to refetch user/category/status metadata for each issue.

### `whenLoaded()` and `mergeWhen()` are important Laravel idioms

`whenLoaded('comments', ...)` means:

- if controller eager-loaded comments, serialize them
- otherwise omit them cleanly

`mergeWhen($this->comments_count !== null, ...)` means:

- include count metadata when available
- do not force every response shape to always carry it

This is how one resource class can serve multiple endpoints cleanly.

### `can_comment` is server-derived UI permission

The resource computes:

```php
'can_comment' => $request->user() ? Gate::allows('comment', $this->resource) : false,
```

That is powerful because the UI does not invent permission logic; it consumes a
server-certified answer.

This is a very good full-stack pattern.

### Reading exercise for §8

1. Compare `IssueController@index()` and `show()`. Which fields in
   `IssueResource` depend on eager loading differences?
2. Explain why `status_obj` belongs in the resource rather than making the
   frontend derive it itself.
3. Find one field in `IssueResource` that is a pure model field, one that is a
   transformed field, and one that is a computed field.

---

## §9 The AI Summary Pipeline

### NestJS translation first

The AI subsystem is the most “architected” part of the backend. If you came from
NestJS, think of it as a provider graph built around an abstraction:

```text
Job -> facade-like entrypoint -> manager -> chosen strategy driver -> value object result
```

Laravel expresses that using:

- interface
- manager
- facade
- service provider
- queued job

### The parts and their jobs

- `SummaryGeneratorInterface` -> contract
- `LlmDriver` -> primary external AI strategy
- `RulesDriver` -> deterministic fallback strategy
- `SummaryManager` -> chooses driver
- `Summary` facade -> readable entry point
- `SummaryResult` -> immutable result carrier
- `GenerateSummaryJob` -> async execution and retry/fallback orchestration
- `SummaryServiceProvider` -> container registration
- `AppServiceProvider::bootAiSettings()` -> DB settings -> config bridge

### Why the interface matters

The interface forces both drivers to return the same output shape and failure
contract. That means the job is insulated from driver-specific details.

This is genuine Dependency Inversion, not ceremonial abstraction.

### Why `SummaryManager` is such a Laravel-native design

Extending `Illuminate\Support\Manager` is an advanced but elegant move. It is
the same pattern Laravel itself uses for cache/mail/queue/filesystems.

So this project is not just using a generic strategy pattern — it is expressing
that strategy in a framework-native way.

### The DB-settings-to-config bridge is a strong design choice

`AppServiceProvider::bootAiSettings()` reads the current `AiSetting` row and
pushes it into config.

That means the summary subsystem only needs to read config. It does **not** need
to know about the DB model directly.

This lowers coupling between:

- runtime configuration persistence
- summary execution

That is one of the best design moves in the project.

### `SummaryResult` is a real value object, not just an array

Using `SummaryResult` means drivers return a named, immutable structure:

- summary
- suggested next action
- suggested next ticket
- driver

This is cleaner than ad hoc arrays because it gives the subsystem a stable
internal contract.

### Job retry and fallback split responsibilities correctly

`GenerateSummaryJob` does not try to be a smart AI client itself. Instead it:

- marks processing
- calls the summary facade
- retries on transient failure
- falls back to rules on final attempt or sync queue
- persists final result
- fires `SummaryCompleted`

That is a very clean async orchestration boundary.

### Reading exercise for §9

1. Trace a successful summary path from `IssueService::create()` to
   `GenerateSummaryJob::handle()` to `Summary::generate()`.
2. Explain why the “no API key” fallback belongs in `SummaryManager`, not in the
   job.
3. Compare `LlmDriver` and `RulesDriver`. Which parts of the interface contract
   force them to remain interchangeable?

---

## §10 Routes and Middleware

### Routes are the public contract map

`routes/api.php` now tells a slightly richer story than earlier sections:

- issues CRUD
- comments on issues
- categories CRUD subset
- statuses CRUD subset + migrate-and-delete workflow
- shares nested under issues, shallow for individual share ops
- AI endpoints
- settings endpoints
- SSE stream endpoint

This is a good example of a route file acting like an application map.

### `apiResource()` is compact, but you must think in expansions

Laravel route files can look tiny because helpers like `apiResource()` expand to
multiple endpoints.

As a reader, always mentally expand them.

### Configurable statuses changed the route architecture

This branch added:

```php
Route::middleware('auth')->apiResource('statuses', StatusController::class)
    ->only(['index', 'store', 'update', 'destroy']);
Route::middleware('auth')->post('statuses/{status}/migrate-and-delete', [StatusController::class, 'migrateAndDelete']);
```

That shows a nice pattern:

- use resource routes for standard CRUD
- add one explicit custom route for a workflow that is not plain CRUD

`migrate-and-delete` is a business workflow route, not a resource-default action.

### Invokable controllers and SSE

The SSE route uses:

```php
Route::middleware('auth')->get('issues/{issue}/stream', IssueSseController::class);
```

That means `IssueSseController` is an **invokable controller** with `__invoke()`.

This is a nice Laravel convention for single-action endpoints.

### Middleware role in this app

Most routes use `auth`, and the app also configures stateful API behavior in
`bootstrap/app.php`.

So route files declare access at the coarse level, while policies declare
object-specific permissions at the fine level.

### Reading exercise for §10

1. Run route:list and group routes into: resource CRUD, custom workflow, auth,
   and streaming.
2. Explain why `migrate-and-delete` should not be forced into a standard REST
   destroy route.
3. Find one route that relies on route model binding for `IssueStatus` and one
   for `Issue`.

---

## §11 Design Patterns Audit

### The major patterns actually used in this repo

| Pattern | Where | Why it matters |
|---|---|---|
| MVC-ish Laravel layering | routes/controllers/models/views | base application structure |
| Form Request | `app/Http/Requests/*` | centralized validation |
| Policy | `app/Policies/*` | centralized authorization |
| Service Layer | `IssueService` | workflow orchestration |
| Active Record | Eloquent models | Laravel-native data access style |
| Model Event / Observer-like hook | `booted()` methods | automatic invariant maintenance |
| Query Scopes | `Issue` model | reusable query language |
| Manager | `SummaryManager` | framework-native driver resolution |
| Strategy | `LlmDriver`, `RulesDriver` | swappable summary generation |
| Facade | `Summary` | readable app entrypoint |
| Value Object | `SummaryResult` | stable immutable driver output |
| Resource Transformer | `IssueResource` | stable API contract |
| Optimistic Locking | `IssueService::update()` | safe concurrent mutation |
| Database-backed workflow metadata | `IssueStatus` | runtime-configurable status system |
| Composable shared state | Vue composables | lightweight frontend architecture |
| Optimistic UI with rollback | `useKanbanBoard` | fast UX with consistency recovery |

### Which patterns are most worth internalizing

If you only remember four, remember these:

1. **Form Request + Policy + thin controller**
2. **Model scopes + Eloquent relationships**
3. **Service layer for non-trivial workflow**
4. **Manager + Strategy + Facade for pluggable subsystems**

Those four explain most of the codebase.

---

## §12 Best Practices Audit

### Practices this repo uses well

| Practice | Where | Why it is good |
|---|---|---|
| Constructor injection | controllers/providers | explicit dependencies |
| Form Requests | issue/comment/share/status requests | validation separation |
| Policies | issue/comment | centralized auth |
| Enum casting | `Issue`, `IssueShare` | type-safe domain values |
| Eager loading + counts | `IssueController@index/show` | avoids N+1 and underfetching |
| Resource shaping | `IssueResource` | stable contract |
| Queue offloading | `GenerateSummaryJob` | faster requests, retries |
| Explicit fallback strategy | summary subsystem | resilient AI path |
| Soft deletes | `Issue` | safe deletion semantics |
| Focused service usage | `IssueService` only where needed | avoids ceremony |
| DB-backed configurable workflow state | `IssueStatus` | flexible product evolution |
| Shared composables instead of overbuilt store | `resources/js/composables/*` | right-sized frontend state |

### Practices intentionally not used

| Not used | Why that is reasonable here |
|---|---|
| Repository layer everywhere | Eloquent + scopes already express intent clearly |
| DTO class for every API output | `JsonResource` already covers response shaping |
| Role-based auth system | object-specific sharing model fits policies better |
| Pinia/Vuex global store | board state is manageable with composables |
| WebSocket stack | SSE is enough for one-way summary completion |

### One nuanced best-practice note

This repo generally follows Laravel best practices, but it also shows a mature
truth: sometimes good architecture includes tactical implementation details, like
the manual `updated_at` bump for deterministic optimistic locking.

So “best practice” is not about purity. It is about keeping compromises local,
documented, and justified.

---

## §13 What's Framework vs. Custom

### A practical classification table

| Component | Classification | Notes |
|---|---|---|
| Routing, middleware, model binding | Framework-native | core Laravel request plumbing |
| Eloquent model base, relationships, casts, scopes convention | Framework-native | used idiomatically |
| Form Request mechanism | Framework-native | custom rule content lives inside |
| Policy mechanism / Gate | Framework-native | authorization logic is custom |
| Service container / providers / singleton binding | Framework-native | binding decisions are custom |
| Queue/job system | Framework-native | job workflow is custom |
| Inertia bridge | Package-provided | app-specific page/component design on top |
| Breeze auth scaffold | Package-provided | customized branding/UI on top |
| Horizon | Package-provided | queue observability |
| shadcn-vue / reka-ui | Package-provided | UI primitives only |
| `IssueService` | Custom architecture | workflow logic |
| `SummaryGeneratorInterface` / drivers / manager wiring | Custom architecture | pluggable AI subsystem |
| `IssueStatus` runtime status system | Custom architecture | configurable workflow states |
| `IssueResource` field mapping | Custom architecture | frontend contract |
| Vue composables | Custom architecture | frontend state/workflow organization |
| `vault/` docs and ADR discipline | Custom architecture/process | not Laravel, but very valuable |

### The main lesson of this classification

Laravel gives the building blocks. The app decides:

- where to split responsibilities
- which concepts are static enums vs runtime tables
- which workflows deserve services
- how to expose a stable frontend contract

That is the line between framework skill and architecture skill.

---

## §14 Frontend Architecture

### Why the frontend belongs in this architecture course

This project is not “backend Laravel plus random JS.” The frontend is part of
the architectural design.

It uses:

- Inertia for page delivery
- Vue 3 for UI
- TypeScript for contracts
- composables for reusable state/workflow logic

### The important frontend folders

- `resources/js/Pages` -> page-level entrypoints
- `resources/js/Layouts` -> application shells
- `resources/js/components` and `resources/js/Components` -> reusable UI/domain components
- `resources/js/composables` -> shared state and async logic
- `resources/js/types` -> frontend contract types

### Composables are the frontend analogue of services

This repo uses composables as the main frontend architecture tool.

Examples:

- `useKanbanBoard` -> board data loading, pagination, drag/drop updates
- `useIssueDetail` -> detail fetch/patch/delete/conflict handling
- `useSummaryStream` -> SSE + polling fallback
- `useStatuses` -> singleton-like status cache
- `useIssueFilters` -> shared board filter state

These are not just helper functions. They are where frontend workflow logic
lives.

### Shared module-scoped refs are a deliberate store alternative

Several composables use module-scoped refs so every consumer shares one state
instance. That gives many benefits of a global store without introducing Pinia.

This is a good example of choosing the smallest architecture that solves the
problem.

### Optimistic UI is one of the strongest frontend patterns here

`useKanbanBoard` performs drag/drop updates optimistically and rolls back on
failure. `useIssueDetail` also detects 409 conflicts and refreshes the issue.

That means the frontend is not just a passive renderer; it actively participates
in consistency management.

### SSE fallback design is excellent teaching material

`useSummaryStream` starts with EventSource, counts repeated failures, and falls
back to polling when needed.

That is robust frontend architecture, not toy demo code.

### Full-stack contract awareness

The frontend depends heavily on the shape defined by `IssueResource`, including:

- `status` slug
- `status_id`
- `status_obj`
- `can_comment`
- comments array when loaded

That means backend resource design and frontend composables must evolve together.

### Reading exercise for §14

1. Open `useStatuses` and explain why it behaves like a singleton cache.
2. Open `useIssueDetail` and find the exact place where optimistic locking is
   surfaced to the UI.
3. Open `useSummaryStream` and describe when it switches from SSE to polling.
4. Compare `useKanbanBoard` to a Pinia store mentally. What benefits does this
   smaller pattern give for this project size?

---

## Course Completion Notes

### What is now fully drafted

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
- §14 Frontend Architecture

### How to use the completed course

Recommended study order now:

1. §1–§2 to get the skeleton
2. §3–§6 to learn the backend layering
3. §7–§10 to understand the most important cross-cutting architecture seams
4. §11–§13 to consolidate pattern recognition
5. §14 to connect the backend architecture to the Vue/Inertia frontend

### Final framing

If you come from NestJS, the most important adaptation is this:

> Laravel does not usually ask you to think in modules first. It asks you to think in lifecycle boundaries and framework conventions first, then apply architecture on top.

That is exactly what this project demonstrates.

---

*Source of truth: if this document and the current branch's code disagree,
trust the code and update the course.*
