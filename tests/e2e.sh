#!/usr/bin/env bash
# tests/e2e.sh — end-to-end regression gate against a RUNNING ReliableForm stack.
#
#   bash tests/e2e.sh [--full] [--keep]
#
#   --full  adds the API rate-limit check (130x /v1/me) which locks the demo
#           key for ~60s — OFF by default
#   --keep  skip data/temp cleanup for debugging (the sink process is still
#           killed; everything else is left in place and the paths printed)
#
# Output is TAP-ish: `ok N - desc` / `not ok N - desc`, summary at the end.
# Exit 0 all green, 1 on any failed check, 2 when preflight fails.
# bash 3.2 compatible (no mapfile, no associative arrays).

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/scripts/common.sh"

FULL=0
KEEP=0
for arg in "$@"; do
    case "$arg" in
        --full) FULL=1 ;;
        --keep) KEEP=1 ;;
        *) err "unknown flag: $arg"; echo "usage: bash tests/e2e.sh [--full] [--keep]"; exit 2 ;;
    esac
done

LB_PORT="$(env_get LB_PORT 8080)"
WEB1_PORT="$(env_get WEB1_PORT 9001)"
DB_HOST="$(env_get DB_HOST 127.0.0.1)"
DB_PORT="$(env_get DB_PORT 3306)"
DB_NAME="$(env_get DB_NAME reliableform)"
DB_USER="$(env_get DB_USER reliableform)"
DB_PASS="$(env_get DB_PASS reliableform)"
REDIS_HOST="$(env_get REDIS_HOST 127.0.0.1)"
REDIS_PORT="$(env_get REDIS_PORT 6379)"

LB="http://localhost:$LB_PORT"
DEMO_KEY="deadbeefdeadbeefdeadbeefdeadbeefdeadbeef"
SINK_PORT=9444

TMP="$(mktemp -d "${TMPDIR:-/tmp}/rf-e2e-XXXXXX")"
JAR="$TMP/jar.txt"            # throwaway user's session cookies
PJAR="$TMP/public-jar.txt"    # public visitor's session cookies
HDRS="$TMP/headers.txt"
SINK_LOG="$TMP/sink.log"
SINK_PID=""

T=0
FAILS=0
pass() { T=$((T + 1)); printf '%sok%s %d - %s\n' "$C_GREEN" "$C_RESET" "$T" "$1"; }
fail() { T=$((T + 1)); FAILS=$((FAILS + 1)); printf '%snot ok%s %d - %s\n' "$C_RED" "$C_RESET" "$T" "$1"; }
note() { printf '%s# %s%s\n' "$C_DIM" "$*" "$C_RESET"; }
stage() { echo ""; info "${C_BOLD}$*${C_RESET}"; }

