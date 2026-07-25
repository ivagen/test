#!/usr/bin/env bash
#
# Runs every infrastructure test suite and aggregates the result.
#
# `scripts/verify.sh` calls this as its single "infrastructure tests" check, so adding a
# suite here automatically puts it in the verification entrypoint and in CI -- there is no
# second place to remember to update.
#
#   bash tests/infrastructure/run.sh

set -uo pipefail
cd "$(dirname "$0")/../.."

SUITES="
tests/infrastructure/compose_test.sh|Compose contract (pinning, ports, health, volumes)
tests/infrastructure/scanner_test.sh|Forbidden-component scanner: comments vs. real usage
tests/infrastructure/config_validation_test.sh|Configuration validation: real parsing, no silent skips
"

failed=0
total=0

while IFS='|' read -r script label; do
  [ -z "$script" ] && continue

  total=$((total + 1))

  printf '\n\033[1m--- %s\033[0m\n' "$label"
  printf '    $ bash %s\n\n' "$script"

  # </dev/null matters: these suites shell out to `docker compose run`, which reads stdin
  # and would otherwise swallow the rest of the suite list being fed to this loop.
  if bash "$script" </dev/null; then
    printf '\033[32m    PASS\033[0m  %s\n' "$label"
  else
    printf '\033[31m    FAIL\033[0m  %s\n' "$label"
    failed=$((failed + 1))
  fi
done <<EOF
$SUITES
EOF

printf '\n\033[1m--- infrastructure summary ---\033[0m\n'

if [ "$failed" -eq 0 ]; then
  printf '\033[32mAll %d infrastructure suites passed.\033[0m\n' "$total"
  exit 0
fi

printf '\033[31m%d of %d infrastructure suites failed.\033[0m\n' "$failed" "$total"
exit 1
