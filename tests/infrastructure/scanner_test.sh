#!/usr/bin/env bash
#
# Regression tests for scripts/scan-forbidden.sh.
#
# WHY THIS EXISTS
#
# The scanner previously matched broad regular expressions against whole files, so a
# comment saying "replaces PHPDaemon" counted as PHPDaemon being installed. Worse, it
# enumerated files with `git ls-files`, so a brand-new untracked file was invisible to it --
# which is why it appeared to pass right up until those files were staged.
#
# These tests pin both halves of the correct behaviour:
#   - documentation that NAMES a forbidden component must not fail the scan;
#   - a real dependency, image, command or code reference must still fail it.
#
# Every case runs against a disposable fixture tree outside the repository, and the test
# asserts that the tracked working tree is byte-identical afterwards.
#
#   bash tests/infrastructure/scanner_test.sh

set -uo pipefail
cd "$(dirname "$0")/../.."

REPO_ROOT="$PWD"
pass=0
fail=0

ok()   { printf 'ok %d - %s\n'     "$((pass + fail + 1))" "$1"; pass=$((pass + 1)); }
nope() { printf 'not ok %d - %s\n' "$((pass + fail + 1))" "$1"; fail=$((fail + 1)); }

WORK="$(mktemp -d "${TMPDIR:-/tmp}/scanner-regression-XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

# Snapshot the working tree so we can prove the scan changed nothing.
tree_state() {
  git -C "$REPO_ROOT" status --porcelain
}

STATE_BEFORE="$(tree_state)"

# ---------------------------------------------------------------------------------------
# A minimal, entirely clean project. Each case copies this and introduces exactly one
# difference, so a failure can only be caused by that difference.
# ---------------------------------------------------------------------------------------
make_clean_fixture() {
  local dir="$1"

  mkdir -p "$dir/docker/php" "$dir/frontend/src" "$dir/www/commands" "$dir/scripts"

  cat > "$dir/compose.yaml" <<'YAML'
name: fixture
services:
  nginx:
    image: nginx:1.31.2-alpine3.23
    ports:
      - "8080:80"
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    command: ["php-fpm"]
volumes:
  data:
YAML

  cat > "$dir/docker/php/Dockerfile" <<'DOCKER'
FROM php:8.4.23-fpm-alpine3.24
RUN docker-php-ext-install pdo_pgsql
CMD ["php-fpm"]
DOCKER

  cat > "$dir/www/composer.json" <<'JSON'
{
    "name": "fixture/app",
    "minimum-stability": "stable",
    "require": {
        "php": "~8.4.0",
        "yiisoft/yii2": "~2.0.55"
    }
}
JSON

  cat > "$dir/www/composer.lock" <<'JSON'
{
    "packages": [
        {"name": "yiisoft/yii2", "version": "2.0.55"}
    ],
    "packages-dev": []
}
JSON

  cat > "$dir/frontend/package.json" <<'JSON'
{
    "name": "fixture-frontend",
    "private": true,
    "devDependencies": {
        "vite": "^8.1.5"
    }
}
JSON

  cat > "$dir/frontend/package-lock.json" <<'JSON'
{
    "name": "fixture-frontend",
    "lockfileVersion": 3,
    "packages": {
        "node_modules/vite": {"version": "8.1.5"}
    }
}
JSON

  cat > "$dir/www/commands/RealtimeController.php" <<'PHP'
<?php

declare(strict_types=1);

namespace app\commands;

use Workerman\Worker;

final class RealtimeController
{
    public function actionStart(): int
    {
        $worker = new Worker('websocket://0.0.0.0:8080');

        return 0;
    }
}
PHP

  cat > "$dir/frontend/src/main.ts" <<'TS'
import { createApp } from './app.js';

createApp();
TS

  cat > "$dir/scripts/run.sh" <<'SH'
#!/usr/bin/env bash
docker compose up -d --wait
SH
}

# Runs the scanner against a fixture, with the running-stack section disabled (there is no
# stack for a fixture). Returns the scanner's exit code.
scan() {
  bash "$REPO_ROOT/scripts/scan-forbidden.sh" --root "$1" --skip-runtime >/dev/null 2>&1
  echo $?
}