mysql_q() { MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -N -B -e "$1" "$DB_NAME" 2>/dev/null; }
redis_q() { redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" "$@" 2>/dev/null; }

# CSRF token from a rendered page (session persisted into the given jar).
csrf_from() { # $1 jar, $2 url
    curl -s -b "$1" -c "$1" "$2" | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -n 1
}

# poll <budget-seconds> <fn> [args...] — re-run fn until true or budget spent.
poll() {
    local budget="$1" start="$SECONDS"
    shift
    while :; do
        if "$@"; then return 0; fi
        [ $((SECONDS - start)) -ge "$budget" ] && return 1
        sleep 0.5
    done
}

cleanup_on_exit() {
    if [ -n "$SINK_PID" ]; then
        kill "$SINK_PID" 2>/dev/null
        wait "$SINK_PID" 2>/dev/null
    fi
    if [ "$KEEP" = "1" ]; then
        note "--keep: temp dir preserved: $TMP (sink log: $SINK_LOG)"
    else
        rm -rf "$TMP"
        rm -f /tmp/rf-sink-*.count
    fi
}
trap cleanup_on_exit EXIT

# ---------------------------------------------------------------------------
# Stage 0 — preflight (exit 2 with guidance on any miss)
# ---------------------------------------------------------------------------
stage "stage 0 — preflight"

preflight_fail() {
    err "$1"
    err "preflight failed — bring the stack up first: bash launch.sh"
    exit 2
}

[ -f "$ROOT/.env" ] || preflight_fail ".env missing — run: bash setup.sh"
for tool in curl mysql redis-cli php; do
    have "$tool" || preflight_fail "required tool '$tool' not found in PATH"
done
pass "preflight: .env + curl/mysql/redis-cli/php present"

curl -s -m 5 "$LB/api/status" | grep -q '^{"ok":true' \
    || preflight_fail "GET $LB/api/status is not ok:true (stack down or degraded?)"
pass "preflight: $LB/api/status reports ok:true"

curl -s -m 5 -H "X-Api-Key: $DEMO_KEY" "$LB/v1/me" | grep -q '"ok":true' \
    || preflight_fail "demo API key rejected on /v1/me (seed missing? rate-limited from a recent --full run?)"
pass "preflight: demo API key answers /v1/me"

[ "$(mysql_q 'SELECT 1')" = "1" ] || preflight_fail "mysql CLI cannot reach $DB_NAME at $DB_HOST:$DB_PORT with .env credentials"
[ "$(redis_q PING)" = "PONG" ] || preflight_fail "redis-cli cannot reach $REDIS_HOST:$REDIS_PORT"
pass "preflight: mysql CLI + redis-cli reach the stack's backends"

DEAD_BEFORE="$(redis_q LLEN queue:dead)"
note "queue:dead depth before run: $DEAD_BEFORE"

# ---------------------------------------------------------------------------
# Stage 1 — web auth journey (register → logout → login)
# ---------------------------------------------------------------------------
stage "stage 1 — web auth journey"

EPOCH="$(date +%s)"
EMAIL="e2e+$EPOCH@test.local"
PASSWORD="e2e-secret-123"

TOKEN="$(csrf_from "$JAR" "$LB/register")"
RES="$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' \
    --data-urlencode "name=E2E Throwaway" \
    --data-urlencode "email=$EMAIL" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "_token=$TOKEN" \
    "$LB/register")"
if [ "$RES" = "302 $LB/dashboard" ]; then
    pass "register $EMAIL → 302 dashboard"
else
    fail "register $EMAIL → 302 dashboard (got: $RES)"
fi

TOKEN="$(csrf_from "$JAR" "$LB/dashboard")"
RES="$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' \
    --data-urlencode "_token=$TOKEN" "$LB/logout")"
if [ "$RES" = "302 $LB/" ]; then
    pass "logout → 302 /"
else
    fail "logout → 302 / (got: $RES)"
fi

TOKEN="$(csrf_from "$JAR" "$LB/login")"
RES="$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' \
    --data-urlencode "email=$EMAIL" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "_token=$TOKEN" \
    "$LB/login")"
if [ "$RES" = "302 $LB/dashboard" ]; then
    pass "login again → 302 dashboard"
else
    fail "login again → 302 dashboard (got: $RES)"
fi

# ---------------------------------------------------------------------------
# Stage 2 — builder: create the throwaway form (+ start the webhook sink)
# ---------------------------------------------------------------------------
stage "stage 2 — builder + webhook sink"

if curl -s -m 1 -o /dev/null "http://127.0.0.1:$SINK_PORT/"; then
    err "port $SINK_PORT is already in use — stop whatever runs there (old sink?) and re-run"
    exit 2
fi
php -S "127.0.0.1:$SINK_PORT" "$ROOT/tools/webhook-sink.php" >"$SINK_LOG" 2>&1 &
SINK_PID=$!
sink_ready() { curl -s -m 1 -o /dev/null "http://127.0.0.1:$SINK_PORT/"; }
if poll 5 sink_ready; then
    pass "webhook sink started on :$SINK_PORT (pid $SINK_PID, log $SINK_LOG)"
else
    fail "webhook sink failed to start on :$SINK_PORT"
fi

FIELDS_JSON='[
 {"id":"f_text01","type":"text","label":"Your name","required":true},
 {"id":"f_email1","type":"email","label":"Email","required":false},
 {"id":"f_sel001","type":"select","label":"Team","required":false,"options":["Alpha","Beta"]},
 {"id":"f_rate01","type":"rating","label":"Rating","required":false,"max":5},
 {"id":"f_file01","type":"file","label":"Attachment","required":false}
]'
TOKEN="$(csrf_from "$JAR" "$LB/forms/new")"
RES="$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' \
    --data-urlencode "title=E2E $EPOCH" \
    --data-urlencode "description=throwaway e2e form" \
    --data-urlencode "webhook_url=http://127.0.0.1:$SINK_PORT/hook" \
    --data-urlencode "fields_json=$FIELDS_JSON" \
    --data-urlencode "_token=$TOKEN" \
    "$LB/forms")"
