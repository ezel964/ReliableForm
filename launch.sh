#!/usr/bin/env bash
# launch.sh — start/stop/inspect the whole ReliableForm stack.
#
#   bash launch.sh [start|stop|restart|status|logs <name>] [--yes|-y]
#
# Services: web1 web2 status worker-pdf worker-email worker-webhook nginx
# MySQL and Redis are system services — launch.sh starts them if needed but
# `stop` never touches them.
#
# Web runtime (WEB_RUNTIME=auto|fpm|cli, env var overrides .env): fpm runs
# web1/web2 as two independent php-fpm masters on the same ports; cli keeps
# the php -S dev servers. auto picks fpm when a php-fpm binary is found.
#
# bash 3.2 compatible. Errors handled explicitly (no set -e).

set -u

ROOT="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=scripts/common.sh
. "$ROOT/scripts/common.sh"

RUN_DIR="$ROOT/storage/run"
LOG_DIR="$ROOT/storage/logs"
NGINX_CONF="$RUN_DIR/nginx.conf"
# Single source of truth for service names — every loop/case derives from
# these (adding a worker here is the ONLY list edit needed).
WORKER_SERVICES="worker-pdf worker-email worker-webhook"
APP_SERVICES="web1 web2 status $WORKER_SERVICES otel"
ALL_SERVICES="$APP_SERVICES nginx"

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
CMD=""
LOG_TARGET=""
KILL_TARGET=""
usage() {
    echo "usage: bash launch.sh [start|stop|restart|status|logs <name>|kill <name>] [--yes|-y]"
    echo "       service names: web1 web2 status worker-pdf worker-email worker-webhook nginx"
    echo "       kill = chaos-kill one service (whole process tree); start brings it back"
}
for arg in "$@"; do
    case "$arg" in
        -y|--yes) ASSUME_YES=1; export ASSUME_YES; continue ;;
    esac
    # Targets bind first: `kill status` / `logs status` must read 'status' as
    # the service name, not as a second command.
    if [ "$CMD" = "logs" ] && [ -z "$LOG_TARGET" ]; then
        LOG_TARGET="$arg"
        continue
    fi
    if [ "$CMD" = "kill" ] && [ -z "$KILL_TARGET" ]; then
        KILL_TARGET="$arg"
        continue
    fi
    case "$arg" in
        start|stop|restart|status|logs|kill)
            if [ -n "$CMD" ]; then err "only one command allowed (got '$CMD' and '$arg')"; usage; exit 1; fi
            CMD="$arg"
            ;;
        *)
            err "unknown argument: $arg"
            usage
            exit 1
            ;;
    esac
done
[ -z "$CMD" ] && CMD="start"

# Ports (env_get falls back to defaults when .env is absent).
LB_PORT="$(env_get LB_PORT 8080)"
WEB1_PORT="$(env_get WEB1_PORT 9001)"
WEB2_PORT="$(env_get WEB2_PORT 9002)"
STATUS_PORT="$(env_get STATUS_PORT 9301)"
REDIS_HOST="$(env_get REDIS_HOST 127.0.0.1)"
REDIS_PORT="$(env_get REDIS_PORT 6379)"
OTEL_ENDPOINT="$(env_get OTEL_EXPORTER_OTLP_ENDPOINT "")"

# Stage 2 runtime knobs. Real env vars override .env (`WEB_RUNTIME=cli bash
# launch.sh start` must win over the file).
WEB_RUNTIME_CFG="${WEB_RUNTIME:-}"
[ -z "$WEB_RUNTIME_CFG" ] && WEB_RUNTIME_CFG="$(env_get WEB_RUNTIME auto)"
QUEUE_DRIVER_CFG="${QUEUE_DRIVER:-}"
[ -z "$QUEUE_DRIVER_CFG" ] && QUEUE_DRIVER_CFG="$(env_get QUEUE_DRIVER auto)"

# ---------------------------------------------------------------------------
# Web runtime resolution (WEB_RUNTIME: auto|fpm|cli)
# ---------------------------------------------------------------------------

# php-fpm discovery, in contract order. Keep in sync with setup.sh.
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

# Sets WEB_MODE (fpm|cli) and PHP_FPM_BIN. Silent — cmd_start prints the
# one-line summary. Returns 1 only on a hard config error.
WEB_MODE=""
PHP_FPM_BIN=""
resolve_web_runtime() {
    case "$WEB_RUNTIME_CFG" in
        fpm)
            PHP_FPM_BIN="$(find_php_fpm)" || PHP_FPM_BIN=""
            if [ -z "$PHP_FPM_BIN" ]; then
                err "WEB_RUNTIME=fpm but no php-fpm binary found (looked for: php-fpm in PATH, <php>/../sbin, brew prefix, /usr/sbin/php-fpm8*)."
                err "Install it (brew install php · sudo apt-get install php<X.Y>-fpm) or set WEB_RUNTIME=cli."
                return 1
            fi
            WEB_MODE="fpm"
            ;;
        cli)
            WEB_MODE="cli"
            ;;
        auto|"")
            PHP_FPM_BIN="$(find_php_fpm)" || PHP_FPM_BIN=""
            if [ -n "$PHP_FPM_BIN" ]; then WEB_MODE="fpm"; else WEB_MODE="cli"; fi
            ;;
        *)
            err "invalid WEB_RUNTIME '$WEB_RUNTIME_CFG' (use auto|fpm|cli)"
            return 1
            ;;
    esac
    return 0
}

