# Analyser Report — Homebrewing Web App v1.0.0

_Produced 2026-09-06 for plan `0001-v1.0.0-initial-release.md`. Reproduced verbatim from the analyser's output._

## Scope note

`/docs` was entirely placeholder at analysis time, and `apps/` was empty with no commits. This is a from-scratch build; everything below is a proposal, not a description of existing code.

**Environment finding relevant to task gating:** MariaDB 10.11.14 was installed and running, but no usable app-level credentials/database existed yet. The coordinator should not treat DB-dependent tasks as unblocked until a real app-level connection actually succeeds — that provisioning check is T5's first job, not an assumption.

## Business Impact

- New user-facing surface area end to end: anonymous visitor → registered brewer → recipe author → batch tracker → public sharer; plus a hidden admin persona. No existing users/behavior to migrate.
- Antispam requirement (no third-party keys) forces a self-contained design: honeypot field + a simple server-rendered challenge (arithmetic question, verified server-side) + persisted rate limiting (IP + action + time window). Weaker than a hosted CAPTCHA but appropriate for a low-traffic personal/hobby app — a deliberate v1 tradeoff, not a security guarantee against determined abuse.
- "Minimal info" registration (username/password/email) implies **no email verification and no password-reset flow in v1** — a known, explicit limitation.
- "Acidity" in the request example is ambiguous between pH and titratable acidity; modeled as a single optional numeric field rather than resolving the ambiguity technically.
- Sharing model: recipes and batches are shared independently via their own tokens — sharing a batch doesn't require its parent recipe to also be shared, and vice versa.
- CSRF protection isn't explicitly requested but is treated as implied, non-negotiable scope for any session-based PHP app with state-changing forms.
- Admin is find+delete only (no edit), single privilege tier (`is_admin` boolean).

## Architectural Impact

Server-rendered PHP 8.3 app, no full framework (disproportionate deploy weight for this app's size and DreamHost's shared-hosting constraints). Hand-rolled: front controller + router + PDO-backed repositories + plain-PHP view templates. One runtime composer dependency: `chillerlan/php-qrcode` (pure PHP, SVG output, no GD needed). `phpunit/phpunit` dev-only. Config loader hand-rolled (~20 lines) rather than pulling in `vlucas/phpdotenv`.

**Directory layout** (under `apps/web/`):
```
public/                index.php (front controller), css/, js/ (vanilla, progressive enhancement only)
src/
  Config/              env/config loader
  Http/                Router, Request, Response, Middleware (auth guard, admin guard, CSRF)
  Db/                  PDO wrapper behind a small interface (enables fake/in-memory doubles in unit tests)
  Auth/                AuthService, session helpers, rate limiter, honeypot+challenge antispam
  Recipes/             RecipeRepository, RecipeService
  Batches/             BatchRepository, BatchService, LogEntry handling, day-counter logic
  Sharing/             ShareTokenService, QrCode wrapper (chillerlan/php-qrcode adapter)
  Admin/               AdminService (search/delete across entities)
  View/                minimal PHP-include renderer with escaping helpers
templates/             layout + per-domain views; parchment-styled batch diary variant
db/migrations/         numbered .sql files
bin/migrate.php        tiny migration runner (tracks applied migrations in schema_migrations)
tests/                 PHPUnit, mirrors src/
composer.json, phpunit.xml, .env.example
```

**Data model:**

- **users**: id, username (unique), email (unique), password_hash, is_admin (bool, default false), created_at.
- **rate_limit_events**: id, ip_address, action (enum: register/login), created_at — indexed on (ip_address, action, created_at); supports both registration rate limiting and login brute-force limiting.
- **recipes**: id, user_id (FK), name, category (enum: beer/wine/mead/cider/other), description, batch_size, batch_size_unit, water_quantity, water_unit, sugar_quantity, sugar_unit, sugar_type, yeast_type, yeast_quantity, yeast_unit, target_og (nullable), target_fg (nullable), notes, share_token (nullable, unique), is_shared (bool), created_at, updated_at.
- **recipe_ingredients** (child, one-to-many): id, recipe_id (FK), ingredient_type (enum: fruit/grain/hop/other), name, quantity, unit, timing_note, sort_order. Keeps `recipes` from needing a rigid wide column set per possible ingredient while covering beer/mead/wine/cider/other.
- **batches**: id, recipe_id (FK), user_id (FK, denormalized owner for cheap authorization), label (optional, defaults to "Batch N"), status (enum: planning/fermenting/conditioning/bottled/completed/archived), started_at (date — day-0 reference), share_token (nullable, unique), is_shared (bool), created_at, updated_at.
- **batch_log_entries** (the diary): id, batch_id (FK), entry_date (date, required), note (text), specific_gravity (decimal, nullable), acidity_ph (decimal, nullable), temperature (decimal, nullable), temperature_unit (nullable), stage_vessel (text, nullable), created_at.
- **schema_migrations**: id/version, applied_at.

Day-counter/timeline display is computed at render time (`entry_date - batch.started_at`), not stored, to avoid drift if `started_at` is edited.

**Sharing/QR:** opaque random tokens (`random_bytes` → base64url, unique-indexed) stored directly on `recipes`/`batches`. QR rendered on demand as inline SVG via `chillerlan/php-qrcode` — cheap enough that no caching/storage of generated images is needed for v1.

**Migration/testing seam:** repositories sit behind a small `Db` interface so migration-runner control flow can be unit-tested against a fake/in-memory double without a live database; the actual `.sql` files are integration-verified only once a real MariaDB connection is confirmed (T5).

## Code Impact

Everything is new. Rough scope per area, all under `apps/web/`: new composer project (PSR-4 `App\` → `src/`, ~1 runtime dependency + phpunit dev); small framework-lite core (Router/Request/Response/Middleware, Config, PDO wrapper); 7 DB tables via numbered SQL migrations + a ~100-line migration runner; domain modules (Auth, Recipes, Batches, Sharing, Admin) each a Repository + Service + a few controllers/templates; a plain functional template set plus a visually distinct parchment/journal template for the batch diary (owner + public-share views share it); hand-written CSS/vanilla JS static assets, no build step.

## Testing Impact

- PHPUnit throughout; Xdebug available for coverage.
- DB-independent modules (Router, Config, renderer, migration-runner control flow via fake `Db`) get straightforward unit tests — 85%+ is easily achievable, little branching.
- DB-dependent modules need both unit tests (service-layer branching against fake repositories) and integration tests against a real MariaDB test database (repository SQL, cross-table behavior, uniqueness constraints).
- Highest-risk areas needing extra scrutiny: antispam/rate-limiting (explicit tests for honeypot-rejection, wrong-challenge-rejection, rate-limit-exceeded, and normal accepted flow), authorization boundaries (user A can't touch user B's resources; non-admin can't reach admin routes), and public-share paths (reachable with zero session state when shared; correctly 404s when not shared even with a stale/guessed token).
- Presentation-only work (parchment CSS/template pass) has no meaningful line-coverage target for the CSS itself; keep the 85% bar on any PHP glue code touched, and use a light structural/rendering smoke test instead of a coverage percentage for styling.
- A dedicated end-to-end journey test (register → create recipe → create batch → add log entries → share both → view both public links unauthenticated → admin search/delete) is planned as T12 to catch integration seams the per-module tests won't.

## Proposed Task Breakdown

See the checklist in `docs/plans/0001-v1.0.0-initial-release.md` (T1–T12) — reproduced there as the authoritative, live-tracked version of this breakdown.
