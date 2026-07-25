# syntax=docker/dockerfile:1
#
# PHP image for both the HTTP application (php-fpm) and the real-time worker (Workerman).
# Build context is the repository root.
#
# Replaces the EOL docker/front/Dockerfile (Ubuntu 16.04 + PHP 7.0 + supervisord +
# PHPDaemon cloned from master + Bower/Gulp), which no longer builds at all.
#
# Both the base image and Composer are pinned by tag AND digest. Both support linux/amd64
# and linux/arm64.

ARG PHP_IMAGE=php:8.4.23-fpm-alpine3.24@sha256:913ddd6934a805429618a16aa36da47cd8a8aec8b2f111c294936ba4003fded6
ARG COMPOSER_IMAGE=composer:2.9.8@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE} AS runtime

# pdo_pgsql -> PostgreSQL; mbstring -> character-correct (not byte-based) name validation;
# pcntl -> Workerman signal handling and graceful SIGTERM shutdown; opcache -> throughput.
# Build toolchain is installed and removed in the same layer so it never ships.
RUN set -eux; \
    apk add --no-cache libpq oniguruma; \
    apk add --no-cache --virtual .build-deps ${PHPIZE_DEPS} postgresql-dev oniguruma-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring pcntl opcache; \
    apk del .build-deps; \
    rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /var/www

COPY www/ /var/www/

# runtime/ and web/assets/ must be writable by the pool user; vendor/ is a named volume in
# Compose and inherits this ownership when Docker seeds it from the image.
RUN set -eux; \
    mkdir -p /var/www/runtime /var/www/web/assets /var/www/vendor; \
    chown -R www-data:www-data /var/www/runtime /var/www/web/assets /var/www/vendor; \
    chmod +x /usr/local/bin/entrypoint /var/www/yii

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