# Mode the RUNNING stack actually uses: the marker cmd_start writes wins;
# fall back to resolving the config when the stack was never started.
effective_web_mode() {
    local m=""
    m="$(tr -d '[:space:]' < "$RUN_DIR/web-runtime" 2>/dev/null)"
    case "$m" in
        fpm|cli) printf '%s' "$m"; return 0 ;;
    esac
    if resolve_web_runtime 2>/dev/null; then
        printf '%s' "$WEB_MODE"
    else
        printf 'cli'
    fi
}

# QUEUE_DRIVER is resolved ONCE here and exported to every service started
# below, so web and workers can never disagree mid-run (auto: gearmand binary
# present => gearman, else redis). Services never see the literal "auto".
if [ "$QUEUE_DRIVER_CFG" = "auto" ] || [ -z "$QUEUE_DRIVER_CFG" ]; then
    if have gearmand; then QUEUE_DRIVER_RESOLVED="gearman"; else QUEUE_DRIVER_RESOLVED="redis"; fi
else
    QUEUE_DRIVER_RESOLVED="$QUEUE_DRIVER_CFG"
fi

# ---------------------------------------------------------------------------
# PID helpers
# ---------------------------------------------------------------------------
pid_of() { # $1 service → echoes pid; returns 1 when no usable pid file
    local f="$RUN_DIR/$1.pid" p=""
    [ -f "$f" ] || return 1
    p="$(tr -d '[:space:]' < "$f" 2>/dev/null)"
    [ -n "$p" ] || return 1
    printf '%s' "$p"
}

is_running() { # $1 service
    local p
    p="$(pid_of "$1")" || return 1
    kill -0 "$p" 2>/dev/null
}

# PHP_CLI_SERVER_WORKERS forks children that survive their master and keep the
# listen socket — killing just the recorded pid leaks orphans. Always take the
# whole tree down: TERM children + parent, wait ≤5s, escalate to KILL.
kill_tree() { # $1 pid
    local p="$1" kids="" k="" i=0 alive=""
    kids="$(pgrep -P "$p" 2>/dev/null || true)"
    for k in $kids; do kill -TERM "$k" 2>/dev/null; done
    kill -TERM "$p" 2>/dev/null
    while [ $i -lt 10 ]; do
        alive=""
        kill -0 "$p" 2>/dev/null && alive=1
        for k in $kids; do kill -0 "$k" 2>/dev/null && alive=1; done
        [ -z "$alive" ] && return 0
        sleep 0.5
        i=$((i + 1))
    done
    kill -0 "$p" 2>/dev/null && kill -KILL "$p" 2>/dev/null
    for k in $kids; do kill -0 "$k" 2>/dev/null && kill -KILL "$k" 2>/dev/null; done
    return 0
}

port_of() { # $1 service → its listen port ('' for workers/nginx)
    case "$1" in
        web1)   printf '%s' "$WEB1_PORT" ;;
        web2)   printf '%s' "$WEB2_PORT" ;;
        status) printf '%s' "$STATUS_PORT" ;;
        *)      printf '' ;;
    esac
}

# If a service's pid file is dead but its port is still held (orphaned php -S
# children after an unclean master death), a fresh master would fail to bind
# with "Address already in use". Clear the squatters before starting.
sweep_orphans() { # $1 service
    local name="$1" port="" strays="" s=""
    port="$(port_of "$name")"
    [ -n "$port" ] || return 0
    have lsof || return 0
    strays="$(lsof -t -iTCP:"$port" -sTCP:LISTEN 2>/dev/null | sort -u)"
    [ -n "$strays" ] || return 0
    warn "$name: port $port still held by orphaned process(es) $(echo $strays | tr '\n' ' ')— cleaning up first"
    for s in $strays; do kill -TERM "$s" 2>/dev/null; done
    sleep 1
    for s in $strays; do kill -0 "$s" 2>/dev/null && kill -KILL "$s" 2>/dev/null; done
    return 0
}

# ---------------------------------------------------------------------------
# HTTP probes
# ---------------------------------------------------------------------------
http_code() { # $1 url → echoes 3-digit code (000 when unreachable / no curl)
    local code=""
    if ! have curl; then printf '000'; return 0; fi
    # curl prints the -w code (000) itself even on connect failure.
    code="$(curl -s -o /dev/null -m 2 -w '%{http_code}' "$1" 2>/dev/null)"
    [ -z "$code" ] && code="000"
    printf '%s' "$code"
}