if [ "$RES" = "302 $LB/dashboard" ]; then
    pass "POST /forms (builder payload + webhook_url) → 302 dashboard"
else
    fail "POST /forms → 302 dashboard (got: $RES)"
fi

if curl -s -b "$JAR" "$LB/dashboard" | grep -q "E2E $EPOCH"; then
    pass "form \"E2E $EPOCH\" appears on the dashboard"
else
    fail "form \"E2E $EPOCH\" appears on the dashboard"
fi

ROW="$(mysql_q "SELECT id, public_id FROM forms WHERE title='E2E $EPOCH' LIMIT 1")"
FORM_ID="$(printf '%s' "$ROW" | cut -f1)"
PUBLIC_ID="$(printf '%s' "$ROW" | cut -f2)"
if [ -n "$FORM_ID" ] && [ -n "$PUBLIC_ID" ]; then
    pass "captured form id=$FORM_ID public_id=$PUBLIC_ID"
else
    fail "could not capture the new form's id/public_id from MySQL"
    err "cannot continue without the form — aborting remaining stages"
    exit 1
fi

# ---------------------------------------------------------------------------
# Stage 3 — public submit #1 (happy path: upload + trace propagation)
# ---------------------------------------------------------------------------
stage "stage 3 — public submit #1 (happy path)"

TRACE1="$(od -An -tx1 -N16 /dev/urandom | tr -d ' \n')"
SPAN1="$(od -An -tx1 -N8 /dev/urandom | tr -d ' \n')"
echo "hello from the e2e suite" > "$TMP/upload.txt"

PTOKEN="$(csrf_from "$PJAR" "$LB/f/$PUBLIC_ID")"
RES="$(curl -s -b "$PJAR" -c "$PJAR" -o /dev/null -w '%{http_code} %{redirect_url}' \
    -H "traceparent: 00-$TRACE1-$SPAN1-01" \
    -F "_token=$PTOKEN" \
    -F "answers[f_text01]=Alice E2E" \
    -F "answers[f_email1]=alice@e2e.test" \
    -F "answers[f_sel001]=Alpha" \
    -F "answers[f_rate01]=4" \
    -F "files[f_file01]=@$TMP/upload.txt;type=text/plain" \
    "$LB/f/$PUBLIC_ID")"
if [ "$RES" = "302 $LB/f/$PUBLIC_ID/thanks" ]; then
    pass "multipart submit (txt upload, traceparent $TRACE1) → 302 thanks"
else
    fail "multipart submit → 302 thanks (got: $RES)"
fi

SUB1="$(mysql_q "SELECT MAX(id) FROM submissions WHERE form_id=$FORM_ID")"
[ -n "$SUB1" ] && [ "$SUB1" != "NULL" ] || SUB1=0

pdf_done() { [ "$(mysql_q "SELECT status FROM pdf_jobs WHERE submission_id=$1")" = "done" ]; }
email_sent() { [ "$(mysql_q "SELECT status FROM emails WHERE submission_id=$1")" = "sent" ]; }
delivered_with() { [ "$(mysql_q "SELECT CONCAT(status,' ',attempts) FROM webhook_deliveries WHERE submission_id=$1")" = "delivered $2" ]; }

if poll 15 pdf_done "$SUB1" && [ -f "$ROOT/storage/pdfs/submission-$SUB1.pdf" ]; then
    pass "pdf_jobs done + storage/pdfs/submission-$SUB1.pdf exists"
else
    fail "pdf_jobs done + pdf file exists (status: $(mysql_q "SELECT status FROM pdf_jobs WHERE submission_id=$SUB1"))"
