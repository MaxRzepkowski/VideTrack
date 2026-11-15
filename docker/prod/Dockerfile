# Base images pinned by digest, so a rebuild is the build that was tested;
# bump the tag and the digest together.
FROM dunglas/frankenphp:1-php8.4@sha256:08af88488ce218936d530b80f6f55dc2afe4d0d91f13f9e6ae350a9e7752128e AS base

COPY --from=mlocati/php-extension-installer@sha256:4fd45396c16685266d32bf147a24ecd5e81029d3820295895bf4a90131190ac9 /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor sqlite3 curl libcap2-bin \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_sqlite intl zip gd exif pcntl bcmath opcache

# Data imports (settings) accept files up to 50 MB; PHP's 2M/8M defaults
# would reject them before Laravel ever sees the request. The memory ceiling
# is raised for the same class of reason: a show like Tagesschau carries 21k
# episode rows, and its details page has no room to work under the 128M
# default.
RUN printf 'upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=256M\n' \
    > /usr/local/etc/php/conf.d/uploads.ini

FROM base AS vendor

COPY --from=composer:2@sha256:ca11f643d7791ebdd90a34fc20caf11380d434007ae539a9ea75cd9212e13dca /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction
COPY . .
# storage stays out of the build context; package discovery boots the app,
# which wants its skeleton. The front's sources stay in the stage that
# compiles them.
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --optimize \
    && rm -rf resources/js resources/css package.json package-lock.json vite.config.ts tsconfig.json

FROM node:22-alpine@sha256:0e1754758c2df4cfa3e68d59e2e1b7374bb7fd9ea6328eaaacf15cc934af8202 AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM base AS app

# The release tag the image was built from, shown on About.
ARG APP_VERSION=dev

# Defaults of a self-hosted instance; the operator's .env overrides any of
# them. Sessions and cache live in files, off the SQLite write lock the
# queue and the library share; the queue stays in the database (durable
# jobs). Logs go to stdout for `docker logs`. Thirty days of inactivity
# before a session ends, not Laravel's two hours.
ENV SERVER_NAME=:80 \
    APP_NAME=VideTrack \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_VERSION=${APP_VERSION} \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    SESSION_DRIVER=file \
    SESSION_LIFETIME=43200 \
    CACHE_STORE=file \
    QUEUE_CONNECTION=database \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    MAIL_MAILER=log

WORKDIR /app

COPY --from=vendor /app /app
COPY --from=frontend /app/public/build /app/public/build
COPY docker/prod/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/prod/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# The processes run as www-data (see supervisord.conf): FrankenPHP may still
# bind port 80, Caddy keeps its state in directories that user owns, and the
# public directory takes the storage symlink.
RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy \
        /app/storage/app/public /app/storage/framework/cache/data \
        /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs \
    # Not /app/public: the served directory stays the image's, so no write the
    # app makes can land a script there. The storage link is made at boot, as root.
    && chown -R www-data:www-data /data /config /app/storage /app/bootstrap/cache

EXPOSE 80

# `/up` is Laravel's health endpoint — catches "process alive but app 500s"
# (boot half-done, cache stale), which `restart: unless-stopped` alone misses.
# Starts checking after migrations/cache warm on first boot.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://localhost:80/up || exit 1

ENTRYPOINT ["entrypoint.sh"]
