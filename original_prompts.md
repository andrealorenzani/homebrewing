# Original Prompts

This file logs the original, verbatim user prompts that initiated each
plan/round of work on this project.

## 2026-09-06

> Ship version 1.0.1 of the homebrewing app. Important context: per docs/plans/0001-v1.0.0-initial-release.md, only Task T1 (bare project scaffold) has actually been implemented so far — there is no router, front controller, auth, recipe/batch CRUD, or any templates yet, even though v1.0.0 was tagged. The requests below (especially #2-4) depend on functionality that doesn't exist yet, so treat this as: build the necessary minimum of the outstanding v1.0.0 foundation (routing, session auth/login, recipe CRUD, batch+diary log CRUD, a real homepage template) as far as needed to support the four items below, then layer these on top. Use judgment on how much of T2-T12 is strictly required as a dependency vs. out of scope — don't gold-plate, but don't fake the features either.
>
> Scope for v1.0.1:
> 1. Add a README.md at the repo root (the user asked for "README.com" but meant README.md) covering: what the project is about; a glossary of domain terms; the structure of the project; how to deploy it on a generic PHP/MySQL shared hosting account (no specific hosting provider named).
> 2. Improve the homepage so it visually showcases the latest recipes (card grid of N most recently created/updated recipes with name, style/type, link to view).
> 3. The homepage/nav should have a clear separation between a "Recipes" section and a "Diaries" section (batches/brew diaries), each with their own listing view.
> 4. When a user is logged in, they must be able to mark each of their own recipes and diaries (batches) as public or private (a visibility toggle), and private ones must not be viewable by other users or via public/share links unless made public.
>
> Bump the version to 1.0.1 wherever the project tracks its version (e.g. composer.json "version" field) and make sure this is reflected in the README/changelog if one exists.
>
> Follow the existing plan-file and task-checklist conventions already established in docs/plans/ for this repo.

## 2026-09-07T22:14:12Z

> Run agents to create version 1.0.2 with:
> 1. git storing the repo in the remote location `git@github.com:andrealorenzani/homebrewing.git`
> 2. prepare a script to upload files into a shared host: this assumes that the script uses user and pass for sftp and for ssh
> 3. the above script should do a full deploy, whatever it takes in terms of actions, but it must be very safe and all configurations should be stored in a gitignored file
> 4. Everything should be well documented in the README file

**Scoping note:** item 1 (configuring/pushing the `git@github.com:andrealorenzani/homebrewing.git` remote) was explicitly declared out of scope for the coordinator and every implementer round in this unit of work — that is repository administration handled outside this workflow (by the calling session directly), not something any task/implementer touched. No task ran `git remote`, `git push`, or touched `.git` config. Only items 2-4 were actually implemented, shipped as version 1.0.2: a safe SFTP/SSH deploy script (`apps/web/bin/deploy.sh`) with staged local builds, timestamped remote backup-before-overwrite, SFTP upload with secret-excluding paths, remote migration execution with automatic `.env` carry-forward across releases, and optional health-check-triggered automatic (code-only) rollback; its gitignored configuration file (`apps/web/deploy.conf`, templated by the committed `apps/web/deploy.conf.example`); and a new README section documenting the whole workflow. No application code, routes, database schema, or user-facing behavior changed in this release.

## 2026-09-08

> Simplify the recipe create/edit form (apps/web/templates/pages/recipes/form.php, backed by RecipeController.php + RecipeService.php + RecipeRepository.php), plus two small housekeeping cleanups. Requirements:
>
> ## 1. Recipe form simplification
> a. Keep as-is: Name (text, required) + Category (select, from RecipeService::CATEGORIES).
> b. Description stays optional (already is on the backend — just make sure the UI doesn't visually imply it's required).
> c. Batch Size means the total finished-product volume the recipe yields (e.g. "20 L"), as opposed to Water Quantity (water actually used, since some is lost to boil-off/grain absorption). Keep the field but add a short inline hint/label clarifying this distinction so it's not confusing.
> d. Every "*_unit" field (batch_size_unit, water_unit, sugar_unit, yeast_unit, and each ingredient row's unit) must become a constrained selection (dropdown, or an icon-based picker if that reads better for units like L/gal/kg/g/oz) instead of a free-text input. Define a sensible fixed list of brewing units (volume: L, gal, mL, qt; weight: kg, g, lb, oz) — reuse the same list everywhere a "unit" applies rather than inventing different lists per field, unless a field genuinely needs a narrower subset (e.g. yeast quantity units are usually packet/g/mL, not liters).
> e. Every "*_type" field (sugar_type, yeast_type, ingredient_type) must become a dropdown of fixed values instead of free text. ingredient_type already has a constrained list (RecipeService::INGREDIENT_TYPES = fruit/grain/hop/other) driving the existing <select> — keep that. sugar_type and yeast_type currently have no constrained list; define reasonable fixed option sets (e.g. sugar_type: table sugar/dextrose/honey/DME/LME/other; yeast_type: ale/lager/wine/champagne/wild/other) and validate server-side the same way category/ingredient_type already are (invalid value silently falls back to a safe default, per the existing pattern in RecipeController::parseRecipeDataFromRequest / parseIngredientsFromRequest).
> f. Layout: group each quantity+its unit side by side as a compact row/section — one section for Batch Size + its unit, one for Water Quantity + its unit, one for Sugar Quantity + its unit + its type, one for Yeast Quantity + its unit + its type. Overall form should read as minimal, not a long vertical stack of individual labeled fields.
> g. target_og and target_fg: these are specific-gravity values stored as DECIMAL(6,3) (e.g. 1.050). Constrain the inputs to the 0.990–1.300 range (displayed/typed as thousandths, i.e. "990 to 1300" the user mentioned means this range in gravity-point terms — use whatever concrete input representation reads best, e.g. step 0.001 min 0.990 max 1.300, or an integer 990-1300 UI that maps to /1000 under the hood — your call, but validate the range both client- and server-side). Additionally, the OG field must show a "fancy" live-updating estimated potential ABV as the user types (standard homebrew formula: ABV% ≈ (OG − FG) × 131.25 when FG is present, otherwise show potential/max ABV assuming full attenuation to FG 1.000). This needs a small vanilla-JS progressive enhancement — no framework (see note below).
> h. Ingredient rows: each ingredient's fields (name, type, quantity, unit, timing note) already render in one fieldset/row — keep that shape but reduce the default visible rows from the current MIN_INGREDIENT_ROWS=6 down to 3, and add an "Add ingredient" button that appends another blank row client-side (vanilla JS, no framework — the current form.php docblock notes this app deliberately avoids a JS framework/add-remove-row widget "per the plan's don't-over-engineer guidance"; a small unobtrusive JS enhancement for this one interaction is fine and necessary now, but keep it minimal and keep the no-blank-rows-submitted server-side behavior that already exists).
>
> Relevant files already located: apps/web/templates/pages/recipes/form.php, apps/web/src/Recipes/RecipeController.php (validation + parsing), apps/web/src/Recipes/RecipeService.php (CATEGORIES/INGREDIENT_TYPES constants), apps/web/src/Recipes/RecipeRepository.php. Check apps/web/docs and /docs (data-model.md, business-logic.md) for existing conventions before adding new constants/migrations if the unit/type lists need DB-level constraints.
>
> ## 2. Remove TO_BE_DONE.md
> It's currently tracked in git (`git ls-files` shows apps root TO_BE_DONE.md) even though .gitignore already has a `TO_BE_DONE.md` line in it (that line was apparently added without ever untracking the file — git status is otherwise clean). Delete the file from the working tree and `git rm --cached` it (or equivalent) so it's actually untracked going forward, keeping the existing .gitignore entry.
>
> ## 3. Move the deploy script to a root-level bin/
> Currently at apps/web/bin/deploy.sh (apps/web/bin/migrate.php stays where it is — only the deploy script moves). Move it to a new /bin/deploy.sh at the repository root. Check what apps/web/deploy.conf / apps/web/deploy.conf.example and the script itself assume about relative paths (working directory, path to apps/web tree, path to its own config file) and fix those references so the script still works correctly when invoked from the new root bin/ location (invoked from repo root, per the docs/plans/0003-v1.0.2-deploy-tooling.md context). Update README.md / CHANGELOG.md / docs/plans references to the script's old path if any exist.
>
> Do a version bump and CHANGELOG entry consistent with this repo's existing release conventions (see recent CHANGELOG.md entries and docs/plans/*.md for the pattern).
>
> **Factual correction (established during analysis, not part of the original request):** `TO_BE_DONE.md` was already untracked/gitignored at the time this request was made — `git ls-files` showed no match and `git status --ignored` already listed it as ignored. Item 2 above was therefore a verify-only task; no fix was needed or made, and this discrepancy is recorded here rather than silently edited out of the verbatim request.
