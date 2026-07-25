#!/bin/sh
#
# Keeps /var/www/vendor (a named volume, so host files are never touched and no root-owned
# artefact leaks onto the developer's machine) in sync with composer.lock.
#
# This stays deterministic because `composer install` is lock-driven: it can only ever
# install the exact versions recorded in composer.lock. The stamp file just avoids paying
# for that on every container start.
#
# Only the container that sets VENDOR_SYNC=1 (the `app` service, which runs as root) may
# write. Others wait for it, so two services never install into the same volume at once.

set -eu

cd /var/www

lock_hash="$(md5sum composer.lock | cut -d' ' -f1)"
stamp="vendor/.composer-lock-hash"

in_sync() {
    [ -f "$stamp" ] && [ "$(cat "$stamp")" = "$lock_hash" ]
}

if [ "${VENDOR_SYNC:-0}" = "1" ]; then
    if ! in_sync; then
        echo "entrypoint: installing PHP dependencies from composer.lock" >&2

        # APP_ENV=prod ships without development tooling and with an optimised autoloader.
        if [ "${APP_ENV:-dev}" = "prod" ]; then
            composer install --no-interaction --no-progress --no-ansi \
                --no-dev --optimize-autoloader --classmap-authoritative
        else
            composer install --no-interaction --no-progress --no-ansi
        fi

        printf '%s' "$lock_hash" > "$stamp"
        chown -R www-data:www-data vendor
        echo "entrypoint: dependencies ready" >&2
    fi
else
    waited=0
    while ! in_sync; do
        if [ "$waited" -ge 180 ]; then
            echo "entrypoint: timed out after ${waited}s waiting for the app service to install dependencies" >&2
            exit 1
        fi
        [ "$waited" = 0 ] && echo "entrypoint: waiting for the app service to install dependencies" >&2
        sleep 2
        waited=$((waited + 2))
    done
fi

exec "$@"
