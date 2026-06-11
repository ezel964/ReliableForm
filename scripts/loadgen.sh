#!/usr/bin/env bash
# scripts/loadgen.sh — traffic generator + server-side latency reporter.
#
#   bash scripts/loadgen.sh [-d seconds] [-c concurrency] [-l label]
#                           [--reads-only|--submits-only] [--yes]
#
# Sends a realistic mix through the load balancer (landing page, public form,
# healthz, /api/status, real CSRF'd form submissions) from C virtual users.
# Client-side timing would dwarf the instrumentation delta we're measuring,
# so latency percentiles come from the SERVER side: the rt= field of
# storage/logs/nginx-access.log, windowed to this run by line offset.
# Each VU rotates X-Forwarded-For (10.77.<vu>.<iter%250+1>) so the per-IP
# submission rate limit spreads across 250 synthetic IPs per VU.
#
# Appends one machine-readable line per run to storage/logs/loadgen-runs.log:
#   label|date|duration|concurrency|requests|rps|2xx|3xx|4xx|5xx|p50_ms|p90_ms|p99_ms
# That file is the A/B ledger for overhead measurements (see docs/SRE-GUIDE.md).
#
# bash 3.2 compatible (no associative arrays, no mapfile). Run from any cwd.

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=common.sh
. "$ROOT/scripts/common.sh"

ACCESS_LOG="$ROOT/storage/logs/nginx-access.log"
LEDGER="$ROOT/storage/logs/loadgen-runs.log"
FORM_PATH="/f/demofeedbk"   # seeded demo form (db/seed.sql)

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------
DURATION=30
CONCURRENCY=4
LABEL=""
MODE="mix"   # mix | reads | submits

usage() {
    echo "usage: bash scripts/loadgen.sh [-d seconds] [-c concurrency] [-l label]"
    echo "                               [--reads-only|--submits-only] [--yes]"
    echo "  -d seconds       run duration (default 30)"
    echo "  -c concurrency   number of virtual users (default 4)"
    echo "  -l label         run label for the summary + ledger (default: timestamp)"
    echo "  --reads-only     GET-only mix (no submissions)"
    echo "  --submits-only   submission cycle only (form GET + POST)"
    echo "  --yes            accepted for symmetry with setup/launch (loadgen never prompts)"
}

while [ $# -gt 0 ]; do
    case "$1" in
        -d) shift; DURATION="${1:-}";;
        -c) shift; CONCURRENCY="${1:-}";;
        -l) shift; LABEL="${1:-}";;
        --reads-only)
            if [ "$MODE" = "submits" ]; then err "--reads-only and --submits-only are mutually exclusive"; exit 1; fi
            MODE="reads";;
        --submits-only)
            if [ "$MODE" = "reads" ]; then err "--reads-only and --submits-only are mutually exclusive"; exit 1; fi
            MODE="submits";;
        -y|--yes) ASSUME_YES=1; export ASSUME_YES ;;
        -h|--help) usage; exit 0 ;;
        *) err "unknown argument: $1"; usage; exit 1 ;;
    esac
    shift
done

case "$DURATION" in
    ''|*[!0-9]*) err "-d needs a positive integer (got '${DURATION}')"; exit 1 ;;
esac
case "$CONCURRENCY" in
    ''|*[!0-9]*) err "-c needs a positive integer (got '${CONCURRENCY}')"; exit 1 ;;
esac
if [ "$DURATION" -lt 1 ] || [ "$CONCURRENCY" -lt 1 ]; then
    err "duration and concurrency must be >= 1"
    exit 1
fi
[ -z "$LABEL" ] && LABEL="$(date -u +%Y%m%d-%H%M%S)"
LABEL="$(printf '%s' "$LABEL" | tr -d '|' | tr ' ' '_')"

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
if ! have curl; then
    err "curl is required for loadgen. Install it and re-run."
    exit 1
fi

LB_PORT="$(env_get LB_PORT 8080)"
BASE="http://127.0.0.1:$LB_PORT"

LB_CODE="$(curl -s -o /dev/null -m 3 -w '%{http_code}' "$BASE/healthz" 2>/dev/null)"
if [ "$LB_CODE" != "200" ]; then
    if [ "$LB_CODE" = "000" ] || [ -z "$LB_CODE" ]; then
        err "Load balancer is not answering on :$LB_PORT — is the stack up?"
    else
        err "Load balancer answered $LB_CODE on /healthz — the stack is up but degraded."
    fi
    info "Start (or repair) it with: bash launch.sh"
    exit 1
fi
ok "LB answering on :$LB_PORT (healthz 200)"