# ---------------------------------------------------------------------------
# Config rendering — plain sed to stdout (NEVER sed -i), per contract.
# ---------------------------------------------------------------------------
# Renders both the runtime-specific web include (fpm: FastCGI to the pools,
# cli: proxy to php -S) and the shared conf that pulls it in. Requires
# WEB_MODE to be resolved.
render_nginx_conf() {
    local inc_template="$ROOT/config/nginx.web-proxy.inc.template"
    [ "$WEB_MODE" = "fpm" ] && inc_template="$ROOT/config/nginx.web-fpm.inc.template"
    sed -e "s|{{ROOT}}|$ROOT|g" \
        -e "s|{{WEB1_PORT}}|$WEB1_PORT|g" \
        -e "s|{{WEB2_PORT}}|$WEB2_PORT|g" \
        "$inc_template" > "$RUN_DIR/nginx-web.inc" || return 1
    sed -e "s|{{ROOT}}|$ROOT|g" \
        -e "s|{{LB_PORT}}|$LB_PORT|g" \
        -e "s|{{WEB1_PORT}}|$WEB1_PORT|g" \
        -e "s|{{WEB2_PORT}}|$WEB2_PORT|g" \
        -e "s|{{STATUS_PORT}}|$STATUS_PORT|g" \
        -e "s|{{WEB_INC}}|$RUN_DIR/nginx-web.inc|g" \
        "$ROOT/config/nginx.conf.template" > "$NGINX_CONF"
}

render_fpm_conf() { # $1 pool name, $2 port
    sed -e "s|{{ROOT}}|$ROOT|g" \
        -e "s|{{NAME}}|$1|g" \
        -e "s|{{PORT}}|$2|g" \
        -e "s|{{QUEUE_DRIVER}}|$QUEUE_DRIVER_RESOLVED|g" \
        "$ROOT/config/php-fpm.conf.template" > "$RUN_DIR/fpm-$1.conf"
}

# ---------------------------------------------------------------------------
# Service starters
# ---------------------------------------------------------------------------

# Start one php-fpm pool master (fpm mode). The caller already checked
# is_running, cleared the pid file and swept orphans. Like nginx, the master
# daemonizes and writes storage/run/<name>.pid itself — the pid never comes
# from $!.
start_fpm_pool() { # $1 pool name, $2 port
    local name="$1" port="$2" pidfile="$RUN_DIR/$1.pid" p="" i=0
    if ! render_fpm_conf "$name" "$port"; then
        err "Could not render config/php-fpm.conf.template to storage/run/fpm-$name.conf."
        return 1
    fi
    if ! "$PHP_FPM_BIN" -t -y "$RUN_DIR/fpm-$name.conf" >"$RUN_DIR/fpm-validate.out" 2>&1; then
        err "Rendered php-fpm config failed validation (php-fpm -t):"
        sed 's/^/    /' "$RUN_DIR/fpm-validate.out" >&2
        rm -f "$RUN_DIR/fpm-validate.out"
        return 1
    fi
    rm -f "$RUN_DIR/fpm-validate.out"
    if ! "$PHP_FPM_BIN" -y "$RUN_DIR/fpm-$name.conf"; then
        err "$name (php-fpm) failed to start — check storage/logs/$name.log"
        return 1
    fi
    while [ $i -lt 30 ]; do
        if [ -s "$pidfile" ]; then
            p="$(tr -d '[:space:]' < "$pidfile" 2>/dev/null)"
            if [ -n "$p" ] && kill -0 "$p" 2>/dev/null; then
                info "$name starting (php-fpm master pid $p) — log: storage/logs/$name.log"
                return 0
            fi
        fi
        sleep 0.1
        i=$((i + 1))
    done
    err "$name (php-fpm) did not write a live pid to storage/run/$name.pid within 3s — check storage/logs/$name.log"
    return 1
}

