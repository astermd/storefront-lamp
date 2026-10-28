#!/usr/bin/env bash
# Container entrypoint. `serve` (the default command) migrates the database and
# runs php-fpm and nginx side by side; anything else is run as given, so
# `docker compose run --rm app bin/console theme:sync` works as expected.
set -euo pipefail

cd /var/www/html

# storage/ is a volume and starts empty. bin/console creates these on demand,
# but the web process should not depend on a console command having run first.
mkdir -p storage/logs storage/cache storage/sessions storage/database

# Under compose the source is bind-mounted and vendor/ may not exist yet.
if [[ ! -f vendor/autoload.php ]]; then
    if command -v composer >/dev/null; then
        composer install --no-interaction --no-progress --prefer-dist
    else
        echo "vendor/autoload.php is missing and this image has no Composer." >&2
        exit 1
    fi
fi

if [[ "${1:-}" != "serve" ]]; then
    exec "$@"
fi

# In production Twig compiles templates into storage/cache/twig with
# auto_reload off, and storage/ is a volume that outlives the image — so a new
# image would keep serving the previous image's compiled templates. Starting a
# container is a deploy, and deploy/README.md asks for a cache clear after
# every deploy. Everything else in storage/cache refills on demand.
php bin/console cache:clear --no-interaction

# Migrations are tracked by name, so re-running them on every start is a no-op
# once applied. Set MIGRATE_ON_START=0 where a release step runs them instead.
if [[ "${MIGRATE_ON_START:-1}" == "1" ]]; then
    php bin/console db:migrate --no-interaction
fi

php-fpm --nodaemonize &
fpm=$!
nginx -e stderr -g 'daemon off;' &
web=$!

# SIGQUIT is a graceful stop for both: in-flight requests finish first. QUIT
# must be trapped as well as TERM — the php-fpm base image declares
# `STOPSIGNAL SIGQUIT`, so that is what `docker stop` sends, and as PID 1 this
# shell ignores any signal it has no trap for.
stop() { kill -QUIT "$fpm" "$web" 2>/dev/null || true; }
trap stop TERM INT QUIT

# Whichever exits first — or a stop signal — takes the container down with it,
# so the runtime's restart policy sees the failure instead of a half-alive
# container serving 502s.
status=0
wait -n "$fpm" "$web" || status=$?
stop
wait "$fpm" "$web" 2>/dev/null || true
exit "$status"