FORM_CODE="$(curl -s -o /dev/null -m 3 -w '%{http_code}' "$BASE$FORM_PATH" 2>/dev/null)"
if [ "$FORM_CODE" != "200" ] && [ "$MODE" != "reads" ]; then
    err "Demo form $FORM_PATH answered $FORM_CODE — submissions need the seeded form (bash setup.sh)."
    exit 1
fi

RATE_LIMIT="$(env_get RATE_LIMIT_SUBMIT_PER_MIN 30)"
if [ "$MODE" != "reads" ]; then
    case "$RATE_LIMIT" in
        0)
            info "RATE_LIMIT_SUBMIT_PER_MIN=0 — submission rate limit disabled, no 429s possible."
            ;;
        ''|*[!0-9]*)
            warn "RATE_LIMIT_SUBMIT_PER_MIN='$RATE_LIMIT' in .env is not a number — assuming the default (30)."
            ;;
        *)
            info "Submit rate limit: $RATE_LIMIT/min per (form, IP). Each VU rotates X-Forwarded-For"
            info "across 250 synthetic IPs (10.77.<vu>.<1-250>), so one IP repeats only every 250"
            info "iterations — normally far under the limit. A VU would need >$((250 * RATE_LIMIT)) iterations/min"
            info "to trip it; expect 429s only if you lowered the limit drastically."
            ;;
    esac
fi

# ---------------------------------------------------------------------------
# Run window start: record the nginx access-log line offset BEFORE traffic.
# ---------------------------------------------------------------------------
OFFSET=0
if [ -f "$ACCESS_LOG" ]; then
    OFFSET="$(wc -l < "$ACCESS_LOG" | tr -d '[:space:]')"
fi

TMP="$(mktemp -d "${TMPDIR:-/tmp}/loadgen.XXXXXX")" || { err "mktemp failed"; exit 1; }
VU_PIDS=""

cleanup() {
    rm -rf "$TMP"
}
on_signal() {
    warn "interrupted — stopping virtual users"
    for p in $VU_PIDS; do kill "$p" 2>/dev/null; done
    wait 2>/dev/null
    cleanup
    exit 130
}
trap on_signal INT TERM
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Virtual user
# ---------------------------------------------------------------------------
# hit RESFILE XFF URL [extra curl args...] — one request, code appended to RESFILE
hit() {
    local res="$1" xff="$2" url="$3"
    shift 3
    curl -s -o /dev/null -m 5 -H "X-Forwarded-For: $xff" \
        -w '%{http_code}\n' "$@" "$url" >> "$res" 2>/dev/null
}

# submit_cycle RESFILE SUBFILE XFF JAR BODYFILE ITER VU
# GET the public form (cookie jar = CSRF session), extract the token, POST
# plausible answers for the seeded demo fields (db/seed.sql ids).
submit_cycle() {
    local res="$1" sub="$2" xff="$3" jar="$4" body="$5" iter="$6" vu="$7"
    local code="" token="" dept="" prod="" first="" day="" rate=""

    code="$(curl -s -o "$body" -m 5 -H "X-Forwarded-For: $xff" -c "$jar" -b "$jar" \
        -w '%{http_code}' "$BASE$FORM_PATH" 2>/dev/null)"
    printf '%s\n' "${code:-000}" >> "$res"
    if [ "$code" != "200" ]; then
        printf 'ERR\n' >> "$sub"
        return 0
    fi

    token="$(grep -o 'name="_token" value="[^"]*"' "$body" 2>/dev/null \
        | head -n 1 | sed -e 's/.*value="//' -e 's/"$//')"
    if [ -z "$token" ]; then
        printf 'ERR\n' >> "$sub"
        return 0
    fi

    # Vary the answers per iteration counter (all values valid per the seeded
    # field definitions: select/radio/checkbox options, rating 1-5, real date).
    case $((iter % 4)) in
        0) dept="Sales" ;;
        1) dept="Support" ;;
        2) dept="Billing" ;;
        *) dept="Other" ;;
    esac
    case $((iter % 4)) in
        0) prod="Forms" ;;
        1) prod="PDF Reports" ;;
        2) prod="Email Alerts" ;;
        *) prod="API" ;;
    esac
    if [ $((iter % 2)) -eq 0 ]; then first="Yes"; else first="No"; fi
    day="$(printf '%02d' $((iter % 28 + 1)))"
    rate=$((iter % 5 + 1))

    code="$(curl -s -o /dev/null -m 5 -H "X-Forwarded-For: $xff" -b "$jar" -c "$jar" \
        --data-urlencode "_token=$token" \
        --data-urlencode "answers[f_name01]=Load Tester vu$vu-$iter" \
        --data-urlencode "answers[f_email1]=vu$vu.run$iter@loadgen.test" \
        --data-urlencode "answers[f_dept01]=$dept" \
        --data-urlencode "answers[f_prod01][]=$prod" \
        --data-urlencode "answers[f_first1]=$first" \
        --data-urlencode "answers[f_visit1]=2026-05-$day" \
        --data-urlencode "answers[f_count1]=$((iter % 9))" \
        --data-urlencode "answers[f_rate01]=$rate" \
        --data-urlencode "answers[f_notes1]=synthetic loadgen run $LABEL (vu $vu iter $iter)" \
        -w '%{http_code}' "$BASE$FORM_PATH" 2>/dev/null)"
    printf '%s\n' "${code:-000}" >> "$res"
    printf '%s\n' "${code:-000}" >> "$sub"
    return 0
}