start_app_service() { # $1 name
    local name="$1"
    if is_running "$name"; then
        ok "$name already running (pid $(pid_of "$name"))"
        return 0
    fi
    rm -f "$RUN_DIR/$name.pid"
    sweep_orphans "$name"
    case "$name" in
        web1)
            if [ "$WEB_MODE" = "fpm" ]; then
                start_fpm_pool web1 "$WEB1_PORT"
                return $?
            fi
            INSTANCE_ID=web1 PORT="$WEB1_PORT" PHP_CLI_SERVER_WORKERS=4 \
                nohup php -S "127.0.0.1:$WEB1_PORT" -t "$ROOT/apps/web/public" \
                "$ROOT/apps/web/public/index.php" >> "$LOG_DIR/web1.log" 2>&1 &
            ;;
        web2)
            if [ "$WEB_MODE" = "fpm" ]; then
                start_fpm_pool web2 "$WEB2_PORT"
                return $?
            fi
            INSTANCE_ID=web2 PORT="$WEB2_PORT" PHP_CLI_SERVER_WORKERS=4 \
                nohup php -S "127.0.0.1:$WEB2_PORT" -t "$ROOT/apps/web/public" \
                "$ROOT/apps/web/public/index.php" >> "$LOG_DIR/web2.log" 2>&1 &
            ;;
        status)
            if [ ! -d "$ROOT/apps/status/node_modules" ]; then
                warn "apps/status/node_modules missing — status service starts degraded (no MySQL stats). Run setup.sh (npm install) for full stats."
            fi
            INSTANCE_ID=status nohup node "$ROOT/apps/status/server.js" \
                >> "$LOG_DIR/status.log" 2>&1 &
            ;;
        worker-pdf)
            INSTANCE_ID=worker-pdf nohup php "$ROOT/apps/workers/pdf_worker.php" \
                >> "$LOG_DIR/worker-pdf.log" 2>&1 &
            ;;
        worker-email)
            INSTANCE_ID=worker-email nohup php "$ROOT/apps/workers/email_worker.php" \
                >> "$LOG_DIR/worker-email.log" 2>&1 &
            ;;
        worker-webhook)
            INSTANCE_ID=worker-webhook nohup php "$ROOT/apps/workers/webhook_worker.php" \
                >> "$LOG_DIR/worker-webhook.log" 2>&1 &
            ;;
        otel)
            if [ -z "$OTEL_ENDPOINT" ]; then
                info "otel shipper disabled (OTEL_EXPORTER_OTLP_ENDPOINT not set in .env) — skipping"
                return 0
            fi
            INSTANCE_ID=otel nohup php "$ROOT/apps/workers/otel_shipper.php" \
                >> "$LOG_DIR/otel.log" 2>&1 &
            ;;
        *)
            err "internal: unknown service '$name'"
            return 1
            ;;
    esac
    echo $! > "$RUN_DIR/$name.pid"
    info "$name starting (pid $(pid_of "$name")) — log: storage/logs/$name.log"
    return 0
}

start_nginx() {
    local pidfile="$RUN_DIR/nginx.pid" p="" i=0
    if [ -f "$pidfile" ]; then
        p="$(tr -d '[:space:]' < "$pidfile" 2>/dev/null)"
    fi
    if [ -n "$p" ] && kill -0 "$p" 2>/dev/null; then
        # Already running, but the conf was just re-rendered — reload it.
        if "$NGINX_BIN" -s reload -c "$NGINX_CONF" >/dev/null 2>&1; then
            ok "nginx already running (pid $p) — reloaded freshly rendered config"
        else
            warn "nginx is running (pid $p) but reload failed — check storage/logs/nginx-error.log"
        fi
        return 0
    fi
    rm -f "$pidfile"
    if ! "$NGINX_BIN" -c "$NGINX_CONF"; then
        err "nginx failed to start — check storage/logs/nginx-error.log"
        return 1
    fi
    # nginx daemonizes itself: its PID comes from storage/run/nginx.pid
    # (written per the rendered conf), never from \$!.
    while [ $i -lt 30 ]; do
        if [ -s "$pidfile" ]; then
            p="$(tr -d '[:space:]' < "$pidfile" 2>/dev/null)"
            if [ -n "$p" ] && kill -0 "$p" 2>/dev/null; then
                info "nginx starting (pid $p) — http://localhost:$LB_PORT"
                return 0
            fi
        fi
        sleep 0.1
        i=$((i + 1))
    done
    err "nginx did not write a live pid to storage/run/nginx.pid within 3s — check storage/logs/nginx-error.log"
    return 1
}

# ---------------------------------------------------------------------------
# Readiness checks
# ---------------------------------------------------------------------------
wait_http_ready() { # $1 url, $2 budget-seconds → echoes last code; 0 iff 200
    local url="$1" budget="$2" start="$SECONDS" code="000"
    while :; do
        code="$(http_code "$url")"
        if [ "$code" = "200" ]; then
            printf '200'
            return 0
        fi
        if [ $((SECONDS - start)) -ge "$budget" ]; then
            printf '%s' "$code"
            return 1
        fi
        sleep 0.5
    done
}

heartbeat_value() { # $1 service → echoes heartbeat:<name> value ("" if absent)
    have redis-cli || return 0
    redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" GET "heartbeat:$1" 2>/dev/null
}

wait_worker_ready() { # $1 name, $2 budget-seconds → 0 ready / 1 not
    local name="$1" budget="$2" start="$SECONDS"
    while :; do
        if ! is_running "$name"; then
            return 1
        fi
        if [ -n "$(heartbeat_value "$name")" ]; then
            return 0
        fi
        if [ $((SECONDS - start)) -ge "$budget" ]; then
            return 1
        fi
        sleep 0.5
    done
}

TABLE_ROWS=""
add_row() { # $1 symbol-colored, $2 name, $3 detail
    TABLE_ROWS="$TABLE_ROWS$(printf '  %s %-14s %s' "$1" "$2" "$3")
"
}

