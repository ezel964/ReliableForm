#!/usr/bin/env bash
# scripts/podman.sh — Podman helpers for the backing-service tier.
#
# Sourced by scripts/common.sh (so both setup.sh and launch.sh get it). Docker
# is forbidden in this project; the stateful infra (MySQL, Redis, gearmand)
# runs in Podman, defined once in config/podman/compose.yaml and driven from
# here through `podman compose`.
#
# bash 3.2 compatible: no associative arrays, no mapfile, no ${var,,}.
# Callers run `set -u`, so unset expansions use ${var:-}.
#
# Depends on helpers already defined in common.sh (have, info, warn, err,
# ask_yn) and on $ROOT — common.sh sources this file last, after defining them.

PODMAN_COMPOSE_FILE="$ROOT/config/podman/compose.yaml"
PODMAN_FULL_COMPOSE_FILE="$ROOT/config/podman/compose.full.yaml"

# Cache for the resolved compose command (set on first compose_cmd call).
PODMAN_COMPOSE_CMD=""

# ---------------------------------------------------------------------------
# podman_warn_box — the loud red box shown when Podman is expected but absent.
# ---------------------------------------------------------------------------
podman_warn_box() {
    printf '%s\n' "${C_RED}${C_BOLD}"
    printf '  ┌────────────────────────────────────────────────────────┐\n'
    printf '  │  PODMAN NOT FOUND                                       │\n'
    printf '  │                                                        │\n'
    printf '  │  Docker is forbidden in this project. The backing      │\n'
    printf '  │  services (MySQL, Redis, gearmand) run in Podman.      │\n'
    printf '  │                                                        │\n'
    printf '  │  Install it:                                           │\n'
    printf '  │    macOS:  brew install podman                         │\n'
    printf '  │            podman machine init && podman machine start │\n'
    printf '  │    Linux:  sudo apt-get install podman                 │\n'
    printf '  └────────────────────────────────────────────────────────┘\n'
    printf '%s' "${C_RESET}"
}

# ---------------------------------------------------------------------------
# podman_present — is the podman binary on PATH at all?
# ---------------------------------------------------------------------------
podman_present() { have podman; }

# ---------------------------------------------------------------------------
# podman_usable — can podman actually talk to its backend right now?
# `podman info` succeeds on native Linux, and on macOS only when a podman
# machine VM is running — so it doubles as the machine readiness check.
# ---------------------------------------------------------------------------
podman_usable() {
    podman_present || return 1
    podman info >/dev/null 2>&1
}

# ---------------------------------------------------------------------------
# podman_ensure_machine — macOS only. When the binary is present but the
# machine VM is down, offer to start it (init first if none exists). Returns 0
# when podman ends up usable, 1 otherwise. No-op on Linux.
# ---------------------------------------------------------------------------
podman_ensure_machine() {
    podman_present || return 1
    podman_usable && return 0
    [ "$OS" = "macos" ] || return 1
    if ! podman machine list --format '{{.Name}}' 2>/dev/null | grep -q .; then
        warn "No Podman machine exists (macOS runs containers inside a VM)."
        if ask_yn "Create one now (podman machine init)?" y; then
            podman machine init >/dev/null 2>&1 || { err "podman machine init failed."; return 1; }
        else
            return 1
        fi
    fi
    if ! podman_usable; then
        if ask_yn "Start the Podman machine now (podman machine start)?" y; then
            podman machine start >/dev/null 2>&1 || { err "podman machine start failed."; return 1; }
        else
            return 1
        fi
    fi
    podman_usable
}

# ---------------------------------------------------------------------------
# podman_available — the gate used by setup.sh/launch.sh: binary present AND
# usable, transparently bringing up the macOS machine if needed.
# ---------------------------------------------------------------------------
podman_available() {
    podman_present || return 1
    podman_usable && return 0
    podman_ensure_machine
}

# ---------------------------------------------------------------------------
# compose_cmd — echo the compose invocation prefix, preferring the built-in
# `podman compose` over the standalone `podman-compose`. Cached after the
# first resolution. Returns 1 when neither is available.
# ---------------------------------------------------------------------------
compose_cmd() {
    if [ -n "$PODMAN_COMPOSE_CMD" ]; then
        printf '%s' "$PODMAN_COMPOSE_CMD"
        return 0
    fi
    if podman compose version >/dev/null 2>&1; then
        PODMAN_COMPOSE_CMD="podman compose"
    elif have podman-compose; then
        PODMAN_COMPOSE_CMD="podman-compose"
    else
        return 1
    fi
    printf '%s' "$PODMAN_COMPOSE_CMD"
    return 0
}

# ---------------------------------------------------------------------------
# infra_compose <compose-file> <args...> — run a compose subcommand against the
# given file, passing .env as the env-file when present so ${VAR} in the YAML
# resolves to the same values the app reads.
# ---------------------------------------------------------------------------
infra_compose() {
    local file="$1"; shift
    local cc=""
    cc="$(compose_cmd)" || { err "No 'podman compose' or 'podman-compose' available."; return 1; }
    if [ -f "$ROOT/.env" ]; then
        # shellcheck disable=SC2086  # cc is an intentionally word-split prefix
        $cc -f "$file" --env-file "$ROOT/.env" "$@"
    else
        # shellcheck disable=SC2086
        $cc -f "$file" "$@"
    fi
}

# ---------------------------------------------------------------------------
# infra_up — start (and build gearmand if needed) the backing-service tier.
# ---------------------------------------------------------------------------
infra_up() {
    infra_compose "$PODMAN_COMPOSE_FILE" up -d "$@"
}

# infra_down — stop and remove the backing-service containers.
infra_down() {
    infra_compose "$PODMAN_COMPOSE_FILE" down "$@"
}

# infra_ps — list the backing-service containers.
infra_ps() {
    infra_compose "$PODMAN_COMPOSE_FILE" ps "$@"
}

# ---------------------------------------------------------------------------
# Container-level probes (by the fixed container_name set in compose.yaml).
# ---------------------------------------------------------------------------
infra_container_name() { # $1 service → container name
    case "$1" in
        mysql)    printf 'rf-mysql' ;;
        redis)    printf 'rf-redis' ;;
        gearmand) printf 'rf-gearmand' ;;
        *)        printf 'rf-%s' "$1" ;;
    esac
}

# infra_container_running <service> — 0 when the container exists and runs.
infra_container_running() {
    local c
    c="$(infra_container_name "$1")"
    [ "$(podman container inspect -f '{{.State.Running}}' "$c" 2>/dev/null)" = "true" ]
}

# infra_container_healthy <service> — 0 when healthy (or running with no
# healthcheck defined). Distinct from "running" so callers can wait for the
# service to actually accept connections.
infra_container_healthy() {
    local c h
    c="$(infra_container_name "$1")"
    h="$(podman container inspect -f '{{.State.Health.Status}}' "$c" 2>/dev/null)"
    case "$h" in
        healthy) return 0 ;;
        ""|"<no value>") infra_container_running "$1" ;;  # no healthcheck → running is enough
        *) return 1 ;;
    esac
}

# infra_wait_healthy <service> <budget-seconds> — poll until healthy or budget.
infra_wait_healthy() {
    local svc="$1" budget="$2" start="$SECONDS"
    while :; do
        infra_container_healthy "$svc" && return 0
        if [ $((SECONDS - start)) -ge "$budget" ]; then
            return 1
        fi
        sleep 1
    done
}
