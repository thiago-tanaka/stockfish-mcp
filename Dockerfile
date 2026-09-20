# syntax=docker/dockerfile:1

# The image carries the application and the engine. It is the same one in development and in
# production: what differs between them is the compose file that uses it, not its contents.
#
# Two choices here are not negotiable:
#
#  - Debian, not Alpine. The official Stockfish binary is dynamically linked against glibc
#    (libc.so.6, libm.so.6) and will not even load under musl. It asks for at most
#    GLIBC_2.35, and trixie ships 2.41 -- enough headroom that the engine does not pin the
#    image to an old Debian.
#  - The engine is fetched per architecture. Builds happen on x86_64 and the server is ARM
#    (Graviton), so TARGETARCH -- filled in by buildx -- decides which binary goes in.
#    Getting it wrong surfaces as "Exec format error" only when a tool is called.

# ---------------------------------------------------------------------------
# Engine
# ---------------------------------------------------------------------------
FROM debian:trixie-slim AS engine

ARG TARGETARCH
ARG STOCKFISH_VERSION=sf_19

RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl \
 && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    case "${TARGETARCH}" in \
      amd64) SF_ARCH='x86-64' ;; \
      arm64) SF_ARCH='arm64'  ;; \
      *) echo "Unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/stockfish.tar.gz \
      "https://github.com/official-stockfish/Stockfish/releases/download/${STOCKFISH_VERSION}/stockfish-linux-${SF_ARCH}-universal.tar.gz"; \
    tar xzf /tmp/stockfish.tar.gz -C /tmp; \
    install -m 755 "/tmp/stockfish/stockfish-linux-${SF_ARCH}-universal" /usr/local/bin/stockfish; \
    # Greet the engine here, so a binary for the wrong architecture fails the build rather
    # than production.
    printf 'uci\nquit\n' | /usr/local/bin/stockfish | grep -q '^uciok$'

# ---------------------------------------------------------------------------
# PHP dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Manifests first: while composer.lock is unchanged this layer is reused and the whole
# install comes from cache.
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev --no-scripts --no-autoloader \
      --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---------------------------------------------------------------------------
# Runtime
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-trixie AS app

# pdo_mysql and redis are the database and the cache; pcntl is how queue:work hears the stop
# signals, without which a deploy kills a worker mid-analysis; opcache is throughput.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl opcache; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY --from=engine /usr/local/bin/stockfish /usr/local/bin/stockfish

WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /app .

# storage/ and bootstrap/cache are the two places the application writes to.
RUN chown -R www-data:www-data storage bootstrap/cache

# Mount point for the volume Caddy reads. It has to exist in the image, and belong to
# www-data: a named volume inherits the owner and mode of the directory it covers, and over
# a path that does not exist it would be created as root, with nothing writable.
RUN mkdir -p /srv/public && chown www-data:www-data /srv/public

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# The engine is the expensive part, so health means it answers -- not merely that the
# process started.
HEALTHCHECK --interval=30s --timeout=10s --start-period=20s --retries=3 \
  CMD printf 'uci\nquit\n' | stockfish | grep -q '^uciok$' || exit 1

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