# ---------------------------------------------------------------------------
# start
# ---------------------------------------------------------------------------
cmd_start() {
    local rc=0 fails=0 sym="" detail="" code="" name=""

    info "${C_BOLD}ReliableForm — starting the stack${C_RESET}"

    # --- preflight -------------------------------------------------------
    if [ ! -f "$ROOT/.env" ]; then
        err "No .env found. Run setup first: bash setup.sh"
        exit 1
    fi

    if ! have php; then
        warn "php not found — required for the web tier and workers."
        if ! pkg_install php || ! have php; then
            err "PHP is required. Install it (brew install php / sudo apt-get install php-cli php-mysql) and re-run."
            exit 1
        fi
    fi
    if ! have node; then
        warn "node not found — required for the status service."
        if ! pkg_install node || ! have node; then
            err "Node.js is required. Install it (brew install node / sudo apt-get install nodejs npm) and re-run."
            exit 1
        fi
    fi
    ok "php + node present"

    # Resolve the web runtime once for this run (binary discovery happens
    # after the php check so the <php>/../sbin probe can work).
    if ! resolve_web_runtime; then
        exit 1
    fi

    # The runtime is pinned per stack-run: flipping it while part of the web
    # tier is still up would desync the LB include from the running instances
    # (FastCGI against an HTTP server, or vice versa). With a partially-running
    # web tier, auto ADOPTS the running mode (e.g. `start` healing one killed
    # instance); an EXPLICIT WEB_RUNTIME that differs restarts web1/web2.
    RUNNING_WEB_MODE=""
    if is_running web1 || is_running web2; then
        RUNNING_WEB_MODE="$(tr -d '[:space:]' < "$RUN_DIR/web-runtime" 2>/dev/null)"
        case "$RUNNING_WEB_MODE" in fpm|cli) ;; *) RUNNING_WEB_MODE="" ;; esac
    fi
    if [ -n "$RUNNING_WEB_MODE" ] && [ "$RUNNING_WEB_MODE" != "$WEB_MODE" ]; then
        # Adopting fpm needs the binary; without it fall through to a restart.
        if { [ "$WEB_RUNTIME_CFG" = "auto" ] || [ -z "$WEB_RUNTIME_CFG" ]; } \
            && { [ "$RUNNING_WEB_MODE" = "cli" ] || [ -n "$PHP_FPM_BIN" ]; }; then
            WEB_MODE="$RUNNING_WEB_MODE"
            info "web tier already running in $WEB_MODE mode — keeping it (stop + start to re-resolve WEB_RUNTIME=auto)"
        else
            warn "web tier is running in $RUNNING_WEB_MODE mode but this run resolved to $WEB_MODE — restarting web1/web2"
            stop_service web1
            stop_service web2
        fi
    fi

    if [ "$WEB_MODE" = "fpm" ]; then
        info "web runtime: fpm — $PHP_FPM_BIN (WEB_RUNTIME=$WEB_RUNTIME_CFG)"
    elif [ "$WEB_RUNTIME_CFG" = "auto" ] && [ -z "$PHP_FPM_BIN" ]; then
        info "web runtime: cli — php -S (WEB_RUNTIME=auto, no php-fpm binary found)"
    else
        info "web runtime: cli — php -S (WEB_RUNTIME=$WEB_RUNTIME_CFG)"
    fi
    # Resolved queue driver for every service started below (see top of file).
    QUEUE_DRIVER="$QUEUE_DRIVER_RESOLVED"
    export QUEUE_DRIVER

    # Redis is required: sessions, queues and heartbeats live there.
    if redis_running; then
        ok "Redis is running ($REDIS_HOST:$REDIS_PORT)"
    else
        warn "Redis is not running — sessions, queues and worker heartbeats need it."
        if ! have redis-cli && ! have redis-server; then
            if ! pkg_install redis; then
                err "Redis is required. Install it (brew install redis / sudo apt-get install redis-server) and re-run."
                exit 1
            fi
        fi
        if ask_yn "Start Redis now?" y; then
            if start_redis; then
                ok "Redis started"
            else
                err "Could not start Redis. Start it manually (brew services start redis / sudo systemctl start redis-server) and re-run."
                exit 1
            fi
        else
            err "Redis is required — cannot continue without it. Start it and re-run: bash launch.sh"
            exit 1
        fi
    fi

    # MySQL: server must be up AND the app database must exist.
    mysql_running
    rc=$?
    if [ "$rc" -eq 2 ]; then
        warn "MySQL server is not reachable."
        if ask_yn "Start MySQL now?" y; then
            if ! start_mysql; then
                err "Could not start MySQL. Start it manually (brew services start mysql / sudo systemctl start mysql) and re-run."
                exit 1
            fi
            mysql_running
            rc=$?
        else
            err "MySQL is required — cannot continue without it. Start it and re-run: bash launch.sh"
            exit 1
        fi
    fi
    if [ "$rc" -eq 1 ]; then
        err "MySQL is up, but the app database/user is missing. Run setup first: bash setup.sh"
        exit 1
    elif [ "$rc" -ne 0 ]; then
        err "MySQL is still unreachable. Check it manually (mysql -h 127.0.0.1 -P 3306), then re-run."
        exit 1
    fi
    ok "MySQL is running and the app database is reachable"

    # Nginx binary — it IS the front door, refusing it means no entry point.
    NGINX_BIN="$(find_nginx)" || NGINX_BIN=""
    if [ -z "$NGINX_BIN" ]; then
        warn "nginx not found — it is the load balancer / front door on port $LB_PORT."
        if ! pkg_install nginx; then
            err "Nginx is required — cannot continue without the load balancer. Install it and re-run."
            exit 1
        fi
        NGINX_BIN="$(find_nginx)" || NGINX_BIN=""
        if [ -z "$NGINX_BIN" ]; then
            err "nginx still not found after install. Open a new shell or install manually, then re-run."
            exit 1
        fi
    fi
    ok "nginx binary: $NGINX_BIN"

    # --- prepare ----------------------------------------------------------
    if ! mkdir -p "$ROOT/storage/pdfs" "$ROOT/storage/mail" "$LOG_DIR" \
                  "$RUN_DIR" "$RUN_DIR/nginx_tmp"; then
        err "Could not create storage directories — check permissions in $ROOT."
        exit 1
    fi

    if ! render_nginx_conf; then
        err "Could not render config/nginx.conf.template to storage/run/nginx.conf."
        exit 1
    fi
    if ! "$NGINX_BIN" -t -c "$NGINX_CONF" >"$RUN_DIR/nginx-validate.out" 2>&1; then
        err "Rendered nginx config failed validation (nginx -t):"
        sed 's/^/    /' "$RUN_DIR/nginx-validate.out" >&2
        rm -f "$RUN_DIR/nginx-validate.out"
        exit 1
    fi
    rm -f "$RUN_DIR/nginx-validate.out"
    ok "nginx config rendered + validated (storage/run/nginx.conf + nginx-web.inc, $WEB_MODE mode)"

    # Record the resolved mode for tooling that probes the running stack
    # (launch.sh status, tests/e2e.sh) — the config alone can't tell them
    # what an already-running stack was started with.
    printf '%s\n' "$WEB_MODE" > "$RUN_DIR/web-runtime"

    # --- start everything -------------------------------------------------
    for name in $APP_SERVICES; do
        start_app_service "$name" || fails=$((fails + 1))
    done
    start_nginx || fails=$((fails + 1))

    # --- readiness ---------------------------------------------------------
    info "Waiting for services to become ready..."
    if ! have curl; then
        warn "curl not found — skipping HTTP readiness checks (services were started blind)."
    fi

    READY_START=$SECONDS
    # Per-target budget: share a ~20s pool, but always give each ≥2s.
    budget_left() {
        left=$((20 - (SECONDS - READY_START)))
        [ "$left" -lt 2 ] && left=2
        printf '%s' "$left"
    }

    # The four /healthz targets. fpm pools speak FastCGI, not HTTP — they are
    # probed through the LB's per-pool routes; cli mode keeps direct probes.
    for name in web1 web2 status nginx; do
        case "$name" in
            web1)
                if [ "$WEB_MODE" = "fpm" ]; then
                    code="$(wait_http_ready "http://127.0.0.1:$LB_PORT/__pool/web1/healthz" "$(budget_left)")"; detail="fpm pool :$WEB1_PORT (via LB /__pool/web1)"
                else
                    code="$(wait_http_ready "http://127.0.0.1:$WEB1_PORT/healthz" "$(budget_left)")"; detail="http://127.0.0.1:$WEB1_PORT"
                fi ;;
            web2)
                if [ "$WEB_MODE" = "fpm" ]; then
                    code="$(wait_http_ready "http://127.0.0.1:$LB_PORT/__pool/web2/healthz" "$(budget_left)")"; detail="fpm pool :$WEB2_PORT (via LB /__pool/web2)"
                else
                    code="$(wait_http_ready "http://127.0.0.1:$WEB2_PORT/healthz" "$(budget_left)")"; detail="http://127.0.0.1:$WEB2_PORT"
                fi ;;
            status) code="$(wait_http_ready "http://127.0.0.1:$STATUS_PORT/healthz" "$(budget_left)")"; detail="http://127.0.0.1:$STATUS_PORT" ;;
            nginx) code="$(wait_http_ready "http://127.0.0.1:$LB_PORT/healthz" "$(budget_left)")"; detail="http://localhost:$LB_PORT (LB)" ;;
        esac
        # A 200 from the port is not enough: orphaned listeners can answer
        # while the supervised master is dead. Require the pid to be alive.
        if [ "$name" != "nginx" ] && ! is_running "$name"; then
            code="dead"
        fi
        if [ "$code" = "dead" ]; then
            sym="${C_RED}✗${C_RESET}"
            detail="$detail · process died after start — see storage/logs/$name.log"
            fails=$((fails + 1))
        elif [ "$code" = "200" ]; then
            sym="${C_GREEN}✓${C_RESET}"
            detail="$detail · healthy"
        elif [ "$code" = "503" ]; then
            sym="${C_YELLOW}!${C_RESET}"
            detail="$detail · up but degraded (check MySQL/Redis)"
        else
            sym="${C_RED}✗${C_RESET}"
            if [ "$name" = "nginx" ]; then
                detail="$detail · not ready (last code $code) — see storage/logs/nginx-error.log"
            else
                detail="$detail · not ready (last code $code) — see storage/logs/$name.log"
            fi
            fails=$((fails + 1))
        fi
        add_row "$sym" "$name" "$detail"
    done

    # Workers: PID alive + heartbeat:<name> present in Redis.
    WAIT_WORKERS="$WORKER_SERVICES"
    [ -n "$OTEL_ENDPOINT" ] && WAIT_WORKERS="$WAIT_WORKERS otel"
    for name in $WAIT_WORKERS; do
        if wait_worker_ready "$name" "$(budget_left)"; then
            add_row "${C_GREEN}✓${C_RESET}" "$name" "pid $(pid_of "$name") · heartbeat ok"
        elif is_running "$name"; then
            add_row "${C_YELLOW}!${C_RESET}" "$name" "pid $(pid_of "$name") alive but no heartbeat yet — see storage/logs/$name.log"
        else
            add_row "${C_RED}✗${C_RESET}" "$name" "process died — see storage/logs/$name.log"
            fails=$((fails + 1))
        fi
    done

    echo ""
    printf '  %s %-14s %s\n' " " "SERVICE" "STATE"
    printf '%s' "$TABLE_ROWS"
    echo ""

    if [ "$fails" -eq 0 ]; then
        printf '%s' "${C_GREEN}${C_BOLD}"
        printf '  ReliableForm is up → http://localhost:%s\n' "$LB_PORT"
        printf '%s' "${C_RESET}"
        info "status page  http://localhost:$LB_PORT/status"
        info "demo login   demo@reliableform.dev / demo1234"
        info "logs         bash launch.sh logs web1   (web1|web2|status|worker-pdf|worker-email|worker-webhook|nginx)"
        info "stop         bash launch.sh stop"
        exit 0
    else
        err "$fails service(s) failed to come up — see the table above and storage/logs/."
        info "Retry with: bash launch.sh start   (it skips healthy services)"
        exit 1
    fi
}

