# Changelog

All notable changes to this project are documented in this file.

## 1.0.4 — 2026-09-08

Recipe form simplification and validation release, plus a deploy-tooling
relocation. The data model and routes are unchanged — this tightens the
create/edit recipe UX and moves an ops script to a more conventional
location.

Added:

- Recipe create/edit form: every unit field (`batch_size_unit`,
  `water_unit`, `sugar_unit`, `yeast_unit`, and each ingredient row's unit)
  and every type field (`sugar_type`, `yeast_type`) is now a constrained
  `<select>` dropdown instead of free text, backed by new `RecipeService`
  constants (a shared brewing-units list reused across most unit fields,
  with yeast using its own narrower `packet`/`g`/`mL` list; fixed
  `sugar_type`/`yeast_type` option sets) and validated server-side the same
  way `category`/`ingredient_type` already were — an invalid or empty
  submitted value falls back to `null` (these are nullable columns), not a
  hard rejection.
- An inline hint on the form clarifying Batch Size (the recipe's total
  finished-product yield) versus Water Quantity (the water actually used,
  since some is lost to boil-off and grain absorption) — the two were
  previously easy to confuse.
- `target_og`/`target_fg` are now range-constrained to 0.990–1.300
  (validated both client-side via HTML5 `min`/`max`/`step` and
  server-side), and the form shows a live-updating estimated ABV figure as
  the user types, using the standard `(OG − FG) × 131.25` formula (or
  potential ABV assuming full attenuation to 1.000 if FG isn't filled in
  yet). This is the app's first client-side JavaScript
  (`apps/web/public/js/recipe-form.js`) — a small, unobtrusive vanilla-JS
  progressive enhancement, no framework, no build step; the form remains
  fully functional and submittable with JavaScript disabled.
- Ingredient rows now default to 3 visible rows (down from 6), with a
  client-side "Add ingredient" button to append more as needed, instead of
  always rendering a long block of mostly-blank rows.
- The Description field no longer visually implies it's required, and the
  form's quantity/unit/type fields are grouped into compact side-by-side
  rows instead of a long vertical stack.

Changed:

- `bin/deploy.sh` moved from `apps/web/bin/deploy.sh` to the repository
  root (`bin/deploy.sh`); it's now invoked from the repo root instead of
  `apps/web/`. `apps/web/bin/migrate.php` is unaffected. `README.md` and
  `/docs` updated to reference the new location.

Explicitly not included in this release: no database schema/migration
changes (the new unit/type constraints are enforced at the application
layer, not via new `ENUM` columns); existing recipes with free-text
`sugar_type`/`yeast_type` values outside the new fixed lists (e.g. a
specific yeast strain name) will lose that exact value if re-saved through
the edit form, since it can no longer be represented in the closed
dropdown — a known, accepted tradeoff of moving to constrained selection.

## 1.0.3 — 2026-09-08

Bug-fix release. Both fixes were discovered during the user's first real
production deploy to shared hosting, not new feature work — the app's
behavior is otherwise unchanged from 1.0.2.

Fixed:

- Missing `apps/web/public/.htaccess`: on Apache-based hosts (the common
  case for shared hosting), every route other than the homepage
  (`/register`, `/login`, etc.) returned a raw Apache 404 instead of
  reaching the app's router, because Apache has no built-in
  fallback-to-`index.php` behavior the way PHP's `php -S` dev server does.
  The homepage worked only because Apache's `DirectoryIndex` serves
  `index.php` for a bare directory request. Added the standard
  front-controller rewrite file (serves real files/directories directly,
  routes everything else through `index.php`), verified end to end against
  a real Apache container.
- `MigrationRunner` now throws a clear, actionable
  `IncompatibleMigrationsTableException` — instead of a cryptic raw
  `PDOException` — when the configured database already has a
  pre-existing `schema_migrations` table with an incompatible structure
  (for example, a database shared with another application). No auto-fix
  is attempted; the goal is a loud, clear failure instead of a confusing
  one.

Explicitly not included in this release: no new application features,
templates, routes, or database schema changes.

## 1.0.2 — 2026-09-07

Ops/release-engineering tooling release. No application/user-facing
behavior changed — the app itself is unchanged from 1.0.1.

Added:

- `apps/web/bin/deploy.sh`, an SFTP/SSH deploy script for shared hosting
  that automates uploading a production-ready release, running the
  database migrations, and (optionally) verifying the deploy: builds a
  local staged copy (`composer install --no-dev --optimize-autoloader`),
  backs up the existing remote release before overwriting it (timestamped,
  with configurable retention/pruning), uploads via SFTP batch mode
  (excluding `.git`, `vendor/`, `.env*`, `tests/`, `docker*`, `deploy.conf`,
  `phpunit.xml.dist`, `.phpunit.result.cache`, `build/`), runs
  `bin/migrate.php` remotely, and — if a health-check URL is configured —
  automatically rolls the code back to the previous release on a failing
  post-deploy health check. Includes a `--dry-run` mode (zero network
  activity), a confirmation prompt before destructive steps (skippable
  with `--yes`/`-f`), and structured timestamped logging.
- `apps/web/deploy.conf.example`, a committed template for the gitignored
  `apps/web/deploy.conf` the deploy script reads its configuration from
  (host/port, SSH and SFTP credentials, remote path, remote PHP binary,
  backup retention, optional health-check URL).
- A new README section, "Automated redeploys with `bin/deploy.sh`",
  documenting the script's prerequisites, configuration, usage, safety
  mechanisms, and — importantly — that automatic rollback only restores
  the previous code release (database migrations are not automatically
  reverted, since this codebase's migration runner is forward-only), with
  a documented manual rollback procedure for the database.

Explicitly not included in this release: no application code, templates,
routes, or database schema changed. This is pure deploy/ops tooling for
operators who already have a working 1.0.1 (or later) installation on a
shared host with SSH/SFTP access; it does not affect what end users see or
do in the app.

## 1.0.1 — 2026-09-06

First real release. The previous `1.0.0` tag only shipped a bare project
scaffold (Composer manifest and test harness, no application code); this
release is the first to actually build and ship working functionality.

Added:

- Homepage showcase of the most recently updated **public** recipes
  (card grid: name, category, link to detail view), with a graceful empty
  state when there are none yet.
- Clear separation between "Recipes" and "Diaries" in the navigation, each
  with its own owner-facing listing view of the logged-in user's own
  recipes / own batches.
- Per-recipe and per-batch visibility toggle (`is_public`, private by
  default, owner-only), with enforced access control: private resources
  are invisible (404) to everyone but their owner, including anonymous
  visitors and other logged-in users; public resources are viewable by
  anyone at their normal detail-view URL.
- Session-based user registration and login (password hashing via
  `password_hash`/`password_verify`, duplicate username/email rejection).
- Full recipe CRUD (create/list/view/edit/delete), including ingredient
  rows, scoped to the owning user.
- Full batch ("diary") CRUD, including dated log entries rendered as a
  day-numbered timeline (`entry_date - batch.started_at`).
- `README.md` documenting the app, its domain glossary, project structure,
  and generic shared-hosting deployment instructions.

Explicitly not included in this release (deferred, not removed or
regressed — these never existed in a shipped version): sharing tokens,
QR codes, an admin area, and antispam/rate-limiting. These appeared in
early planning for the never-actually-built v1.0.0 scope and remain a
forward-looking non-goal for now.

## 1.0.0

Tag only; shipped a bare project scaffold (Composer manifest, PHPUnit
harness, empty directory structure) with no application code, templates,
routing, or database layer.
