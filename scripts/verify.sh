#!/usr/bin/env bash
#
# The single documented verification entrypoint (tasks.md T052, quickstart.md "Automated
# verification"). `make verify` calls this, and so does CI, so a green run locally means
# the same checks passed that CI will run.
#
# Every step prints the exact command it runs, and the script continues after a failure so
# that one broken check does not hide the state of the others. It exits non-zero if
# anything failed.
#
#   bash scripts/verify.sh

set -uo pipefail
cd "$(dirname "$0")/.."

failed=()
passed=()

run() {
  local label="$1"
  shift

  printf '\n\033[1m==> %s\033[0m\n    $ %s\n\n' "$label" "$*"

  if "$@"; then
    passed+=("$label")
  else
    failed+=("$label")
    printf '\n\033[31m    FAILED: %s\033[0m\n' "$label"
  fi
}

# --- configuration ----------------------------------------------------------------------
run "compose configuration"      docker compose config --quiet
# Runs every suite under tests/infrastructure/: the Compose contract plus the regression
# tests that keep the scanner and the configuration validator honest.
run "infrastructure tests"       bash tests/infrastructure/run.sh
run "YAML/JSON validity"         bash scripts/validate-config.sh
run "forbidden components"       bash scripts/scan-forbidden.sh

# --- backend ----------------------------------------------------------------------------
run "composer manifest"          docker compose exec -T app composer validate --strict
run "backend tests"              docker compose exec -T app composer test
run "static analysis"            docker compose exec -T app composer analyse
run "coding style"               docker compose exec -T app composer style

# --- frontend ---------------------------------------------------------------------------
run "frontend lint"              docker compose exec -T frontend npm run lint
run "frontend typecheck"         docker compose exec -T frontend npm run typecheck
run "frontend unit tests"        docker compose exec -T frontend npm test
run "end-to-end tests"           docker compose exec -T frontend npm run test:e2e

# --- dependency audits ------------------------------------------------------------------
run "composer audit"             docker compose exec -T app composer audit --abandoned=report
run "npm audit"                  docker compose exec -T frontend npm audit --audit-level=high

# --- summary ------------------------------------------------------------------------------
printf '\n\033[1m==> summary\033[0m\n'

for label in "${passed[@]}"; do
  printf '    \033[32mPASS\033[0m  %s\n' "$label"
done

for label in "${failed[@]:-}"; do
  [ -z "$label" ] && continue
  printf '    \033[31mFAIL\033[0m  %s\n' "$label"
done

if [ "${#failed[@]}" -eq 0 ]; then
  printf '\n\033[32mAll %d checks passed.\033[0m\n' "${#passed[@]}"
  exit 0
fi

printf '\n\033[31m%d of %d checks failed.\033[0m\n' "${#failed[@]}" "$(( ${#passed[@]} + ${#failed[@]} ))"
exit 1
