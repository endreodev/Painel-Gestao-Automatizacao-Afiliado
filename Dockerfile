# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# ml-group em container
#
# PHP, Node e Chromium moram na MESMA imagem de proposito: o PHP inicia a ponte
# do WhatsApp (Node) e o navegador da coleta como processos filhos e conversa
# com os dois por 127.0.0.1. Separar em tres containers exigiria reescrever essa
# comunicacao - e, no caso da ponte, correr o risco de duas instancias abrirem a
# mesma sessao do WhatsApp, que e justamente o que derruba o pareamento.
# ---------------------------------------------------------------------------
FROM php:8.3-cli-bookworm

ARG TZ=America/Sao_Paulo
ENV TZ=${TZ} \
    DEBIAN_FRONTEND=noninteractive

# procps: o Sentinela confere se o processo do laco ainda existe
# libonig/libxml: so entram em jogo se a imagem base nao trouxer mbstring/dom
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        tzdata \
        procps \
        chromium \
        fonts-liberation \
        fonts-noto-color-emoji \
        libonig-dev \
        libxml2-dev; \
    ln -snf "/usr/share/zoneinfo/${TZ}" /etc/localtime; \
    echo "${TZ}" > /etc/timezone; \
    rm -rf /var/lib/apt/lists/*

# As extensoes que o bin/mlgroup exige. A imagem oficial ja traz quase todas
# compiladas; o laco so instala o que faltar, para nao gerar aviso de "modulo ja
# carregado". A conferencia no fim quebra o build agora, e nao no primeiro ciclo.
RUN set -eux; \
    for ext in pdo_sqlite mbstring dom curl pcntl; do \
        php -m | grep -qix "$ext" || docker-php-ext-install -j"$(nproc)" "$ext"; \
    done; \
    php -r 'foreach (["curl","pdo_sqlite","dom","mbstring","pcntl","posix"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "extensao ausente: $e\n"); exit(1); } } echo "extensoes ok\n";'

# Node 20+, exigido pelo Baileys. Vem da imagem oficial (mesma Debian) porque o
# repositorio do bookworm ainda entrega Node 18, que o Baileys recusa.
COPY --from=node:20-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:20-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN set -eux; \
    ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm; \
    node -v; \
    npm -v

COPY docker/php.ini "$PHP_INI_DIR/conf.d/mlgroup.ini"

WORKDIR /app

# As dependencias da ponte entram antes do resto do codigo: assim mexer no PHP
# nao invalida a camada do npm. O colchete no lock deixa o arquivo opcional -
# ele nao vai para o repositorio.
COPY ponte/package.json ponte/package-lock.jso[n] /app/ponte/
RUN cd /app/ponte && npm install --omit=dev --no-audit --no-fund

COPY . /app

RUN chmod +x /app/bin/mlgroup /app/docker/entrada.sh

# O navegador nao sobe como root com o sandbox ligado, e o sandbox do Chromium
# depende de chamadas que o Docker bloqueia por padrao.
ENV MLG_CHROME_BIN=/usr/bin/chromium \
    MLG_CHROME_ARGS="--no-sandbox --disable-setuid-sandbox" \
    PAINEL_HOST=0.0.0.0 \
    PAINEL_ORIGENS="10.0.0.0/8,172.16.0.0/12,192.168.0.0/16"

EXPOSE 8321

# Confere o painel de dentro do proprio container (nao ha curl na imagem).
HEALTHCHECK --interval=60s --timeout=10s --start-period=40s --retries=3 \
    CMD php -r '$s=@stream_socket_client("tcp://127.0.0.1:8321",$c,$e,3); exit($s?0:1);'

# chamado pelo bash, e nao direto: o bit de execucao nao sobrevive a um checkout
# do repositorio no Windows, e o container falharia com "permission denied"
ENTRYPOINT ["/bin/bash", "/app/docker/entrada.sh"]
CMD ["tudo"]
