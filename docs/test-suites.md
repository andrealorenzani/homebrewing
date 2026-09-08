# Test Suites

## Strategy

Tests are split into two kinds, mirroring the app's `src/` namespace
structure, run by PHPUnit from `apps/web/`:

- **Unit tests** (`apps/web/tests/Unit/`) run against `App\Db\FakeDb` (an
  in-memory double) and `App\Http\Session\ArraySession` (an in-memory
  session) — no real database, no real PHP session, no network access. This
  covers Router/Config/CSRF/middleware control flow, the view renderer, the
  migration runner's pending/applied/ordering logic, and every
  Service/Repository/Controller's business logic, including the full
  owner/other-authenticated-user/anonymous × public/private × view/edit/
  delete/toggle authorization matrix for both Recipes and Batches.
  `tests/Unit/KernelRoutesTest.php` and `KernelTest.php` additionally
  dispatch full requests through a real `Router`/middleware pipeline (with
  fakes injected into the `Kernel`) to prove the routing/middleware/
  controller wiring itself, not just each piece in isolation.
- **Integration tests** (`apps/web/tests/Integration/`) run the same
  production code against a real, Dockerized MySQL 8.0 database. They exist
  specifically to catch real-SQL/real-dialect issues `FakeDb` can't
  (`FakeDb` no-ops real DDL and only emulates specific query shapes) — e.g.
  the project has already caught and fixed one such real bug this way (a
  migration-runner comment-stripping issue, and a `LIMIT`-must-be-a-literal-
  not-a-bound-placeholder issue against real MySQL; see `architecture.md`
  for the latter's rationale). Organized by domain: `Auth/`, `Recipes/`,
  `Batches/`, `Db/` (schema-structure assertions), plus a single top-level
  `EndToEndJourneyTest.php`.

All integration tests extend `tests/Integration/IntegrationTestCase.php`,
which connects to the `homebrewing_test` database (via `PdoDb::fromConfig()`
reading `apps/web/.env.testing`) in `setUp()`. If that connection fails —
most commonly because the Docker MySQL container isn't running —
`setUp()` catches the failure and calls `markTestSkipped()` with an
actionable message pointing at `docker compose up -d`, rather than letting a
raw `PDOException` fail the whole run. **`cd apps/web && docker compose up
-d` must be running for integration tests to actually execute**; otherwise
they report as skipped, not failed, and the reported pass count silently
drops. Always check the run summary's skip count is `0` before trusting a
"green" run.

## What's covered

- **Router/Config/CSRF/Session/middleware** — route matching with `{param}`
  segments, middleware pipeline composition, CSRF token generation and
  rejection (missing/invalid token → 419), `RequireAuthMiddleware` redirect
  behavior, `OptionalAuthMiddleware`'s always-attach-never-block behavior.
- **View renderer** — output escaping, layout composition, a real sample
  page (the homepage template) rendering correctly with real recipe data.
- **Migration runner** — pending/applied/ordering control flow against
  `FakeDb`, including a dedicated test that runs the actual six `.sql` files
  from `db/migrations/` through the runner end to end (control-flow only);
  a live-MySQL integration test (`SchemaIntegrationTest`) additionally
  asserts all 6 tables + `schema_migrations` exist, that `recipes.is_public`
  and `batches.is_public` are `NOT NULL tinyint DEFAULT 0` via
  `information_schema.COLUMNS`, and that `schema_migrations` records all 6
  expected versions in order.
- **Auth** — successful registration (row persisted, password verifiable),
  duplicate-username rejection, duplicate-email rejection, successful login
  (session set), wrong-password rejection, logout (session cleared) — both
  as unit tests (fake repository) and as integration tests against real
  MySQL unique constraints.
- **Recipes** — the full authorization matrix: owner has full access; a
  second user cannot edit/delete/toggle another user's recipe (403); a
  private recipe 404s for both an anonymous visitor and another logged-in
  user; a public recipe is viewable by both; toggling visibility (public →
  private → public again) actually changes read-access behavior each time,
  not just once. Also: ingredient-set replace-on-save, category/type
  fallback-to-`other` for unrecognized values, CRUD status codes
  (200/302/403/404/422) for every action and actor. Also (v1.0.4): every
  `*_unit`/`sugar_type`/`yeast_type` field (plus each ingredient row's
  `unit`) falls back to `null` on an invalid/empty submitted value;
  `target_og`/`target_fg` boundary behavior (0.990/1.300 accepted,
  0.989/1.301 rejected with a validation error, non-numeric rejected,
  empty/absent still accepted); the create form renders exactly
  `MIN_INGREDIENT_ROWS` (3) blank ingredient rows and an edit form with more
  than 3 existing ingredients renders all of them without truncation;
  structural assertions that the create/edit form has no remaining free-text
  `*_unit`/`*_type` inputs, renders the correct `<select>` options, the
  compact grouped-row markup, the OG/FG HTML5 constraints + ABV output
  element, and the add-ingredient button/script tag.
- **Batches / diaries** — the same authorization matrix as Recipes, plus:
  correct day-number computation (day 0 on the start date, positive after,
  negative for a backdated pre-start entry, time-component ignored); log
  entries always returned in ascending date order regardless of insertion
  order (including against real MySQL); a user cannot create a batch under
  another user's recipe; cascade-delete of a batch's log entries verified
  against a real `ON DELETE CASCADE` constraint, not just assumed.
- **Homepage** — empty state with zero public recipes; a more-recently-
  updated **private** recipe is explicitly proven excluded from the
  showcase; recency ordering across multiple public recipes; the configured
  `HOMEPAGE_RECIPE_COUNT` limit is respected; links point at the real
  detail route; nav renders both "Recipes" and "Diaries" links.
- **End-to-end journey** (`EndToEndJourneyTest.php`) — one integration test
  against real MySQL, composed at the service layer (deliberately not
  through HTTP/`Kernel`, since the HTTP-level visibility matrix is already
  covered exhaustively by the controller/`KernelRoutesTest` suites — this
  test's job is proving the service seams compose correctly end to end):
  register two users → create a recipe (asserts private by default) →
  confirm hidden from an anonymous visitor, from the other user, and absent
  from `recentPublic()` → toggle it public → confirm now visible to all
  three, and present in the showcase → create a batch under the recipe
  (asserted private *independently* of the recipe's now-public state) →
  add/edit/delete log entries → toggle the batch public → confirm the same
  visibility flip for the batch and its log entries.

## Current suite stats (last verified run)

**397 tests, 748 assertions, 0 failures, 0 skips.**

Coverage (whole-suite): **85.19% classes** (23/27), **97.54% methods**
(238/244), **99.16% lines** (1184/1194).

Two classes are intentionally accepted below 85% on methods (while still
comfortably clearing it on lines), and are not considered gaps:

- **`App\Auth\AuthService`** — 75.00% methods / 95.65% lines. The uncovered
  branch is a defensive "insert succeeded but the immediate readback of the
  just-inserted row failed" path, which cannot be triggered against either
  `FakeDb` (which never fails a readback it just wrote) or real MySQL in a
  deterministic test without artificially corrupting the connection
  mid-request.
- **`App\Recipes\RecipeService`** — 81.82% methods / 88.00% lines. The
  uncovered branches are `catch (\Throwable) { rollBack(); throw; }`
  transaction-failure paths in `create()`/`update()`, which `FakeDb` never
  triggers since it never throws mid-transaction; the realistic failure mode
  (a real MySQL constraint violation mid-transaction) is exercised
  indirectly by other integration tests' FK/constraint behavior, not by a
  forced fault injected specifically to hit this line. This percentage is
  unchanged by the recipe-form simplification (v1.0.4): `RecipeController`,
  which absorbed that release's new validation/fallback logic, is at 100%
  methods/lines.

## Running the suite

```bash
cd apps/web
vendor/bin/phpunit --coverage-text
```

### Docker workaround (needed in this environment)

The host's PHP 8.3 install is missing `mbstring`, `dom`, `xmlwriter`, and
`curl` (required by Composer dependencies and PHPUnit itself), and has no
coverage driver (Xdebug is present but defaults to `develop` mode, not
`coverage`, and installing extensions system-wide isn't available
non-interactively in this environment). Rather than depend on host PHP,
test runs use a small custom Docker image, `homebrewing-php-test:latest`
(`FROM backend-php:latest` — a cached base image that already has
`mbstring`/`dom`/`xmlwriter`/`curl`/`pdo_mysql`/`pdo_sqlite` — plus
`RUN pecl install pcov && docker-php-ext-enable pcov` for coverage):

```bash
docker run --rm --network host -v "$(pwd)":/app -w /app homebrewing-php-test:latest \
  php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text

# fix file ownership afterward (the container writes as root):
docker run --rm -v "$(pwd)":/app -w /app homebrewing-php-test:latest \
  chown -R "$(id -u)":"$(id -g)" /app
```

`--network host` is required so the container's `127.0.0.1:3307` resolves to
the actual host, where the Dockerized MySQL for integration tests is
published (see `architecture.md`).

### The database must be running for integration tests to execute

```bash
cd apps/web
docker compose up -d
```

If this isn't running, integration tests **skip** (with an actionable
message) rather than fail — a suite can report `OK` with a much lower test
count and still be "green" in the sense PHPUnit reports, while silently not
having run any integration coverage at all. Always check the skip count is
zero, not just that the run says `OK`, when verifying the suite is
genuinely exercising real MySQL.

## Deployment tooling (`bin/deploy.sh`) — not part of the PHPUnit suite

`bin/deploy.sh` (repository root, run from there) is a standalone ops script, not `App\`-namespaced
PHP code, so no PHPUnit coverage percentage applies to it and it is not
counted in the suite stats above. Its correctness is instead verified
manually/end-to-end against a throwaway target: a disposable
`linuxserver/openssh-server` Docker container (as the "remote host") with a
real PHP CLI installed inside it and network access to a MySQL instance
(reusing the same Dockerized MySQL used by the integration suite, or a
dedicated throwaway database on it), covering: `--dry-run` performing zero
network activity, `--stage-only` producing a working staged `vendor/`
autoloader with all excluded paths (`.git`, secrets, tests, etc.) genuinely
absent, two consecutive live deploys correctly backing up and replacing the
previous release, a real `bin/migrate.php` run against the target database,
a forced health-check failure triggering automatic rollback to the prior
release, and a password containing `$`/a space to catch shell-quoting bugs.
`shellcheck` (run via the `koalaman/shellcheck:stable` Docker image, since it
isn't installed on the host) is also part of this verification. These runs
are recorded as the "done when" evidence in the plan file that introduced
the script, not as an ongoing automated CI check.

## Client-side JavaScript (`public/js/recipe-form.js`) — not part of the PHPUnit suite

Like `bin/deploy.sh`, `public/js/recipe-form.js` is not `App\`-namespaced PHP
code, so it carries no PHPUnit coverage percentage and isn't counted in the
suite stats above (there is no JS test tooling anywhere in this repo). Its
correctness is verified manually in a real browser instead, covering: the
"Add ingredient" button appending a working, correctly-indexed blank row; the
live ABV estimate updating for both an FG-present and an FG-absent OG/FG
combination; and the create form remaining fully fillable and submittable
end to end with JavaScript disabled in the browser. This manual-verification
transcript is recorded as "done when" evidence in the plan file that
introduced the script, not as an ongoing automated check.