# ---------------------------------------------------------------------------
# stop
# ---------------------------------------------------------------------------
stop_service() { # $1 name — TERM, wait ≤5s, then KILL
    local name="$1" f="$RUN_DIR/$1.pid" p="" i=0
    if [ ! -f "$f" ]; then
        info "$name: not running (no pid file)"
        return 0
    fi
    p="$(tr -d '[:space:]' < "$f" 2>/dev/null)"
    if [ -z "$p" ] || ! kill -0 "$p" 2>/dev/null; then
        rm -f "$f"
        info "$name: not running (stale pid file removed)"
        return 0
    fi
    kill_tree "$p"
    rm -f "$f"
    ok "$name stopped (was pid $p)"
    return 0
}

cmd_kill() { # chaos drill: take one service down, whole process tree
    local name="$KILL_TARGET" p=""
    case " $ALL_SERVICES " in
        *" $name "*) ;;
        *)
            if [ -z "$name" ]; then err "kill needs a service name."; else err "unknown service '$name'."; fi
            usage; exit 1
            ;;
    esac
    if ! is_running "$name"; then
        info "$name is not running."
        exit 0
    fi
    p="$(pid_of "$name")"
    kill_tree "$p"
    rm -f "$RUN_DIR/$name.pid"
    ok "$name (pid $p) is down. Watch http://localhost:$LB_PORT/status react."
    info "bring it back with: bash launch.sh start"
    exit 0
}

