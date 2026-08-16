#!/usr/bin/env bash
# scripts/smoke.sh — lint every PHP file, boot the dev server, and exercise every
# endpoint and view mode, failing on PHP diagnostics rendered into the response body.
#
# There is no unit test suite; this is the project's verification loop.
#
# Usage:
#   scripts/smoke.sh              # lint + serve + check
#   scripts/smoke.sh --no-lint    # skip the lint stage
#   PORT=9000 scripts/smoke.sh    # use a different port
#
# Network-dependent endpoints (weather, geocoding, RSS) are checked for a well-formed
# response, not for live data, so the script passes offline.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-8111}"
BASE="http://127.0.0.1:$PORT"
PHP="$REPO_ROOT/scripts/php"
CONTAINER_NAME="librejoanne-smoke-$$"
DO_LINT=1

[ "${1:-}" = "--no-lint" ] && DO_LINT=0

# PHP writes diagnostics into the response body, where they are invisible to a
# status-code check. These are the prefixes it uses.
PHP_ERR_RE='(Fatal error|Parse error|Warning|Deprecated|Notice|Uncaught [A-Za-z_]+):'

PASS=0; FAIL=0; SKIP=0
TMP="$(mktemp -d)"
REQ_N=0

if [ -t 1 ]; then
    R=$'\e[31m'; G=$'\e[32m'; Y=$'\e[33m'; B=$'\e[1m'; N=$'\e[0m'
else
    R=""; G=""; Y=""; B=""; N=""
fi

pass() { PASS=$((PASS+1)); printf '  %sPASS%s %s\n' "$G" "$N" "$1"; }
fail() { FAIL=$((FAIL+1)); printf '  %sFAIL%s %s\n' "$R" "$N" "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }
skip() { SKIP=$((SKIP+1)); printf '  %sSKIP%s %s\n' "$Y" "$N" "$1"; }
head_() { printf '\n%s%s%s\n' "$B" "$1" "$N"; }

cleanup() {
    # Remove the container first: the runtime client will not always exit on a
    # SIGTERM to itself, and waiting on it unconditionally can hang forever.
    for rt in podman docker; do
        command -v "$rt" >/dev/null 2>&1 && "$rt" rm -f "$CONTAINER_NAME" >/dev/null 2>&1
    done
    if [ -n "${SERVER_PID:-}" ]; then
        kill "$SERVER_PID" 2>/dev/null
        for _ in $(seq 1 20); do
            kill -0 "$SERVER_PID" 2>/dev/null || break
            sleep 0.2
        done
        kill -9 "$SERVER_PID" 2>/dev/null
    fi
    rm -rf "$TMP"
}
trap cleanup EXIT

# ---------------------------------------------------------------- preconditions

head_ "Environment"

if ! "$PHP" -r 'exit(0);' >/dev/null 2>&1; then
    printf '  %sFAIL%s no usable PHP (see scripts/php)\n' "$R" "$N"
    exit 1
fi
if [ -n "${PHP_BIN:-}" ] || command -v php >/dev/null 2>&1; then
    pass "php interpreter (native)"
else
    pass "php interpreter (container: ${PHP_IMAGE:-docker.io/library/php:8.3-cli})"
fi

# Nothing in the application creates this directory, and every cache write fails
# silently without it.
if mkdir -p "$REPO_ROOT/web/data/cache"; then
    pass "web/data/cache exists"
else
    fail "web/data/cache could not be created"
fi

if [ -f "$REPO_ROOT/web/data/config.php" ]; then
    pass "config: web/data/config.php"
else
    skip "config: falling back to config.sample.php"
fi

# ------------------------------------------------------------------------ lint

if [ "$DO_LINT" = 1 ]; then
    head_ "Lint"
    # Collect first: the interpreter reads stdin, and would otherwise swallow the
    # rest of the file list mid-loop.
    PHP_FILES=()
    while IFS= read -r f; do PHP_FILES+=("$f"); done < <(find "$REPO_ROOT/web" -name '*.php' | sort)
    for f in "${PHP_FILES[@]}"; do
        if out=$("$PHP" -l "$f" 2>&1 </dev/null); then
            pass "php -l ${f#"$REPO_ROOT"/}"
        else
            fail "php -l ${f#"$REPO_ROOT"/}" "$(printf '%s' "$out" | head -3)"
        fi
    done
fi

# ------------------------------------------------------------------ unit checks

head_ "Unit checks"
UNIT_FILES=()
while IFS= read -r f; do UNIT_FILES+=("$f"); done < <(find "$REPO_ROOT/scripts" -name 'test-*.php' | sort)
for f in "${UNIT_FILES[@]}"; do
    if unit_out=$("$PHP" "$f" 2>&1 </dev/null); then
        printf '%s\n' "$unit_out" | grep -E '^ +PASS'
        PASS=$((PASS + $(printf '%s\n' "$unit_out" | grep -c 'PASS')))
    else
        printf '%s\n' "$unit_out" | grep -E '^ +(PASS|FAIL)|expected:|actual:' | head -30
        fail "$(basename "$f")"
    fi
done

# ---------------------------------------------------------------------- server

head_ "Server"

PHP_CONTAINER_NAME="$CONTAINER_NAME" "$PHP" -S "127.0.0.1:$PORT" -t "$REPO_ROOT/web/app" \
    >"$TMP/server.log" 2>&1 &
SERVER_PID=$!

ready=0
for _ in $(seq 1 60); do
    if curl -sS -o /dev/null --max-time 2 "$BASE/" 2>/dev/null; then ready=1; break; fi
    kill -0 "$SERVER_PID" 2>/dev/null || break
    sleep 0.5
done

if [ "$ready" != 1 ]; then
    fail "server did not come up on $BASE" "$(tail -5 "$TMP/server.log")"
    printf '\n%s1 failed, %d passed%s\n' "$R" "$PASS" "$N"
    exit 1
fi
pass "server listening on $BASE"

# --------------------------------------------------------------------- helpers

fetch() { # fetch <path>; sets HTTP_CODE and BODY
    REQ_N=$((REQ_N+1))
    BODY="$TMP/body.$REQ_N"
    HTTP_CODE=$(curl -sS -o "$BODY" -w '%{http_code}' --max-time 45 "$BASE$1" 2>"$TMP/curl.err") \
        || HTTP_CODE="000"
}

assert_no_php_errors() { # assert_no_php_errors <label>
    local hit
    hit=$(grep -Eo "$PHP_ERR_RE.{0,90}" "$BODY" | head -1)
    if [ -n "$hit" ]; then
        fail "$1 — PHP diagnostic in body" "$hit"
        return 1
    fi
    return 0
}

assert_json() { # assert_json <label>
    if command -v python3 >/dev/null 2>&1; then
        python3 -c 'import json,sys; json.load(open(sys.argv[1]))' "$BODY" 2>"$TMP/json.err"
    else
        "$PHP" -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$BODY" \
            2>"$TMP/json.err"
    fi
    if [ $? -ne 0 ]; then
        fail "$1 — invalid JSON" "$(head -c 120 "$BODY")"
        return 1
    fi
    return 0
}

check_json() { # check_json <label> <path> [scan_for_php_errors:1|0]
    local label="$1" path="$2" scan="${3:-1}"
    fetch "$path"
    # 503 is a correct degraded response when an upstream API is unreachable.
    case "$HTTP_CODE" in
        200|503) ;;
        *) fail "$label — HTTP $HTTP_CODE" "$(head -c 120 "$BODY")"; return ;;
    esac
    [ "$scan" = 1 ] && { assert_no_php_errors "$label" || return; }
    assert_json "$label" || return
    pass "$label (HTTP $HTTP_CODE)"
}

