#!/usr/bin/env bash
# setup.sh — one-time interactive bootstrap for ReliableForm.
#
# Checks/installs dependencies, creates .env, provisions the MySQL database
# and app user, applies schema + seed, installs the status service's npm
# dependency, and creates the storage directories.
#
# Usage: bash setup.sh [--yes|-y]      (--yes answers yes to every prompt)
#
# bash 3.2 compatible. Errors are handled explicitly (no set -e) so every
# failure comes with a friendly "what to do next".

set -u

ROOT="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=scripts/common.sh
. "$ROOT/scripts/common.sh"

for arg in "$@"; do
    case "$arg" in
        -y|--yes) ASSUME_YES=1; export ASSUME_YES ;;
        *) err "unknown argument: $arg"; echo "usage: bash setup.sh [--yes|-y]"; exit 1 ;;
    esac
done

# ---------------------------------------------------------------------------
# Prompt helpers (setup-only).
# ---------------------------------------------------------------------------
prompt_value() { # $1 label, $2 default → echoes answer (default when non-interactive)
    local v=""
    if [ "${ASSUME_YES:-0}" = "1" ] || [ ! -r /dev/tty ]; then
        printf '%s' "$2"
        return 0
    fi
    printf '%s [%s]: ' "$1" "$2" > /dev/tty
    IFS= read -r v < /dev/tty || v=""
    [ -z "$v" ] && v="$2"
    printf '%s' "$v"
}

prompt_secret() { # $1 label → echoes answer (empty default / non-interactive)
    local v=""
    if [ "${ASSUME_YES:-0}" = "1" ] || [ ! -r /dev/tty ]; then
        printf ''
        return 0
    fi
    printf '%s [empty]: ' "$1" > /dev/tty
    IFS= read -rs v < /dev/tty || v=""
    printf '\n' > /dev/tty
    printf '%s' "$v"
}

# ---------------------------------------------------------------------------
# Banner
# ---------------------------------------------------------------------------
printf '%s\n' "${C_BOLD}"
printf '  ┌─────────────────────────────────────────────┐\n'
printf '  │  ReliableForm · one-time setup              │\n'
printf '  │  LB + 2x PHP web + Node status + 2 workers  │\n'
printf '  └─────────────────────────────────────────────┘\n'
printf '%s' "${C_RESET}"
info "Project root: $ROOT"
info "Detected OS: $OS (package manager: ${PKG_MANAGER:-none})"
echo ""

# ---------------------------------------------------------------------------
# 1. Dependency checks
# ---------------------------------------------------------------------------
info "${C_BOLD}Step 1/6 — dependencies${C_RESET}"

php_ok() {
    have php || return 1
    php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1 || return 1
    php -m 2>/dev/null | grep -qi '^pdo_mysql$' || return 1
    return 0
}

node_ok() {
    local major
    have node || return 1
    major="$(node -v 2>/dev/null | sed 's/^v//' | cut -d. -f1)"
    [ -n "$major" ] && [ "$major" -ge 18 ] 2>/dev/null
}

# check_dep <generic-pkg> <label> <verify-fn> <why> → 0 ok / 1 still missing
check_dep() {
    local pkg="$1" label="$2" verify="$3" why="$4"
    if "$verify"; then
        ok "$label"
        return 0
    fi
    warn "$label — missing or too old. $why"
    if pkg_install "$pkg"; then
        hash -r 2>/dev/null
        if "$verify"; then
            ok "$label (installed)"
            return 0
        fi
        err "$label still not usable after install (open a new shell and re-run if PATH changed)."
        return 1
    fi
    return 1
}

mysql_client_ok() { have mysql; }
redis_ok()        { have redis-cli || have redis-server; }
nginx_ok()        { find_nginx >/dev/null; }
npm_ok()          { have npm; }

