#!/bin/sh
# Preparo antes de entregar o controle ao php-fpm ou ao worker.
#
# O que roda aqui roda a cada start do container, então tudo precisa ser idempotente:
# um container reiniciado pelo Docker tem de chegar ao mesmo estado de um recém-criado.
set -e

# O banco sobe em paralelo, e o depends_on do compose só garante a ordem de partida, não
# que o MySQL já aceite conexão. Sem esta espera, o primeiro start depois de um `up` falha.
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

# Só o serviço que declara RUN_MIGRATIONS migra. Se app e worker migrassem juntos, duas
# conexões correriam a mesma migration ao mesmo tempo.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

# Em produção o cache de config e rotas vale muito; em desenvolvimento ele esconde toda
# alteração de .env e de rota atrás de um clear manual.
if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
else
    php artisan config:clear
    php artisan route:clear
fi

# public/ vive na imagem, mas quem o serve é o container do Caddy. Copiar a cada start
# mantém os dois em dia sem que o Caddy precise de uma imagem própria a cada deploy.
# Em desenvolvimento o Caddy monta ./public do host e este diretório não é gravável pelo
# UID do host; ali a cópia é desnecessária, e testar por -w é o que distingue os dois casos
# sem precisar de uma variável a mais.
if [ -d /srv/public ] && [ -w /srv/public ]; then
    cp -a /var/www/html/public/. /srv/public/
fi

# O motor é a dependência que mais silenciosamente quebra (arquitetura errada, binário
# ausente). Melhor gritar no start do que devolver erro na primeira ferramenta chamada.
php artisan chess:engine

exec "$@"
