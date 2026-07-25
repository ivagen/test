#!/bin/sh
#
# Keeps node_modules in sync with package-lock.json (same lock-driven, deterministic
# approach as the PHP entrypoint), then builds the browser bundle once so that a plain
# `docker compose up -d` yields a working page with no extra manual step.
#
# The build output goes to /assets/app, which Compose maps to www/web/assets/app -- the
# directory nginx serves and www/views/layouts/main.php reads the Vite manifest from.

set -eu

cd /app

lock_hash="$(md5sum package-lock.json | cut -d' ' -f1)"
stamp="node_modules/.package-lock-hash"

if [ ! -f "$stamp" ] || [ "$(cat "$stamp")" != "$lock_hash" ]; then
    echo "entrypoint: installing frontend dependencies from package-lock.json" >&2
    npm ci
    printf '%s' "$lock_hash" > "$stamp"
fi

if [ "${BUILD_ON_START:-1}" = "1" ]; then
    echo "entrypoint: building browser assets" >&2
    npm run build
    echo "entrypoint: assets ready" >&2
fi

exec "$@"