# php-fpm discovery, in contract order. Keep in sync with launch.sh.
find_php_fpm() {
    local p=""
    if have php-fpm; then
        command -v php-fpm
        return 0
    fi
    if have php; then
        p="$(dirname "$(command -v php)")/../sbin/php-fpm"
        if [ -x "$p" ]; then printf '%s\n' "$p"; return 0; fi
    fi
    if have brew; then
        p="$(brew --prefix php 2>/dev/null)/sbin/php-fpm"
        if [ -x "$p" ]; then printf '%s\n' "$p"; return 0; fi
    fi
    # apt ships php-fpm as a separate php8.x-fpm package under /usr/sbin.
    for p in /usr/sbin/php-fpm8*; do
        if [ -x "$p" ]; then printf '%s\n' "$p"; return 0; fi
    done
    return 1
}

# php-fpm is OPTIONAL: WEB_RUNTIME=auto falls back to php -S without it, so
# this never fails setup — it informs and (on apt) offers the package.
check_php_fpm() {
    local fpm_bin="" php_mm="" pkg=""
    fpm_bin="$(find_php_fpm)" || fpm_bin=""
    if [ -n "$fpm_bin" ]; then
        ok "php-fpm ($fpm_bin) — web tier runs in FPM mode under WEB_RUNTIME=auto"
        return 0
    fi
    info "php-fpm not found — WEB_RUNTIME=auto falls back to php -S (cli mode); the stack still works."
    if [ "$PKG_MANAGER" = "apt" ] && have php; then
        php_mm="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)"
        if [ -n "$php_mm" ]; then
            pkg="php${php_mm}-fpm"
            if pkg_install "$pkg"; then
                hash -r 2>/dev/null
                fpm_bin="$(find_php_fpm)" || fpm_bin=""
                if [ -n "$fpm_bin" ]; then
                    ok "php-fpm installed ($fpm_bin)"
                else
                    warn "php-fpm still not found after installing $pkg — launch.sh will use cli mode."
                fi
            fi
        fi
    elif [ "$PKG_MANAGER" = "brew" ]; then
        info "Homebrew ships php-fpm with the php formula — 'brew install php' provides it."
    fi
    return 0
}

# gearmand discovery — keep in sync with launch.sh.
find_gearmand() {
    local p=""
    if have gearmand; then
        command -v gearmand
        return 0
    fi
    for p in /usr/local/sbin/gearmand /opt/homebrew/sbin/gearmand /usr/sbin/gearmand; do
        if [ -x "$p" ]; then printf '%s\n' "$p"; return 0; fi
    done
    if have brew; then
        p="$(brew --prefix gearman 2>/dev/null)/sbin/gearmand"
        if [ -x "$p" ]; then printf '%s\n' "$p"; return 0; fi
    fi
    return 1
}

# gearmand is OPTIONAL: QUEUE_DRIVER=auto falls back to the redis queue
# driver, so this never fails setup — it informs and offers the install
# (production parity: the reference stack queues jobs through gearmand).
check_gearmand() {
    local bin="" pkg=""
    bin="$(find_gearmand)" || bin=""
    if [ -n "$bin" ]; then
        ok "gearmand ($bin) — queue runs in gearman mode under QUEUE_DRIVER=auto"
        return 0
    fi
    info "gearmand not found — QUEUE_DRIVER=auto falls back to the redis queue driver; the stack still works."
    pkg=""
    if [ "$PKG_MANAGER" = "brew" ]; then
        pkg="gearman"
    elif [ "$PKG_MANAGER" = "apt" ]; then
        pkg="gearman-job-server"
    fi
    if [ -n "$pkg" ] && pkg_install "$pkg"; then
        hash -r 2>/dev/null
        bin="$(find_gearmand)" || bin=""
        if [ -n "$bin" ]; then
            ok "gearmand installed ($bin)"
        else
            warn "gearmand still not found after installing $pkg — launch.sh will use the redis queue driver."
        fi
    fi
    return 0
}