fi

if poll 15 email_sent "$SUB1" && [ -f "$ROOT/storage/mail/submission-$SUB1.eml" ]; then
    pass "emails sent + storage/mail/submission-$SUB1.eml exists"
else
    fail "emails sent + eml file exists (status: $(mysql_q "SELECT status FROM emails WHERE submission_id=$SUB1"))"
fi

if poll 15 delivered_with "$SUB1" 1; then
    pass "webhook delivered with attempts=1"
else
    fail "webhook delivered with attempts=1 (got: $(mysql_q "SELECT CONCAT(status,' ',attempts) FROM webhook_deliveries WHERE submission_id=$SUB1"))"
fi

sink_has_trace() { grep -q "traceparent=00-$TRACE1-" "$SINK_LOG" 2>/dev/null; }
if poll 5 sink_has_trace; then
    pass "TRACE PROPAGATION: sink log carries trace id $TRACE1"
else
    fail "TRACE PROPAGATION: sink log carries trace id $TRACE1"
fi

if [ -d "$ROOT/storage/uploads/$PUBLIC_ID" ] && [ -n "$(ls "$ROOT/storage/uploads/$PUBLIC_ID" 2>/dev/null)" ]; then
    pass "uploaded file stored under storage/uploads/$PUBLIC_ID/"
else
    fail "uploaded file stored under storage/uploads/$PUBLIC_ID/"
fi

if mysql_q "SELECT data FROM submissions WHERE id=$SUB1" | grep -q "storage/uploads/$PUBLIC_ID/"; then
    pass "submission data references the stored upload path"
else
    fail "submission data references the stored upload path"
fi

# ---------------------------------------------------------------------------
# Stage 4 — webhook retry determinism (fail_first=1 ⇒ attempts=2)
# ---------------------------------------------------------------------------
stage "stage 4 — webhook retry determinism"

# Direct mysql UPDATE + cache-bust (DEL cache:form:<public_id>) — chosen over
# a builder POST so the retry setup can't be polluted by UI validation.
mysql_q "UPDATE forms SET webhook_url='http://127.0.0.1:$SINK_PORT/hook?fail_first=1' WHERE id=$FORM_ID"
redis_q DEL "cache:form:$PUBLIC_ID" >/dev/null
if [ "$(mysql_q "SELECT webhook_url FROM forms WHERE id=$FORM_ID")" = "http://127.0.0.1:$SINK_PORT/hook?fail_first=1" ]; then
    pass "webhook_url → ?fail_first=1 (mysql UPDATE + redis DEL cache:form:$PUBLIC_ID)"
else
    fail "webhook_url update did not stick"
fi

PTOKEN="$(csrf_from "$PJAR" "$LB/f/$PUBLIC_ID")"
RES="$(curl -s -b "$PJAR" -c "$PJAR" -o /dev/null -w '%{http_code} %{redirect_url}' \
    --data-urlencode "_token=$PTOKEN" \
    --data-urlencode "answers[f_text01]=Bob Retry" \
    --data-urlencode "answers[f_rate01]=5" \
    "$LB/f/$PUBLIC_ID")"
if [ "$RES" = "302 $LB/f/$PUBLIC_ID/thanks" ]; then
    pass "submit #2 (no file) → 302 thanks"
else
    fail "submit #2 → 302 thanks (got: $RES)"
fi

SUB2="$(mysql_q "SELECT MAX(id) FROM submissions WHERE form_id=$FORM_ID")"
if poll 25 delivered_with "$SUB2" 2; then
    pass "webhook delivered with attempts=2 (retry after a deterministic 500)"
else
    fail "webhook delivered with attempts=2 (got: $(mysql_q "SELECT CONCAT(status,' ',attempts) FROM webhook_deliveries WHERE submission_id=$SUB2"))"
fi
if grep -q "submission=$SUB2 .*served=500" "$SINK_LOG" 2>/dev/null; then
    pass "sink log shows the first attempt was served 500"
else
    fail "sink log shows the first attempt was served 500"
fi