cmd_stop() {
    local name
    info "${C_BOLD}ReliableForm — stopping the stack${C_RESET}"
    for name in $APP_SERVICES; do
        stop_service "$name"
    done
    # nginx wrote storage/run/nginx.pid itself; TERM on the master is the
    # same as `nginx -s stop -c <conf>` without needing the binary.
    stop_service nginx
    rm -f "$RUN_DIR/nginx-validate.out"
    info "MySQL and Redis are system services — left running (stop them yourself if you want)."
    exit 0
}

# ---------------------------------------------------------------------------
# status
# ---------------------------------------------------------------------------
cmd_status() {
    local name="" p="" code="" hb="" detail="" sym="" mode=""
    mode="$(effective_web_mode)"
    info "${C_BOLD}ReliableForm — service status${C_RESET}"
    info "web runtime: $mode"
    echo ""
    printf '  %s %-14s %-10s %s\n' " " "SERVICE" "PID" "DETAIL"
    for name in $ALL_SERVICES; do
        if is_running "$name"; then
            p="$(pid_of "$name")"
            sym="${C_GREEN}✓${C_RESET}"
            detail=""
            case "$name" in
                web1)
                    if [ "$mode" = "fpm" ]; then
                        code="$(http_code "http://127.0.0.1:$LB_PORT/__pool/web1/healthz")"; detail="healthz $code · fpm pool :$WEB1_PORT via LB /__pool/web1"
                    else
                        code="$(http_code "http://127.0.0.1:$WEB1_PORT/healthz")"; detail="healthz $code · http://127.0.0.1:$WEB1_PORT"
                    fi ;;
                web2)
                    if [ "$mode" = "fpm" ]; then
                        code="$(http_code "http://127.0.0.1:$LB_PORT/__pool/web2/healthz")"; detail="healthz $code · fpm pool :$WEB2_PORT via LB /__pool/web2"
                    else
                        code="$(http_code "http://127.0.0.1:$WEB2_PORT/healthz")"; detail="healthz $code · http://127.0.0.1:$WEB2_PORT"
                    fi ;;
                status) code="$(http_code "http://127.0.0.1:$STATUS_PORT/healthz")"; detail="healthz $code · http://127.0.0.1:$STATUS_PORT" ;;
                nginx)  code="$(http_code "http://127.0.0.1:$LB_PORT/healthz")";     detail="LB healthz $code · http://localhost:$LB_PORT" ;;
                worker-*|otel)
                    hb="$(heartbeat_value "$name")"
                    if [ -n "$hb" ]; then
                        detail="heartbeat ok"
                    else
                        detail="no heartbeat (redis down or worker stuck?)"
                        sym="${C_YELLOW}!${C_RESET}"
                    fi
                    ;;
            esac
            case "$name" in
                web1|web2|status|nginx)
                    if [ "$code" = "200" ]; then
                        : # healthy
                    elif [ "$code" = "503" ]; then
                        sym="${C_YELLOW}!${C_RESET}"; detail="$detail (degraded)"
                    else
                        sym="${C_YELLOW}!${C_RESET}"; detail="$detail (not answering)"
                    fi
                    ;;
            esac
            printf '  %s %-14s %-10s %s\n' "$sym" "$name" "$p" "$detail"
        else
            if [ "$name" = "otel" ] && [ -z "$OTEL_ENDPOINT" ]; then
                printf '  %s %-14s %-10s %s\n' "${C_YELLOW}·${C_RESET}" "$name" "-" "disabled (set OTEL_EXPORTER_OTLP_ENDPOINT in .env to enable)"
            else
                printf '  %s %-14s %-10s %s\n' "${C_RED}✗${C_RESET}" "$name" "-" "stopped"
            fi
        fi
    done
    echo ""
    if redis_running; then
        ok "redis: running ($REDIS_HOST:$REDIS_PORT)"
    else
        warn "redis: not running (or redis-cli missing)"
    fi
    if [ -f "$ROOT/.env" ] && have php; then
        mysql_running
        case $? in
            0) ok "mysql: running, app database reachable" ;;
            1) warn "mysql: server up, but app database missing — run: bash setup.sh" ;;
            *) warn "mysql: not reachable" ;;
        esac
    else
        warn "mysql: not probed (need .env + php — run: bash setup.sh)"
    fi
    exit 0
}

