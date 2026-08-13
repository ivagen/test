# Editable list

A small editable list: view, add, rename and delete items, with changes propagating to other
open browser sessions in real time.

It started as a 2017 demo on Yii 2 / AngularJS. The project has been fully revived: every
end-of-life component has been replaced, the data has been preserved, and the whole
application is now reproducible, covered by tests and secure by default.

---

## Architecture

```text
browser
  ├── GET/POST/PUT/DELETE  /api/items ──┐
  └── WS/WSS               /ws ─────┐   │
                                    v   v
                            nginx (the only published port)
                                │       │
                                v       v
                          websocket   php-fpm (Yii 2)
                           worker       │
                             ^          ├──> PostgreSQL 17   (source of truth)
                             └── Redis 8 <── event published after each commit
```

**PostgreSQL is the single source of truth.** WebSocket events are best-effort hints that let
the client refresh quickly; on connect, after a dropped connection, or upon receiving anything
it fails to parse, the client re-requests `GET /api/items`. A real-time failure therefore
degrades convenience but never blocks adding, renaming or deleting.

| Component         | Version                                    |
| ----------------- | ------------------------------------------ |
| PHP               | 8.4 (`php:8.4.23-fpm-alpine3.24`)          |
| Yii               | 2.0.55                                     |
| PostgreSQL        | 17.10                                      |
| Redis             | 8.8.1                                      |
| nginx             | 1.31.2                                     |
| Node.js (tooling) | 24 LTS (`node:24.18.0-bookworm`)           |
| WebSocket server  | Workerman 5.2                              |
| Browser client    | TypeScript + Vite, no runtime dependencies |

Every image is pinned by tag **and** digest in [`compose.yaml`](compose.yaml).
Both `linux/amd64` and `linux/arm64` are supported.

---

## Prerequisites

- Docker Engine / Docker Desktop with Compose v2 or newer
- Git

PHP, Composer, Node, PostgreSQL or Redis are not needed on the host — everything runs in
containers.

---

## Clean installation

```sh
cp .env.example .env
docker compose config
docker compose build --no-cache
docker compose up -d --wait
docker compose exec app php yii migrate --interactive=0
```

Or the same thing in a single command:

```sh
make setup
```

Then open **<http://localhost:8080>**. If port 8080 is taken, change `APP_PORT` in the `.env`
file.

Only nginx is published to the host. PostgreSQL and Redis are reachable only from inside the
project network.

### Configuration

All configuration is supplied through environment variables; see
[`.env.example`](.env.example) for the full list. The development values are safe to use
locally, and they are **rejected** when `APP_ENV=prod`, so a production deployment cannot
accidentally start with the example secrets.

---

## Everyday commands

```sh
make up                 # start everything and wait for healthy
make logs               # follow the app, websocket and nginx logs
make migrate            # apply pending migrations
make ps                 # service status
make down               # stop the project containers (data is PRESERVED)
make shell              # shell inside the application container
make psql               # psql session
```

`make help` lists every target.

### Reset

```sh
make reset              # asks for confirmation, then: docker compose down --volumes
```

> **This deletes this project's PostgreSQL volume and every item stored in it.**
> The command is deliberately separate from `make down`, which keeps your data.
> No command in this repository touches Docker containers, images, networks or volumes that
> belong to anything else. (The 2017-era `docker/rmi.sh` script ran
> `docker rm $(docker ps -a -q)` and `docker rmi $(docker images -q)`, wiping the entire host.
> It has been removed.)

---

## Testing and verification

A single command runs everything CI runs:

```sh
make verify
```

It covers: the Compose configuration and contract, YAML/JSON validity, scanning for banned
legacy components, `composer validate --strict`, PHPUnit (unit, contract and migration tests
plus the legacy parity check), PHPStan, PHP-CS-Fixer, ESLint, `tsc`, Vitest, Playwright and
both dependency audits.

Individual layers:

```sh
make test-backend       # PHPUnit
make test-frontend      # Vitest
make e2e                # Playwright
make lint               # ESLint + tsc
make analyse            # PHPStan
make style              # PHP-CS-Fixer (style-fix to apply)
make audit              # composer audit + npm audit
make scan               # banned legacy components, repository and running stack
```

Checks that need control over the containers themselves — dependency failures, SIGTERM
handling, error responses in production mode, data retention across re-creation:

```sh
make acceptance
```

---

## API

`GET` is open; every mutation requires a CSRF token, which the page publishes in a
`<meta name="csrf-token">` tag and which is returned in the `X-CSRF-Token` header. The full
contract is in [`contracts/openapi.yaml`](contracts/openapi.yaml).

| Method   | Path              | Success                 | Errors                     |
| -------- | ----------------- | ----------------------- | -------------------------- |
| `GET`    | `/api/items`      | `200 {"items":[…]}`     | —                          |
| `POST`   | `/api/items`      | `201` + item            | `403`, `415`, `422`        |
| `PUT`    | `/api/items/{id}` | `200` + item            | `403`, `404`, `415`, `422` |
| `DELETE` | `/api/items/{id}` | `204` (no body)         | `403`, `404`               |
| `GET`    | `/healthz`        | `200` liveness          | —                          |
| `GET`    | `/readyz`         | `200` / `503` readiness | —                          |

Errors always use the same envelope:
`{"code": "...", "message": "...", "details": {...}}`, where `code` is stable and
machine-readable. No response ever contains a stack trace, a filesystem path, SQL or an
account.

### Reproducible curl session