# Backing-service runtime (INFRA_RUNTIME=auto|podman|host). podman runs
# MySQL/Redis/gearmand as containers (config/podman/compose.yaml); host uses
# system-installed services. auto prefers podman when usable, else falls back
# to host with the red-box warning. Docker is forbidden — podman compose only.
INFRA_RUNTIME_CFG="${INFRA_RUNTIME:-}"
[ -z "$INFRA_RUNTIME_CFG" ] && INFRA_RUNTIME_CFG="$(env_get INFRA_RUNTIME auto)"
INFRA_MODE=""
case "$INFRA_RUNTIME_CFG" in
    host) INFRA_MODE="host" ;;
    podman|auto|"")
        if podman_available; then
            INFRA_MODE="podman"
        else
            podman_warn_box
            if [ "$INFRA_RUNTIME_CFG" = "podman" ]; then
                if ask_yn "Podman unavailable. Fall back to host-installed MySQL/Redis/gearmand for setup?" y; then
                    INFRA_MODE="host"
                else
                    err "INFRA_RUNTIME=podman but Podman is unavailable. Install it and re-run: bash setup.sh"
                    exit 1
                fi
            else
                warn "Continuing with host-installed services (INFRA_RUNTIME=auto). Install Podman to containerize them."
                INFRA_MODE="host"
            fi
        fi
        ;;
    *)
        err "invalid INFRA_RUNTIME '$INFRA_RUNTIME_CFG' (use auto|podman|host)"
        exit 1
        ;;
esac
if [ "$INFRA_MODE" = "podman" ]; then
    ok "infra runtime: podman — MySQL/Redis/gearmand run as containers (config/podman/compose.yaml)"
else
    info "infra runtime: host — MySQL/Redis/gearmand expected as system services"
fi

PHP_READY=0;   check_dep php   "PHP >= 8.1 with pdo_mysql"        php_ok          "PHP runs the web tier, both workers and the DB probe." || PHP_READY=1
NODE_READY=0;  check_dep node  "Node.js >= 18"                    node_ok         "Node runs the status & analytics service." || NODE_READY=1
NPM_READY=0
if npm_ok; then
    ok "npm"
else
    check_dep node "npm" npm_ok "npm installs the status service's one dependency (mysql2)." || NPM_READY=1
fi
# In podman mode MySQL/Redis/gearmand are containers — no host client/server
# is required (schema is applied through `podman exec`). In host mode they are
# checked/installed as before.
MYSQL_READY=0
REDIS_READY=0
if [ "$INFRA_MODE" = "host" ]; then
    check_dep mysql "MySQL client + server"            mysql_client_ok "MySQL stores users, forms, submissions and job rows." || MYSQL_READY=1
    check_dep redis "Redis"                            redis_ok        "Redis backs sessions, caches, queues and worker heartbeats." || REDIS_READY=1
else
    ok "MySQL — provided by the rf-mysql container (no host client needed)"
    ok "Redis — provided by the rf-redis container (no host client needed)"
fi
NGINX_READY=0; check_dep nginx "Nginx"                            nginx_ok        "Nginx is the load balancer / front door on port 8080." || NGINX_READY=1
check_php_fpm
if [ "$INFRA_MODE" = "host" ]; then
    check_gearmand
else
    ok "gearmand — provided by the rf-gearmand container (built on first launch)"
fi

# php and mysql are required to bootstrap the database — bail out if missing.
if [ "$PHP_READY" -ne 0 ]; then
    err "PHP is required (the DB probe and the entire app run on it)."
    err "Install PHP 8.1+ with the pdo_mysql extension, then re-run: bash setup.sh"
    exit 1
fi
if [ "$MYSQL_READY" -ne 0 ]; then
    err "MySQL is required — setup cannot create the database without it."
    err "Install MySQL (brew install mysql / sudo apt-get install mysql-server), then re-run: bash setup.sh"
    exit 1
fi
[ "$NODE_READY" -ne 0 ]  && warn "Continuing without Node — the status service will not run until you install it."
[ "$NPM_READY" -ne 0 ]   && warn "Continuing without npm — status service stats will be degraded (mysql2 not installed)."
[ "$REDIS_READY" -ne 0 ] && warn "Continuing without Redis — launch.sh will require it before starting the stack."
[ "$NGINX_READY" -ne 0 ] && warn "Continuing without Nginx — launch.sh will require it before starting the stack."
echo ""

# ---------------------------------------------------------------------------
# 2. .env
# ---------------------------------------------------------------------------
info "${C_BOLD}Step 2/6 — configuration (.env)${C_RESET}"
if [ -f "$ROOT/.env" ]; then
    ok ".env already exists — keeping it as-is."
