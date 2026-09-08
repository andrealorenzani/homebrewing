# Homebrewing

A hobby homebrewing web application for tracking beer/wine/mead/cider (and
other) recipes and the actual brewing sessions ("batches") made from them.
It is server-rendered PHP with no framework: plain PHP templates, a small
hand-rolled router/front-controller, and a MySQL/MariaDB database accessed
through PDO. There is no client-side JavaScript framework and no build step.

Current released version: **1.0.2**. See `CHANGELOG.md` at the repo root for
release history.

## What the project is

- Users register and log in with a username/email + password (session-based
  auth, no third-party login).
- A logged-in user can create **recipes** (ingredients, target gravity,
  category, etc.) and **batches** (an actual brewing attempt of one of their
  recipes), and keep a dated log/diary of measurements for each batch.
- Each recipe and each batch can be individually marked public or private.
  Public ones are viewable by anyone, including anonymous visitors; private
  ones are visible only to their owner. The homepage showcases a handful of
  the most recently updated public recipes.
- The app is small and deliberately not "gold-plated" — see the "Not yet
  built" section below for functionality that was planned at some point but
  is intentionally absent from this release.

## Glossary of domain terms

These are the terms actually used in the code and the UI. Where the
user-facing label differs from the internal class/table name, both are
given.

- **Recipe** — a beer/wine/mead/cider/other recipe: name, category, target
  gravity, and a list of ingredients. Backed by the `recipes` and
  `recipe_ingredients` tables and the `App\Recipes` namespace
  (`RecipeService`, `RecipeRepository`, `RecipeController`). The nav label
  and route (`/recipes`) match the internal name exactly.

- **Batch**, shown to users as **"Diary" / "Diaries"** — a specific brewing
  attempt of one of the user's recipes, with a status (e.g. planning,
  fermenting, conditioning, done) and a start date. Internally this is the
  `App\Batches` namespace, the `batches` table, and the `BatchService`/
  `BatchRepository`/`BatchController` classes, and its routes are still
  `/diaries` (not `/batches`). **This is a deliberate naming split**: the
  nav link, page titles, and route prefix all say "Diary"/"Diaries" for
  users, while the code, database table, classes, and this README's more
  technical sections say "Batch". If you're reading the source and looking
  for "Diary", start at `App\Batches`.

- **Batch log entry** (the individual entries that make up a diary) — a
  single dated entry under a batch: entry date, and optional specific
  gravity, acidity/pH, temperature, and free-text stage/vessel notes.
  Backed by the `batch_log_entries` table and
  `App\Batches\BatchLogEntryRepository`. On a batch's detail page these are
  rendered as a chronological, day-numbered timeline: each entry's "Day N"
  label is `entry_date - batch.started_at` in whole days, computed at
  render time (never stored). Day 0 is the start date itself; a backdated
  entry (e.g. a pre-pitch gravity reading taken before the batch officially
  started) can show a negative day number.

- **`is_public` / visibility** — a boolean column on both `recipes` and
  `batches`, `false`/private by default. Only the owning user can toggle it
  (a "Make Public" / "Make Private" button on the recipe/batch detail page,
  CSRF-protected). When a resource is public, anyone — including an
  anonymous, logged-out visitor — can view it at its normal detail-view URL
  (`/recipes/{id}` or `/diaries/{id}`); there is no separate share link.
  When it's private, only the owner can view it; everyone else (including
  other logged-in users) gets a plain 404, not a 403, so a private
  resource's existence is never revealed. Public recipes additionally
  appear in the homepage's "recently updated" showcase; private ones never
  do, regardless of how recently they were updated.

### Not yet built (do not assume these exist)

The following were discussed in early planning documents for a hypothetical
v1.0.0 but were never actually implemented beyond that scaffold. v1.0.1 is
the first real release, and none of the following exist in it:

- **Sharing tokens** — there is no separate "share link" mechanism distinct
  from the normal detail-view URL described above.
- **QR codes** for sharing recipes/batches.
- **An admin area** of any kind.
- **Antispam / rate limiting** on registration, login, or any other form
  (no honeypot fields, no CAPTCHA-style challenge, no rate-limit table).