```sh
BASE=http://localhost:8080

# Fetch the CSRF token and keep the cookie it is validated against.
TOKEN=$(curl -sS -c /tmp/cookies "$BASE/" \
  | grep -o 'name="csrf-token" content="[^"]*"' | sed 's/.*content="//; s/"$//')

# List
curl -sS "$BASE/api/items"

# Create -> 201
ID=$(curl -sS -b /tmp/cookies -X POST "$BASE/api/items" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" \
  -d '{"name":"Milk"}' | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')

# Rename -> 200
curl -sS -b /tmp/cookies -X PUT "$BASE/api/items/$ID" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" \
  -d '{"name":"Bread"}'

# Invalid input -> 422 with code, message and details
curl -sS -b /tmp/cookies -X POST "$BASE/api/items" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" -d '{"name":"   "}'

# Missing CSRF token -> 403
curl -sS -X POST "$BASE/api/items" -H 'Content-Type: application/json' -d '{"name":"x"}'

# Unknown id -> 404
curl -sS -b /tmp/cookies -X DELETE "$BASE/api/items/999999" -H "X-CSRF-Token: $TOKEN"

# Delete -> 204
curl -sS -o /dev/null -w '%{http_code}\n' -b /tmp/cookies -X DELETE "$BASE/api/items/$ID" \
  -H "X-CSRF-Token: $TOKEN"
```

### Real-time events

The browser connects to `/ws` on the page's own origin (`wss` automatically when the page is
served over HTTPS). Every committed mutation publishes exactly one typed event:

```json
{
  "eventId": "6db69aac-0f19-47f0-a24c-c57af9cf0d16",
  "type": "item.updated",
  "itemId": 12,
  "item": { "id": 12, "name": "Updated name" },
  "occurredAt": "2026-07-25T15:00:00.000Z"
}
```

Delivery is best-effort and may duplicate or drop messages; the client de-duplicates by
`eventId` and re-fetches whenever it is unsure. Full rules:
[`contracts/websocket-events.md`](contracts/websocket-events.md).

---

## Migrating from the 2017 API

The old query-string routes have been **removed**, not kept behind an adapter: they contained
no business logic of their own, had no external consumers, and each one was a mutation point
without CSRF protection.

| 2017                                                        | Now                                                  |
| ----------------------------------------------------------- | ---------------------------------------------------- |
| `GET /index.php?r=site/get` → `{"rows":[…]}`                | `GET /api/items` → `{"items":[…]}`                   |
| `POST /index.php?r=site/create`, form-encoded `Items[name]` | `POST /api/items`, JSON `{"name":…}`                 |
| `PUT /index.php?r=site/update&id=N`                         | `PUT /api/items/{id}`                                |
| `DELETE /index.php?r=site/delete&id=N`                      | `DELETE /api/items/{id}`                             |
| `ws://host:8047/websocket`, whole list sent every second    | `/ws` on the same origin, one typed event per change |
| validation error → `400 {"error":{…}}`                      | → `422 {"code","message","details"}`                 |
| no CSRF protection                                          | `X-CSRF-Token` required for all mutations            |

**Stored data is untouched.** The `items` table is neither dropped nor recreated; it is still
created by the same initial migration. A test populates the database using the 2017 schema,
migrates it, and verifies that every id and every name survives byte for byte — including
Unicode names, a 255-character name and non-contiguous ids.

---

## Production notes

- Set `APP_ENV=prod`. Debug output is disabled, and the application **refuses to start** with
  a placeholder in place of a secret.
- Generate real secrets: `openssl rand -base64 32` for `APP_COOKIE_VALIDATION_KEY` and a
  strong `POSTGRES_PASSWORD`.
- Terminate TLS at a reverse proxy in front of nginx and forward `X-Forwarded-Proto`. The
  client derives `wss://` from the page origin automatically; nothing needs configuring.
- Only the nginx port should be published. PostgreSQL and Redis stay on the internal network.
- Redis holds no durable state (`--save "" --appendonly no`). Losing it entirely costs nothing
  beyond a brief real-time pause.
- Installing without development tooling: with `APP_ENV=prod` the entrypoint runs
  `composer install --no-dev --optimize-autoloader`.
- Point your orchestrator's liveness probe at `/healthz` and its readiness probe at `/readyz`.
- Logs are structured JSON on stdout/stderr with known secrets redacted.

---

## Troubleshooting

**Port 8080 is already in use** — change `APP_PORT` in `.env` and run `make up`.

**The page reports that the bundle has not been built** — the frontend container builds the
assets on startup; `docker compose logs frontend` will show why it failed. To rebuild:
`docker compose exec frontend npm run build`.

**`app` never becomes healthy** — it waits for PostgreSQL and Redis, then installs the
Composer dependencies on the first run. `docker compose logs app` shows the progress;
`docker compose exec app php yii health/check` reports which dependency is failing.

**Dependencies look stale after changing `composer.json` or `package.json`** — the entrypoints
reinstall them automatically whenever the lock-file hash changes. To force it:
`docker compose exec app composer install` or `docker compose exec frontend npm ci`.

**Real-time updates have stopped** — the page shows a degraded-mode banner and CRUD keeps
working. Check `docker compose logs websocket`; the worker reconnects to Redis on its own with
bounded backoff.

**Where did Adminer go?** — the 2017 stack included a database browser on `php:5.6-apache`
(end of life since 2018) that downloaded `adminer.org/latest.php` at build time and published
its own port on the host. It was removed rather than pinned: it existed only to browse the
database, `make psql` does the same without an EOL runtime, and every extra published port
contradicts the "only the proxy is reachable from outside" rule. If you want a GUI, run it on
your own machine and tunnel to the container — nothing in this repository needs changing.

**Everything is a mess** — `make reset` rebuilds everything from scratch. It deletes this
project's database volume and nothing else.