# Asserts the scanner ACCEPTS a fixture built by the given mutator.
expect_clean() {
  local label="$1" mutate="$2"
  local dir="$WORK/clean-$((pass + fail))"

  make_clean_fixture "$dir"
  "$mutate" "$dir"

  local code
  code="$(scan "$dir")"

  if [ "$code" = "0" ]; then
    ok "$label"
  else
    nope "$label (scanner exited $code, expected 0)"
    bash "$REPO_ROOT/scripts/scan-forbidden.sh" --root "$dir" --skip-runtime 2>&1 | sed 's/^/#   /' | grep -i found
  fi
}

# Asserts the scanner REJECTS a fixture built by the given mutator.
expect_finding() {
  local label="$1" mutate="$2"
  local dir="$WORK/finding-$((pass + fail))"

  make_clean_fixture "$dir"
  "$mutate" "$dir"

  local code
  code="$(scan "$dir")"

  if [ "$code" != "0" ]; then
    ok "$label"
  else
    nope "$label (scanner exited 0, expected a finding)"
  fi
}

nothing() { :; }

echo "# scanner: a clean project passes"
expect_clean "an entirely clean project produces no finding" nothing

# =========================================================================================
echo "# scanner: documentation that NAMES a forbidden component must not fail"
# =========================================================================================

comment_in_php() {
  cat > "$1/www/commands/RealtimeController.php" <<'PHP'
<?php

declare(strict_types=1);

namespace app\commands;

use Workerman\Worker;

/**
 * The real-time worker -- the supported replacement for the 2017 PHPDaemon setup.
 *
 * PHPDaemon was abandoned upstream and required pecl event/eio, so it was replaced by
 * Workerman. Supervisor (supervisord) is gone too: the container runs one process.
 */
final class RealtimeController
{
    public function actionStart(): int
    {
        // Replaces the old \PHPDaemon\Core\Daemon bootstrap; see research.md.
        $worker = new Worker('websocket://0.0.0.0:8080');

        return 0;
    }
}
PHP
}
expect_clean "a PHP docblock and inline comment naming PHPDaemon/supervisord" comment_in_php

comment_in_dockerfile() {
  cat > "$1/docker/php/Dockerfile" <<'DOCKER'
# Replaces the EOL docker/front/Dockerfile (Ubuntu 16.04 + PHP 7.0 + supervisord +
# PHPDaemon cloned from master), which no longer builds at all.
FROM php:8.4.23-fpm-alpine3.24
RUN docker-php-ext-install pdo_pgsql
CMD ["php-fpm"]
DOCKER
}
expect_clean "a Dockerfile comment naming supervisord, PHPDaemon and ubuntu 16.04" comment_in_dockerfile

comment_in_compose() {
  cat > "$1/compose.yaml" <<'YAML'
name: fixture
# The websocket worker replaces PHPDaemon; no supervisord, no links, no version key.
services:
  nginx:
    image: nginx:1.31.2-alpine3.23
    ports:
      - "8080:80"
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    # Previously started through supervisord.
    command: ["php-fpm"]
YAML
}
expect_clean "a Compose comment naming PHPDaemon and supervisord" comment_in_compose

comment_in_typescript() {
  cat > "$1/frontend/src/main.ts" <<'TS'
// Replaces the AngularJS 1.6 client that Bower installed and Gulp 3 bundled.
import { createApp } from './app.js';

/* angular.module('app', []) used to live here. */
createApp();
TS
}
expect_clean "a TypeScript comment naming AngularJS, Bower and Gulp" comment_in_typescript

comment_in_shell() {
  cat > "$1/scripts/run.sh" <<'SH'
#!/usr/bin/env bash
# The old script ran: docker rm $(docker ps -a -q) and docker rmi $(docker images -q),
# which deleted every container and image on the host. Removed.
docker compose up -d --wait
SH
}
expect_clean "a shell comment quoting the removed destructive commands" comment_in_shell

# =========================================================================================
echo "# scanner: real usage must still be found"
# =========================================================================================

real_composer_dependency() {
  cat > "$1/www/composer.json" <<'JSON'
{
    "name": "fixture/app",
    "minimum-stability": "stable",
    "require": {
        "php": "~8.4.0",
        "phpdaemon/phpdaemon": "^1.0"
    }
}
JSON
}
expect_finding "a real phpdaemon Composer requirement" real_composer_dependency

real_composer_lock_entry() {
  cat > "$1/www/composer.lock" <<'JSON'
{
    "packages": [
        {"name": "yiisoft/yii2", "version": "2.0.55"},
        {"name": "bower-asset/jquery", "version": "3.7.1"}
    ],
    "packages-dev": []
}
JSON
}
expect_finding "a bower-asset package installed in composer.lock" real_composer_lock_entry

