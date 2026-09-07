#!/usr/bin/env bash
#
# SFTP/SSH deploy script for shared hosting.
#
# Builds a production-ready copy of apps/web (composer install --no-dev),
# backs up the current remote release, uploads the new one via SFTP, runs
# the remote migration runner, and optionally rolls back automatically if a
# post-deploy health check fails. Configuration lives in deploy.conf (copy
# deploy.conf.example to deploy.conf and fill in real values — never commit
# deploy.conf, it is gitignored on purpose).
#
# Usage:
#   bin/deploy.sh --help
#   bin/deploy.sh --dry-run              # print the full plan, no network activity
#   bin/deploy.sh --stage-only           # build the local release copy and stop (debug)
#   bin/deploy.sh                        # full deploy, asks for confirmation first
#   bin/deploy.sh --yes                  # full deploy, skip the confirmation prompt
#
# See README.md's "Automated redeploys with bin/deploy.sh" section for the
# full workflow, safety mechanisms, and manual-rollback notes.

set -euo pipefail

# --- Paths -------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
CONFIG_FILE="${DEPLOY_CONFIG_FILE:-$APP_ROOT/deploy.conf}"
CONFIG_EXAMPLE_FILE="$APP_ROOT/deploy.conf.example"

REQUIRED_CONFIG_KEYS=(
    DEPLOY_HOST
    DEPLOY_PORT
    DEPLOY_SSH_USER
    DEPLOY_SSH_PASS
    DEPLOY_SFTP_USER
    DEPLOY_SFTP_PASS
    DEPLOY_REMOTE_PATH
    DEPLOY_REMOTE_PHP_BIN
    DEPLOY_BACKUP_KEEP
)
# DEPLOY_HEALTHCHECK_URL is deliberately not required: an empty value just
# disables the post-deploy health check (and therefore automatic rollback).

# --- CLI flags (populated by parse_args) --------------------------------

DRY_RUN=false
ASSUME_YES=false
STAGE_ONLY=false

# Set by stage_release() so callers (main/--stage-only) can report the path.
STAGED_DIR=""

# --- Logging -------------------------------------------------------------

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

log_error() {
    printf '[%s] ERROR: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
}

die() {
    log_error "$*"
    exit 1
}

# --- Usage -----------------------------------------------------------------

usage() {
    cat <<'EOF'
Usage: bin/deploy.sh [OPTIONS]

Builds a production release of apps/web and deploys it to the shared host
configured in deploy.conf via SSH/SFTP.

Options:
  --dry-run       Print the full deploy plan and exit. Performs zero network
                   activity and does not touch the local filesystem beyond
                   reading deploy.conf.
  --stage-only    Build the local release copy (composer install --no-dev)
                   in a fresh temp directory and stop there. Useful for
                   verifying the staging step in isolation. No network
                   activity.
  --yes, -f       Skip the confirmation prompt before a real deploy.
  --help, -h      Show this help and exit.

Configuration:
  Copy deploy.conf.example to deploy.conf (in this directory's parent,
  apps/web/) and fill in real values. deploy.conf is gitignored and must
  never be committed or uploaded to the remote host.
EOF
}

# --- Argument parsing ------------------------------------------------------

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --dry-run)
                DRY_RUN=true
                ;;
            --stage-only)
                STAGE_ONLY=true
                ;;
            --yes|-f)
                ASSUME_YES=true
                ;;
            --help|-h)
                usage
                exit 0
                ;;
            *)
                usage
                die "Unknown option: $1"
                ;;
        esac
        shift
    done
}

# --- Config loading ----------------------------------------------------

