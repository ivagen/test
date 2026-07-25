#!/usr/bin/env bash
#
# Container-level acceptance run (tasks.md T057-T060, quickstart.md "Production-mode
# checks" and "Real-time acceptance").
#
# These are the checks that need control over the containers themselves -- stopping a
# dependency, signalling a process, recreating a service -- which the in-container test
# suites deliberately cannot do.
#
# SAFETY: every docker command below is scoped to THIS Compose project by `docker compose`.
# Nothing here removes a volume, an image, or any container outside the project.
#
#   bash scripts/acceptance.sh

set -uo pipefail
cd "$(dirname "$0")/.."

PORT="$(grep -E '^APP_PORT=' .env 2>/dev/null | cut -d= -f2)"
PORT="${PORT:-8080}"
BASE="http://localhost:${PORT}"

failed=0
step=0

pass() { printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; failed=$((failed + 1)); }
head() { step=$((step + 1)); printf '\n\033[1m[%d] %s\033[0m\n' "$step" "$1"; }

wait_healthy() {
  local service="$1" deadline=$((SECONDS + ${2:-90}))

  while [ "$SECONDS" -lt "$deadline" ]; do
    if docker compose ps "$service" --format '{{.Health}}' 2>/dev/null | grep -q '^healthy$'; then
      return 0
    fi

    sleep 2
  done

  return 1
}

csrf_token() {
  curl -sS -c /tmp/acceptance-cookies "$BASE/" \
    | grep -o 'name="csrf-token" content="[^"]*"' \
    | sed 's/.*content="//; s/"$//'
}

api_create() {
  local token
  token="$(csrf_token)"

  curl -sS -o /tmp/acceptance-body -w '%{http_code}' \
    -b /tmp/acceptance-cookies \
    -X POST "$BASE/api/items" \
    -H 'Content-Type: application/json' \
    -H "X-CSRF-Token: $token" \
    -d "{\"name\":\"$1\"}"
}

# ==========================================================================================
head "startup deadline and health (SC-001, spec US1)"

started=$SECONDS
ok=1

for service in postgres redis app websocket nginx frontend; do
  if wait_healthy "$service" 120; then
    pass "$service is healthy"
  else
    fail "$service did not become healthy"
    ok=0
  fi
done

elapsed=$((SECONDS - started))
printf '        all services healthy after %ds\n' "$elapsed"

# ==========================================================================================
head "only the reverse proxy is published (SC-006, Constitution IV)"

# Inspect the RUNNING containers, not the compose file: `docker compose port` exits 0 with
# empty output when there is no mapping, so it cannot be used as a test on its own.
published="$(docker compose ps --format json 2>/dev/null | python3 -c "
import json, sys

services = set()

for line in sys.stdin:
    line = line.strip()
    if not line:
        continue
    try:
        entries = json.loads(line)
    except json.JSONDecodeError:
        continue
    if isinstance(entries, dict):
        entries = [entries]
    for entry in entries:
        for publisher in entry.get('Publishers') or []:
            if publisher.get('PublishedPort'):
                services.add(entry['Service'])

print(' '.join(sorted(services)))
")"

if [ "$published" = "nginx" ]; then
  pass "only nginx publishes a host port"
else
  fail "expected only nginx to publish a port, got: '${published}'"
fi

for service in postgres redis websocket app frontend; do
  case " $published " in
    *" $service "*) fail "$service publishes a host port" ;;
    *)              pass "$service publishes no host port" ;;
  esac
done

# ==========================================================================================
head "migrations are idempotent (spec US1 scenario 2)"

if docker compose exec -T app php yii migrate --interactive=0 2>/dev/null | grep -q 'No new migrations found'; then
  pass "a repeat migration reports nothing pending"
else
  fail "a repeat migration did not report a clean state"
fi

# ==========================================================================================
head "CRUD survives a real-time outage (FR-007)"

docker compose stop websocket >/dev/null 2>&1