vu_loop() { # $1 vu-number
    local vu="$1" iter=0 deadline=0 xff=""
    local res="$TMP/vu$vu.res" sub="$TMP/vu$vu.sub" jar="$TMP/vu$vu.cookies" body="$TMP/vu$vu.body"
    : > "$res"
    : > "$sub"
    deadline=$((SECONDS + DURATION))
    while [ "$SECONDS" -lt "$deadline" ]; do
        iter=$((iter + 1))
        xff="10.77.$vu.$((iter % 250 + 1))"
        if [ "$MODE" != "submits" ]; then
            hit "$res" "$xff" "$BASE/"
            [ "$SECONDS" -lt "$deadline" ] || break
            hit "$res" "$xff" "$BASE$FORM_PATH"
            [ "$SECONDS" -lt "$deadline" ] || break
            hit "$res" "$xff" "$BASE/healthz"
            [ "$SECONDS" -lt "$deadline" ] || break
            hit "$res" "$xff" "$BASE/api/status"
            [ "$SECONDS" -lt "$deadline" ] || break
        fi
        if [ "$MODE" != "reads" ]; then
            submit_cycle "$res" "$sub" "$xff" "$jar" "$body" "$iter" "$vu"
        fi
    done
}

# ---------------------------------------------------------------------------
# Percentiles: sort -n + line-index math (nearest-rank) in one awk pass.
# Input file: one latency (ms) per line. Echoes "p50 p90 p99 max n".
# ---------------------------------------------------------------------------
percentiles() {
    local f="$1" n=0
    n="$(wc -l < "$f" | tr -d '[:space:]')"
    if [ "$n" -eq 0 ]; then
        printf -- '- - - - 0'
        return 0
    fi
    sort -n "$f" | awk -v n="$n" '
        function rank(p) { r = int(p * n / 100); if (r * 100 < p * n) r += 1; if (r < 1) r = 1; return r }
        BEGIN { r50 = rank(50); r90 = rank(90); r99 = rank(99) }
        NR == r50 { p50 = $1 }
        NR == r90 { p90 = $1 }
        NR == r99 { p99 = $1 }
        { last = $1 }
        END { printf "%.1f %.1f %.1f %.1f %d", p50, p90, p99, last, n }'
}

# ---------------------------------------------------------------------------
# Run
# ---------------------------------------------------------------------------
MODE_DESC="mixed (4 GETs + 1 submission per iteration)"
[ "$MODE" = "reads" ] && MODE_DESC="reads-only (4 GETs per iteration)"
[ "$MODE" = "submits" ] && MODE_DESC="submits-only (form GET + POST per iteration)"

STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
info "${C_BOLD}loadgen '$LABEL'${C_RESET} — ${DURATION}s, $CONCURRENCY VU(s), $MODE_DESC"
info "target $BASE · access-log window starts after line $OFFSET"

START_EPOCH="$SECONDS"
i=1
while [ "$i" -le "$CONCURRENCY" ]; do
    vu_loop "$i" &
    VU_PIDS="$VU_PIDS $!"
    i=$((i + 1))
done
wait
ELAPSED=$((SECONDS - START_EPOCH))
[ "$ELAPSED" -lt 1 ] && ELAPSED=1