else
    if cp "$ROOT/.env.example" "$ROOT/.env"; then
        ok "Created .env from .env.example (edit it to change ports/credentials)."
    else
        err "Could not copy .env.example to .env — check permissions in $ROOT."
        exit 1
    fi
fi

DB_HOST="$(env_get DB_HOST 127.0.0.1)"
DB_PORT="$(env_get DB_PORT 3306)"
DB_NAME="$(env_get DB_NAME reliableform)"
DB_USER="$(env_get DB_USER reliableform)"
DB_PASS="$(env_get DB_PASS reliableform)"
info "Database target: $DB_NAME on $DB_HOST:$DB_PORT as $DB_USER"
echo ""

# Run the mysql client as the APP user against the app database. In podman
# mode this goes through `podman exec` into the rf-mysql container, so NO host
# mysql client is needed; in host mode it connects over TCP. MYSQL_PWD (vs -p)
# avoids the "password on the command line is insecure" warning.
mysql_app() {
    if [ "$INFRA_MODE" = "podman" ]; then
        podman exec -i -e MYSQL_PWD="$DB_PASS" rf-mysql \
            mysql -h 127.0.0.1 -P 3306 -u "$DB_USER" "$DB_NAME" "$@"
    else
        MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" "$@"
    fi
}

# ---------------------------------------------------------------------------
# 3. MySQL server running?
# ---------------------------------------------------------------------------
info "${C_BOLD}Step 3/6 — MySQL server${C_RESET}"
if [ "$INFRA_MODE" = "podman" ]; then
    info "Bringing up the podman backing services (first run pulls the MySQL/Redis images)..."
    if ! infra_up mysql redis; then
        err "podman compose up failed — see the output above."
        exit 1
    fi
    info "Waiting for the MySQL container to become healthy..."
    if infra_wait_healthy mysql 120; then
        ok "MySQL container (rf-mysql) is healthy — database/user created from compose env."
    else
        err "MySQL container did not become healthy in time — check: podman logs rf-mysql"
        exit 1
    fi
    # Confirm the app can actually reach it over the published port.
    mysql_running
    if [ $? -eq 2 ]; then
        err "MySQL container is up but not reachable on $DB_HOST:$DB_PORT — is the port published?"
        err "Check: podman ps   (expect 127.0.0.1:$DB_PORT->3306)"
        exit 1
    fi
else
    mysql_running
    MYSQL_STATE=$?
    if [ "$MYSQL_STATE" -eq 2 ]; then
        warn "MySQL server is not reachable on $DB_HOST:$DB_PORT."
        if ask_yn "Start MySQL now?" y; then
            if start_mysql; then
                ok "MySQL server is up."
            else
                err "Could not start MySQL."
                err "Start it manually (macOS: brew services start mysql · Linux: sudo systemctl start mysql), then re-run: bash setup.sh"
                exit 1
            fi
        else
            err "MySQL must be running to create the database. Start it, then re-run: bash setup.sh"
            exit 1
        fi
    else
        ok "MySQL server is running."
    fi
fi
echo ""

# ---------------------------------------------------------------------------
# 4. Create database + app user (as MySQL admin)
# ---------------------------------------------------------------------------
info "${C_BOLD}Step 4/6 — database + app user${C_RESET}"
mkdir -p "$ROOT/storage/logs"   # used for the admin-mysql error capture below

if [ "$INFRA_MODE" = "podman" ]; then
    # The rf-mysql container already created the database, the app user
    # ('$DB_USER'@'%'), and the grants from its MYSQL_* env on first boot —
    # there is no separate admin step. Verify the app user can actually
    # connect to its database (this is what every later command relies on).
    if mysql_app -e "SELECT 1" >/dev/null 2>&1; then
        ok "Database '$DB_NAME' and user '$DB_USER' are in place (created by the rf-mysql container)."
    else
        err "App user '$DB_USER' cannot connect to '$DB_NAME' inside rf-mysql."
        err "If you changed DB_* after the volume was created, the old creds persist."
        err "Reset with: bash launch.sh stop --infra && podman volume rm reliableform_rf-mysql-data, then re-run setup."
        exit 1
    fi