If you see references to these (e.g. leftover config keys in
`.env.example`, or the `chillerlan/php-qrcode` Composer dependency), they
are placeholders/leftovers from that earlier planning stage, not indications
that the feature is live.

## Project structure

Everything application-specific lives under `apps/web/`:

```
apps/web/
├── public/            Web-accessible document root. Front controller
│                       (index.php) and static assets (css/). This is the
│                       ONLY directory that should be exposed to the web.
├── src/                PHP source, PSR-4 autoloaded under the App\ namespace,
│                       organized by domain:
│   ├── Auth/           Registration/login/logout (AuthService, UserRepository,
│                       AuthController).
│   ├── Batches/        Batches ("Diaries") + their log entries.
│   ├── Config/         .env-file config loader.
│   ├── Db/             DbInterface, the real PDO implementation (PdoDb),
│                       an in-memory test double (FakeDb), and the migration
│                       runner.
│   ├── Home/           The homepage controller (public-recipe showcase).
│   ├── Http/           Request/Response, the router, session handling,
│                       CSRF token management, and auth middleware.
│   ├── Recipes/        Recipes + their ingredients.
│   ├── View/            The template renderer.
│   └── Kernel.php       Wires config/DB/session/routes together; used by
│                       both public/index.php and the test suite.
├── templates/          Server-rendered PHP view templates (layout.php plus
│                       templates/pages/... per feature area). Included by
│                       App\View\Renderer, not a templating engine.
├── db/migrations/      Numbered, plain-SQL migration files, applied in
│                       filename order by App\Db\MigrationRunner.
├── bin/                CLI scripts; notably migrate.php, the migration
│                       runner entry point.
├── tests/
│   ├── Unit/            Mirrors src/, runs against FakeDb/ArraySession —
│                       no real database or network needed.
│   └── Integration/     Runs the same code against a real MySQL database
│                       (see "Local development" below).
├── docker-compose.yml  Local MySQL 8.0 for development/testing (see below).
├── composer.json / composer.lock
└── phpunit.xml.dist
```

## Deployment on generic PHP/MySQL shared hosting

These steps assume a typical shared-hosting control panel (file manager,
a database-creation tool, cron jobs) and PHP 8.1 or newer (the codebase is
developed against 8.3, but requires only 8.1+). No specific hosting
provider is assumed. Some hosts offer SSH access and some don't — both
paths are covered below.

### 1. Get the code onto the server

Upload the contents of this repository to the server (via the file
manager's upload/zip-extract feature, or `git clone`/`scp` if you have SSH).
The whole repository can live outside the public web root; only
`apps/web/public/` needs to be reachable by the web server (step 5 covers
that).

### 2. Install PHP dependencies

The app depends on Composer packages that must be present in
`apps/web/vendor/` before it will run.

- **If you have SSH:** `cd apps/web && composer install --no-dev --optimize-autoloader`.
- **If you don't have SSH:** many hosts provide a "Composer"/"Setup PHP App"
  button in the control panel that runs `composer install` for you inside
  `apps/web/`. If neither SSH nor such a button is available, run
  `composer install --no-dev` on your own machine (against the same PHP
  version, or close to it) and upload the resulting `apps/web/vendor/`
  directory along with the rest of the code.

### 3. Create the database

Use the host's database tool (often "MySQL Databases" in a control panel)
to create a MySQL or MariaDB database and a database user with full
privileges on it. Note the database name, host (often `localhost`), port
(often `3306`), username, and password — you'll need them next.

### 4. Configure the app

Copy `apps/web/.env.example` to `apps/web/.env` (via the file manager or
`cp` over SSH) and fill in real values:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=` the real public URL of the site (no trailing slash)
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` — from step 3
- Leave `HOMEPAGE_RECIPE_COUNT` and the session settings at their defaults
  unless you have a reason to change them.

`.env` should **not** be web-accessible or committed to version control; it
already lives outside `apps/web/public/`, which is the only directory the
web server should expose (see step 6).

### 5. Run the database migrations

`apps/web/bin/migrate.php` applies any pending SQL migrations from
`apps/web/db/migrations/`. It needs to run once (and again after any future
update that adds new migrations).

- **If you have SSH:** `php apps/web/bin/migrate.php`.
- **If you only have cPanel-style Terminal/cron and no persistent shell:**
  either use the control panel's one-off "Terminal"/PHP-CLI runner if it
  offers one, or schedule a cron job that runs
  `php /full/path/to/apps/web/bin/migrate.php` once and then remove or
  disable the cron entry immediately after it has run (leaving it scheduled
  indefinitely is harmless — the runner only applies *pending* migrations
  and is a no-op once everything is applied — but disabling it avoids
  needless repeated runs). Some hosts only allow cron, not a temporary
  web-accessible script, for exactly this reason; if neither cron nor a CLI
  runner is available, as a last resort you can temporarily drop a small
  PHP script under `apps/web/public/` that `require`s and runs the same
  logic as `bin/migrate.php`, visit it once in a browser, and then **delete
  it immediately** — an unprotected script capable of running arbitrary
  migrations should never be left web-accessible.

### 6. Point the domain/subdomain at the right directory

Set the domain's (or subdomain's) document root to `apps/web/public`, **not**
`apps/web/` and not the repository root. `apps/web/public/` contains only
the front controller and static assets; everything else (`src/`, `templates/`,
`.env`, `vendor/`, etc.) should stay outside the web-accessible tree.

