#!/usr/bin/env bash
#
# Ponto de entrada do container.
#
#   tudo      (padrao) painel + laco de publicacao, no mesmo container
#   painel    so o painel web
#   rodar     so o laco de publicacao
#   importar  copia /importar para dentro de storage/ e sai
#   <resto>   qualquer comando do bin/mlgroup: conectar, grupos, status, ciclo...
#
set -euo pipefail

cd /app

# ---------------------------------------------------------------------------
# Importacao: roda antes de qualquer preparo porque o container e descartavel e
# nao deve criar .env nem tocar em mais nada.
# ---------------------------------------------------------------------------
if [ "${1:-tudo}" = "importar" ]; then
    if [ ! -d /importar ]; then
        echo "  nada para importar: /importar nao foi montado" >&2
        exit 1
    fi

    # -a preserva data e permissao; o ponto no fim leva tambem os ocultos
    cp -a /importar/. /app/storage/

    echo "  storage importado:"
    ls -la /app/storage
    exit 0
fi

# ---------------------------------------------------------------------------
# Preparo
# ---------------------------------------------------------------------------
# o volume comeca vazio na primeira subida
mkdir -p storage/logs storage/cache storage/pulso storage/whatsapp-sessao

if [ ! -f .env ]; then
    echo "  .env ausente - criando a partir do .env.example"
    cp .env.example .env
fi

# A imagem ja instala ponte/node_modules. Este ramo cobre o primeiro start com o
# volume nomeado ainda vazio e a troca de versao do package.json.
if [ ! -d ponte/node_modules/@whiskeysockets/baileys ]; then
    echo "  instalando dependencias da ponte (so desta vez)..."
    ( cd ponte && npm install --omit=dev --no-audit --no-fund )
fi

# ---------------------------------------------------------------------------
# O que rodar
# ---------------------------------------------------------------------------
comando="${1:-tudo}"

case "$comando" in
    tudo)
        painel_pid=0
        laco_pid=0

        encerrar() {
            # o laco fecha o ciclo em andamento ao receber o TERM; sem isto o
            # docker stop cortaria no meio de um envio
            [ "$laco_pid" -gt 0 ] && kill "$laco_pid" 2>/dev/null || true
            [ "$painel_pid" -gt 0 ] && kill "$painel_pid" 2>/dev/null || true
            wait || true
        }

        trap encerrar INT TERM

        php bin/mlgroup painel --sem-navegador &
        painel_pid=$!

        php bin/mlgroup rodar &
        laco_pid=$!

        echo "  painel (pid $painel_pid) e laco (pid $laco_pid) no ar"

        # se qualquer um dos dois cair, o container cai junto: o restart do
        # compose sobe os dois de novo, o que e melhor que ficar pela metade
        codigo=0
        wait -n || codigo=$?

        encerrar

        exit "$codigo"
        ;;

    painel)
        exec php bin/mlgroup painel --sem-navegador
        ;;

    bash|sh)
        shift
        exec /bin/bash "$@"
        ;;

    *)
        exec php bin/mlgroup "$@"
        ;;
esac