else
    BOOTSTRAP_SQL="CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;"

    ADMIN_USER="root"
    ADMIN_PASS=""
    ADMIN_OK=0

    # Fresh Homebrew MySQL installs have passwordless root over the local
    # socket — try that silently first so most macOS users are never prompted.
    if mysql -u root -e 'SELECT 1' >/dev/null 2>&1; then
        ok "MySQL admin access works as 'root' with no password — using it."
        ADMIN_OK=1
    else
        ADMIN_USER="$(prompt_value "MySQL admin user" "root")"
        ADMIN_PASS="$(prompt_secret "MySQL admin password for '$ADMIN_USER'")"
    fi

    BOOTSTRAP_DONE=0
    if [ "$ADMIN_OK" -eq 1 ] || [ -z "$ADMIN_PASS" ]; then
        if printf '%s\n' "$BOOTSTRAP_SQL" | mysql -u "$ADMIN_USER" 2>"$ROOT/storage/logs/setup-mysql.err"; then
            BOOTSTRAP_DONE=1
        fi
    else
        # MYSQL_PWD (env, command-scoped) avoids the "password on the command
        # line interface can be insecure" warning that -p produces.
        if printf '%s\n' "$BOOTSTRAP_SQL" | MYSQL_PWD="$ADMIN_PASS" mysql -u "$ADMIN_USER" 2>"$ROOT/storage/logs/setup-mysql.err"; then
            BOOTSTRAP_DONE=1
        fi
    fi

    # apt-installed MySQL/MariaDB authenticates root via auth_socket → sudo mysql.
    if [ "$BOOTSTRAP_DONE" -ne 1 ] && [ "$OS" = "linux" ] && [ "$PKG_MANAGER" = "apt" ] && have sudo; then
        warn "Admin login failed — retrying with 'sudo mysql' (apt installs use auth_socket for root)."
        if printf '%s\n' "$BOOTSTRAP_SQL" | sudo mysql 2>"$ROOT/storage/logs/setup-mysql.err"; then
            BOOTSTRAP_DONE=1
        fi
    fi

    if [ "$BOOTSTRAP_DONE" -ne 1 ]; then
        err "Could not create the database/user as a MySQL admin."
        [ -s "$ROOT/storage/logs/setup-mysql.err" ] && sed 's/^/    /' "$ROOT/storage/logs/setup-mysql.err" >&2
        echo ""
        info "Run this SQL yourself as a MySQL admin, then re-run: bash setup.sh"
        echo ""
        printf '%s\n' "$BOOTSTRAP_SQL" | sed 's/^/    /'
        echo ""
        exit 1
    fi
    rm -f "$ROOT/storage/logs/setup-mysql.err"
    ok "Database '$DB_NAME' and user '$DB_USER'@{localhost,127.0.0.1} are in place."
fi

# Was this database already in use BEFORE this setup run? Captured now —
# before any tables are created — so the migration runner below knows whether
# to baseline (fresh: schema.sql is the complete current schema) or apply
# pending migrations (existing). mysql_app routes through the rf-mysql
# container in podman mode and over TCP in host mode (defined in Step 2).
TABLE_COUNT="$(mysql_app -N -B \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null)"
FRESH_DB=0
[ "$TABLE_COUNT" = "0" ] && FRESH_DB=1

# Apply schema + seed AS THE APP USER — this validates the grants end-to-end
# with exactly the credentials the app will use.
if mysql_app < "$ROOT/db/schema.sql"; then
    ok "Schema applied (db/schema.sql — idempotent)."
else
    err "Could not apply db/schema.sql as '$DB_USER'."
    err "Check DB_* in .env matches the user, then re-run: bash setup.sh"
    exit 1
fi
if mysql_app < "$ROOT/db/seed.sql"; then
    ok "Demo data seeded (db/seed.sql — idempotent)."
else
    err "Could not apply db/seed.sql. Fix the error above and re-run: bash setup.sh"
    exit 1
fi

