# Data Model

Six tables, defined by the numbered migrations under `apps/web/db/migrations/`
and applied in order by `App\Db\MigrationRunner`. All tables use
`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`. This document mirrors the actual
`.sql` files exactly (column names/types below are taken directly from them,
not summarized from memory) — treat the migration files as the ultimate
source of truth if this drifts.

## `schema_migrations`

Bookkeeping table tracking which migrations have been applied. Also created
directly by `MigrationRunner::ensureMigrationsTableExists()` (with an
identical `CREATE TABLE IF NOT EXISTS`) so the runner can determine
pending/applied state even against a totally empty database.

| Column      | Type              | Notes                          |
|-------------|-------------------|----------------------------------|
| `id`        | `INT UNSIGNED`    | PK, auto-increment              |
| `version`   | `VARCHAR(191)`    | unique (`uniq_schema_migrations_version`) |
| `applied_at`| `DATETIME`        | not null                        |

## `users`

| Column          | Type              | Notes                                   |
|-----------------|-------------------|-------------------------------------------|
| `id`            | `INT UNSIGNED`    | PK, auto-increment                        |
| `username`      | `VARCHAR(60)`     | not null, unique (`uniq_users_username`)  |
| `email`         | `VARCHAR(191)`    | not null, unique (`uniq_users_email`)     |
| `password_hash` | `VARCHAR(255)`    | not null (output of `password_hash()`)    |
| `is_admin`      | `TINYINT(1)`      | not null, default `0` (present in the schema; no admin area or role-enforcement code consumes it in this release — see `architecture.md` non-goals) |
| `created_at`    | `DATETIME`        | not null, default `CURRENT_TIMESTAMP`     |

## `recipes`

Owner is `user_id`. `is_public` controls the visibility rules described in
`business-logic.md`. There is no `share_token`/`is_shared` column — sharing
is out of scope for this release.

| Column            | Type                                              | Notes |
|-------------------|----------------------------------------------------|-------|
| `id`              | `INT UNSIGNED`                                      | PK, auto-increment |
| `user_id`         | `INT UNSIGNED`                                      | not null; FK → `users.id`, `ON DELETE CASCADE`; indexed (`idx_recipes_user_id`) |
| `name`            | `VARCHAR(191)`                                      | not null |
| `category`        | `ENUM('beer','wine','mead','cider','other')`        | not null, default `'other'` |
| `description`     | `TEXT`                                              | nullable |
| `batch_size`      | `DECIMAL(10,2)`                                     | nullable |
| `batch_size_unit` | `VARCHAR(20)`                                       | nullable |
| `water_quantity`  | `DECIMAL(10,2)`                                     | nullable |
| `water_unit`      | `VARCHAR(20)`                                       | nullable |
| `sugar_quantity`  | `DECIMAL(10,2)`                                     | nullable |
| `sugar_unit`      | `VARCHAR(20)`                                       | nullable |
| `sugar_type`      | `VARCHAR(60)`                                       | nullable |
| `yeast_type`      | `VARCHAR(60)`                                       | nullable |
| `yeast_quantity`  | `DECIMAL(10,2)`                                     | nullable |
| `yeast_unit`      | `VARCHAR(20)`                                       | nullable |
| `target_og`       | `DECIMAL(6,3)`                                      | nullable (target original gravity) |
| `target_fg`       | `DECIMAL(6,3)`                                      | nullable (target final gravity) |
| `notes`           | `TEXT`                                              | nullable |
| `is_public`       | `BOOLEAN`                                           | not null, default `0` (private by default) |
| `created_at`      | `DATETIME`                                          | not null, default `CURRENT_TIMESTAMP` |
| `updated_at`      | `DATETIME`                                          | not null, default `CURRENT_TIMESTAMP`, `ON UPDATE CURRENT_TIMESTAMP` |

