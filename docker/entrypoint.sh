#!/bin/sh
set -e

# Runs on every container start (app, queue, and scheduler all use this image -
# see docker-compose.yml). Idempotent: safe to run again on every restart.

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force
fi

# Wait for MySQL to actually accept connections before migrating - the mysql
# container is "started" long before it's ready to serve queries.
until php artisan db:show > /dev/null 2>&1; do
    echo "Waiting for the database to be ready..."
    sleep 2
done

# Only the `app` service runs migrations (RUN_MIGRATIONS=true in
# docker-compose.yml) - queue/scheduler containers share this same entrypoint
# but must not race the app container to apply migrations concurrently.
if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force
    php artisan storage:link || true
fi

exec "$@"
