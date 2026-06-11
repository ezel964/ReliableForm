#!/usr/bin/env bash
# scripts/common.sh — shared helpers sourced by setup.sh and launch.sh.
#
# bash 3.2 compatible (stock macOS): no associative arrays, no mapfile,
# no ${var,,}, no &>>. Callers run `set -u`, so every expansion of a
# possibly-unset variable uses ${var:-} here.
#
# Expects $ROOT (absolute project root) to be set by the sourcing script;
# falls back to the parent of this file's directory.

if [ -z "${ROOT:-}" ]; then
    ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi

# ---------------------------------------------------------------------------
# Colors — respect NO_COLOR and non-tty stdout.
# ---------------------------------------------------------------------------
if [ -n "${NO_COLOR:-}" ] || [ ! -t 1 ]; then
    C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""; C_BOLD=""; C_DIM=""; C_RESET=""
else
    C_RED=$'\033[31m'
    C_GREEN=$'\033[32m'
    C_YELLOW=$'\033[33m'
    C_BLUE=$'\033[34m'
    C_BOLD=$'\033[1m'
    C_DIM=$'\033[2m'
    C_RESET=$'\033[0m'
fi

ok()   { printf '%s✓%s %s\n' "$C_GREEN"  "$C_RESET" "$*"; }
warn() { printf '%s!%s %s\n' "$C_YELLOW" "$C_RESET" "$*"; }
err()  { printf '%s✗%s %s\n' "$C_RED"    "$C_RESET" "$*" >&2; }
info() { printf '%s·%s %s\n' "$C_BLUE"   "$C_RESET" "$*"; }

# ---------------------------------------------------------------------------
# have <cmd> — is a command available?
# ---------------------------------------------------------------------------
have() { command -v "$1" >/dev/null 2>&1; }

# ---------------------------------------------------------------------------
# ask_yn "question" [default y|n] → returns 0 (yes) / 1 (no).
# Reads from /dev/tty when available so prompts work even when stdout/stdin
# are redirected. ASSUME_YES=1 (set by --yes/-y) answers yes to everything.
# When no tty and no stdin answer is available, the default wins.
# ---------------------------------------------------------------------------
ask_yn() {
    local question="$1" default="${2:-y}" hint ans=""
    if [ "${ASSUME_YES:-0}" = "1" ]; then
        printf '%s?%s %s %s(auto-yes)%s\n' "$C_BOLD" "$C_RESET" "$question" "$C_DIM" "$C_RESET"
        return 0
    fi
    case "$default" in
        y|Y) hint="[Y/n]" ;;
        *)   hint="[y/N]" ;;
    esac
    if [ -r /dev/tty ]; then
        printf '%s?%s %s %s ' "$C_BOLD" "$C_RESET" "$question" "$hint" > /dev/tty
        IFS= read -r ans < /dev/tty || ans=""
    else
        printf '%s?%s %s %s ' "$C_BOLD" "$C_RESET" "$question" "$hint"
        IFS= read -r ans || ans=""
    fi
    [ -z "$ans" ] && ans="$default"
    case "$ans" in
        y|Y|yes|YES|Yes) return 0 ;;
        *) return 1 ;;
    esac
}

# ---------------------------------------------------------------------------
# OS + package manager detection. Sets OS (macos|linux|unknown) and
# PKG_MANAGER (brew|apt|""). Runs once at source time.
# ---------------------------------------------------------------------------
OS="unknown"
PKG_MANAGER=""
detect_os() {
    case "$(uname -s 2>/dev/null)" in
        Darwin) OS="macos" ;;
        Linux)  OS="linux" ;;
        *)      OS="unknown" ;;
    esac
    PKG_MANAGER=""
    if [ "$OS" = "macos" ] && have brew; then
        PKG_MANAGER="brew"
    elif [ "$OS" = "linux" ] && have apt-get; then
        PKG_MANAGER="apt"
    fi
}
detect_os

# ---------------------------------------------------------------------------
# pkg_install <generic-name> — maps php|node|mysql|redis|nginx to the right
# package(s) for the detected manager and installs them. ALWAYS asks first
# (ask_yn honors ASSUME_YES=1). Returns non-zero on refusal or failure.
# ---------------------------------------------------------------------------
pkg_install() {
    local name="$1" pkgs=""
    if [ "$PKG_MANAGER" = "brew" ]; then
        case "$name" in
            php)   pkgs="php" ;;
            node)  pkgs="node" ;;
            mysql) pkgs="mysql" ;;
            redis) pkgs="redis" ;;
            nginx) pkgs="nginx" ;;
            *)     pkgs="$name" ;;
        esac
        ask_yn "Install '$pkgs' with Homebrew (brew install $pkgs)?" y || return 1
        # shellcheck disable=SC2086  # pkgs is intentionally word-split
        brew install $pkgs
        return $?
    elif [ "$PKG_MANAGER" = "apt" ]; then
        case "$name" in
            php)   pkgs="php-cli php-mysql" ;;
            node)  pkgs="nodejs npm" ;;
            mysql)
                if apt-cache show mysql-server >/dev/null 2>&1; then
                    pkgs="mysql-server"
                elif apt-cache show default-mysql-server >/dev/null 2>&1; then
                    pkgs="default-mysql-server"
                else
                    pkgs="mariadb-server"
                fi
                ;;
            redis) pkgs="redis-server" ;;
            nginx) pkgs="nginx" ;;
            *)     pkgs="$name" ;;
        esac
        ask_yn "Install '$pkgs' with apt-get (needs sudo)?" y || return 1
        # shellcheck disable=SC2086  # pkgs is intentionally word-split
        sudo apt-get update -qq && sudo apt-get install -y $pkgs
        return $?
    fi
    err "No supported package manager found (need Homebrew on macOS or apt-get on Linux)."
    err "Please install '$name' manually, then re-run this script."
    return 1
}