Indexes: `idx_recipes_user_id (user_id)`,
`idx_recipes_is_public_updated_at (is_public, updated_at)` (supports the
homepage's "most recently updated public recipes" query).

`batch_size_unit`/`water_unit`/`sugar_unit`/`yeast_unit`/`sugar_type`/
`yeast_type` are plain nullable `VARCHAR` columns with **no database-level
`ENUM`** — unlike `category` above, their closed-list constraint (see
`RecipeService::UNITS`/`YEAST_UNITS`/`SUGAR_TYPES`/`YEAST_TYPES` in
`code-structure.md`) is enforced only in the application layer
(`RecipeController`), by design (see `business-logic.md`). An invalid or
out-of-list value submitted through the app is stored as `NULL`, not
rejected at the database level; a value written by another means (a direct
`INSERT`, or one saved before the closed list existed) can still contain
arbitrary text these columns' own type permits. `target_og`/`target_fg` are
likewise unconstrained at the database level (`DECIMAL(6,3)` accepts any
value the type permits) — the 0.990–1.300 range is an application-layer rule
enforced in `RecipeController::validateRecipe()`, not a `CHECK` constraint.

`BOOLEAN` is stored by MySQL 8.0 as `tinyint(1)`; `is_public` is confirmed
`NOT NULL`, `tinyint`, `DEFAULT 0` against the live Dockerized database (see
`test-suites.md`).

## `recipe_ingredients`

One row per ingredient line on a recipe.

| Column           | Type                                       | Notes |
|------------------|----------------------------------------------|-------|
| `id`             | `INT UNSIGNED`                               | PK, auto-increment |
| `recipe_id`      | `INT UNSIGNED`                               | not null; FK → `recipes.id`, `ON DELETE CASCADE`; indexed (`idx_recipe_ingredients_recipe_id`) |
| `ingredient_type`| `ENUM('fruit','grain','hop','other')`        | not null, default `'other'` |
| `name`           | `VARCHAR(191)`                               | not null |
| `quantity`       | `DECIMAL(10,2)`                              | nullable |
| `unit`           | `VARCHAR(20)`                                | nullable |
| `timing_note`    | `VARCHAR(191)`                               | nullable (e.g. "boil 60 min") |
| `sort_order`     | `INT UNSIGNED`                               | not null, default `0` (display order) |

A recipe's ingredient set is edited by delete-then-reinsert-all
(`RecipeIngredientRepository::replaceAllForRecipe()`), not per-row diffing.

## `batches`

The "Diary" concept in the UI (see `business-logic.md`'s naming-split note).
Owner is `user_id`; every batch also belongs to exactly one recipe via
`recipe_id`, which cannot change after creation. `is_public` mirrors
`recipes.is_public` and follows the same enforcement pattern, toggled
independently of the parent recipe's visibility.

| Column       | Type                                                                              | Notes |
|--------------|-------------------------------------------------------------------------------------|-------|
| `id`         | `INT UNSIGNED`                                                                       | PK, auto-increment |
| `recipe_id`  | `INT UNSIGNED`                                                                       | not null; FK → `recipes.id`, `ON DELETE CASCADE`; indexed (`idx_batches_recipe_id`) |
| `user_id`    | `INT UNSIGNED`                                                                       | not null; FK → `users.id`, `ON DELETE CASCADE`; indexed (`idx_batches_user_id`) |
| `label`      | `VARCHAR(191)`                                                                       | nullable |
| `status`     | `ENUM('planning','fermenting','conditioning','bottled','completed','archived')`      | not null, default `'planning'` |
| `started_at` | `DATE`                                                                               | not null; the reference date for day-number computation |
| `is_public`  | `BOOLEAN`                                                                            | not null, default `0` (private by default) |
| `created_at` | `DATETIME`                                                                           | not null, default `CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME`                                                                           | not null, default `CURRENT_TIMESTAMP`, `ON UPDATE CURRENT_TIMESTAMP` |

Indexes: `idx_batches_recipe_id`, `idx_batches_user_id`,
`idx_batches_is_public_updated_at (is_public, updated_at)`.

Deleting a recipe cascades to its batches (and, transitively, to their log
entries).

## `batch_log_entries`

The individual dated diary entries under a batch. Day-counter/timeline
display (`entry_date - batch.started_at`) is computed at render time by
`BatchService::dayNumberFor()`, never stored, so editing a batch's
`started_at` later doesn't leave stale day numbers behind.

| Column             | Type              | Notes |
|--------------------|-------------------|-------|
| `id`               | `INT UNSIGNED`    | PK, auto-increment |
| `batch_id`         | `INT UNSIGNED`    | not null; FK → `batches.id`, `ON DELETE CASCADE`; part of composite index `idx_batch_log_entries_batch_id_entry_date` |
| `entry_date`       | `DATE`            | not null |
| `note`             | `TEXT`            | nullable |
| `specific_gravity` | `DECIMAL(5,3)`    | nullable |
| `acidity_ph`       | `DECIMAL(4,2)`    | nullable |
| `temperature`      | `DECIMAL(5,2)`    | nullable |
| `temperature_unit` | `VARCHAR(10)`     | nullable |
| `stage_vessel`     | `VARCHAR(191)`    | nullable, free text (e.g. "racked to secondary, glass carboy") |
| `created_at`       | `DATETIME`        | not null, default `CURRENT_TIMESTAMP` |

Index: `idx_batch_log_entries_batch_id_entry_date (batch_id, entry_date)` —
supports fetching a batch's entries in ascending chronological order
regardless of insertion order.

Deleting a batch cascades to its log entries via the real database
`ON DELETE CASCADE` constraint (`fk_batch_log_entries_batch_id`) — the
application does not implement an explicit cascade-delete loop for this;
`BatchService::delete()` relies on the FK, the same approach
`RecipeService::delete()` takes for `recipe_ingredients`. This is verified
against real MySQL by an integration test, not just assumed.

## Relationships summary

```
users 1───* recipes 1───* recipe_ingredients
users 1───* batches *───1 recipes
batches 1───* batch_log_entries
```

- A user owns many recipes and many batches independently.
- A batch always references exactly one recipe (its "parent"); a recipe can
  have zero or more batches brewed from it, by its owner only.
- A recipe's ingredients and a batch's log entries are both owned
  exclusively by their parent (cascade-deleted with it).

## What's deliberately not in the schema

No `rate_limit_events` table, and no `share_token`/`is_shared` columns on
`recipes`/`batches` — antispam and sharing/QR are out of scope for this
release (see `business-logic.md`/`architecture.md` non-goals). `is_admin` on
`users` exists in the schema but has no admin-area code consuming it yet.
