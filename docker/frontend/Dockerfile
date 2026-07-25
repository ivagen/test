# syntax=docker/dockerfile:1
#
# Frontend tooling container: builds the browser bundle and runs lint, typecheck, unit
# tests and Playwright end-to-end tests. Its existence is what keeps quickstart.md's
# "no host PHP, Composer, Node, PostgreSQL or Redis is required" promise true, and it is
# what `docker compose exec frontend npm run ...` talks to.
#
# node:24 (Active LTS, per the constitution) on Debian bookworm rather than Alpine, because
# Playwright publishes no musl browser builds. A prebuilt mcr.microsoft.com/playwright
# image was rejected: it pins its own Node line, which would silently drift off Node 24.

ARG NODE_IMAGE=node:24.18.0-bookworm@sha256:5711a0d445a1af54af9589066c646df387d1831a608226f4cd694fc59e745059

FROM ${NODE_IMAGE}

ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    NPM_CONFIG_FUND=false \
    NPM_CONFIG_AUDIT=false \
    CI=1

WORKDIR /app

# Dependencies are baked into the image from the lockfile. Compose mounts node_modules as a
# named volume, which Docker seeds from this layer, so a normal `up` needs no install at
# all -- the entrypoint only reinstalls when package-lock.json actually changes.
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

# All three evergreen engines. spec.md's assumptions name Chrome, Firefox and Safari as the
# support target, and verifying only one of them would leave two thirds of that claim
# untested -- WebKit is the closest available stand-in for Safari.
RUN npx playwright install --with-deps chromium firefox webkit \
    && rm -rf /var/lib/apt/lists/*

RUN md5sum package-lock.json | cut -d' ' -f1 > /app/node_modules/.package-lock-hash

COPY docker/frontend/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["sleep", "infinity"]
