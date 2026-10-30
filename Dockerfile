# AsterMD storefront — one container serving nginx in front of php-fpm.
# See "Docker" in deploy/README.md for how to run it and what to mount.
#
#   docker build -t astermd-storefront .            # production image
#   docker compose up                               # local development (target: dev)
#
# Built assets in public/assets/build/ are committed, so the production image
# carries no Node: it is built from what the repository already holds. Only the
# dev target adds Node and npm, for `npm run build` and `npm run dev`.

ARG PHP_VERSION=8.5

FROM composer:2 AS composer

# ---------------------------------------------------------------------------
# base — PHP, the extensions every database driver needs, nginx, and the
# runtime configuration. Nothing from the application is copied in yet.
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

RUN set -eux; \
    apk add --no-cache bash nginx icu-libs libpq; \
    apk add --no-cache --virtual .build-deps icu-dev postgresql-dev; \
    docker-php-ext-install -j"$(nproc)" intl pdo_mysql pdo_pgsql; \
    # OPcache is always compiled in from PHP 8.5; on 8.4 it ships as a module.
    php -m | grep -qi '^Zend OPcache$' || docker-php-ext-enable opcache; \
    apk del .build-deps; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY deploy/docker/php.ini      $PHP_INI_DIR/conf.d/zz-storefront.ini
COPY deploy/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-storefront.conf
COPY deploy/docker/nginx.conf   /etc/nginx/nginx.conf
COPY --chmod=0755 deploy/docker/entrypoint.sh /usr/local/bin/storefront-entrypoint

# Code changes in a production image only arrive with a new image, so OPcache
# never needs to stat a file. The dev target turns this back on.
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    COMPOSER_HOME=/tmp/composer \
    HOME=/tmp

WORKDIR /var/www/html

# Everything — nginx, php-fpm and any `docker exec` — runs as www-data, so a
# console command can never leave a root-owned log or cache file behind that
# the web process then cannot write. The parent image leaves /var/www/html
# world-writable; only storage/ should be.
RUN chown root:root /var/www/html && chmod 0755 /var/www/html \
    && mkdir -p storage && chown www-data:www-data storage
USER www-data

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO /dev/null http://127.0.0.1:8080/health/ || exit 1

ENTRYPOINT ["storefront-entrypoint"]
CMD ["serve"]

# ---------------------------------------------------------------------------
# dev — the source is bind-mounted over /var/www/html by compose.yaml, so this
# target carries only tooling: Composer (for `composer install` and the test
# suite), Node 24 with npm (for the asset pipeline) and nano (for editing a
# file from `docker compose exec`). Alpine 3.24 ships Node 24, the version the
# pipeline is developed against.
# ---------------------------------------------------------------------------
FROM base AS dev

COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1 \
    npm_config_cache=/tmp/npm-cache

USER root
RUN apk add --no-cache nodejs npm nano

# compose.yaml mounts a named volume on each of these, which starts out with
# this directory's mode. Sticky and world-writable lets it work under whatever
# uid compose runs the container as, while PHP's session handler still sees
# each session file owned by the uid that created it.
RUN mkdir -p node_modules && chmod 1777 storage node_modules
USER www-data

# ---------------------------------------------------------------------------
# vendor — production dependencies, resolved in their own layer so a source
# edit does not re-download them.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer /usr/bin/composer /usr/bin/composer
USER root
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
        --no-scripts --no-autoloader

COPY core/ core/
RUN composer dump-autoload --no-dev --optimize

# ---------------------------------------------------------------------------
# production — the default target. The application is owned by root and only
# readable by www-data: storage/ is the one place the web process may write,
# matching the permission model in deploy/README.md.
# ---------------------------------------------------------------------------
FROM base AS production

COPY --chown=root:root . .
COPY --from=vendor --chown=root:root /var/www/html/vendor/ vendor/

VOLUME ["/var/www/html/storage"]