# --- schema migrations (db/migrations/*.sql, ledger: schema_migrations) -----
# Fresh database: schema.sql already IS the complete current schema, so every
# migration id is recorded WITHOUT executing the file (baseline). Existing
# database: pending migrations are applied in lexicographic order and
# recorded; any failure aborts setup before later migrations run.
if ! mysql_app -e "CREATE TABLE IF NOT EXISTS schema_migrations (
    id VARCHAR(100) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;"; then
    err "Could not ensure the schema_migrations table exists. Fix the error above and re-run: bash setup.sh"
    exit 1
fi
for MIG_FILE in "$ROOT/db/migrations"/*.sql; do
    [ -e "$MIG_FILE" ] || continue   # no migrations shipped (glob unmatched)
    MIG_ID="$(basename "$MIG_FILE" .sql)"
    if [ "$FRESH_DB" -eq 1 ]; then
        if mysql_app -e "INSERT IGNORE INTO schema_migrations (id) VALUES ('$MIG_ID');"; then
            ok "Migration $MIG_ID baselined (fresh database — already covered by schema.sql)."
        else
            err "Could not baseline migration '$MIG_ID' in schema_migrations. Fix the error above and re-run: bash setup.sh"
            exit 1
        fi
    else
        MIG_DONE="$(mysql_app -N -B -e "SELECT 1 FROM schema_migrations WHERE id='$MIG_ID';" 2>/dev/null)"
        if [ "$MIG_DONE" = "1" ]; then
            ok "Migration $MIG_ID already applied/baselined — skipping."
        elif mysql_app < "$MIG_FILE"; then
            if ! mysql_app -e "INSERT INTO schema_migrations (id) VALUES ('$MIG_ID');"; then
                err "Migration $MIG_ID ran but could not be recorded in schema_migrations."
                err "Record it manually (INSERT INTO schema_migrations (id) VALUES ('$MIG_ID');) before re-running setup."
                exit 1
            fi
            ok "Migration $MIG_ID applied (db/migrations/$(basename "$MIG_FILE"))."
        else
            err "Migration FAILED: db/migrations/$(basename "$MIG_FILE")"
            err "Nothing was recorded for it. Fix the error above, then re-run: bash setup.sh"
            exit 1
        fi
    fi
done
echo ""

# ---------------------------------------------------------------------------
# 5. Storage directories
# ---------------------------------------------------------------------------
info "${C_BOLD}Step 5/6 — storage directories${C_RESET}"
if mkdir -p "$ROOT/storage/pdfs" "$ROOT/storage/mail" "$ROOT/storage/logs" \
            "$ROOT/storage/run" "$ROOT/storage/run/nginx_tmp"; then
    ok "storage/{pdfs,mail,logs,run,run/nginx_tmp} ready."
else
    err "Could not create storage directories — check permissions in $ROOT."
    exit 1
fi
echo ""

# ---------------------------------------------------------------------------
# 6. npm install for the status service
# ---------------------------------------------------------------------------
info "${C_BOLD}Step 6/6 — status service dependency (mysql2)${C_RESET}"
if have npm; then
    if (cd "$ROOT/apps/status" && npm install --no-fund --no-audit --loglevel=error); then
        ok "npm install completed in apps/status."
    else
        warn "npm install failed — the status service will run with degraded MySQL stats."
        warn "Fix npm and re-run: (cd apps/status && npm install)"
    fi
else
    warn "npm not found — skipping. The status service starts anyway but MySQL stats stay degraded."
    warn "Install npm and run: (cd apps/status && npm install)"
fi
echo ""

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
printf '%s\n' "${C_GREEN}${C_BOLD}"
printf '  ┌──────────────────────────────────────────────────────────┐\n'
printf '  │  ReliableForm setup complete                             │\n'
printf '  └──────────────────────────────────────────────────────────┘\n'
printf '%s' "${C_RESET}"
ok   "Config:    .env (DB '$DB_NAME' on $DB_HOST:$DB_PORT, user '$DB_USER')"
ok   "Database:  schema + demo data applied"
ok   "Storage:   storage/{pdfs,mail,logs,run} created"
info "Demo login: demo@reliableform.dev / demo1234"
info "Next step:  bash launch.sh"
exit 0