#### Apache rewrite (`.htaccess`)

`apps/web/public/.htaccess` is already committed and requires no manual
setup beyond completing step 6 above as normal: it serves real files/
directories (e.g. `public/css/`) directly and routes every other request
through `index.php`, which is required on Apache-based hosts (the common
case for shared hosting) since Apache has no built-in fallback-to-script
behavior the way PHP's built-in development server does. It relies on
`mod_rewrite` and on `AllowOverride` being permitted for this directory,
both of which are enabled by default on most hosts.

**Troubleshooting:** if the homepage loads but every other route 404s,
your host likely has `AllowOverride` disabled for this directory; ask your
host to enable it, or ask them to add the equivalent rewrite block to the
site's main vhost config.

### Automated redeploys with `bin/deploy.sh`

`bin/deploy.sh` (run from the repository root) automates three of the six manual steps above for
hosts that offer SSH/SFTP access: **step 1** (get the code onto the server),
**step 2** (install PHP dependencies — done locally via a staged copy and
`composer install --no-dev --optimize-autoloader`, then uploaded), and
**step 5** (run the database migrations). It does not replace the manual
steps above; read this section alongside them, not instead of them.

**Steps 3, 4, and 6 remain manual, one-time, host-console actions** and are
deliberately not automated, because they cannot be safely generalized to an
arbitrary shared host:

- **Step 3 (create the database)** requires the host's own database-creation
  tool/control panel; there is no portable way to script this across hosts.
- **Step 4 (first-time `.env` configuration)** is required exactly once, on
  the very *first* deploy to a fresh `DEPLOY_REMOTE_PATH`: the operator must
  manually place a working `apps/web/.env` there (see step 4 above) *before*
  running `deploy.sh` for the first time against that path. `deploy.sh`
  never uploads `.env` from your machine (this would risk leaking or
  clobbering a production secret), so if `.env` is missing on that very
  first run, the script fails fast with a clear message pointing back at
  this step. **Every subsequent deploy is different: `deploy.sh`
  automatically carries `.env` forward** by copying it, entirely on the
  remote host (never over the network), from the release it just backed up
  into the newly uploaded one — no re-upload or re-configuration needed
  after the first deploy.
- **Step 6 (pointing the document root at `apps/web/public`)** is a one-time
  host-console/control-panel action that has nothing to do with the code or
  database and is outside anything a deploy script could do.

#### Prerequisites

Run `deploy.sh` from your own machine (not the remote host). It needs, all
installed locally:

- `sshpass` — required for non-interactive password-based SSH/SFTP
  authentication (bare OpenSSH cannot take a password from a non-interactive
  script). Install with `apt-get install -y sshpass` (Debian/Ubuntu) or
  `brew install hudochenkov/sshpass/sshpass` (macOS/Homebrew).
- `ssh` and `sftp` (OpenSSH client — usually already installed).
- `curl` (used for the optional post-deploy health check).
- `composer` (used to build the staged release locally before upload).

