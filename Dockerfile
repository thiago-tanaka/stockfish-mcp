# syntax=docker/dockerfile:1

# A imagem carrega a aplicação e o motor. É a mesma em desenvolvimento e em produção:
# o que muda entre os dois é o compose que a usa, não o que está dentro dela.
#
# Duas escolhas não são negociáveis aqui:
#
#  - Debian, não Alpine. O binário oficial do Stockfish é linkado dinamicamente contra
#    glibc (libc.so.6, libm.so.6); em Alpine, que usa musl, ele nem carrega. O binário pede
#    no máximo GLIBC_2.35, e trixie traz 2.41 -- folga suficiente para não prender a imagem
#    a uma versão antiga de Debian só por causa do motor.
#  - O motor é baixado por arquitetura. O build roda em x86_64 e o servidor é ARM
#    (Graviton), então TARGETARCH -- preenchido pelo buildx -- decide qual binário entra.
#    Baixar o errado dá "Exec format error" só quando alguém chama uma ferramenta.

# ---------------------------------------------------------------------------
# Motor
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
    # Falha o build aqui, e não em produção, se o binário for da arquitetura errada.
    printf 'uci\nquit\n' | /usr/local/bin/stockfish | grep -q '^uciok$'

# ---------------------------------------------------------------------------
# Dependências PHP
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Só os manifestos primeiro: enquanto composer.lock não mudar, esta camada é reaproveitada
# e o install inteiro sai do cache.
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

# pdo_mysql e redis são o banco e o cache; pcntl é como o queue:work escuta os sinais de
# parada (sem ele um deploy mata o worker no meio de uma análise); opcache é desempenho.
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

# storage/ e bootstrap/cache são os dois lugares onde a aplicação escreve.
RUN chown -R www-data:www-data storage bootstrap/cache

# Ponto de montagem do volume que o Caddy lê. Precisa existir na imagem, e pertencer a
# www-data: um volume nomeado herda dono e permissão do diretório que cobre, e sobre um
# caminho inexistente nasceria como root, sem escrita para o processo.
RUN mkdir -p /srv/public && chown www-data:www-data /srv/public

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# O motor é o que mais custa aqui, então vale checar que ele responde e não só que o
# processo subiu.
HEALTHCHECK --interval=30s --timeout=10s --start-period=20s --retries=3 \
  CMD printf 'uci\nquit\n' | stockfish | grep -q '^uciok$' || exit 1

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
