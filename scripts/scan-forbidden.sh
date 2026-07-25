#!/usr/bin/env bash
#
# Scans the repository AND the running stack for every component the constitution forbids
# (tasks.md T062, SC-007).
#
# The repository half is done by scripts/scan-forbidden.php, which reads each file as what
# it is -- a manifest, a Dockerfile, a Compose document, a shell script, PHP source -- and
# looks only at evidence that a component is actually installed or executed. That is what
# lets a comment saying "replaces PHPDaemon" stay in the code, where it is useful, without
# failing the scan.
#
# The running-stack half lives here, because it needs the docker CLI: a manifest can be
# spotless while a container still ships an EOL runtime or publishes a port it shouldn't.
#
#   bash scripts/scan-forbidden.sh [--root DIR] [--skip-runtime]
#
#     --root DIR       scan DIR instead of the repository (used by the regression tests)
#     --skip-runtime   skip the running-stack section

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="$REPO_ROOT"
SKIP_RUNTIME=0

while [ "$#" -gt 0 ]; do
  case "$1" in
    --root)
      TARGET="$(cd "$2" 2>/dev/null && pwd)" || { echo "scan-forbidden: --root '$2' is not a directory" >&2; exit 2; }
      shift 2
      ;;
    --skip-runtime)
      SKIP_RUNTIME=1
      shift
      ;;
    *)
      echo "scan-forbidden: unknown argument '$1'" >&2
      exit 2
      ;;
  esac
done

cd "$REPO_ROOT"

findings=0

report() { printf '  FOUND  %s\n' "$1"; findings=$((findings + 1)); }
pass()   { printf '  ok     %s\n' "$1"; }

echo "== repository =="

# The analyser runs inside the app container: it is where PHP 8.4 and the locked
# symfony/yaml live, and using it keeps this check working identically on a developer's
# machine and in CI without anyone installing PHP on the host.
#
# Fail closed. If the toolchain is unavailable the scan has NOT passed, and saying so is
# the whole point -- a check that cannot run must never look like a check that succeeded.
if ! docker compose version >/dev/null 2>&1; then
  echo "  FAIL   docker compose is unavailable, so the repository scan could not run" >&2
  exit 2
fi

analyser_output="$(
  docker compose run --rm --no-deps -T \
    -v "$REPO_ROOT:/tools:ro" \
    -v "$TARGET:/target:ro" \
    app php /tools/scripts/scan-forbidden.php /target 2>&1
)"
analyser_status=$?

# Compose writes container lifecycle chatter to the same stream; keep only the report.
printf '%s\n' "$analyser_output" | grep -E '^( +(ok|FOUND) |No forbidden|[0-9]+ repository)' || true

if [ "$analyser_status" -ne 0 ] && [ "$analyser_status" -ne 1 ]; then
  echo "  FAIL   the repository analyser could not run (exit $analyser_status)" >&2
  printf '%s\n' "$analyser_output" | tail -20 >&2
  exit 2
fi

if [ "$analyser_status" -eq 1 ]; then
  findings=$((findings + 1))
fi

# --- checks that need git, and therefore only apply to the real repository --------------
if [ "$TARGET" = "$REPO_ROOT" ]; then
  if git ls-files --error-unmatch .env >/dev/null 2>&1; then
    report ".env is tracked in git"
  else
    pass ".env is not tracked"
  fi
fi

if [ "$SKIP_RUNTIME" -eq 1 ]; then
  echo
  echo "== running stack =="
  echo "  skip   running-stack checks were disabled with --skip-runtime"
else
  echo
  echo "== running stack =="

  if ! docker compose ps --format '{{.Service}}' 2>/dev/null | grep -q .; then
    pass "the stack is not running; start it with \`make up\` to include this section"
  else
    php_version="$(docker compose exec -T app php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo unknown)"

    case "$php_version" in
      8.4) pass "app runs PHP $php_version" ;;
      *)   report "app runs PHP $php_version (expected 8.4)" ;;
    esac

    node_version="$(docker compose exec -T frontend node --version 2>/dev/null | tr -d 'v\r' | cut -d. -f1 || echo unknown)"

    case "$node_version" in
      24) pass "frontend runs Node $node_version" ;;
      *)  report "frontend runs Node $node_version (expected 24)" ;;
    esac

    # The bytes actually served to a browser.
    bundle="$(docker compose exec -T frontend sh -c 'cat /assets/app/assets/*.js 2>/dev/null | head -c 400000' 2>/dev/null || true)"

    if [ -n "$bundle" ]; then
      if printf '%s' "$bundle" | grep -qE 'angular\.module\(|angularjs-toaster|jQuery\.fn\.jquery'; then
        report "a forbidden library is present in the served bundle"
      else
        pass "the served bundle contains no forbidden library"
      fi
    else
      pass "no served bundle to inspect"
    fi

    published="$(docker compose ps --format '{{.Service}} {{.Publishers}}' 2>/dev/null | grep -E '[0-9]+->' | awk '{print $1}' | sort -u || true)"

    if [ -z "$published" ] || [ "$published" = "nginx" ]; then
      pass "only the reverse proxy publishes a host port"
    else
      report "these services publish host ports: $(echo "$published" | tr '\n' ' ')"
    fi
  fi
fi

echo
if [ "$findings" -eq 0 ]; then
  echo "No forbidden component found."
  exit 0
fi

echo "$findings finding group(s) must be removed."
exit 1