`deploy.sh` checks for all of the above at startup and fails fast with an
install hint if any is missing, rather than failing partway through a
deploy.

#### Configuration: `deploy.conf`

Copy `apps/web/deploy.conf.example` to `apps/web/deploy.conf` and fill in
real values. `deploy.conf` is gitignored on purpose and must never be
committed or uploaded to the remote host. Keys:

| Key | Meaning |
| --- | --- |
| `DEPLOY_HOST` | Hostname or IP of the shared-hosting server. |
| `DEPLOY_PORT` | SSH/SFTP port (commonly `22`, but some hosts assign a custom port). |
| `DEPLOY_SSH_USER` | Username for the SSH session used to run remote commands (backup/migrate/health-check). |
| `DEPLOY_SSH_PASS` | Password for the SSH user above, used non-interactively via `sshpass`. |
| `DEPLOY_SFTP_USER` | Username for the SFTP upload session. Usually the same as `DEPLOY_SSH_USER` — override only if the host issues separate SFTP-only credentials. |
| `DEPLOY_SFTP_PASS` | Password for the SFTP user above, used non-interactively via `sshpass`. |
| `DEPLOY_REMOTE_PATH` | Remote directory whose `public/` subdirectory is the document root. This is where the release is uploaded and backed up from. |
| `DEPLOY_REMOTE_PHP_BIN` | PHP CLI binary to invoke remotely for `bin/migrate.php`. Shared hosts commonly expose a version-suffixed binary rather than a bare `php` (e.g. `php8.3`) — check what your host provides. |
| `DEPLOY_BACKUP_KEEP` | Number of timestamped backup directories to retain on the remote host before older ones are pruned. |
| `DEPLOY_HEALTHCHECK_URL` | Optional. A URL to `curl` after migrations run; a non-2xx (or unreachable) response triggers automatic rollback. Leave blank to skip the health check and automatic rollback entirely. |

**Important:** `deploy.conf` is loaded by `source`-ing it as a bash script,
not by a naive `KEY=VALUE` line parser. Any value containing shell-special
characters — `$`, spaces, quotes, etc. (most likely in a password) — **must
be single-quoted**, e.g. `DEPLOY_SSH_PASS='p$w ord!'`. An unquoted value
containing a `$` or a space will be corrupted or misinterpreted by the
shell when the file is sourced.

#### Usage

Run from the repository root:

```bash
bin/deploy.sh --help        # show usage and exit
bin/deploy.sh --dry-run     # print the full plan; zero network activity
bin/deploy.sh --stage-only  # build the local release copy and stop (debug)
bin/deploy.sh               # full deploy, asks for confirmation first
bin/deploy.sh --yes         # full deploy, skip the confirmation prompt (-f also works)
```

`--dry-run` reads `deploy.conf` and prints the complete backup/stage/upload/
migrate/health-check plan without making any network connection or touching
the filesystem beyond reading the config file. `--stage-only` actually
builds the local staged release (including a real `composer install
--no-dev --optimize-autoloader`) in a fresh temp directory and stops there,
without any network activity — useful for verifying the build in isolation.

#### Safety mechanisms

- **Confirmation prompt** before any destructive step, unless `--yes`/`-f`
  is passed.
- **Timestamped backup-before-overwrite**: the existing release at
  `DEPLOY_REMOTE_PATH` is moved sideways to `<path>.backup-<timestamp>`
  before the new one is uploaded, never deleted outright. Backups beyond
  `DEPLOY_BACKUP_KEEP` are pruned automatically (oldest first).
- **Upload excludes**: the staged copy that gets uploaded never includes
  `.git`, `vendor/` (a fresh one is installed inside the staged copy),
  `.env*`, `tests/`, `docker*`/`docker/`, `deploy.conf`, `phpunit.xml.dist`,
  `.phpunit.result.cache`, or `build/`. In particular, `deploy.conf` and any
  `.env*` file are never uploaded and never overwritten on the remote host.
- **Structured, timestamped logging** of every step to stdout/stderr.
- **Optional health-check-triggered automatic rollback**: if
  `DEPLOY_HEALTHCHECK_URL` is set, it is `curl`ed after migrations run; a
  non-2xx (or unreachable) response automatically restores the previous
  release directory back into place and exits non-zero.

