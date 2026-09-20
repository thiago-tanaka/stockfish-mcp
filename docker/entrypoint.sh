#!/bin/sh
# Preparation before handing control to php-fpm or to the worker.
#
# Everything here runs on every container start, so all of it has to be idempotent: a
# container the daemon restarted must reach the same state as a freshly created one.
set -e

# The database starts in parallel, and compose's depends_on only orders the starts -- it
# does not promise MySQL is accepting connections. Without this wait, the first start after
# an `up` fails.
if [ -n "${DB_HOST:-}" ]; then
    echo "Waiting for ${DB_HOST}:${DB_PORT:-3306}..."
    i=0
    until php -r "exit(@fsockopen(getenv('DB_HOST'), (int)(getenv('DB_PORT') ?: 3306)) ? 0 : 1);" 2>/dev/null; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "Database did not become reachable in 60s." >&2
            exit 1
        fi
        sleep 1
    done
fi

# Only the service that declares RUN_MIGRATIONS migrates. If app and worker both did, two
# connections would race the same migration.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

# Caching config and routes is worth a lot in production; in development it hides every
# change to .env and to routes behind a manual clear.
if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
else
    php artisan config:clear
    php artisan route:clear
fi

# public/ lives in the image, but Caddy is what serves it. Copying on each start keeps the
# two in step without giving Caddy an image of its own for every deploy.
#
# In development Caddy mounts ./public from the host and this path is not writable by the
# host UID; there the copy is pointless, and testing for -w is what tells the two cases
# apart without another variable.
if [ -d /srv/public ] && [ -w /srv/public ]; then
    cp -a /var/www/html/public/. /srv/public/
fi

# The engine is the dependency that breaks most quietly -- wrong architecture, missing
# binary. Better to say so at start than to return an error on the first tool call.
php artisan chess:engine

exec "$@"
