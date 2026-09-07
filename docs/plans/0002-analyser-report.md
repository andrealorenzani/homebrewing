# Analyser Report — Homebrewing Web App v1.0.1

_Produced 2026-09-06 for plan `0002-v1.0.1-release.md`. Reproduced verbatim from the analyser's output._

## Grounding check

`/docs/architecture.md`, `business-logic.md`, `code-structure.md`, `data-model.md`, and `test-suites.md` are all still literally placeholder stubs (`_(Not yet written...)_`). All factual grounding below comes from `docs/plans/0001-v1.0.0-initial-release.md`, `docs/plans/0001-analyser-report.md`, and direct inspection of `apps/web/`.

Confirmed actual repo state:
- `apps/web/composer.json` — no `"version"` field yet; requires `php >=8.1`, `chillerlan/php-qrcode ^6.0`, dev-only `phpunit/phpunit ^11.5`, PSR-4 `App\`→`src/`, `Tests\`→`tests/`.
- `apps/web/.env.example` — already anticipates `DB_*`, `SESSION_*`, and antispam/`RATE_LIMIT_*`/`SHARE_TOKEN_BYTES` knobs from the original plan, but nothing consumes them yet.
- `apps/web/phpunit.xml.dist`, `tests/bootstrap.php`, `tests/TestCase.php`, `tests/Unit/ScaffoldTest.php` exist — only the scaffold test exists (2 assertions, proves autoload wiring, no `App\` code to cover).
- `apps/web/{public,src,templates,db/migrations,bin}` each contain only a `.gitkeep` — genuinely empty. No router, no front controller, no auth, no templates, no schema, no migrations.
- No root `README.md` or `CHANGELOG.md` exist.
- Docker images already cached locally: `php:8.3-cli`, `php:8.3-apache`, `mysql:8.0`, and a custom `backend-php:latest` — usable for both dev/test DB and for running composer/phpunit given the host's missing PHP extensions (`mbstring`/`dom`/`xmlwriter`/`curl`), per T1's resume notes in the 0001 plan.
- No usable host MySQL/MariaDB credentials are reachable non-interactively (`sudo mysql` needs an interactive TTY password); `mysql:8.0` via Docker is the practical path.

This is genuinely still a from-scratch build for everything except the composer/PHPUnit scaffold — v1.0.1 must build the load-bearing slice of the original T2-T8 before it can layer the four new requirements on top.

## Business Impact

- New user-facing surface, mostly for the first time: a real homepage, real nav, real recipe/diary CRUD, and login — none of this currently exists for any user to experience. This is not an incremental UI tweak; it's shipping the app's actual front door.
- Visibility model is new product behavior with real stakes: recipes and diaries become either private (default) or public. Read per the request's own framing as **default-private, opt-in public**; must be enforced at read time, not just hidden from a listing (a private resource must 404 on direct URL access by anyone but its owner, including anonymous visitors).
- Sharing/QR tokens are out of scope for v1.0.1. Since no share-token mechanism will exist, item 4's "nor via public/share links" clause has no separate mechanism to violate — it becomes a forward-looking non-goal rather than a requirement to build now.
- Antispam/rate-limiting deferred: registration/login has no honeypot, challenge, or DB-backed rate limiting in v1.0.1 — not load-bearing for visibility enforcement (an authorization concern, not an abuse-prevention one).
- Product decisions carried into the plan (surfaced here for visibility, not blocking):
  1. Since sharing tokens are deferred, the only mechanism by which a public recipe becomes discoverable by someone other than its owner is (a) the homepage showcase and (b) direct-URL access to a public resource's normal view route (no login required). No separate "browse all public recipes" page is being built — not asked for.
  2. Diaries get no homepage showcase — item 2 asks only for a recipe card grid; item 3 only requires diaries to have their own listing view reachable via nav.
  3. N (homepage recipe count) is config-driven (default 6), not hardcoded.
  4. Private-resource-hit-by-non-owner behavior: 404 (hide existence), consistent with the original plan's sharing design for stale/guessed tokens.
  5. First-run homepage will look empty until someone flips a recipe to public — an accepted, honest consequence of default-private, not a bug.

## Architectural Impact

Nothing changes the original architecture's shape — it requires actually building the load-bearing slice of it. Per `apps/web/`'s planned layout:

- **Router/front-controller/CSRF** (from original T2) is a hard dependency for everything else. CSRF stays in scope: cheap, directly protects the new visibility-toggle and CRUD forms. New addition versus the original T2 design: an "optional auth" resolver (attaches current user to the request if a session exists, without forcing a redirect) distinct from the existing "auth required" guard, needed for the owner/other-user/anonymous × public/private access matrix.
- **View renderer + CSS** (from original T3), descoped: only the plain functional template/CSS set is needed (layout, nav, card-grid styling for the homepage). The ornate "parchment/journal" diary theme (original T11) is out of scope for v1.0.1.
- **DB abstraction + migration runner + schema** (from original T4), descoped to 6 tables: `users`, `recipes`, `recipe_ingredients`, `batches`, `batch_log_entries`, `schema_migrations`. `rate_limit_events` is dropped since antispam is deferred. **`is_public` (boolean, `NOT NULL DEFAULT 0`) is added directly to `recipes` and `batches` in their initial migration** — there's no live schema anywhere yet, so there's no reason to ship the tables without it first and migrate later. No `share_token`/`is_shared` columns now; a future sharing feature would add those via a new migration.
- **Dual-mode authorization** is a real (small) architectural addition versus the original T2 scope: route handlers for viewing a recipe/batch support three actors — owner (full access), other authenticated user (view-only if `is_public`), anonymous (view-only if `is_public`) — versus create/edit/delete/toggle routes which require "must be the logged-in owner" only.
- **DB provisioning** (from original T5), adapted to environment: since there's no reachable host MySQL/MariaDB, provisioning means standing up `mysql:8.0` via Docker for both a dev DB and a test DB, running `bin/migrate.php` against each, and documenting the invocation for future sessions.
- **Sharing (original T9) and Admin (original T10) are out of scope** for v1.0.1 — neither is load-bearing for items 1-4 given the design above.
- **README is a new architectural artifact** (repo root, outside `apps/web/`).

## Code Impact

Everything under `apps/web/src/`, `apps/web/templates/`, `apps/web/public/`, `apps/web/db/migrations/`, `apps/web/bin/` is new (currently empty):

- `src/Config/` — config loader.
- `src/Http/` — Router, Request, Response, middleware (auth-required guard, optional-auth resolver, CSRF).
- `src/Db/` — `Db` interface, PDO implementation, in-memory fake for unit tests.
- `src/Auth/` — `AuthService`, session helpers, password hash/verify (no rate-limiter/honeypot).
- `src/Recipes/` — `RecipeRepository`, `RecipeService` (CRUD + ownership + visibility toggle + ingredients).
- `src/Batches/` — `BatchRepository`, `BatchService`, log-entry CRUD, day-counter logic, visibility toggle.
- `src/View/` — minimal PHP-include renderer with escaping helpers.
- `templates/` — layout, nav, homepage, recipe list/detail/form, diary list/detail/form (functional CSS only).
- `db/migrations/` — numbered `.sql` files for the 6 tables above, including `is_public` columns.
- `bin/migrate.php` — migration runner (~100 lines).
- `apps/web/composer.json` — patch: add `"version": "1.0.1"`.
- Root `README.md` — new file.
- Root `CHANGELOG.md` — new file.

No migrations are needed to change already-shipped schema, since nothing has been applied to any live database yet.

## Testing Impact

- No host DB access confirmed. Strategy: fake/in-memory `Db` double for unit-testing service-layer branching and migration-runner control flow (no live DB needed), plus a **Dockerized `mysql:8.0` container** for integration tests. Composer/PHPUnit itself likely still needs to run inside `php:8.3-cli` (or the cached `backend-php:latest`) per T1's documented workaround for missing host PHP extensions — re-verify at the start rather than assuming extensions got installed since T1.
- **Highest-risk area: visibility enforcement.** Deliberate cross-product test matrix: {owner, other authenticated user, anonymous} x {public, private} x {view route, edit attempt, delete attempt, homepage listing}. A missed case here is a privacy leak, not a cosmetic bug.
- Authorization boundaries (user B can't edit/delete/toggle user A's resource) — same pattern as original T7/T8.
- Homepage query correctness — must never include private recipes in the "most recent" grid; explicit negative test (create a private recipe with a very recent `updated_at`, assert it's absent from homepage output).
- Realistic path to >=85% on touched code: straightforward for Router/Config/renderer/migration-runner. Auth/Recipes/Batches need both unit tests (fake repos) and integration tests (real Dockerized MySQL) to responsibly hit 85% on the ownership/visibility branches specifically.
- End-to-end journey test, descoped from original T12: register -> create recipe (private by default) -> confirm invisible to another user/anonymous and absent from homepage -> toggle public -> confirm visible to another user, anonymous, and homepage -> create a batch/diary under the recipe -> add/edit/delete log entries -> toggle batch visibility -> confirm same enforcement pattern. Runs against the Dockerized test DB.

## Proposed Task Breakdown

See the checklist in `docs/plans/0002-v1.0.1-release.md` (task IDs V1-V11) — reproduced there as the authoritative, live-tracked version of this breakdown.
