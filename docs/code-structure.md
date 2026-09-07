# Code Structure

Everything application-specific lives under `apps/web/`. The repository root
otherwise holds only `README.md`, `CHANGELOG.md`, `original_prompts.md`,
`docs/` (this directory), and `docs/plans/` (planning/history records).

```
apps/web/
├── public/                 Web-accessible document root — the ONLY directory
│                           that should be exposed to a web server.
│   ├── index.php           Front controller: builds Config, Kernel, Request;
│                           dispatches; emits the Response.
│   └── css/app.css         Single plain functional stylesheet.
├── src/                    PSR-4 autoloaded under App\, organized by domain.
├── templates/              Plain PHP-include view templates.
├── db/migrations/          Numbered, plain-SQL migration files.
├── bin/                    CLI entry points: migrate.php (migration runner,
│                           part of App\) and deploy.sh (ops-only SFTP/SSH
│                           deploy script — not part of the App\ application;
│                           see "Deployment tooling" below).
├── deploy.conf.example     Committed template for deploy.conf (gitignored;
│                           read by bin/deploy.sh). Mirrors .env/.env.example.
├── docker-compose.yml      Local MySQL 8.0 for dev/integration tests.
├── docker/mysql-init/      First-boot SQL (creates app user + both DBs).
├── tests/
│   ├── Unit/               Mirrors src/, runs against FakeDb/ArraySession.
│   └── Integration/        Runs against real Dockerized MySQL.
├── composer.json / composer.lock
└── phpunit.xml.dist
```

## `src/` by namespace

### `App\Config`

- `Config.php` — loads key/value pairs from a `.env`-style file, with real
  process environment variables taking priority over file values.
  `Config::fromArray()` builds one in-memory for tests. `get($key, $default)`
  is the read accessor used everywhere (e.g. `HOMEPAGE_RECIPE_COUNT`,
  `DB_*`).

### `App\Http`

- `Router.php` — path/method → handler matching with `{param}` route
  segments and a middleware pipeline (`pipe()`).
- `Request.php` / `Response.php` — request/response value objects.
- `MiddlewareInterface.php` — the contract every middleware implements.
- `Session/` — `SessionInterface`, `PhpSession` (real, wraps `$_SESSION`),
  `ArraySession` (in-memory test double, used throughout `tests/Unit/`).
- `Csrf/CsrfTokenManager.php` — CSRF token generation/validation/hidden-field
  rendering, bound to the session.
- `Middleware/` — `CsrfMiddleware`, `RequireAuthMiddleware` (redirect to
  `/login` if unauthenticated), `OptionalAuthMiddleware` (attaches the
  current user if present, never blocks — the basis of dual-mode
  owner/other/anonymous view routes; see `architecture.md`).

### `App\Db`

- `DbInterface.php` — storage contract (`fetchAll`/`fetchOne`/`execute`/
  `lastInsertId`/transaction methods) that every repository depends on.
- `PdoDb.php` — the real PDO-backed implementation; `fromConfig()` builds the
  connection from `Config`.
- `FakeDb.php` — in-memory test double recognizing the SQL shapes the app's
  repositories emit (parameterized `WHERE`/`ORDER BY`/`LIMIT`, `UPDATE`,
  `DELETE`, `INSERT` with auto-incrementing ids); real DDL is a no-op.
- `MigrationRunner.php` — applies `db/migrations/*.sql` in filename order,
  tracks state in `schema_migrations`, strips `--` line comments before
  splitting statements on `;` (so a semicolon inside a comment can't
  truncate a statement).

### `App\View`

- `Renderer.php` — PHP-include renderer (`render()`, `renderWithLayout()`)
  plus the static `e()` HTML-escaping helper used throughout `templates/`.

### `App\Auth`

- `UserRepository.php` — PDO-backed CRUD against `users`
  (`findByUsername`/`findByEmail`/`findById`/`insert`).
- `AuthService.php` — `register()` (hashes via `password_hash`, rejects
  duplicate username/email), `login()` (accepts username-or-email,
  `password_verify()`s, sets the session, rotates the session id),
  `logout()` (clears the session, rotates the session id again).
- `AuthController.php` — HTTP glue for `showRegisterForm`/`register`/
  `showLoginForm`/`login`/`logout`, with basic field validation and
  form-repopulation on error.
- `Exception/` — `DuplicateUsernameException`, `DuplicateEmailException`,
  `InvalidCredentialsException` (the same exception is used for "unknown
  account" and "wrong password," deliberately, so a caller can't
  distinguish the two).

### `App\Recipes`

- `RecipeRepository.php` — PDO-backed CRUD on `recipes`, plus
  `findRecentPublic($limit)` for the homepage showcase.
- `RecipeIngredientRepository.php` — CRUD on `recipe_ingredients`;
  `replaceAllForRecipe()` deletes and reinserts the whole set for a recipe.
- `RecipeService.php` — the recipe domain's business rules: dual-mode
  visibility (`viewForUser()`), ownership enforcement (`requireOwned()`,
  used by edit/delete/toggle), `recentPublic()` for the homepage. Holds the
  `CATEGORIES`/`INGREDIENT_TYPES` constants that must stay in sync with the
  `ENUM(...)` definitions in the recipes/recipe_ingredients migrations.
