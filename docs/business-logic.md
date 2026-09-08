# Business Logic

## What the app is for

Homebrewing is a hobby tracker for people who make their own beer, wine,
mead, cider, or other fermented drinks. A user records **recipes** (what they
plan to make, and how) and then, each time they actually brew one, a
**batch** (a real attempt at that recipe) with a dated log of measurements
kept as the brew progresses. Recipes and batches can each be shared publicly
at the owner's discretion; everything is private by default.

## Domain concepts

### Users

A user registers with a username, an email address, and a password. Login
accepts either the username or the email plus the password. Authentication
is session-based (no third-party/OAuth login, no email verification, no
password-reset flow, no antispam protection on the forms — see "Deliberately
deferred" below).

### Recipes

A recipe belongs to exactly one user (its owner) and describes how to make a
batch of something: a name, a category (`beer`, `wine`, `mead`, `cider`, or
`other`), free-text description/notes (optional — the create/edit form
labels it "(optional)" and never implies it's required), target original and
final gravity, and quantities/units for batch size, water, sugar (plus sugar
type), and yeast (plus yeast type). A recipe has an ordered list of
**ingredients** (name, an ingredient type of `fruit`/`grain`/`hop`/`other`,
quantity, unit, and an optional timing note such as "boil 60 min"), edited as
a set — saving a recipe's ingredient list replaces the whole list rather than
diffing individual rows.

Only the owner can create, edit, delete, or toggle the visibility of a
recipe. Deleting a recipe cascades to its ingredients and to any batches
brewed from it (which in turn cascades to their log entries).

#### Batch size vs. water quantity

These are two deliberately distinct fields, easy to conflate: **Batch Size**
is the total finished-product volume the recipe yields (e.g. "20 L" of
finished beer/wine/mead/cider), while **Water Quantity** is the water
actually used during the process, which is normally somewhat larger than the
batch size since some is lost to boil-off and grain/fruit absorption along
the way. The create/edit form carries a short inline hint next to these two
fields clarifying the distinction, since nothing about the field names alone
makes it obvious.

#### Constrained units and types

Every quantity field on a recipe pairs with a unit, and two fields
(`sugar_type`, `yeast_type`) carry a type. As of the form simplification,
all of these are closed-list selections rather than free text, presented as
`<select>` dropdowns and validated server-side the same way `category` and
`ingredient_type` already were:

- **Units** (`batch_size_unit`, `water_unit`, `sugar_unit`, and each
  ingredient row's `unit`): `L`, `mL`, `gal`, `qt`, `kg`, `g`, `lb`, `oz`.
- **Yeast unit** (`yeast_unit`, a narrower subset since yeast is rarely
  measured in liters): `packet`, `g`, `mL`.
- **Sugar type** (`sugar_type`): `table_sugar`, `dextrose`, `honey`, `dme`,
  `lme`, `other`.
- **Yeast type** (`yeast_type`): `ale`, `lager`, `wine`, `champagne`,
  `wild`, `other`.

Unlike `category`/`ingredient_type` (which are non-nullable columns with a
non-null `'other'` default, both at the app layer and via a database
`ENUM`), these unit/type columns are **nullable** and have **no database-level
`ENUM` constraint** — the closed lists are enforced only in
`RecipeController`. Submitting an invalid or empty value for any of them
does not error and does not fall back to a non-null default; it silently
falls back to `null`, since these columns already support "not specified."
This is an app-layer-only design choice (narrower blast radius, no schema
migration) rather than an oversight.

**Known, intentional tradeoff:** because `sugar_type`/`yeast_type` used to be
free text, some existing recipes may have values outside the new closed
lists (for example, a specific yeast strain name like `"US-05"` rather than
one of `ale`/`lager`/`wine`/`champagne`/`wild`/`other`). Such a value is
still stored and still displayed wherever it's simply read back, but the
edit form's dropdown cannot pre-select it (no matching option exists), and
re-saving that recipe through the edit form will replace it with whatever
the dropdown was actually showing (typically blank/`null`) — the original
out-of-list value is lost on that save. This is a deliberate, accepted
product tradeoff of moving from free text to a closed list, not a bug to be
fixed.

#### Target gravity range and estimated ABV

`target_og`/`target_fg` (target original/final specific gravity) remain
optional, but when provided must fall within the inclusive range **0.990 to
1.300**, validated both by the browser (`type="number" step="0.001"
min="0.990" max="1.300"`) and, authoritatively, server-side in
`RecipeController::validateRecipe()` — a value outside that range, or a
non-numeric value, is rejected with a validation error rather than saved;
an empty/absent value is still accepted, since both fields stay optional.

The recipe form also shows a live estimated ABV (alcohol by volume) as the
user types, using the standard homebrew approximation
`ABV% ≈ (OG − FG) × 131.25` when a final gravity is present, or the
"potential"/maximum ABV assuming full attenuation to FG 1.000
(`(OG − 1.000) × 131.25`) when it isn't. This is a client-side, progressive
enhancement (see `architecture.md`) — it never blocks form submission and
has no effect on what's actually validated/stored server-side.

### Batches (shown to users as "Diaries")

**This is a deliberate user-facing naming split, worth calling out
explicitly:** internally, in the database, and throughout the codebase this
concept is called a **Batch** (`batches` table, `App\Batches` namespace,
`BatchService`/`BatchRepository`/`BatchController`). In the UI, the nav
link, page titles, and URL prefix (`/diaries`) all say **"Diary" /
"Diaries"** instead. A batch is a specific brewing attempt of one of the
user's own recipes: it references the recipe it was brewed from, has a
free-text label, a status (`planning`, `fermenting`, `conditioning`,
`bottled`, `completed`, or `archived`), and a start date. A batch's recipe
cannot be changed after creation (the edit form omits that field). Only the
owner can create, edit, delete, or toggle the visibility of a batch, and
only if they also own the recipe it's created under (creating a batch under
someone else's recipe is rejected the same way editing someone else's
recipe would be).

### Batch log entries (the diary itself)

Each batch has a chronological set of dated log entries — the actual diary
content. An entry has a date and any combination of optional measurements:
specific gravity, acidity (pH), temperature (with a unit), a free-text
stage/vessel note (e.g. "racked to secondary, glass carboy"), and a general
note. Entries are added, edited, and deleted individually (not as a batch
list like ingredients), and are always displayed in ascending date order
regardless of the order they were entered in.

On a batch's detail page, each entry is additionally labeled with a **day
number**: `entry_date - batch.started_at`, in whole days. This is computed
fresh every time the page renders — it is never stored — specifically so
that editing a batch's start date later doesn't leave stale day numbers
behind. Day 0 is the start date itself; positive numbers follow; a
backdated entry (for example, a gravity reading taken before the batch was
officially started) can legitimately show a **negative** day number rather
than being rejected.

### Visibility (`is_public`)

Both recipes and batches carry an independent `is_public` flag, `false`
(private) by default. Only the owner can flip it, via a "Make Public" /
"Make Private" toggle on the resource's own detail page (a CSRF-protected
POST action, not a separate settings screen). The two resources' visibility
is toggled independently of each other — making a recipe public does not
make its batches public, and vice versa.

Enforcement rules, applied identically to recipes and batches, for every
combination of viewer and visibility:

| Viewer                        | Public resource        | Private resource |
|--------------------------------|-------------------------|-------------------|
| Owner                           | full access (view/edit/delete/toggle) | full access |
| Other logged-in user            | view-only               | **404** (not 403) |
| Anonymous (logged-out) visitor  | view-only               | **404** (not 403) |

A private resource returns exactly the same 404 response that a
nonexistent id would, both for anonymous visitors and for other logged-in
users — it never reveals that something exists at that URL. Attempting to
*edit, delete, or toggle* a resource you don't own, on the other hand, is a
real ownership violation and returns a genuine **403**, since by that point
the actor is attempting an action that requires already knowing the
resource is theirs to act on (see `architecture.md` for why the two cases
are handled differently).

There is no separate "share link" or token mechanism: a public resource is
simply viewable, unauthenticated, at its own normal detail-view URL
(`/recipes/{id}` or `/diaries/{id}`). This is a deliberate simplification —
see "Deliberately deferred" below.

### Homepage recipe showcase

The homepage (`/`) shows a card grid of the **N most recently updated public
recipes** (name, category, link to the read-only detail view), where **N**
is config-driven via the `HOMEPAGE_RECIPE_COUNT` environment variable
(default **6**) rather than hardcoded. Only recipes with `is_public = true`
are ever eligible, regardless of how recently a private recipe was updated —
a private recipe updated a second ago is still excluded. When there are zero
public recipes yet (e.g. on a fresh install before anyone has made anything
public), the homepage shows a graceful empty-state message instead of an
empty grid. There is no equivalent showcase for batches/diaries on the
homepage — only recipes are showcased there.

### Navigation

The top nav has two clearly separated sections, each linking to that
domain's own-listing view for the currently logged-in user: **"Recipes"**
(`/recipes` — the user's own recipes) and **"Diaries"** (`/diaries` — the
user's own batches). These own-listing views require login; the homepage
showcase and individual public detail pages do not.

## Deliberately deferred (non-goals for this release)

These were discussed during early planning but are intentionally not part
of the product yet. None of them are half-built; treat any trace of them in
config files or dependencies as inert leftovers, not as evidence the
feature exists:

- **Sharing tokens / QR codes** — no separate share-link mechanism exists
  beyond a public resource's own URL; no QR rendering.
- **Admin area** — no admin role, no cross-user moderation tooling.
- **Antispam / rate limiting** — registration and login have no honeypot
  field, no challenge/CAPTCHA, and no rate-limit tracking; the only
  protections are ordinary password hashing (`password_hash`/
  `password_verify`) and duplicate username/email rejection.
- **Public "browse all" page** — beyond the homepage's recent-public-recipes
  showcase, there is no page that lists every public recipe or batch across
  all users.
- **Email verification and password reset** — not implemented.