# ---------------------------------------------------------------------------
# Client-side aggregation (counts only — latency comes from the server side)
# ---------------------------------------------------------------------------
# shellcheck disable=SC2046  # word-splitting the awk output is intentional
set -- $(cat "$TMP"/vu*.res /dev/null 2>/dev/null | awk '
    { t++; k = substr($0, 1, 1)
      if (k == "2") c2++; else if (k == "3") c3++
      else if (k == "4") c4++; else if (k == "5") c5++
      else c0++ }
    END { printf "%d %d %d %d %d %d", t + 0, c2 + 0, c3 + 0, c4 + 0, c5 + 0, c0 + 0 }')
TOTAL="${1:-0}"; C2XX="${2:-0}"; C3XX="${3:-0}"; C4XX="${4:-0}"; C5XX="${5:-0}"; C000="${6:-0}"
RPS="$(awk -v t="$TOTAL" -v e="$ELAPSED" 'BEGIN { printf "%.1f", t / e }')"

SUB_ATTEMPTED=0
SUB_ACCEPTED=0
if [ "$MODE" != "reads" ]; then
    SUB_ATTEMPTED="$(cat "$TMP"/vu*.sub /dev/null 2>/dev/null | wc -l | tr -d '[:space:]')"
    SUB_ACCEPTED="$(cat "$TMP"/vu*.sub /dev/null 2>/dev/null | grep -c '^302')"
fi

# ---------------------------------------------------------------------------
# Server-side latency from the nginx access log window (rt= seconds → ms).
# Log line: ... "$request" $status ... rt=<sec> upstream=... urt=... rid=...
# ---------------------------------------------------------------------------
WINDOW_LINES=0
if [ -f "$ACCESS_LOG" ]; then
    tail -n +"$((OFFSET + 1))" "$ACCESS_LOG" | awk -F'"' '
        NF >= 3 {
            split($2, req, " "); m = req[1]
            n = split($3, f, " ")
            for (i = 1; i <= n; i++)
                if (f[i] ~ /^rt=[0-9.]+$/) {
                    sub(/^rt=/, "", f[i])
                    printf "%s %.3f\n", m, f[i] * 1000
                    break
                }
        }' > "$TMP/rt.all"
    awk '$1 == "GET"  { print $2 }' "$TMP/rt.all" > "$TMP/rt.get"
    awk '$1 == "POST" { print $2 }' "$TMP/rt.all" > "$TMP/rt.post"
    awk '{ print $2 }' "$TMP/rt.all" > "$TMP/rt.ms"
    WINDOW_LINES="$(wc -l < "$TMP/rt.ms" | tr -d '[:space:]')"
else
    : > "$TMP/rt.ms"; : > "$TMP/rt.get"; : > "$TMP/rt.post"
    warn "no $ACCESS_LOG — server-side percentiles unavailable"
fi

# shellcheck disable=SC2046
set -- $(percentiles "$TMP/rt.ms");   P50="$1"; P90="$2"; P99="$3"; PMAX="$4"; PN="$5"
# shellcheck disable=SC2046
set -- $(percentiles "$TMP/rt.get");  G50="$1"; G90="$2"; G99="$3"; GMAX="$4"; GN="$5"
# shellcheck disable=SC2046
set -- $(percentiles "$TMP/rt.post"); S50="$1"; S90="$2"; S99="$3"; SMAX="$4"; SN="$5"

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo ""
printf '%s── loadgen summary · %s ──%s\n' "$C_BOLD" "$LABEL" "$C_RESET"
printf '  started      %s (UTC) · %ss elapsed · %s VU(s) · %s\n' "$STARTED_AT" "$ELAPSED" "$CONCURRENCY" "$MODE_DESC"
printf '  requests     %s total · %s req/s\n' "$TOTAL" "$RPS"
printf '  by status    2xx %s · 3xx %s · 4xx %s · 5xx %s · failed(000) %s\n' "$C2XX" "$C3XX" "$C4XX" "$C5XX" "$C000"
if [ "$MODE" != "reads" ]; then
    printf '  submissions  %s attempted · %s accepted (302 → thanks page)\n' "$SUB_ATTEMPTED" "$SUB_ACCEPTED"
fi
printf '  server-side latency — nginx rt= over %s window line(s):\n' "$WINDOW_LINES"
printf '    %-7s p50 %sms · p90 %sms · p99 %sms · max %sms (n=%s)\n' "overall" "$P50" "$P90" "$P99" "$PMAX" "$PN"
printf '    %-7s p50 %sms · p90 %sms · p99 %sms · max %sms (n=%s)\n' "GET" "$G50" "$G90" "$G99" "$GMAX" "$GN"
printf '    %-7s p50 %sms · p90 %sms · p99 %sms · max %sms (n=%s)\n' "POST" "$S50" "$S90" "$S99" "$SMAX" "$SN"
if [ "$C5XX" -gt 0 ]; then
    warn "$C5XX server error(s) — check storage/logs/php-error.log and storage/logs/nginx-error.log"
fi

mkdir -p "$ROOT/storage/logs"
printf '%s|%s|%s|%s|%s|%s|%s|%s|%s|%s|%s|%s|%s\n' \
    "$LABEL" "$STARTED_AT" "$DURATION" "$CONCURRENCY" "$TOTAL" "$RPS" \
    "$C2XX" "$C3XX" "$C4XX" "$C5XX" "$P50" "$P90" "$P99" >> "$LEDGER"
info "ledger line appended → storage/logs/loadgen-runs.log"

exit 0