load_config() {
    if [[ ! -f "$CONFIG_FILE" ]]; then
        log_error "Config file not found: $CONFIG_FILE"
        log_error "Copy $(basename "$CONFIG_EXAMPLE_FILE") to $(basename "$CONFIG_FILE") and fill in real values first."
        exit 1
    fi

    set -a
    # shellcheck disable=SC1090
    source "$CONFIG_FILE"
    set +a

    local missing=()
    local key
    for key in "${REQUIRED_CONFIG_KEYS[@]}"; do
        if [[ -z "${!key:-}" ]]; then
            missing+=("$key")
        fi
    done

    if [[ ${#missing[@]} -gt 0 ]]; then
        log_error "Missing required config keys in $CONFIG_FILE:"
        for key in "${missing[@]}"; do
            log_error "  - $key"
        done
        die "See $(basename "$CONFIG_EXAMPLE_FILE") for the full documented key set."
    fi
}

# --- Dependency checks ---------------------------------------------------

check_dependencies() {
    local missing=()

    command -v ssh >/dev/null 2>&1 || missing+=("ssh (OpenSSH client)")
    command -v sftp >/dev/null 2>&1 || missing+=("sftp (OpenSSH client)")
    command -v composer >/dev/null 2>&1 || missing+=("composer")
    command -v curl >/dev/null 2>&1 || missing+=("curl")

    if ! command -v sshpass >/dev/null 2>&1; then
        log_error "Required command not found: sshpass"
        log_error "sshpass is needed for non-interactive password-based SSH/SFTP auth."
        log_error "Install it with one of:"
        log_error "  apt-get install -y sshpass          (Debian/Ubuntu)"
        log_error "  brew install hudochenkov/sshpass/sshpass   (macOS/Homebrew)"
        missing+=("sshpass")
    fi

    if [[ ${#missing[@]} -gt 0 ]]; then
        die "Missing required local dependencies: ${missing[*]}"
    fi
}

# --- Planning (used by --dry-run) ---------------------------------------

# Populates plan variables from the loaded config. Pure computation, no
# filesystem or network side effects.
compute_plan() {
    RELEASE_TIMESTAMP="$(date '+%Y%m%d%H%M%S')"
    # Backup convention: the currently-live release directory is moved
    # sideways to this sibling path before the new release is uploaded.
    BACKUP_PATH="${DEPLOY_REMOTE_PATH%/}.backup-${RELEASE_TIMESTAMP}"
    UPLOAD_DEST="$DEPLOY_REMOTE_PATH"
    MIGRATE_CMD="cd '${DEPLOY_REMOTE_PATH}' && '${DEPLOY_REMOTE_PHP_BIN}' bin/migrate.php"
}

print_plan() {
    compute_plan

    echo "Deploy plan for ${DEPLOY_SSH_USER}@${DEPLOY_HOST}:${DEPLOY_PORT}"
    echo "  1. Backup:  if a release exists at '${DEPLOY_REMOTE_PATH}', move it to"
    echo "              '${BACKUP_PATH}' (then prune backups beyond DEPLOY_BACKUP_KEEP=${DEPLOY_BACKUP_KEEP})"
    echo "  2. Stage:   copy apps/web to a fresh local temp dir (excluding .git, vendor,"
    echo "              .env*, tests, docker*, deploy.conf, phpunit.xml.dist,"
    echo "              .phpunit.result.cache, build) and run:"
    echo "              composer install --no-dev --optimize-autoloader"
    echo "  3. Upload:  SFTP the staged copy to '${UPLOAD_DEST}' (source: the staged"
    echo "              temp dir created fresh in step 2 — see stage_release())"
    echo "  4. Migrate: ${MIGRATE_CMD}"
    if [[ -n "${DEPLOY_HEALTHCHECK_URL:-}" ]]; then
        echo "  5. Health check: curl '${DEPLOY_HEALTHCHECK_URL}'; on a non-2xx response,"
        echo "              automatically roll back to '${BACKUP_PATH}'"
    else
        echo "  5. Health check: DEPLOY_HEALTHCHECK_URL not set — skipped (no automatic rollback)"
    fi
    echo
    echo "No network activity was performed to produce this plan (--dry-run)."
}

# --- Staging (D3) ---------------------------------------------------------

# Copies apps/web into a fresh temp directory, excluding dev-only/secret
# paths, then runs a real "composer install --no-dev --optimize-autoloader"
# inside that staged copy (never in the developer's working tree). Sets
# STAGED_DIR to the resulting directory on success.
stage_release() {
    local staging_dir
    staging_dir="$(mktemp -d "${TMPDIR:-/tmp}/homebrewing-deploy.XXXXXXXX")"

    log "Staging release copy of '$APP_ROOT' into '$staging_dir'"
    cp -a "$APP_ROOT/." "$staging_dir/"

    log "Removing dev-only/secret paths from the staged copy"
    local exclude
    for exclude in .git vendor tests phpunit.xml.dist .phpunit.result.cache build deploy.conf deploy-logs .deploy-logs; do
        rm -rf -- "${staging_dir:?}/${exclude}"
    done
    # .env, .env.testing, .env.example, ... (.env*)
    find "$staging_dir" -maxdepth 1 -name '.env*' -exec rm -rf -- {} +
    # docker-compose.yml, docker/ (docker*)
    find "$staging_dir" -maxdepth 1 -name 'docker*' -exec rm -rf -- {} +

    log "Running: composer install --no-dev --optimize-autoloader (in staged copy)"
    (
        cd "$staging_dir"
        composer install --no-dev --optimize-autoloader --no-interaction
    )

    STAGED_DIR="$staging_dir"
    log "Staging complete: $STAGED_DIR"
}

# --- Remote SSH/SFTP helpers ---------------------------------------------

# Runs a single command line on the remote host over SSH, authenticating
# non-interactively with DEPLOY_SSH_PASS via sshpass. Takes exactly one
# argument: the full remote command line, already quoted for the remote
# shell by the caller. StrictHostKeyChecking=accept-new trusts a host's key
# on first connect (so a fresh throwaway target "just works") but still
# rejects a *changed* key on a later connect, unlike disabling host key
# checking entirely.
ssh_run() {
    sshpass -p "$DEPLOY_SSH_PASS" ssh \
        -o StrictHostKeyChecking=accept-new \
        -o ConnectTimeout=15 \
        -p "$DEPLOY_PORT" \
        "${DEPLOY_SSH_USER}@${DEPLOY_HOST}" \
        "$1"
}

# --- Backup + upload (D4) -------------------------------------------------

# Moves any existing release at DEPLOY_REMOTE_PATH sideways to BACKUP_PATH
# (computed by compute_plan) before the new release is uploaded, then prunes
# backups beyond DEPLOY_BACKUP_KEEP (oldest first). A missing release (first
# deploy to a host) is not an error.
backup_remote() {
    log "Checking for an existing release at '${DEPLOY_REMOTE_PATH}' on ${DEPLOY_HOST}"

    local backup_cmd result
    backup_cmd="if [ -e '${DEPLOY_REMOTE_PATH}' ]; then mv -- '${DEPLOY_REMOTE_PATH}' '${BACKUP_PATH}' && echo BACKED_UP; else echo NO_EXISTING_RELEASE; fi"
    result="$(ssh_run "$backup_cmd")"

    case "$result" in
        BACKED_UP)
            log "Existing release backed up to '${BACKUP_PATH}'"
            ;;
        NO_EXISTING_RELEASE)
            log "No existing release at '${DEPLOY_REMOTE_PATH}' — nothing to back up (first deploy?)"
            ;;
        *)
            die "Unexpected response while backing up the remote release: $result"
            ;;
    esac

    log "Pruning backup directories beyond DEPLOY_BACKUP_KEEP=${DEPLOY_BACKUP_KEEP}"
    local prune_cmd
    prune_cmd="ls -1d '${DEPLOY_REMOTE_PATH}'.backup-* 2>/dev/null | sort | head -n -'${DEPLOY_BACKUP_KEEP}' | xargs -r rm -rf --"
    ssh_run "$prune_cmd"
}

# Uploads the staged release directory (STAGED_DIR, set by stage_release)
# into a fresh DEPLOY_REMOTE_PATH via a single SFTP batch-mode session.
# Requires DEPLOY_REMOTE_PATH to not already exist (backup_remote must run
# first) — sftp's "put -R local remote" creates "remote" fresh with local's
# *contents* only when remote does not yet exist; if it already existed the
# upload would nest under it instead.
upload_release() {
    if [[ -z "$STAGED_DIR" || ! -d "$STAGED_DIR" ]]; then
        die "upload_release called without a staged release directory (internal error)."
    fi

    log "Uploading staged release '$STAGED_DIR' to '${DEPLOY_SFTP_USER}@${DEPLOY_HOST}:${DEPLOY_REMOTE_PATH}' via SFTP"

    local batch_file
    batch_file="$(mktemp "${TMPDIR:-/tmp}/homebrewing-deploy-sftp.XXXXXXXX")"
    printf 'put -R "%s" "%s"\n' "$STAGED_DIR" "$DEPLOY_REMOTE_PATH" > "$batch_file"

    # BatchMode=no is required here: sftp's own -b batch-file mode otherwise
    # forces BatchMode=yes, which disables password/keyboard-interactive
    # auth entirely (sshpass would silently be unable to authenticate).
    if ! sshpass -p "$DEPLOY_SFTP_PASS" sftp \
        -o BatchMode=no \
        -o StrictHostKeyChecking=accept-new \
        -o ConnectTimeout=15 \
        -P "$DEPLOY_PORT" \
        -b "$batch_file" \
        "${DEPLOY_SFTP_USER}@${DEPLOY_HOST}"; then
        rm -f "$batch_file"
        die "SFTP upload failed. The remote release directory may be missing or incomplete at '${DEPLOY_REMOTE_PATH}'."
    fi
    rm -f "$batch_file"

    log "Upload complete: '${DEPLOY_REMOTE_PATH}'"
}

# --- Migration + health check + rollback (D5) -----------------------------

# Runs bin/migrate.php inside the freshly uploaded release, against the
# remote's own .env. This script never uploads .env from the local machine.
# Because backup_remote()/upload_release() swap in a brand-new release
# directory on every deploy, .env cannot simply "already be there" after the
# very first deploy unless something carries it forward — so, before
# checking, this does a same-host remote-side `cp` of .env from the release
# that was just backed up (BACKUP_PATH) into the new release, if present and
# not already there. No secret ever crosses the network to do this: both
# sides of the copy already live on the remote host. On the very first
# deploy to a host there is no previous release to carry it from, so the
# operator must place .env manually (README step 4) before migrations can
# run — that is the only case where the check below can still fail.
run_migrations() {
    log "Ensuring '.env' is present in the new release at '${DEPLOY_REMOTE_PATH}' on ${DEPLOY_HOST}"

    local carry_cmd
    carry_cmd="if [ ! -f '${DEPLOY_REMOTE_PATH}/.env' ] && [ -f '${BACKUP_PATH}/.env' ]; then cp -- '${BACKUP_PATH}/.env' '${DEPLOY_REMOTE_PATH}/.env' && echo CARRIED; else echo NOOP; fi"
    ssh_run "$carry_cmd" >/dev/null

    local check_cmd check_result
    check_cmd="[ -f '${DEPLOY_REMOTE_PATH}/.env' ] && echo ENV_OK || echo ENV_MISSING"
    check_result="$(ssh_run "$check_cmd")"

    if [[ "$check_result" != "ENV_OK" ]]; then
        die "Remote '.env' not found at '${DEPLOY_REMOTE_PATH}/.env' (and no previous release to carry it forward from either). This script never uploads .env — create it manually on the remote host first (see README's manual deploy step 4, 'configure the app'), then re-run."
    fi

    log "Running migrations: ${DEPLOY_REMOTE_PHP_BIN} bin/migrate.php (remote, in '${DEPLOY_REMOTE_PATH}')"
    if ! ssh_run "$MIGRATE_CMD"; then
        die "Remote migration run failed. The uploaded release is in place at '${DEPLOY_REMOTE_PATH}' but migrations did not complete — investigate manually before retrying. Automatic rollback only restores the previous code release, never the database (see README's manual-rollback notes)."
    fi

    log "Migrations applied successfully."
}

# If DEPLOY_HEALTHCHECK_URL is set, curls it and returns non-zero on a
# non-2xx (or unreachable) response. Returns success immediately (no
# network call) when the health check is not configured.
health_check() {
    if [[ -z "${DEPLOY_HEALTHCHECK_URL:-}" ]]; then
        log "DEPLOY_HEALTHCHECK_URL not set — skipping post-deploy health check."
        return 0
    fi

    log "Running post-deploy health check: curl '${DEPLOY_HEALTHCHECK_URL}'"
    local http_code
    http_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$DEPLOY_HEALTHCHECK_URL")" || true
    if [[ -z "$http_code" ]]; then
        http_code="000"
    fi

    if [[ "$http_code" =~ ^2[0-9][0-9]$ ]]; then
        log "Health check passed (HTTP $http_code)."
        return 0
    fi

    log_error "Health check failed (HTTP $http_code) for '${DEPLOY_HEALTHCHECK_URL}'."
    return 1
}

# Restores the previous release (BACKUP_PATH) back into place at
# DEPLOY_REMOTE_PATH after a failed health check. Only ever touches the
# uploaded code — never the database, since migrations are forward-only and
# cannot be safely auto-reverted.
rollback() {
    log_error "Rolling back: restoring previous release from '${BACKUP_PATH}'"

    local rollback_cmd rollback_result
    rollback_cmd="if [ ! -d '${BACKUP_PATH}' ]; then echo NO_BACKUP; exit 1; fi; rm -rf -- '${DEPLOY_REMOTE_PATH}' && mv -- '${BACKUP_PATH}' '${DEPLOY_REMOTE_PATH}' && echo ROLLED_BACK"

    if rollback_result="$(ssh_run "$rollback_cmd")" && [[ "$rollback_result" == "ROLLED_BACK" ]]; then
        log "Rollback complete: '${DEPLOY_REMOTE_PATH}' now points at the previous release again."
        log_error "NOTE: automatic rollback only restores the previous CODE release. Any database migrations applied during this deploy were NOT reverted (migrations are forward-only). See README for the manual DB-rollback procedure."
    else
        die "Automatic rollback FAILED (remote response: '${rollback_result:-none}'). Manual intervention required at ${DEPLOY_SSH_USER}@${DEPLOY_HOST}:${DEPLOY_REMOTE_PATH} (expected backup at '${BACKUP_PATH}')."
    fi
}

# --- Confirmation --------------------------------------------------------

confirm_or_exit() {
    if $ASSUME_YES; then
        return 0
    fi

    local reply
    read -r -p "Deploy to ${DEPLOY_SSH_USER}@${DEPLOY_HOST}:${DEPLOY_REMOTE_PATH}? [y/N] " reply
    case "$reply" in
        y|Y|yes|YES)
            return 0
            ;;
        *)
            die "Aborted (no confirmation given)."
            ;;
    esac
}

# --- Main ------------------------------------------------------------------

main() {
    parse_args "$@"
    load_config
    check_dependencies

    if $DRY_RUN; then
        print_plan
        exit 0
    fi

    if $STAGE_ONLY; then
        stage_release
        log "Stage-only run finished. Staged release left at: $STAGED_DIR"
        log "(Remove it manually when done inspecting it.)"
        exit 0
    fi

    compute_plan
    confirm_or_exit

    stage_release
    backup_remote
    upload_release
    run_migrations

    if health_check; then
        log "Deploy succeeded: '${DEPLOY_REMOTE_PATH}' is live on ${DEPLOY_HOST}."
        exit 0
    fi

    rollback
    die "Deploy FAILED its post-deploy health check and was automatically rolled back to the previous code release. See above for details."
}

main "$@"
