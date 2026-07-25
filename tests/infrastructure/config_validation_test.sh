#!/usr/bin/env bash
#
# Regression tests for scripts/validate-config.sh.
#
# WHY THIS EXISTS
#
# The validator used python3 with an optional PyYAML import. PyYAML is not installed here,
# so every YAML file was reported as "skip (no YAML parser available)" -- and the script
# still printed "All configuration files parse" and exited 0. A check that cannot run must
# never look like a check that passed (Constitution VI).
#
# These tests pin the corrected behaviour: real parsing, no silent skipping, fail-closed on
# a missing validator, and a non-zero exit on malformed input.
#
#   bash tests/infrastructure/config_validation_test.sh

set -uo pipefail
cd "$(dirname "$0")/../.."

REPO_ROOT="$PWD"
pass=0
fail=0

ok()   { printf 'ok %d - %s\n'     "$((pass + fail + 1))" "$1"; pass=$((pass + 1)); }
nope() { printf 'not ok %d - %s\n' "$((pass + fail + 1))" "$1"; fail=$((fail + 1)); }

WORK="$(mktemp -d "${TMPDIR:-/tmp}/config-validation-XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

STATE_BEFORE="$(git -C "$REPO_ROOT" status --porcelain)"

# ---------------------------------------------------------------------------------------
echo "# validator: the repository's own configuration"
# ---------------------------------------------------------------------------------------

output="$(bash scripts/validate-config.sh 2>&1)"
code=$?

if [ "$code" -eq 0 ]; then
  ok "the repository's configuration files all parse"
else
  nope "the repository's configuration files all parse (exit $code)"
  printf '%s\n' "$output" | sed 's/^/#   /' | tail -20
fi

# The whole point of the fix: nothing may be reported as skipped.
if printf '%s' "$output" | grep -qi 'skip'; then
  nope "no configuration file is skipped"
  printf '%s\n' "$output" | grep -i skip | sed 's/^/#   /'
else
  ok "no configuration file is skipped"
fi

# Each file the audit named must appear as actually parsed, not merely absent from errors.
for required in \
  'compose.yaml' \
  '.github/workflows/ci.yml' \
  'contracts/openapi.yaml'
do
  if printf '%s' "$output" | grep -qE "(ok|OK)[[:space:]]+.*${required//./\\.}"; then
    ok "$required was actually parsed"
  else
    nope "$required was not reported as parsed"
  fi
done

# JSON must still be covered.
if printf '%s' "$output" | grep -qE "(ok|OK)[[:space:]]+.*www/composer\.json"; then
  ok "www/composer.json was actually parsed"
else
  nope "www/composer.json was not reported as parsed"
fi

# ---------------------------------------------------------------------------------------
echo "# validator: malformed input must fail"
# ---------------------------------------------------------------------------------------

# Fixtures live outside the repository, so no tracked file is ever modified.
cat > "$WORK/broken.yaml" <<'YAML'
services:
  app:
    image: "unterminated
     - this: is not
    valid yaml
YAML

cat > "$WORK/broken.json" <<'JSON'
{"name": "fixture",}
JSON

cat > "$WORK/good.yaml" <<'YAML'
services:
  app:
    image: nginx:1.31.2-alpine3.23
YAML

if bash scripts/validate-config.sh --files "$WORK/broken.yaml" >/dev/null 2>&1; then
  nope "malformed YAML produces a non-zero exit"
else
  ok "malformed YAML produces a non-zero exit"
fi

if bash scripts/validate-config.sh --files "$WORK/broken.json" >/dev/null 2>&1; then
  nope "malformed JSON produces a non-zero exit"
else
  ok "malformed JSON produces a non-zero exit"
fi

if bash scripts/validate-config.sh --files "$WORK/good.yaml" >/dev/null 2>&1; then
  ok "well-formed YAML passes"
else
  nope "well-formed YAML passes"
fi

# A file that cannot be read at all must fail, not be quietly skipped.
if bash scripts/validate-config.sh --files "$WORK/does-not-exist.yaml" >/dev/null 2>&1; then
  nope "an unreadable file produces a non-zero exit"
else
  ok "an unreadable file produces a non-zero exit"
fi

# ---------------------------------------------------------------------------------------
echo "# validator: compose.yaml is additionally checked by Compose itself"
# ---------------------------------------------------------------------------------------

if docker compose config --quiet >/dev/null 2>&1; then
  ok "docker compose config accepts compose.yaml"
else
  nope "docker compose config accepts compose.yaml"
fi

# ---------------------------------------------------------------------------------------
echo "# validator: the working tree is untouched"
# ---------------------------------------------------------------------------------------

if [ "$(git -C "$REPO_ROOT" status --porcelain)" = "$STATE_BEFORE" ]; then
  ok "validation left every tracked file unchanged"
else
  nope "validation modified the working tree"
fi

echo "1..$((pass + fail))"
echo "# passed $pass, failed $fail"
[ "$fail" -eq 0 ]