# ---------------------------------------------------------------------------
# Stage 5 — validation negatives (no rows created)
# ---------------------------------------------------------------------------
stage "stage 5 — validation negatives"

CNT_BEFORE="$(mysql_q "SELECT COUNT(*) FROM submissions WHERE form_id=$FORM_ID")"

CODE="$(curl -s -b "$PJAR" -c "$PJAR" -o "$TMP/neg1.html" -w '%{http_code}' \
    --data-urlencode "_token=$PTOKEN" \
    --data-urlencode "answers[f_rate01]=2" \
    "$LB/f/$PUBLIC_ID")"
if [ "$CODE" = "200" ] && grep -q 'This field is required.' "$TMP/neg1.html"; then
    pass "missing required text → 200 re-render with error string"
else
    fail "missing required text → 200 re-render with error string (code $CODE)"
fi

dd if=/dev/zero of="$TMP/big.txt" bs=1024 count=3072 2>/dev/null
# Runtime-aware: php -S answers plain HTTP on :$WEB1_PORT, php-fpm speaks
# FastCGI only. launch.sh records the resolved mode in storage/run/web-runtime;
# fall back to probing the port when the marker is missing.
WEB_MODE="$(cat "$ROOT/storage/run/web-runtime" 2>/dev/null)"
if [ "$WEB_MODE" != "cli" ] && [ "$WEB_MODE" != "fpm" ]; then
    if curl -s -m 2 -o /dev/null "http://127.0.0.1:$WEB1_PORT/healthz"; then WEB_MODE=cli; else WEB_MODE=fpm; fi
fi
if [ "$WEB_MODE" = "fpm" ]; then
    # No direct HTTP path to one instance under fpm — send the oversized POST
    # through the LB and expect nginx's client_max_body_size (2m) to 413 it.
    CODE="$(curl -s -b "$PJAR" -c "$PJAR" -o "$TMP/neg2.html" -w '%{http_code}' \
        -F "_token=$PTOKEN" \
        -F "answers[f_text01]=Big Upload" \
        -F "files[f_file01]=@$TMP/big.txt;type=text/plain" \
        "$LB/f/$PUBLIC_ID")"
    if [ "$CODE" = "413" ]; then
        pass "3MB upload via the LB (fpm mode) → nginx 413"
    else
        fail "3MB upload via the LB (fpm mode) → nginx 413 (code $CODE)"
    fi
else
    # Own jar bound to 127.0.0.1: the LB jar's cookies are scoped to "localhost"
    # and would not be sent to 127.0.0.1:9001 (CSRF would 419).
    BJAR="$TMP/big-jar.txt"
    BTOKEN="$(csrf_from "$BJAR" "http://127.0.0.1:$WEB1_PORT/f/$PUBLIC_ID")"
    CODE="$(curl -s -b "$BJAR" -c "$BJAR" -o "$TMP/neg2.html" -w '%{http_code}' \
        -F "_token=$BTOKEN" \
        -F "answers[f_text01]=Big Upload" \
        -F "files[f_file01]=@$TMP/big.txt;type=text/plain" \
        "http://127.0.0.1:$WEB1_PORT/f/$PUBLIC_ID")"
    if [ "$CODE" = "200" ] && grep -q 'File too large (2 MB max).' "$TMP/neg2.html"; then
        pass "3MB upload direct to :$WEB1_PORT → friendly too-large error"
    else
        fail "3MB upload direct to :$WEB1_PORT → friendly too-large error (code $CODE)"
    fi
fi

CNT_AFTER="$(mysql_q "SELECT COUNT(*) FROM submissions WHERE form_id=$FORM_ID")"
if [ "$CNT_BEFORE" = "$CNT_AFTER" ]; then
    pass "no submission rows created by invalid submits ($CNT_AFTER unchanged)"
else
    fail "no submission rows created by invalid submits (before $CNT_BEFORE, after $CNT_AFTER)"
fi

# ---------------------------------------------------------------------------
# Stage 6 — REST API v1 (demo key, demofeedbk)
# ---------------------------------------------------------------------------
stage "stage 6 — REST API v1"

no_cookie() { ! grep -qi '^set-cookie' "$HDRS"; }

