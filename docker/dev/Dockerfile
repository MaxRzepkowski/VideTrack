FROM php:8.4-cli-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache git sqlite nodejs npm \
    && install-php-extensions pdo_sqlite intl zip gd exif pcntl bcmath opcache pcov @composer

# Data imports (settings) accept files up to 50 MB; PHP's 2M/8M defaults
# would reject them before Laravel ever sees the request. The memory ceiling
# is raised for the same class of reason: a show like Tagesschau carries 21k
# episode rows, and its details page has no room to work under the 128M
# default.
RUN printf 'upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=256M\n' \
    > /usr/local/etc/php/conf.d/uploads.ini

# Coverage for the test suite: pcov stays off and is switched on only for the
# coverage run (php -d pcov.enabled=1), so the app itself never pays for it.
RUN printf 'pcov.enabled=0\n' > /usr/local/etc/php/conf.d/pcov.ini

ENV COMPOSER_HOME=/tmp/composer \
    npm_config_cache=/tmp/npm

WORKDIR /app