name="acceptance-outage-$$"
status="$(api_create "$name")"

if [ "$status" = "201" ]; then
  pass "a create still succeeds with the worker stopped (HTTP $status)"
else
  fail "a create returned HTTP $status with the worker stopped"
fi

id="$(python3 -c "import json;print(json.load(open('/tmp/acceptance-body'))['id'])" 2>/dev/null || echo '')"

if [ -n "$id" ]; then
  token="$(csrf_token)"
  del="$(curl -sS -o /dev/null -w '%{http_code}' -b /tmp/acceptance-cookies -X DELETE "$BASE/api/items/$id" -H "X-CSRF-Token: $token")"

  if [ "$del" = "204" ]; then
    pass "a delete still succeeds with the worker stopped (HTTP $del)"
  else
    fail "a delete returned HTTP $del with the worker stopped"
  fi
fi

if curl -sS -o /dev/null -w '%{http_code}' "$BASE/api/items" | grep -q 200; then
  pass "the list is still served with the worker stopped"
else
  fail "the list failed with the worker stopped"
fi

docker compose start websocket >/dev/null 2>&1

if wait_healthy websocket 90; then
  pass "the worker returns to healthy after being restarted"
else
  fail "the worker did not recover"
fi

# ==========================================================================================
head "the worker recovers from a Redis outage (T039)"

docker compose stop redis >/dev/null 2>&1
sleep 3

if docker compose ps websocket --format '{{.State}}' | grep -q running; then
  pass "the worker stays running while Redis is down"
else
  fail "the worker died when Redis went away"
fi

status="$(api_create "acceptance-redis-$$")"

if [ "$status" = "201" ]; then
  pass "a create still succeeds with Redis down (HTTP $status)"
else
  fail "a create returned HTTP $status with Redis down"
fi

redis_id="$(python3 -c "import json;print(json.load(open('/tmp/acceptance-body'))['id'])" 2>/dev/null || echo '')"

docker compose start redis >/dev/null 2>&1
wait_healthy redis 60

# The subscriber reconnects with jittered backoff capped at 10s.
sleep 15

if docker compose logs websocket --since 30s 2>&1 | grep -q 'Subscribed to Redis channel'; then
  pass "the worker re-subscribed after Redis came back"
else
  fail "the worker did not re-subscribe after Redis came back"
fi

if [ -n "$redis_id" ]; then
  token="$(csrf_token)"
  curl -sS -o /dev/null -b /tmp/acceptance-cookies -X DELETE "$BASE/api/items/$redis_id" -H "X-CSRF-Token: $token"
fi

# ==========================================================================================
head "SIGTERM stops every process gracefully (quickstart.md production checks)"

docker compose stop websocket >/dev/null 2>&1
exit_code="$(docker inspect "$(docker compose ps -aq websocket)" --format '{{.State.ExitCode}}' 2>/dev/null || echo unknown)"

# 137 means the grace period expired and the process was SIGKILLed.
if [ "$exit_code" = "0" ]; then
  pass "the worker exited cleanly on SIGTERM (exit $exit_code)"
else
  fail "the worker exited with $exit_code on SIGTERM (137 means it had to be killed)"
fi

docker compose start websocket >/dev/null 2>&1
wait_healthy websocket 90

# ==========================================================================================
head "persistence across container recreation (SC-008)"

persist_name="acceptance-persist-$$"
api_create "$persist_name" >/dev/null
before="$(curl -sS "$BASE/api/items")"

docker compose up -d --force-recreate app nginx websocket >/dev/null 2>&1
wait_healthy app 120
wait_healthy nginx 60

after="$(curl -sS "$BASE/api/items")"

if [ "$before" = "$after" ]; then
  pass "recreating the stateless services lost no data"
else
  fail "the item list changed across a recreate"
fi

persist_id="$(python3 -c "
import json,sys
items = json.loads(sys.argv[1])['items']
print(next((i['id'] for i in items if i['name'] == sys.argv[2]), ''))
" "$after" "$persist_name" 2>/dev/null || echo '')"

