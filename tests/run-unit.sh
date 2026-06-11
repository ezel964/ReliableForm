#!/usr/bin/env bash
# tests/run-unit.sh — run every tests/unit/test_*.php under INSTANCE_ID=unittest.
# No MySQL needed; Redis-dependent cases skip themselves when PING fails.
# Exit 1 on any failing test. bash 3.2 compatible.

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/scripts/common.sh"

if ! have php; then
    err "php not found — the unit suite is plain PHP."
    exit 1
fi

files_run=0
files_failed=0

for f in "$ROOT"/tests/unit/test_*.php; do
    [ -f "$f" ] || continue
    printf '%s%s%s\n' "$C_BOLD" "$(basename "$f")" "$C_RESET"
    if INSTANCE_ID=unittest php "$f"; then
        :
    else
        files_failed=$((files_failed + 1))
    fi
    files_run=$((files_run + 1))
done

echo ""
if [ "$files_run" -eq 0 ]; then
    err "no test files found under tests/unit/"
    exit 1
fi
if [ "$files_failed" -gt 0 ]; then
    err "unit suite: $files_failed of $files_run test file(s) had failures"
    exit 1
fi
ok "unit suite: all $files_run test file(s) green"
exit 0