CODE="$(curl -s -D "$HDRS" -o "$TMP/api.json" -w '%{http_code}' -H "X-Api-Key: $DEMO_KEY" "$LB/v1/me")"
if [ "$CODE" = "200" ] && grep -q '"email":"demo@reliableform.dev"' "$TMP/api.json" && no_cookie; then
    pass "GET /v1/me → 200, demo user, no Set-Cookie"
else
    fail "GET /v1/me → 200, demo user, no Set-Cookie (code $CODE)"
fi

CODE="$(curl -s -D "$HDRS" -o "$TMP/api.json" -w '%{http_code}' -H "Authorization: Bearer $DEMO_KEY" "$LB/v1/forms")"
if [ "$CODE" = "200" ] && grep -q '"public_id":"demofeedbk"' "$TMP/api.json" && no_cookie; then
    pass "GET /v1/forms → 200, contains demofeedbk, no Set-Cookie"
else
    fail "GET /v1/forms → 200, contains demofeedbk, no Set-Cookie (code $CODE)"
fi

CODE="$(curl -s -D "$HDRS" -o "$TMP/api.json" -w '%{http_code}' -X POST \
    -H "X-Api-Key: $DEMO_KEY" -H 'Content-Type: application/json' \
    -d '{"answers":{"f_name01":"E2E Bot","f_email1":"bot@e2e.test","f_dept01":"Support","f_first1":"No","f_rate01":"5"}}' \
    "$LB/v1/forms/demofeedbk/submissions")"
API_SUB="$(grep -o '"id":[0-9]*' "$TMP/api.json" | head -n 1 | cut -d: -f2)"
if [ "$CODE" = "201" ] && grep -q '"ok":true' "$TMP/api.json" && [ -n "$API_SUB" ] && no_cookie; then
    pass "POST /v1/.../submissions valid → 201 (submission $API_SUB), no Set-Cookie"
else
    fail "POST /v1/.../submissions valid → 201 (code $CODE, body $(head -c 120 "$TMP/api.json"))"
fi

if [ -n "$API_SUB" ] && poll 15 pdf_done "$API_SUB"; then
    pass "API submission's pdf job reaches done"
else
    fail "API submission's pdf job reaches done"
fi

CODE="$(curl -s -D "$HDRS" -o "$TMP/api.json" -w '%{http_code}' -X POST \
    -H "X-Api-Key: $DEMO_KEY" -H 'Content-Type: application/json' \
    -d '{"answers":{"f_email1":"not-an-email"}}' \
    "$LB/v1/forms/demofeedbk/submissions")"
if [ "$CODE" = "422" ] && grep -q '"code":"validation_failed"' "$TMP/api.json" \
    && grep -q '"fields":{' "$TMP/api.json" && grep -q '"f_email1"' "$TMP/api.json" && no_cookie; then
    pass "POST invalid → 422 validation_failed with fields map, no Set-Cookie"
else
    fail "POST invalid → 422 validation_failed with fields map (code $CODE)"
fi

CODE="$(curl -s -D "$HDRS" -o "$TMP/api.json" -w '%{http_code}' \
    -H "X-Api-Key: 0000000000000000000000000000000000000000" "$LB/v1/me")"
if [ "$CODE" = "401" ] && grep -q '"code":"unauthorized"' "$TMP/api.json" && no_cookie; then
    pass "bogus key → 401 unauthorized, no Set-Cookie"
else
    fail "bogus key → 401 unauthorized, no Set-Cookie (code $CODE)"
fi

if [ "$FULL" = "1" ]; then
    note "--full: hammering /v1/me 130x (locks the demo key ~60s)"
    HITS=0
    RL=0
    while [ "$HITS" -lt 130 ]; do
        C="$(curl -s -m 5 -o /dev/null -w '%{http_code}' -H "X-Api-Key: $DEMO_KEY" "$LB/v1/me")"
        [ "$C" = "429" ] && RL=$((RL + 1))
        HITS=$((HITS + 1))
    done
    if [ "$RL" -ge 1 ]; then
        pass "API rate limit: $RL of 130 requests answered 429 (demo key locked ~60s)"
    else
        fail "API rate limit: expected ≥1 429 from 130 requests, got none"
    fi