#### Rollback limitations and manual database rollback

**Automatic rollback only restores the previous CODE release — it does
not, and cannot, undo database migrations already applied by that deploy.**
This codebase's migration runner (`App\Db\MigrationRunner`, driven by
`bin/migrate.php`) is forward-only: there is no `down()`/revert mechanism
anywhere in the codebase. If a deploy's migrations already ran successfully
before a later health-check failure, an automatic rollback puts the old
code back in front of a database schema that has already moved forward,
which may or may not be compatible — check manually before relying on it.

If you need to fully undo a bad deploy, including its database changes,
you must do so manually:

1. **Restore the previous code release** from the timestamped backup left
   on the remote host (automatic rollback already does this for you when a
   health check fails; do it by hand if you're recovering after the fact or
   without a health check configured):

   ```bash
   ssh -p <DEPLOY_PORT> <DEPLOY_SSH_USER>@<DEPLOY_HOST>
   rm -rf '<DEPLOY_REMOTE_PATH>'
   mv '<DEPLOY_REMOTE_PATH>.backup-<timestamp>' '<DEPLOY_REMOTE_PATH>'
   ```

2. **Restore the database** from a pre-deploy backup/dump taken separately.
   `deploy.sh` does **not** take a database backup itself — it only backs up
   code. Before running any deploy that includes new migrations, take your
   own dump first, e.g.:

   ```bash
   mysqldump -h <DB_HOST> -u <DB_USER> -p <DB_NAME> > backup-before-deploy.sql
   ```

   and restore it (`mysql -h <DB_HOST> -u <DB_USER> -p <DB_NAME> < backup-before-deploy.sql`)
   if the deploy needs to be fully reverted.

Key-based SSH authentication is more secure than password authentication
where it's available; `deploy.sh` supports password-based SSH/SFTP (via
`sshpass`) specifically because many shared hosts only offer password-based
access.

## Local development

`apps/web/docker-compose.yml` provides a local MySQL 8.0 instance for
development and for the integration test suite (no host MySQL/MariaDB
installation required):

```bash
cd apps/web
docker compose up -d          # starts MySQL 8.0 on 127.0.0.1:3307
composer install
cp .env.example .env          # DB_PORT=3307, DB_USER=homebrewing, DB_PASS=homebrewing_dev_pw, DB_NAME=homebrewing_dev
cp .env.example .env.testing  # same, but APP_ENV=testing, DB_NAME=homebrewing_test
php bin/migrate.php                     # applies migrations against .env
APP_ENV=testing php bin/migrate.php     # applies migrations against .env.testing
php -S 127.0.0.1:8000 -t public         # serve the app locally
```

The compose file maps the container's MySQL to host port **3307** (not the
default 3306), so it doesn't clash with a system MySQL/MariaDB that might
already be running on 3306. The `homebrewing` app user and both
`homebrewing_dev`/`homebrewing_test` databases are created automatically on
first start via `docker/mysql-init/01-init.sql`.

### Running tests

```bash
cd apps/web
vendor/bin/phpunit --coverage-text
```

If your local PHP is missing extensions the project needs (`mbstring`,
`dom`, `xmlwriter`, `curl`, `pdo_mysql`) or lacks a coverage driver, run the
suite inside a container instead. A minimal image with PCOV enabled can be
built from any PHP 8.3 CLI image (`RUN pecl install pcov && docker-php-ext-enable pcov`);
this project's implementer rounds used one tagged `homebrewing-php-test:latest`:

```bash
docker run --rm --network host -v "$(pwd)":/app -w /app homebrewing-php-test:latest \
  php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text
# fix file ownership afterward, since the container writes as root:
docker run --rm -v "$(pwd)":/app -w /app homebrewing-php-test:latest chown -R "$(id -u)":"$(id -g)" /app
```

`--network host` is needed so the container can reach the Dockerized MySQL
published on the host's `127.0.0.1:3307`. Integration tests
(`tests/Integration/`) automatically skip themselves with an explicit
message if that database isn't reachable, rather than failing outright.
