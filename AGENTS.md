# AGENTS.md

Project-specific agent configuration. Supplements `~/.config/opencode/AGENTS.md`.

## Project

- **Stack:** Laravel 13 + Inertia + Vue 3 + TypeScript + PostgreSQL + Redis
- **Spec:** `vault/SPEC.md` (what to build)
- **SRS:** `vault/docs/SRS.md` (how to build it — ground truth)
- **ADRs:** `vault/docs/adr/` (why decisions were made)
- **Sprint state:** `vault/sprint/PLAN.md`

## Agents

Project agents in `.opencode/agents/` are the DEFAULT for this project. Do not use global pipeline agents.

| Agent | Role |
|---|---|
| tech-lead | Task enrichment, code review |
| coder-backend | Laravel implementation |
| coder-frontend | Vue + Inertia implementation |
| qa | Test writing, regression audits |

### Workflow per task

1. tech-lead → enriches task with `## Technical Guidance`
2. qa → writes RED tests
3. coder-backend → implements until tests pass
4. coder-frontend → implements UI (skip if backend-only)
5. If the task touches UI/frontend/browser-visible behavior: run `make verify-visual` and keep the evidence artifacts
6. tech-lead → reviews diff **and visual evidence**, approves or requests changes
7. On approval: `feat(scope): description - done` → merge to dev

## Visual Verification Contract

**This gate is mandatory for any task that touches browser-visible behavior.** That includes changes under `resources/js/`, `resources/css/`, Inertia pages/layouts/forms, auth flow, navigation, Horizon access flow, Vite/runtime wiring, or anything a user can see or trigger in the browser.

Before requesting tech-lead review on such work:
1. Run `make verify-visual`
2. Keep the Playwright artifact paths from `test-results/playwright/smoke/`
3. Confirm the gate passed with zero browser `console.error` events and zero uncaught `pageerror` events

Passing the visual gate means all of the following are true:
- `make status` is green before the check starts
- `vue-tsc --noEmit` passes
- Playwright smoke passes against the live app
- Screenshots exist for the covered pages in `test-results/playwright/smoke/`

**HTTP 200 and PHPUnit alone are not sufficient evidence for frontend completion.**

**Tech-lead must reject review if visual evidence is missing** for any browser-visible task.

If a task changes a page or flow that is not already covered by the smoke spec, extend `tests/Playwright/smoke.spec.ts` in the same branch before calling the task done.

## Cold-Start Protocol

Every new session:
1. `cat vault/sprint/PLAN.md` — current sprint state
2. `ls vault/sprint/ongoing/` — resume something in progress?
3. `ls vault/sprint/backlog/` — what's next (filename sort order)?
4. `git status && git branch` — uncommitted work? which branch?
5. `make status` — is the dev environment healthy? If anything is DOWN: `make dev`

## Dev Environment — `make` + Laravel Sail

**Every dev command goes through `make` (which wraps Sail).** The Makefile in the repo root is the single source of truth — `make` alone lists all targets. Run `cat Makefile` once at session start to know what's available.

**Always-running dev rule:** the dev environment (containers + Vite + queue worker) MUST stay up for the entire session so agents can verify changes against a live app. Before and after any change that could break runtime, run `make status` — fix anything that went DOWN before continuing.

**Daily workflow targets:**

| Command | Purpose |
|---|---|
| `make dev` | Start everything (containers + vite + queue), idempotent — safe to re-run |
| `make status` | Health check all services + HTTP — run before and after changes |
| `make verify-visual` | Run the live browser verification gate (vue-tsc + Playwright smoke + screenshots) |
| `make logs` | Tail vite + queue logs |
| `make test` | Full PHPUnit suite |
| `make test-filter FILTER=ClassName` | Single test class |
| `make pint` | Auto-format PHP via Pint |
| `make migrate` / `make fresh` / `make seed` | DB ops |
| `make tinker` / `make shell` | REPL / container bash |
| `make stop` | Stop vite + queue (containers stay up) |
| `make down` | Stop everything |

**Never run bare host commands.** Host PHP is 8.1; the app needs 8.4. If you must reach for Sail directly (a target doesn't exist), use `./vendor/bin/sail <command>` — never bare `php`, `composer`, `npm`, `npx`, `vue-tsc`, or `phpunit`.

**Composer's `dev` script does NOT work for us** — it tries `php artisan serve` on the host and collides with Sail's nginx on port 80. Ignore it. `make dev` is the replacement.

**Services exposed on host:**
- App: `http://localhost`
- Vite (HMR, background): `localhost:5175`
- Postgres: `localhost:5434` (user `sail`, pass `password`, db `laravel`) — override via `FORWARD_DB_PORT` in `.env`
- Redis: `localhost:6379`

**Boost MCP requires Sail to be running** — the MCP server invokes `php artisan boost:mcp` which depends on the app being bootable. `make up` ensures this.

## Git

- `main` (stable) → `dev` (integration) → `feature/<task-slug>`
- No-FF merges to dev: `git merge --no-ff feature/<task-slug>`
- Conventional commits: `feat(scope): description`
- Final commit on feature branch: `feat(scope): description - done`
- Never force push.

## Testing Contract

1. `./vendor/bin/sail test` before any change → record baseline
2. `./vendor/bin/sail test` after every logical change
3. Previously-passing test fails → YOUR change is wrong → fix code, not the test
4. Never modify or delete existing tests
5. Task not done until full suite passes

## Tool Usage — Prefer Generators

Always use framework CLIs to generate boilerplate. Never hand-write what a generator produces.

- Laravel: `./vendor/bin/sail artisan make:{model,controller,request,policy,job,event,observer,test,seeder,factory}`
- Composer: `./vendor/bin/sail composer require <package>` (don't manually configure)
- Frontend: `./vendor/bin/sail npx shadcn-vue@latest add <component>`, `./vendor/bin/sail npm install <package>`

Customize the generated file after generation. Never skip generation to write boilerplate manually.

## MCP Servers

Project-level (in `opencode.json`):
- `laravel-boost` — schema, routes, artisan, tinker, config, error logs, 17k Laravel docs. **Requires Sail running.**
- `postgres` — direct DB queries, schema introspection (npx @henkey/postgres-mcp-server)

Globally available: `playwright`, `serena`, `context7`.

When Boost MCP is available, prefer its tools over manual artisan calls:
- `database-query` / `database-schema` > `sail artisan tinker`
- `search-docs` > Context7 for Laravel ecosystem questions

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v12

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