fi

# ---------------------------------------------------------------------------
# Stage 7 — CSV export (labels + formula-injection guard)
# ---------------------------------------------------------------------------
stage "stage 7 — CSV export"

PTOKEN="$(csrf_from "$PJAR" "$LB/f/$PUBLIC_ID")"
RES="$(curl -s -b "$PJAR" -c "$PJAR" -o /dev/null -w '%{http_code} %{redirect_url}' \
    --data-urlencode "_token=$PTOKEN" \
    --data-urlencode "answers[f_text01]==SUM(A1:A9)" \
    "$LB/f/$PUBLIC_ID")"
if [ "$RES" = "302 $LB/f/$PUBLIC_ID/thanks" ]; then
    pass "submit with a leading-= answer → 302 thanks"
else
    fail "submit with a leading-= answer → 302 thanks (got: $RES)"
fi

CODE="$(curl -s -b "$JAR" -o "$TMP/export.csv" -w '%{http_code}' "$LB/forms/$FORM_ID/export.csv")"
HEADER="$(head -n 1 "$TMP/export.csv" 2>/dev/null)"
if [ "$CODE" = "200" ] && printf '%s' "$HEADER" | grep -q 'Your name' \
    && printf '%s' "$HEADER" | grep -q 'Attachment'; then
    pass "export.csv → 200, header row carries the field labels"
else
    fail "export.csv → 200 with labels (code $CODE, header: $HEADER)"
fi

if grep -q "'=SUM(A1:A9)" "$TMP/export.csv"; then
    pass "formula guard: leading-= cell exported with a quote prefix"
else
    fail "formula guard: leading-= cell exported with a quote prefix"
fi

# ---------------------------------------------------------------------------
# Stage 8 — chaos: kill web1, LB absorbs, status notices, bring it back
# ---------------------------------------------------------------------------
stage "stage 8 — chaos drill (web1)"

bash "$ROOT/launch.sh" kill web1 >"$TMP/chaos.log" 2>&1
if ! curl -s -m 2 -o /dev/null "http://127.0.0.1:$WEB1_PORT/healthz"; then
    pass "launch.sh kill web1 — instance is down"
else
    fail "launch.sh kill web1 — instance is down (web1 still answering)"
fi

LB_OK=0
i=0
while [ "$i" -lt 6 ]; do
    C="$(curl -s -m 5 -o /dev/null -w '%{http_code}' "$LB/")"
    [ "$C" = "200" ] && LB_OK=$((LB_OK + 1))
    i=$((i + 1))
done
if [ "$LB_OK" = "6" ]; then
    pass "6/6 GET / via the LB answered 200 with web1 down"
else
    fail "6/6 GET / via the LB answered 200 with web1 down (got $LB_OK/6)"
fi

web1_reported_down() { curl -s -m 5 "$LB/api/status" | grep -q '"name":"web1","ok":false'; }
if poll 15 web1_reported_down; then
    pass "/api/status reports web1 down"
else
    fail "/api/status reports web1 down"
fi

ASSUME_YES=1 bash "$ROOT/launch.sh" start --yes >"$TMP/restart.log" 2>&1
RC=$?
# Probed through the LB's per-pool route — works for both web runtimes
# (direct :$WEB1_PORT is FastCGI under fpm and never answers HTTP 200).
web1_healthy() { [ "$(curl -s -m 2 -o /dev/null -w '%{http_code}' "$LB/__pool/web1/healthz")" = "200" ]; }
if [ "$RC" = "0" ] && poll 20 web1_healthy; then
    pass "launch.sh start --yes brings web1 back (healthz 200)"
else
    fail "launch.sh start --yes brings web1 back (rc=$RC; see $TMP/restart.log)"
fi

stack_ok() { curl -s -m 5 "$LB/api/status" | grep -q '^{"ok":true'; }
if poll 15 stack_ok; then
    pass "stack left as found: /api/status ok:true again"
else
    fail "stack left as found: /api/status ok:true again"
fi

