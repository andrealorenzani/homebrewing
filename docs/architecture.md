# Architecture

## Overview

Homebrewing is a server-rendered PHP web application with no framework. A
single app lives under `apps/web/`: plain PHP source (PSR-4 autoloaded under
the `App\` namespace), plain PHP-include templates, plain SQL migrations, and
a MySQL/MariaDB database accessed through PDO. There is no client-side
JavaScript framework, no build step, and no ORM — deliberately, given the
app's size. There is exactly one client-side JavaScript file in the whole
app, `public/js/recipe-form.js` (see "Client-side JavaScript" below); every
other page is plain server-rendered HTML with zero JS.

Request lifecycle: a web server (or `php -S`) routes every request to
`apps/web/public/index.php`, the single front controller. It bootstraps
Composer's autoloader, builds a `Config` from `.env`, constructs an
`App\Kernel`, builds an `App\Http\Request` from PHP superglobals, dispatches
it through the kernel's `Router`, and emits the resulting `Response`.

## Kernel

`App\Kernel` is the composition root: it owns the `Router`, the `Renderer`,
and (lazily) the `Config`-backed `SessionInterface` and `DbInterface`, and
registers every route in one place (`registerRoutes()`). It is used both by
the real front controller and directly by tests (`KernelRoutesTest` dispatches
requests through a `Kernel` built with an injected `FakeDb`/`ArraySession`,
exercising the full routing/middleware pipeline without a real webserver or
database).

Session and DB connections are resolved **lazily** — only the first time a
dispatched route actually needs them (`session()`/`db()`/`csrf()` private
accessors on `Kernel`) — not eagerly in the constructor. This means
constructing a `Kernel` (e.g. for a `/healthz` check, or in a unit test that
never touches an authenticated route) never opens a real PHP session or a
real database connection unless a route that needs one is actually
dispatched. Route-level middleware objects are likewise constructed lazily,
inside small closures, at dispatch time rather than at route-registration
time.

Controllers are built by small private factory methods on `Kernel`
(`authController()`, `homeController()`, `recipeController()`,
`batchController()`), each wiring together that feature's service and
repository classes against the lazily-resolved `Db`/`Session`.

## HTTP layer (`App\Http`)

- **`Router`** — maps HTTP method + path (with `{param}` segments, e.g.
  `/recipes/{id}`) to a handler closure, with support for both
  route-specific and route-registration-order middleware. Dispatch composes
  the middleware pipeline around the handler via `pipe()`.
- **`Request` / `Response`** — small immutable-ish value objects (`with*()`
  clone semantics) wrapping method, path, query/body params, route
  parameters, headers, status code, and body. `Response::json()` is used for
  `/healthz`; all page routes return rendered HTML.
- **`MiddlewareInterface`** — a single `handle(Request, callable $next):
  Response` contract implemented by all middleware below.
- **Session (`App\Http\Session`)** — `SessionInterface` with two
  implementations: `PhpSession` (wraps native `$_SESSION`, used in
  production) and `ArraySession` (an in-memory test double used by every
  unit test and by `KernelRoutesTest`). This is what lets the whole
  authentication/authorization pipeline be unit-tested without a real PHP
  session or cookies.
- **CSRF (`App\Http\Csrf\CsrfTokenManager` + `Middleware\CsrfMiddleware`)**
  — a session-bound token generated per session, exposed to templates as a
  hidden-field helper, and validated by `CsrfMiddleware` on every
  state-changing (`POST`) route. A missing/invalid token yields a 419
  response rather than executing the action.
- **Auth middleware — the "dual-mode" pattern.** Two distinct middleware
  classes implement two distinct policies, and the choice of which one
  guards a given route is itself part of the app's authorization model:
  - `RequireAuthMiddleware` — used on every route that mutates state
    (create/edit/delete/toggle-visibility for recipes and batches, own-list
    pages, log-entry CRUD). Redirects to `/login` if there is no
    authenticated user in the session.
  - `OptionalAuthMiddleware` — used on routes that must serve **three**
    kinds of caller with different results from the same URL: the owner, a
    different logged-in user, and an anonymous visitor. It always attaches
    an `auth_user_id` request attribute when a session exists, but never
    blocks or redirects when one doesn't. It guards the homepage (`/`, so
    the nav can show Login/Register vs. Logout) and the read-only detail
    views (`/recipes/{id}`, `/diaries/{id}`), which implement the
    visibility rules described in `business-logic.md`.

## View layer (`App\View\Renderer`)

A minimal PHP-include renderer, not a templating engine: `render()` executes
a plain PHP template file with a given variable scope and returns the
captured output (buffered via `ob_start()`/`ob_get_clean()`, safely closed
via `try`/`finally` even if the template throws); `renderWithLayout()` renders
a page template first and then injects its output into `templates/layout.php`
as `$content`. A static `Renderer::e()` helper wraps `htmlspecialchars()` for
output-escaping in templates. There is no separate templating language,
partial-compilation step, or caching layer — every template is plain PHP with
access to whatever variables its controller passed in.

`templates/layout.php` provides the shared page chrome: a top nav with
distinct "Recipes" and "Diaries" links (routing to each domain's own-listing
view) plus conditional Login/Register vs. Logout controls based on whether
the current request has an authenticated user attribute. `public/css/app.css`
is a single plain, functional stylesheet (nav, container, forms, buttons,
`.card-grid`/`.card` for the homepage and list views, `.form-errors`, plus a
small "compact grouped rows" block for the recipe form's quantity+unit
groupings and hint text) — there is no separate "parchment/journal"
decorative theme (see Non-goals below).

### Client-side JavaScript

`public/js/recipe-form.js` is the app's one and only client-side script — a
small, framework-free, build-step-free vanilla-JS file, loaded via a plain
`<script src="/js/recipe-form.js" defer>` only from the recipe create/edit
template (`templates/pages/recipes/form.php`). It does two narrowly-scoped
things, both progressive enhancements that the form works correctly without:

- **Add-ingredient row cloning** — clicking `#add-ingredient-row` clones an
  inert `<template>` block already rendered by the server, re-indexes its
  `id`/`label[for]` attributes, and appends it to `#ingredient-rows`, so a
  user can add more than the default 3 rendered ingredient rows without a
  page reload. `name="ingredient_*[]"` attributes are left untouched
  (they're PHP array fields paired by array position server-side, not by a
  shared index).
- **Live ABV estimate** — on `input` events on the target-OG/target-FG
  fields, computes and writes an estimated ABV percentage into an
  `<output id="abv-estimate">` element, per the formula in
  `business-logic.md`.

Both behaviors no-op safely if their expected DOM elements are missing, and
the form remains fully functional and submittable with JavaScript disabled
(verified manually — a static form renders the default 3 ingredient rows and
a static ABV placeholder, and still submits and persists correctly). There is
still no JS framework, no bundler/build step, and no other page in the app
loads any script — this remains a deliberately minimal, single-purpose
addition, not the start of a client-side architecture shift.

## Data layer (`App\Db`)

- **`DbInterface`** — the storage contract every repository depends on:
  `fetchAll`/`fetchOne`/`execute`/`lastInsertId` plus transaction methods
  (`beginTransaction`/`commit`/`rollBack`).
- **`PdoDb`** — the real implementation, a thin wrapper around PDO.
  `PdoDb::fromConfig(Config $config)` builds a MySQL DSN from `DB_HOST` /
  `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` / `DB_CHARSET`.
- **`FakeDb`** — an in-memory double used by every unit test (Repositories,
  Services, Controllers, the migration runner). It recognizes the specific
  SQL shapes the app's repositories actually emit: unconditional and
  parameterized (`?`-only) `WHERE`, `ORDER BY`, and `LIMIT` (both bound and
  literal) on `SELECT`; `UPDATE ... SET ... WHERE ...`; `DELETE FROM ...
  [WHERE ...]`; and plain `INSERT`, auto-assigning an incrementing `id` per
  table to mirror `AUTO_INCREMENT` + `lastInsertId()`. Anything else
  (real DDL such as `CREATE TABLE`) is a documented no-op — `FakeDb` proves
  control flow, not SQL/dialect correctness; real DDL and real MySQL
  behavior (`ON DELETE CASCADE`, `ENUM` validation, unique constraints,
  `LIMIT` binding rules, etc.) is only proven against the real Dockerized
  database in the integration suite (see `test-suites.md`).
- **`MigrationRunner`** — reads `.sql` files from `db/migrations/` in
  filename order, tracks applied versions in the `schema_migrations` table
  (`ensureMigrationsTableExists()`, `pendingMigrations()`,
  `appliedVersions()`, `migrate()`), and is idempotent (re-running it is a
  no-op once everything is applied). It strips `-- ...` line comments before
  splitting a migration file into individual statements on `;`, so a
  semicolon that appears inside a SQL comment is never mistaken for a
  statement terminator. `apps/web/bin/migrate.php` is the CLI entry point;
  it reads `.env` (or `.env.testing` when `APP_ENV=testing`) and invokes the
  runner.

## Dockerized MySQL for dev/test

There is no assumption of a host-installed MySQL/MariaDB. Local development
and the integration test suite both run against a MySQL 8.0 container
defined in `apps/web/docker-compose.yml`:

- Container `homebrewing-mysql`, image `mysql:8.0`, published on host port
  **3307** (not the default 3306, to avoid clashing with any system
  MySQL/MariaDB already using 3306) mapped to the container's 3306.
- Data persisted in a named Docker volume, `homebrewing-mysql-data`, so
  restarts (`docker compose up -d` after a stop) don't lose applied
  migrations or dev data. `restart: unless-stopped` keeps it running across
  host reboots once started.
- `apps/web/docker/mysql-init/01-init.sql` runs once, on first volume
  creation, to create the `homebrewing` app user and both the
  `homebrewing_dev` and `homebrewing_test` databases with full privileges.
- `apps/web/.env` (dev) and `apps/web/.env.testing` (integration tests) point
  at `127.0.0.1:3307` with the `homebrewing_dev` / `homebrewing_test`
  database names respectively; both files are gitignored.
- Bring it up with `cd apps/web && docker compose up -d`; it is safe to leave
  running indefinitely.

## Visibility enforcement (owner / other / anonymous)

This is the app's one genuinely cross-cutting authorization pattern, applied
identically to recipes and batches. Each of the two domains has an
`is_public BOOLEAN NOT NULL DEFAULT 0` column (private by default) and a
service (`RecipeService`, `BatchService`) that enforces the same rule at read
time, in the service layer, not just by hiding the resource from listings:

- The **owner** always has full access (view/edit/delete/toggle), regardless
  of `is_public`.
- **Any other actor** — another authenticated user, or an anonymous
  (logged-out) visitor — gets **view-only** access to a resource **if and
  only if `is_public` is true**.
- A resource that is private and not owned by the viewer is treated
  identically to a resource that doesn't exist at all: both throw the same
  "not found" exception (`RecipeNotFoundException` / `BatchNotFoundException`),
  which the controller maps to a plain **404**. This is deliberate: a **403**
  would confirm that *something* exists at that URL, leaking the existence of
  a private resource to someone who merely guessed or was given an id. A 404
  never distinguishes "doesn't exist" from "exists but is private and not
  yours."
- A genuine ownership violation on a *mutating* action (a non-owner trying to
  edit, delete, or toggle visibility on a resource they can see is public, or
  attempting the same on a private one they have no view rights to either
  way) is a distinct code path (`requireOwned()` in each service) that throws
  a separate "access denied" exception (`RecipeAccessDeniedException` /
  `BatchAccessDeniedException`), mapped to a real **403**. The 404-vs-403
  split is intentional: view routes never reveal existence; mutation routes
  on a resource whose existence the actor could otherwise establish do return
  a real 403.
- The `OptionalAuthMiddleware` route guard described above is what makes it
  possible for a single view route (`GET /recipes/{id}`, `GET /diaries/{id}`)
  to correctly serve all three actor types from one URL.

## Deployment tooling (ops-only, outside the `App\` application)

`bin/deploy.sh` (at the repository root, invoked from there) is a standalone bash script for pushing a release to
a generic PHP/MySQL shared-hosting target over SSH/SFTP with password
authentication. It is deliberately **not** part of the `App\` PSR-4
application — it never runs on the server, is not autoloaded, has no unit
tests of its own, and is excluded from every release it uploads (see
"Upload excludes" below). It exists purely to automate part of the manual
shared-hosting deployment procedure documented in `README.md`.

- **Configuration** — `apps/web/deploy.conf` (gitignored, never committed),
  bash-`source`d directly by the script; `apps/web/deploy.conf.example` is
  the committed template documenting every key (host/port, separate SSH and
  SFTP username/password pairs, remote path, remote PHP CLI binary, backup
  retention count, optional health-check URL). This mirrors the existing
  `.env`/`.env.example` pattern used for application configuration. Values
  containing shell-special characters (e.g. a password with `$` or a space)
  must be single-quoted, since the file is sourced as bash, not parsed as
  plain `KEY=VALUE` lines.
- **What it does, in order:** stage a local release copy of `apps/web`
  (excluding `.git`, `vendor/`, `.env*`, `tests/`, `docker*`, `deploy.conf`,
  `phpunit.xml.dist`, `.phpunit.result.cache`, `build/`) and run `composer
  install --no-dev --optimize-autoloader` inside that staged copy → back up
  the existing remote release directory to a timestamped sibling path (never
  deleted outright; pruned beyond a configurable retention count) → upload
  the staged release via a single SFTP batch-mode session → carry the
  previous release's `.env` forward with a same-host remote-side copy (never
  over the network) and run `bin/migrate.php` remotely → optionally `curl` a
  health-check URL and automatically roll the **code** back to the previous
  release on a non-2xx/unreachable response.
- **Rollback scope:** automatic rollback only ever restores the previous
  code release. Database migrations are forward-only (`App\Db\MigrationRunner`
  has no `down()`/revert mechanism anywhere in the codebase), so a migration
  applied during a failed deploy is never automatically reverted — only a
  manual restore from a separately-taken database dump can fully undo that.
- **Modes:** `--dry-run` (prints the full plan, zero network activity),
  `--stage-only` (builds the local staged copy and stops, for debugging the
  build step in isolation), and a normal run (confirmation prompt unless
  `--yes`/`-f`).
- See `README.md`'s "Automated redeploys with `bin/deploy.sh`" section for
  the full prerequisites, configuration key reference, usage examples, and
  manual-rollback procedure; this script automates 3 of the 6 manual
  shared-hosting deployment steps documented earlier in that same file
  (uploading code, installing PHP dependencies, running migrations) — the
  other 3 (creating the database, first-time `.env` setup, pointing the
  document root at `apps/web/public`) remain manual, one-time,
  host-console-dependent actions that cannot be safely generalized to an
  arbitrary shared host.

## Non-goals (explicitly out of scope, not gaps)

The following were discussed during early planning but are deliberately not
built in the current release. Their absence is a considered decision, not an
oversight, and none of them are half-implemented:

- **Sharing tokens / share links** — there is no separate unauthenticated
  "share URL" distinct from a public resource's normal detail-view URL.
- **QR codes** for sharing recipes or batches.
- **An admin area** of any kind (no admin role enforcement, no cross-user
  moderation UI).
- **Antispam / rate limiting** — no honeypot fields, no CAPTCHA-style
  challenge, and no rate-limit tracking table on registration or login.
- **The ornate "parchment/journal" diary theme** — the diary/batch timeline
  uses the same plain functional CSS as the rest of the app.
- **A public "browse all recipes/diaries" page** — the only public-facing
  discovery surface is the homepage's recently-updated-public-recipes
  showcase and direct-URL access to an individual public resource.

Leftover configuration keys or Composer dependencies that reference these
ideas (e.g. rate-limit or share-token env keys, the `chillerlan/php-qrcode`
package) are inert holdovers from early planning, not indications that the
feature is live.