- `RecipeController.php` — HTTP glue: `listOwn`, `showCreateForm`/`create`,
  `show` (dual-mode: owner/other/anonymous), `showEditForm`/`update`,
  `delete`, `toggleVisibility`.
- `Exception/` — `RecipeNotFoundException` (used both for "doesn't exist"
  and "exists but private and not yours" — the source of the 404-not-403
  behavior), `RecipeAccessDeniedException` (a genuine ownership violation on
  a mutating action, mapped to 403).

### `App\Batches`

- `BatchRepository.php` — PDO-backed CRUD on `batches`.
- `BatchLogEntryRepository.php` — per-row CRUD on `batch_log_entries`,
  ordered by `entry_date ASC` (unlike ingredients, entries are edited one at
  a time, not replaced as a set).
- `BatchService.php` — mirrors `RecipeService`'s dual-mode visibility
  pattern (`viewForUser()`/`requireOwned()`); `create()` additionally checks
  that the target `recipe_id` belongs to the creating user, reusing
  `App\Recipes\Exception\{RecipeNotFoundException,RecipeAccessDeniedException}`
  for that check; log-entry methods additionally verify a given entry
  belongs to the given batch id before allowing a mutation
  (`requireLogEntryBelongsToBatch()`); `dayNumberFor()` is the pure function
  computing a log entry's day number from `entry_date - batch.started_at`.
  Holds the `STATUSES` constant, kept in sync with the `ENUM(...)` in the
  batches migration.
- `BatchController.php` — HTTP glue mirroring `RecipeController`'s shape,
  plus the nested log-entry actions: `showAddLogEntryForm`/`addLogEntry`,
  `showEditLogEntryForm`/`updateLogEntry`, `deleteLogEntry`.
- `Exception/` — `BatchNotFoundException`, `BatchAccessDeniedException`
  (same 404-vs-403 split as Recipes).

### `App\Home`

- `HomeController.php` — `show()`, the homepage action (`GET /`): fetches
  `RecipeService::recentPublic($limit)` (limit from `HOMEPAGE_RECIPE_COUNT`)
  and renders `templates/pages/home.php`.

### `App\Kernel`

`Kernel.php` — the composition root. Registers every route
(`registerRoutes()`), lazily resolves `Session`/`Db`/`CsrfTokenManager` only
on first use, and provides small private controller factories
(`authController()`, `homeController()`, `recipeController()`,
`batchController()`). Used by both `public/index.php` and
`tests/Unit/KernelRoutesTest.php`/`KernelTest.php`.

## `templates/`

- `layout.php` — shared page chrome: nav (Recipes / Diaries links, plus
  conditional Login/Register/Logout), `$content` injection point.
- `pages/auth/{login,register}.php`
- `pages/home.php` — the homepage's public-recipe card grid.
- `pages/recipes/{index,show,form}.php` — own-list, detail (dual-mode), and
  a single shared create/edit form template.
- `pages/batches/{index,show,form,log_entry_form}.php` — own-list, detail
  (with the day-numbered diary timeline), shared create/edit batch form, and
  the shared add/edit log-entry form.

## `db/migrations/`

Six numbered, plain-SQL files, applied in filename order by
`MigrationRunner`: `0001_create_schema_migrations_table.sql`,
`0002_create_users_table.sql`, `0003_create_recipes_table.sql`,
`0004_create_recipe_ingredients_table.sql`,
`0005_create_batches_table.sql`, `0006_create_batch_log_entries_table.sql`.
See `data-model.md` for exact columns.

## `bin/`

- `migrate.php` — part of the `App\` application: reads `.env` (or
  `.env.testing` under `APP_ENV=testing`) and runs
  `MigrationRunner::migrate()` against it.
- `deploy.sh` — **ops-only tooling, not part of the `App\` application**: a
  standalone bash script that stages a production release locally
  (`composer install --no-dev --optimize-autoloader`), backs up and uploads
  it to a shared-hosting target via SSH/SFTP, runs `bin/migrate.php`
  remotely, and optionally verifies + auto-rolls-back on a failing health
  check. Reads its configuration from the gitignored `deploy.conf` (template:
  `deploy.conf.example`). See `architecture.md`'s "Deployment tooling"
  section and `README.md`'s "Automated redeploys with `bin/deploy.sh`"
  section for full details; it is excluded from every release it itself
  uploads and has no PHPUnit coverage of its own (see `test-suites.md`).

## `tests/`

- `Unit/` — mirrors `src/`'s namespace structure 1:1 (e.g.
  `tests/Unit/Recipes/RecipeServiceTest.php` tests `App\Recipes\RecipeService`).
  Runs entirely against `FakeDb`/`ArraySession` — no real database or
  network access. Also holds `KernelRoutesTest.php` and `KernelTest.php`,
  which dispatch full requests through a real `Router`/middleware pipeline
  (with injected fakes) to prove routes/middleware/controllers compose
  correctly end to end without a real webserver.
- `Integration/` — runs the same production code against a real, Dockerized
  MySQL instance. `IntegrationTestCase.php` is the shared base class (connects
  via `PdoDb::fromConfig()` against `.env.testing`; auto-skips with an
  actionable message if the database is unreachable). Organized by domain
  (`Auth/`, `Recipes/`, `Batches/`, `Db/`) plus `EndToEndJourneyTest.php`, a
  single cross-cutting smoke test. See `test-suites.md` for what's covered
  and current pass/coverage numbers.
