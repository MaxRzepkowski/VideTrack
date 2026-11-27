#!/bin/sh
set -e

cd /app

if [ $# -gt 0 ]; then
    exec "$@"
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public storage/logs

# Everything below except the ownership fix runs as www-data, the user the
# processes run as: files it writes (cache, compiled views, the database)
# stay writable for them.
as_app() {
    su -s /bin/sh www-data -c "$*"
}

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_FILE="${DB_DATABASE:-/data/database.sqlite}"
    mkdir -p "$(dirname "$DB_FILE")"
    [ -f "$DB_FILE" ] || touch "$DB_FILE"
    # www-data owns the data volume, whoever created it.
    chown -R www-data:www-data "$(dirname "$DB_FILE")"

    # Online snapshot before migrating: a failed migration under `set -e` kills
    # the boot, and `restart: unless-stopped` would loop it — a pre-migration
    # backup gives a clean point to restore from. `.backup` is consistent even
    # while the queue/scheduler hold the write lock (unlike a plain `cp`). Keep
    # the five most recent.
    if [ -s "$DB_FILE" ]; then
        BACKUP_DIR="$(dirname "$DB_FILE")/backups"
        mkdir -p "$BACKUP_DIR"
        SNAPSHOT="$BACKUP_DIR/pre-migrate-$(date +%Y%m%d-%H%M%S).sqlite"
        if sqlite3 "$DB_FILE" ".backup '$SNAPSHOT'"; then
            ls -1t "$BACKUP_DIR"/pre-migrate-*.sqlite 2>/dev/null | tail -n +6 | while read -r old; do
                rm -f "$old"
            done
        else
            echo "entrypoint: pre-migration backup failed; aborting boot" >&2
            exit 1
        fi
    fi
fi

# The app key encrypts stored tokens and sessions. One the operator gives
# wins; otherwise the first start makes one and keeps it in the data volume,
# so it survives a new image and the operator has nothing to generate.
if [ -z "${APP_KEY:-}" ]; then
    KEY_FILE=/data/app.key
    if [ ! -s "$KEY_FILE" ]; then
        mkdir -p /data
        (umask 077 && php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEY_FILE")
        echo "entrypoint: generated an app key in $KEY_FILE; back it up with the database" >&2
    fi
    chown www-data:www-data "$KEY_FILE"
    chmod 600 "$KEY_FILE"
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

if [ -z "${TMDB_API_KEY:-}" ]; then
    echo "entrypoint: TMDB_API_KEY is not set; shows and movies need it (https://www.themoviedb.org/settings/api)" >&2
    exit 1
fi

# As root: public/ is not the app's to write (see the Dockerfile).
php artisan storage:link --force

chown -R www-data:www-data storage bootstrap/cache

as_app php artisan migrate --force
as_app php artisan app:ensure-admin

# Web push signs every message with a key pair: one the operator gives wins,
# otherwise the first start makes one next to the database. A new pair would
# leave every browser to be switched on again, so it is made only once.
as_app php artisan vide:webpush:keys

# The library projection is a shortcut of the play history, so working it out
# again is always safe: it heals what a crash left between a write and its
# projection, and brings back the titles of a module switched off and on
# again. It writes only what changed.
as_app php artisan vide:library:rebuild \
    || echo "entrypoint: library rebuild failed; run vide:library:rebuild once the cause is fixed" >&2

# After the rebuild, so the tables it fills get statistics too: a query plan
# picked without them can be slow on a large library.
as_app php artisan vide:database:optimize \
    || echo "entrypoint: refreshing the query planner statistics failed" >&2

# Recover any recurring runs missed while the container was down (a daily
# refresh does not wait for the next 04:00 after a late start). Never blocks
# boot — it only enqueues; the queue worker drains once supervisord is up.
as_app php artisan tasks:catch-up || true

as_app php artisan config:cache
as_app php artisan route:cache
as_app php artisan view:cache

exec supervisord -c /etc/supervisor/supervisord.conf