# ---------------------------------------------------------------------------
# logs
# ---------------------------------------------------------------------------
cmd_logs() {
    local f1="" f2=""
    case "$LOG_TARGET" in
        web1|web2)
            f1="$LOG_DIR/$LOG_TARGET.log"
            # fpm masters also keep a slowlog (requests > 2s) next to it.
            [ -f "$LOG_DIR/$LOG_TARGET-slow.log" ] && f2="$LOG_DIR/$LOG_TARGET-slow.log"
            ;;
        status|worker-*|otel)
            f1="$LOG_DIR/$LOG_TARGET.log"
            ;;
        nginx)
            f1="$LOG_DIR/nginx-error.log"
            f2="$LOG_DIR/nginx-access.log"
            ;;
        "")
            err "logs needs a service name."
            usage
            exit 1
            ;;
        *)
            err "unknown service '$LOG_TARGET'."
            usage
            exit 1
            ;;
    esac
    if [ ! -f "$f1" ] && { [ -z "$f2" ] || [ ! -f "$f2" ]; }; then
        err "No log file yet for '$LOG_TARGET' (expected $f1)."
        info "Has it been started? Try: bash launch.sh start"
        exit 1
    fi
    if [ -n "$f2" ] && [ -f "$f2" ]; then
        if [ -f "$f1" ]; then
            exec tail -n 50 -f "$f1" "$f2"
        fi
        exec tail -n 50 -f "$f2"
    fi
    exec tail -n 50 -f "$f1"
}

# ---------------------------------------------------------------------------
# Dispatch
# ---------------------------------------------------------------------------
case "$CMD" in
    start)   cmd_start ;;
    stop)    cmd_stop ;;
    restart)
        # stop exits 0; run it in a subshell so we continue into start.
        ( cmd_stop ) || true
        cmd_start
        ;;
    status)  cmd_status ;;
    logs)    cmd_logs ;;
    kill)    cmd_kill ;;
esac