if [ -n "$persist_id" ]; then
  token="$(csrf_token)"
  curl -sS -o /dev/null -b /tmp/acceptance-cookies -X DELETE "$BASE/api/items/$persist_id" -H "X-CSRF-Token: $token"
fi

# ==========================================================================================
head "production mode leaks nothing on an internal failure (FR-010)"

# Production mode refuses to boot with a placeholder secret -- which is the behaviour under
# test, so this check has to supply REAL ones. The database password is rotated for the
# duration of the check and restored afterwards; both statements run inside this project's
# own PostgreSQL container.
original_db_password="$(grep -E '^POSTGRES_PASSWORD=' .env | cut -d= -f2-)"
db_user="$(grep -E '^POSTGRES_USER=' .env | cut -d= -f2- || echo app)"
db_user="${db_user:-app}"
temp_db_password="acceptance-$(openssl rand -hex 12)"

docker compose exec -T postgres psql -q -U "$db_user" -d "${POSTGRES_DB:-app}" \
  -c "ALTER USER \"$db_user\" WITH PASSWORD '$temp_db_password'" >/dev/null 2>&1

restore_password() {
  docker compose exec -T postgres psql -q -U "$db_user" -d "${POSTGRES_DB:-app}" \
    -c "ALTER USER \"$db_user\" WITH PASSWORD '$original_db_password'" >/dev/null 2>&1
}
trap restore_password EXIT

override="$(mktemp -t prodcheck-XXXXXX.yaml)"
cat > "$override" <<YAML
services:
  app:
    environment:
      APP_ENV: prod
      APP_COOKIE_VALIDATION_KEY: acceptance-$(openssl rand -hex 24)
      DB_PASSWORD: $temp_db_password
YAML

docker compose -f compose.yaml -f "$override" up -d --force-recreate app >/dev/null 2>&1
wait_healthy app 120

if curl -sS "$BASE/" | grep -q 'csrf-token'; then
  pass "the application serves normally in production mode"
else
  fail "the application did not start in production mode"
fi

# A real internal failure: the database goes away underneath a request.
docker compose stop postgres >/dev/null 2>&1
sleep 2

body="$(curl -sS "$BASE/api/items")"
code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/api/items")"

if [ "$code" -ge 500 ]; then
  pass "a failed dependency produces a server error (HTTP $code)"
else
  fail "expected a 5xx with the database down, got HTTP $code"
fi

leaked=0
for secret in 'Stack trace' '/var/www' 'SQLSTATE' 'PDOException' 'pgsql:host' 'SELECT ' 'password'; do
  if printf '%s' "$body" | grep -qiF -- "$secret"; then
    fail "the production error response leaked: $secret"
    leaked=1
  fi
done

[ "$leaked" -eq 0 ] && pass "the production error response leaked nothing"

readyz="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/readyz")"

if [ "$readyz" = "503" ]; then
  pass "readiness reports 503 while the database is unavailable"
else
  fail "readiness returned $readyz with the database down (expected 503)"
fi

docker compose start postgres >/dev/null 2>&1
wait_healthy postgres 60

# Restore the development configuration and the original database password.
restore_password
trap - EXIT
docker compose up -d --force-recreate app >/dev/null 2>&1
wait_healthy app 120
rm -f "$override"

if docker compose exec -T app printenv APP_ENV | grep -q '^dev$'; then
  pass "the development configuration was restored"
else
  fail "the stack was left in production mode"
fi

# ==========================================================================================
printf '\n\033[1m== acceptance summary ==\033[0m\n'

rm -f /tmp/acceptance-cookies /tmp/acceptance-body

if [ "$failed" -eq 0 ]; then
  printf '\033[32mEvery acceptance check passed.\033[0m\n'
  exit 0
fi

printf '\033[31m%d acceptance check(s) failed.\033[0m\n' "$failed"
exit 1