# ---------------------------------------------------------------------------
# env_get <KEY> <default> — read a value from $ROOT/.env (last match wins),
# stripping whitespace, CR, and surrounding quotes. Echoes the default when
# the key is absent/empty or .env does not exist.
# ---------------------------------------------------------------------------
env_get() {
    local key="$1" default="${2:-}" val=""
    if [ -f "$ROOT/.env" ]; then
        val="$(grep -E "^[[:space:]]*${key}=" "$ROOT/.env" 2>/dev/null \
            | tail -n 1 | cut -d= -f2- | tr -d '\r' \
            | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
                  -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/")"
    fi
    [ -z "$val" ] && val="$default"
    printf '%s' "$val"
}

# ---------------------------------------------------------------------------
# find_nginx — echo the nginx binary path (apt puts it in /usr/sbin, which
# is usually not in a normal user's PATH). Returns 1 when not found.
# ---------------------------------------------------------------------------
find_nginx() {
    local p
    if have nginx; then
        command -v nginx
        return 0
    fi
    for p in /usr/sbin/nginx /usr/local/sbin/nginx /opt/homebrew/bin/nginx /usr/local/bin/nginx; do
        if [ -x "$p" ]; then
            printf '%s\n' "$p"
            return 0
        fi
    done
    return 1
}

# ---------------------------------------------------------------------------
# Service probes.
# ---------------------------------------------------------------------------

# redis_running — 0 when redis answers PING.
redis_running() {
    have redis-cli || return 1
    [ "$(redis-cli -h "$(env_get REDIS_HOST 127.0.0.1)" -p "$(env_get REDIS_PORT 6379)" ping 2>/dev/null)" = "PONG" ]
}

# mysql_running — PHP PDO probe (uses the app's own config + 3s timeout).
#   0 = server up and app database reachable
#   1 = server up, but the app database/user is missing (run setup.sh)
#   2 = server down / unreachable
mysql_running() {
    local rc
    have php || return 2
    php "$ROOT/scripts/dbcheck.php" >/dev/null 2>&1
    rc=$?
    case "$rc" in
        0|1|2) return "$rc" ;;
        *)     return 2 ;;
    esac
}

# ---------------------------------------------------------------------------
# Service starters. Prefer brew services (macOS) / systemctl (Linux), then
# `service`, then — for redis only — a direct daemon as last resort.
# Both poll until the service actually answers (or time out).
# ---------------------------------------------------------------------------
start_redis() {
    local i=0
    if [ "$OS" = "macos" ] && have brew; then
        brew services start redis >/dev/null 2>&1
    elif [ "$OS" = "linux" ]; then
        if have systemctl; then
            sudo systemctl start redis-server 2>/dev/null \
                || sudo systemctl start redis 2>/dev/null \
                || sudo service redis-server start 2>/dev/null \
                || sudo service redis start 2>/dev/null
        else
            sudo service redis-server start 2>/dev/null \
                || sudo service redis start 2>/dev/null
        fi
    fi
    while [ $i -lt 10 ]; do
        redis_running && return 0
        sleep 0.5
        i=$((i + 1))
    done
    # Last resort: run the daemon directly.
    if have redis-server; then
        info "Falling back to a direct daemon: redis-server --daemonize yes"
        redis-server --daemonize yes >/dev/null 2>&1
        i=0
        while [ $i -lt 6 ]; do
            redis_running && return 0
            sleep 0.5
            i=$((i + 1))
        done
    fi
    return 1
}

start_mysql() {
    local i=0
    if [ "$OS" = "macos" ] && have brew; then
        brew services start mysql >/dev/null 2>&1 \
            || brew services start mariadb >/dev/null 2>&1
    elif [ "$OS" = "linux" ]; then
        if have systemctl; then
            sudo systemctl start mysql 2>/dev/null \
                || sudo systemctl start mariadb 2>/dev/null \
                || sudo service mysql start 2>/dev/null \
                || sudo service mariadb start 2>/dev/null
        else
            sudo service mysql start 2>/dev/null \
                || sudo service mariadb start 2>/dev/null
        fi
    fi
    # MySQL can take a while to accept connections; "up" here means the
    # probe no longer reports server-down (a missing database still counts
    # as a running server).
    while [ $i -lt 40 ]; do
        mysql_running
        [ $? -ne 2 ] && return 0
        sleep 0.5
        i=$((i + 1))
    done
    return 1
}
