# Changelog

All notable changes to this project are documented in this file.

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
