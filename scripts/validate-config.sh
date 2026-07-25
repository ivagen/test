#!/usr/bin/env bash
#
# Validates every YAML and JSON configuration file in the repository (quickstart.md: the
# verification command "must also validate YAML/JSON").
#
# The parsing itself is done by scripts/validate-config.php inside the `app` container,
# where PHP 8.4 and the locked symfony/yaml live. Nothing is ever skipped: a file that
# cannot be parsed -- or a validator that cannot run -- is a failure, because a check that
# silently does nothing is indistinguishable from one that passed (Constitution VI).
#
#   bash scripts/validate-config.sh [--files FILE ...]
#
#     --files FILE ...   validate exactly these files instead of the repository's own
#                        (used by the regression tests, with fixtures outside the repo)

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

# Written for bash 3.2, which is what macOS ships: no `mapfile`, no associative arrays, and
# no bare expansion of a possibly-empty array under `set -u`.
EXPLICIT_MODE=0

if [ "${1:-}" = "--files" ]; then
  shift
  EXPLICIT_MODE=1

  if [ "$#" -eq 0 ]; then
    echo "validate-config: --files needs at least one path" >&2
    exit 2
  fi
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "validate-config: docker compose is unavailable, so configuration cannot be validated." >&2
  echo "                 This is a failure, not a skip." >&2
  exit 2
fi

# Every YAML file that MUST be valid for the project to build and run. Listing them
# explicitly means a file silently disappearing from the glob is caught too.
REQUIRED_FILES=(
  "compose.yaml"
  ".github/workflows/ci.yml"
  "contracts/openapi.yaml"
)

run_validator() { # run_validator <host-dir> <path-inside-container>...
  local mount="$1"
  shift

  docker compose run --rm --no-deps -T \
    -v "$REPO_ROOT:/tools:ro" \
    -v "$mount:/subject:ro" \
    app php /tools/scripts/validate-config.php "$@" 2>&1
}

if [ "$EXPLICIT_MODE" -eq 1 ]; then
  # Fixture mode: every file is validated from its own directory, so a temporary file
  # outside the repository can be checked without copying anything into the working tree.
  status=0

  for file in "$@"; do
    dir="$(cd "$(dirname "$file")" 2>/dev/null && pwd)" || dir=""

    if [ -z "$dir" ]; then
      echo "  INVALID $file: the directory does not exist"
      status=1

      continue
    fi

    output="$(run_validator "$dir" "/subject/$(basename "$file")")"
    code=$?

    printf '%s\n' "$output" | grep -E '^( +(ok|INVALID) |All [0-9]+|[0-9]+ of [0-9]+|validate-config:)' || true

    [ "$code" -ne 0 ] && status=1
  done

  exit "$status"
fi

# --- repository mode --------------------------------------------------------------------

echo "== collecting configuration files =="

FILES="$(
  {
    git ls-files '*.json' '*.yml' '*.yaml'
    # Also include anything present but not yet tracked, so a brand-new configuration file
    # is never invisible to validation the way it used to be to the forbidden-component
    # scanner.
    git ls-files --others --exclude-standard '*.json' '*.yml' '*.yaml'
  } | grep -vE '(^|/)(node_modules|vendor|coverage|test-results|playwright-report|dist)/' | sort -u
)"

file_count="$(printf '%s\n' "$FILES" | grep -c . || true)"

if [ "$file_count" -eq 0 ]; then
  echo "validate-config: no configuration file was found, which cannot be right." >&2
  exit 2
fi

printf '  %d file(s) to validate\n\n' "$file_count"

# Every required file must actually be in the set that gets parsed.
missing=0

for required in "${REQUIRED_FILES[@]}"; do
  if ! printf '%s\n' "$FILES" | grep -qxF "$required"; then
    echo "  MISSING $required is required but was not collected for validation"
    missing=$((missing + 1))
  fi
done

if [ "$missing" -gt 0 ]; then
  echo
  echo "$missing required configuration file(s) were not validated."
  exit 1
fi

set -- # clear positional parameters, then rebuild them as container paths
while IFS= read -r file; do
  [ -z "$file" ] && continue
  set -- "$@" "/subject/$file"
done <<EOF
$FILES
EOF

output="$(run_validator "$REPO_ROOT" "$@")"
status=$?

# Strip the container path prefix so the report reads as repository-relative paths, and
# drop Compose's own lifecycle chatter.
printf '%s\n' "$output" \
  | grep -E '^( +(ok|INVALID) |All [0-9]+|[0-9]+ of [0-9]+|validate-config:)' \
  | sed 's#/subject/##'

if [ "$status" -eq 2 ]; then
  echo
  echo "The validator itself could not run. Nothing was checked." >&2
  exit 2
fi

if [ "$status" -ne 0 ]; then
  exit 1
fi

# compose.yaml must additionally satisfy Compose's own schema, which a YAML parser cannot
# check: correct syntax is not the same as a valid Compose document.
echo
echo "== docker compose config =="

if docker compose config --quiet >/dev/null 2>&1; then
  echo "  ok      compose.yaml is a valid Compose document"
else
  echo "  INVALID compose.yaml was rejected by docker compose config"
  docker compose config --quiet 2>&1 | sed 's/^/          /'
  exit 1
fi

exit 0