unstable_composer_lock_entry() {
  cat > "$1/www/composer.lock" <<'JSON'
{
    "packages": [
        {"name": "kakserpom/phpdaemon", "version": "dev-master"}
    ],
    "packages-dev": []
}
JSON
}
expect_finding "a dev-master package locked in composer.lock" unstable_composer_lock_entry

unstable_minimum_stability() {
  cat > "$1/www/composer.json" <<'JSON'
{
    "name": "fixture/app",
    "minimum-stability": "dev",
    "require": {
        "php": "~8.4.0"
    }
}
JSON
}
expect_finding "minimum-stability: dev in composer.json" unstable_minimum_stability

real_npm_dependency() {
  cat > "$1/frontend/package.json" <<'JSON'
{
    "name": "fixture-frontend",
    "private": true,
    "dependencies": {
        "angular": "1.6.4"
    },
    "devDependencies": {
        "gulp": "^3.9.1"
    }
}
JSON
}
expect_finding "real angular and gulp npm dependencies" real_npm_dependency

real_supervisord_command() {
  cat > "$1/docker/php/Dockerfile" <<'DOCKER'
FROM php:8.4.23-fpm-alpine3.24
RUN apk add --no-cache supervisor
ENTRYPOINT ["/usr/bin/supervisord"]
DOCKER
}
expect_finding "supervisord installed and used as the entrypoint" real_supervisord_command

eol_base_image() {
  cat > "$1/docker/php/Dockerfile" <<'DOCKER'
FROM ubuntu:16.04
RUN apt-get update && apt-get install -y php7.0-fpm
CMD ["php-fpm7.0"]
DOCKER
}
expect_finding "an EOL ubuntu:16.04 base image with PHP 7.0" eol_base_image

unpinned_image() {
  cat > "$1/compose.yaml" <<'YAML'
name: fixture
services:
  nginx:
    image: nginx
    ports:
      - "8080:80"
  postgres:
    image: postgres:latest
YAML
}
expect_finding "unpinned and :latest Compose images" unpinned_image

compose_links() {
  cat > "$1/compose.yaml" <<'YAML'
version: '2'
services:
  nginx:
    image: nginx:1.31.2-alpine3.23
    container_name: front
    links:
      - postgres:postgres
YAML
}
expect_finding "an obsolete version key, container_name and links" compose_links

php_use_statement() {
  cat > "$1/www/commands/RealtimeController.php" <<'PHP'
<?php

declare(strict_types=1);

namespace app\commands;

use PHPDaemon\Core\Daemon;

final class RealtimeController extends \PHPDaemon\Core\AppInstance
{
    public function onReady(): void
    {
        Daemon::log('running');
    }
}
PHP
}
expect_finding "a real PHPDaemon use statement and parent class" php_use_statement

typescript_import() {
  cat > "$1/frontend/src/main.ts" <<'TS'
import angular from 'angular';

angular.module('app', []);
TS
}
expect_finding "a real angular import in TypeScript" typescript_import

destructive_shell_command() {
  cat > "$1/scripts/run.sh" <<'SH'
#!/usr/bin/env bash
docker compose stop
docker rm $(docker ps -a -q)
docker rmi $(docker images -q)
SH
}
expect_finding "a script that deletes every container and image on the host" destructive_shell_command

# =========================================================================================
echo "# scanner: the real repository"
# =========================================================================================

repo_code="$(bash "$REPO_ROOT/scripts/scan-forbidden.sh" --skip-runtime >/dev/null 2>&1; echo $?)"

if [ "$repo_code" = "0" ]; then
  ok "the repository itself produces no finding"
else
  nope "the repository itself produces no finding (exit $repo_code)"
  bash "$REPO_ROOT/scripts/scan-forbidden.sh" --skip-runtime 2>&1 | grep -i found | sed 's/^/#   /'
fi

# =========================================================================================
echo "# scanner: the scan must not touch the working tree"
# =========================================================================================

if [ "$(tree_state)" = "$STATE_BEFORE" ]; then
  ok "running the scanner left every tracked file unchanged"
else
  nope "running the scanner modified the working tree"
  diff <(printf '%s\n' "$STATE_BEFORE") <(tree_state) | sed 's/^/#   /'
fi

echo "1..$((pass + fail))"
echo "# passed $pass, failed $fail"
[ "$fail" -eq 0 ]
