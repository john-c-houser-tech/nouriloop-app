# syntax=docker/dockerfile:1
#
# Self-hosted image: FrankenPHP (PHP + the Caddy web server in one process, with
# automatic HTTPS from SERVER_NAME). The same image runs the web server and, with a
# different command, the scheduler (supercronic). See docs/self-hosting.md and
# compose.yaml.

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.4-trixie

FROM ${PHP_IMAGE} AS base

# HEIC photos (iPhone): heif-convert from libheif. Debian trixie's libheif is new
# enough for modern iPhone files; Ubuntu 24.04's isn't (see .claude/rules/meals.md).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libheif-examples \
 && rm -rf /var/lib/apt/lists/*

# exif: meal photos default to their capture time (ExifTimestampService); without it
# every library photo would silently be logged as "now".
RUN install-php-extensions intl pdo_mysql gd imagick sodium opcache zip exif

# supercronic: cron for containers (logs to stdout, runs without root).
ARG SUPERCRONIC_VERSION=v0.2.49
ARG TARGETARCH
RUN set -eux; \
    case "${TARGETARCH:-amd64}" in \
      amd64) sum=e63c11a9726b775a6a11801e81af4f3fb926aa68 ;; \
      arm64) sum=0b6c5bb743e0b0dafed1132198c81807927ac413 ;; \
      *) echo "unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    file="supercronic-linux-${TARGETARCH:-amd64}"; \
    curl -fsSLo /usr/local/bin/supercronic \
      "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/${file}"; \
    echo "${sum}  /usr/local/bin/supercronic" | sha1sum -c -; \
    chmod +x /usr/local/bin/supercronic

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-nouriloop.ini
RUN cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini

WORKDIR /app

# ---------------------------------------------------------------------------
FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV APP_ENV=prod COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY . .
RUN set -eux; \
    composer dump-autoload --no-dev --classmap-authoritative; \
    php bin/console importmap:install; \
    php bin/console assets:install public; \
    php bin/console asset-map:compile; \
    rm -rf var/cache/* var/log/*

# ---------------------------------------------------------------------------
FROM base AS app

ARG APP_USER=nouriloop
RUN set -eux; \
    useradd --create-home --shell /usr/sbin/nologin "${APP_USER}"; \
    setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp; \
    chown -R "${APP_USER}:${APP_USER}" /data/caddy /config/caddy

COPY --from=build --chown=${APP_USER}:${APP_USER} /app /app
RUN set -eux; \
    mkdir -p var/cache var/log public/uploads/meals public/uploads/supplements \
             public/uploads/favorites public/uploads/goal-charts; \
    chown -R "${APP_USER}:${APP_USER}" var public/uploads

ENV APP_ENV=prod APP_HOSTING_MODE=self
USER ${APP_USER}

COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/nouriloop-entrypoint
ENTRYPOINT ["nouriloop-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
