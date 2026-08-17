#!/usr/bin/env bash
# PostToolUse hook: syntax-check any .php file that was just edited or written.
#
# A syntax error in this project surfaces only as a blank page in the browser,
# so catching it at edit time is worth the round trip. Exit 2 reports the error
# back to Claude; every other outcome is a silent pass.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

input="$(cat)"

if command -v jq >/dev/null 2>&1; then
    file="$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')"
else
    file="$(printf '%s' "$input" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')"
fi

case "$file" in
    *.php) ;;
    *) exit 0 ;;
esac

[ -f "$file" ] || exit 0

out="$("$ROOT/scripts/php" -l "$file" 2>&1 </dev/null)"
status=$?

# 127 means no interpreter is available at all — do not block edits over that.
if [ "$status" -eq 0 ] || [ "$status" -eq 127 ]; then
    exit 0
fi

printf 'php -l failed for %s\n\n%s\n' "$file" "$out" >&2
exit 2