# ---------------------------------------------------------------------------
# Stage 9 — trace join across instances
# ---------------------------------------------------------------------------
stage "stage 9 — trace join"

JOIN_FILES="$(grep -l "$TRACE1" "$ROOT"/storage/logs/app-*.jsonl 2>/dev/null)"
JOIN_COUNT="$(printf '%s\n' "$JOIN_FILES" | grep -c . )"
if [ "$JOIN_COUNT" -ge 3 ]; then
    pass "trace $TRACE1 appears in $JOIN_COUNT app-*.jsonl files"
    note "$(printf '%s' "$JOIN_FILES" | tr '\n' ' ')"
else
    fail "trace $TRACE1 appears in ≥3 app-*.jsonl files (got $JOIN_COUNT: $(printf '%s' "$JOIN_FILES" | tr '\n' ' '))"
fi

# ---------------------------------------------------------------------------
# Stage 10 — cleanup (skipped with --keep)
# ---------------------------------------------------------------------------
stage "stage 10 — cleanup"

if [ "$KEEP" = "1" ]; then
    note "--keep: leaving throwaway user/form/files in place for debugging"
    note "  user: $EMAIL  form id: $FORM_ID  public_id: $PUBLIC_ID  api submission: ${API_SUB:-?}"
else
    # File artifacts must be collected BEFORE the cascading deletes.
    SUB_IDS="$(mysql_q "SELECT id FROM submissions WHERE form_id=$FORM_ID")"

    TOKEN="$(csrf_from "$JAR" "$LB/dashboard")"
    RES="$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' \
        --data-urlencode "_token=$TOKEN" "$LB/forms/$FORM_ID/delete")"
    if [ "$RES" = "302 $LB/dashboard" ] && [ "$(mysql_q "SELECT COUNT(*) FROM forms WHERE id=$FORM_ID")" = "0" ]; then
        pass "web UI delete cascaded the throwaway form + submissions/jobs"
    else
        fail "web UI delete of form $FORM_ID (got: $RES)"
    fi

    for sid in $SUB_IDS; do
        rm -f "$ROOT/storage/pdfs/submission-$sid.pdf" "$ROOT/storage/mail/submission-$sid.eml"
    done
    rm -rf "$ROOT/storage/uploads/$PUBLIC_ID"
    redis_q DEL "cache:form:$PUBLIC_ID" >/dev/null

    # The API check necessarily created one submission on the DEMO form;
    # remove that row (FK-cascades pdf_jobs/emails) + its files so repeated
    # runs don't pile data onto the seeded demo form.
    if [ -n "$API_SUB" ]; then
        mysql_q "DELETE FROM submissions WHERE id=$API_SUB"
        rm -f "$ROOT/storage/pdfs/submission-$API_SUB.pdf" "$ROOT/storage/mail/submission-$API_SUB.eml"
    fi

    mysql_q "DELETE FROM users WHERE email='$EMAIL'"
    if [ "$(mysql_q "SELECT COUNT(*) FROM users WHERE email='$EMAIL'")" = "0" ]; then
        pass "throwaway user + uploads dir removed; demo data untouched"
    else
        fail "throwaway user removal"
    fi

    if [ "$FULL" = "1" ]; then
        redis_q DEL "ratelimit:api:$DEMO_KEY" >/dev/null
        note "--full: cleared ratelimit:api:<demo key> so the key works immediately again"
    fi
fi

DEAD_AFTER="$(redis_q LLEN queue:dead)"
if [ "$DEAD_AFTER" = "$DEAD_BEFORE" ]; then
    pass "queue:dead unchanged ($DEAD_AFTER)"
else
    fail "queue:dead unchanged (before $DEAD_BEFORE, after $DEAD_AFTER)"
fi

if curl -s -m 5 "$LB/api/status" | grep -q '^{"ok":true'; then
    pass "post-run stack health: /api/status ok:true"
else
    fail "post-run stack health: /api/status ok:true"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo ""
if [ "$FAILS" -eq 0 ]; then
    ok "e2e: all $T checks passed in ${SECONDS}s"
    exit 0
fi
err "e2e: $FAILS of $T checks FAILED (${SECONDS}s)"
exit 1