check_html() { # check_html <label> <path>
    local label="$1" path="$2"
    fetch "$path"
    if [ "$HTTP_CODE" != "200" ]; then
        fail "$label — HTTP $HTTP_CODE" "$(head -c 120 "$BODY")"; return
    fi
    assert_no_php_errors "$label" || return
    if ! grep -q '</html>' "$BODY"; then
        fail "$label — response truncated (no </html>)" "$(tail -c 120 "$BODY")"; return
    fi
    pass "$label"
}

# -------------------------------------------------------------- JSON endpoints

head_ "JSON endpoints"
check_json "calendar.php?room=default"        "/calendar.php?room=default"
check_json "calendar.php?room=personal"       "/calendar.php?room=personal"
check_json "weather.php (Toronto)"            "/weather.php?lat=43.65&lon=-79.38&city=Toronto"
check_json "geocoding.php?name=London"        "/geocoding.php?name=London"
# Skip the diagnostic scan for RSS: live headlines can legitimately contain
# strings like "Warning:" and would trip the pattern.
check_json "rss.php?lang=en"                  "/rss.php?lang=en" 0
check_json "rss.php?lang=fr"                  "/rss.php?lang=fr" 0

# ------------------------------------------------------------------ view modes

head_ "View modes"
check_html "room view (/)"                    "/"
check_html "dashboard view"                   "/?room=personal"
check_html "7-day grid view"                  "/?room=personal-grid"
check_html "view override (?view=grid)"       "/?view=grid"
check_html "lang override (?lang=fr)"         "/?lang=fr"
check_html "widgets off"                      "/?show_rss=0&show_weather=0"
check_html "unknown room falls back"          "/?room=does-not-exist"
check_html "manage.php (login gate)"          "/manage.php"

# The database-backed paths — rooms rows and tokenised users — are where the config
# resolution chain does the most work, so exercise them whenever fixtures exist.
# Populate with: scripts/php scripts/seed-dev-data.php
DB="$REPO_ROOT/web/data/librejoanne.db"
if command -v sqlite3 >/dev/null 2>&1 && [ -f "$DB" ]; then
    ROOM_KEY=$(sqlite3 "$DB" "SELECT room_key FROM rooms LIMIT 1" 2>/dev/null)
    if [ -n "$ROOM_KEY" ]; then
        check_html "database room view"       "/?room=$ROOM_KEY"
        check_json "calendar.php, database room" "/calendar.php?room=$ROOM_KEY"
    else
        skip "database room — no rooms rows (see scripts/seed-dev-data.php)"
    fi

    TOKEN=$(sqlite3 "$DB" "SELECT access_token FROM users WHERE access_token IS NOT NULL AND is_admin = 0 LIMIT 1" 2>/dev/null)
    if [ -n "$TOKEN" ]; then
        check_html "personal token view"      "/?userid=$TOKEN"
        check_json "calendar.php via token"   "/calendar.php?userid=$TOKEN"
        check_html "token + view override"    "/?userid=$TOKEN&view=grid"
        # A token must win over ?room=, which is what forces the personal context.
        check_html "token overrides ?room="   "/?room=default&userid=$TOKEN"
    else
        skip "token views — no non-admin user rows (see scripts/seed-dev-data.php)"
    fi
else
    skip "database paths — no sqlite3 or no database yet"
fi

# --------------------------------------------------------------------- summary

if grep -Eq "$PHP_ERR_RE" "$TMP/server.log"; then
    fail "server log contains PHP diagnostics" "$(grep -Eo "$PHP_ERR_RE.{0,90}" "$TMP/server.log" | head -3)"
fi

head_ "Summary"
printf '  %d passed, %d failed, %d skipped\n\n' "$PASS" "$FAIL" "$SKIP"
[ "$FAIL" -eq 0 ] || exit 1
