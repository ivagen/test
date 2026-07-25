#!/usr/bin/env bash
#
# Infrastructure checks (tasks.md T008, made to pass by T014/T015).
#
# These assert the Compose contract required by the constitution and the spec:
#   - Constitution II  : images pinned, no `latest`, no obsolete `version:` key
#   - Constitution IV  : PostgreSQL and Redis publish no host ports; only the proxy does
#   - Constitution V   : no fixed global container names, no legacy `links`
#   - FR-009 / SC-006  : health checks, readiness dependencies, named DB volume
#
# Run from the repository root:  bash tests/infrastructure/compose_test.sh

set -uo pipefail
cd "$(dirname "$0")/../.."

pass=0
fail=0

ok()   { printf 'ok %d - %s\n'      "$((pass + fail + 1))" "$1"; pass=$((pass + 1)); }
nope() { printf 'not ok %d - %s\n'  "$((pass + fail + 1))" "$1"; fail=$((fail + 1)); }

assert() { # assert <description> <condition-exit-code>
  if [ "$2" -eq 0 ]; then ok "$1"; else nope "$1"; fi
}

echo "# infrastructure: compose contract"

# --- the file itself -------------------------------------------------------------------
if [ -f compose.yaml ]; then
  ok "compose.yaml exists (current Compose specification filename)"
else
  nope "compose.yaml exists (current Compose specification filename)"
  echo "# fatal: compose.yaml missing, remaining checks cannot run"
  echo "1..$((pass + fail))"
  exit 1
fi

assert "legacy docker-compose.yml is gone" \
  "$([ ! -f docker-compose.yml ]; echo $?)"

# --- `docker compose config` must be clean --------------------------------------------
config_err="$(docker compose config 2>&1 >/dev/null)"
config_rc=$?
assert "docker compose config succeeds" "$config_rc"

# Compose prints warnings on stderr. Ignore anything not caused by this repository.
repo_warnings="$(printf '%s\n' "$config_err" | grep -Ei 'warn|obsolete|deprecat' || true)"
if [ -z "$repo_warnings" ]; then
  ok "docker compose config emits no repository-caused warning"
else
  nope "docker compose config emits no repository-caused warning"
  printf '#   %s\n' "$repo_warnings"
fi

config="$(docker compose config 2>/dev/null)"

# --- forbidden legacy constructs -------------------------------------------------------
assert "no obsolete top-level 'version:' key" \
  "$(! grep -Eq '^version:' compose.yaml; echo $?)"
assert "no legacy 'links:' between services" \
  "$(! grep -Eq '^\s*links:' compose.yaml; echo $?)"
assert "no fixed global 'container_name:'" \
  "$(! grep -Eq '^\s*container_name:' compose.yaml; echo $?)"

# --- image pinning ---------------------------------------------------------------------
images="$(printf '%s\n' "$config" | grep -E '^\s+image:' | awk '{print $2}' | sort -u)"
if [ -z "$images" ]; then
  nope "compose declares at least one image"
else
  ok "compose declares at least one image"
  unpinned=""
  for img in $images; do
    case "$img" in
      *@sha256:*) ;;                       # digest-pinned: strongest
      *:latest|*latest*) unpinned="$unpinned $img" ;;
      *:*.*) ;;                            # at least an explicit patch/minor tag
      *) unpinned="$unpinned $img" ;;
    esac
  done
  if [ -z "$unpinned" ]; then
    ok "every image is pinned (digest or explicit version tag, never :latest)"
  else
    nope "every image is pinned (digest or explicit version tag, never :latest)"
    printf '#   unpinned:%s\n' "$unpinned"
  fi
fi

# --- host port exposure (default profile only) ----------------------------------------
# Constitution IV: PostgreSQL and Redis MUST NOT publish host ports by default.
for svc in postgres redis websocket app; do
  published="$(docker compose config --format json 2>/dev/null \
    | python3 -c "
import json,sys
try:
    c=json.load(sys.stdin)
except Exception:
    sys.exit(0)
s=c.get('services',{}).get('$svc')
if s is None:
    print('MISSING'); sys.exit(0)
for p in s.get('ports') or []:
    print(p.get('published') if isinstance(p,dict) else p)
")"
  if [ "$published" = "MISSING" ]; then
    nope "service '$svc' is defined"
  elif [ -z "$published" ]; then
    ok "service '$svc' publishes no host port"
  else
    nope "service '$svc' publishes no host port (found: $(echo $published))"
  fi
done

# SC-006: exactly one service (the reverse proxy) may reach the host.
publishing_services="$(docker compose config --format json 2>/dev/null \
  | python3 -c "
import json,sys
try:
    c=json.load(sys.stdin)
except Exception:
    sys.exit(0)
for n,s in c.get('services',{}).items():
    if s.get('ports'):
        print(n)
" | sort)"
if [ "$publishing_services" = "nginx" ]; then
  ok "only the reverse proxy publishes a host port (SC-006)"
else
  nope "only the reverse proxy publishes a host port (SC-006); publishing: $(echo $publishing_services)"
fi

# --- health checks and readiness -------------------------------------------------------
for svc in nginx app websocket postgres redis frontend; do
  has="$(docker compose config --format json 2>/dev/null \
    | python3 -c "
import json,sys
try:
    c=json.load(sys.stdin)
except Exception:
    sys.exit(0)
s=c.get('services',{}).get('$svc') or {}
print('yes' if s.get('healthcheck') else 'no')
")"
  assert "service '$svc' defines a healthcheck" "$([ "$has" = "yes" ]; echo $?)"
done

# app must wait for its data services to be *healthy*, not merely started.
deps_ok="$(docker compose config --format json 2>/dev/null \
  | python3 -c "
import json,sys
try:
    c=json.load(sys.stdin)
except Exception:
    sys.exit(1)
d=(c.get('services',{}).get('app') or {}).get('depends_on') or {}
need={'postgres','redis'}
print('yes' if need.issubset(d) and all(d[k].get('condition')=='service_healthy' for k in need) else 'no')
")"
assert "app depends on postgres and redis being healthy" "$([ "$deps_ok" = "yes" ]; echo $?)"

# --- persistence -----------------------------------------------------------------------
vol_ok="$(docker compose config --format json 2>/dev/null \
  | python3 -c "
import json,sys
try:
    c=json.load(sys.stdin)
except Exception:
    sys.exit(1)
vols=c.get('volumes') or {}
pg=(c.get('services',{}).get('postgres') or {}).get('volumes') or []
named=[v for v in pg if (v.get('type') if isinstance(v,dict) else '')=='volume']
print('yes' if vols and named else 'no')
")"
assert "postgres data lives on a named volume (SC-008)" "$([ "$vol_ok" = "yes" ]; echo $?)"

echo "1..$((pass + fail))"
echo "# passed $pass, failed $fail"
[ "$fail" -eq 0 ]
