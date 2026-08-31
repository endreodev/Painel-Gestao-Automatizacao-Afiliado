<?php

declare(strict_types=1);

namespace MlGroup\Database;

use PDO;

/**
 * Cria/atualiza o schema. Roda sozinha na primeira conexao.
 */
final class Migracoes
{
    public static function executar(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS produtos (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                ml_id             TEXT    NOT NULL UNIQUE,
                titulo            TEXT    NOT NULL,
                permalink         TEXT    NOT NULL,
                thumb             TEXT,
                categoria_id      TEXT,
                categoria_nome    TEXT,
                marca             TEXT,
                vendedor          TEXT,
                assinatura        TEXT,
                preco             REAL    NOT NULL DEFAULT 0,
                preco_original    REAL    NOT NULL DEFAULT 0,
                desconto          REAL    NOT NULL DEFAULT 0,
                comissao          REAL    NOT NULL DEFAULT 0,
                ganho_estimado    REAL    NOT NULL DEFAULT 0,
                frete_gratis      INTEGER NOT NULL DEFAULT 0,
                full              INTEGER NOT NULL DEFAULT 0,
                vendidos          INTEGER NOT NULL DEFAULT 0,
                avaliacao         REAL    NOT NULL DEFAULT 0,
                total_avaliacoes  INTEGER NOT NULL DEFAULT 0,
                pontuacao         REAL    NOT NULL DEFAULT 0,
                origem            TEXT    NOT NULL DEFAULT 'api',
                criado_em         TEXT    NOT NULL,
                atualizado_em     TEXT    NOT NULL
            )
        SQL);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_produtos_pontuacao ON produtos (pontuacao DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_produtos_atualizado ON produtos (atualizado_em)');

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS historico_precos (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                ml_id      TEXT NOT NULL,
                preco      REAL NOT NULL,
                capturado_em TEXT NOT NULL
            )
        SQL);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hist_ml_id ON historico_precos (ml_id, capturado_em DESC)');

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envios (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                ml_id       TEXT NOT NULL,
                assinatura  TEXT,
                grupo       TEXT NOT NULL,
                preco       REAL NOT NULL DEFAULT 0,
                desconto    REAL NOT NULL DEFAULT 0,
                pontuacao   REAL NOT NULL DEFAULT 0,
                mensagem    TEXT,
                status      TEXT NOT NULL DEFAULT 'enviado',
                erro        TEXT,
                enviado_em  TEXT NOT NULL
            )
        SQL);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_envios_ml_id ON envios (ml_id, enviado_em DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_envios_data ON envios (enviado_em DESC)');

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS links_afiliado (
                chave       TEXT NOT NULL PRIMARY KEY,
                ml_id       TEXT,
                url_produto TEXT NOT NULL,
                link        TEXT NOT NULL,
                criado_em   TEXT NOT NULL
            )
        SQL);

        /*
         | Criada aqui, junto das outras tabelas, e nao no fim do metodo.
         |
         | Estava depois do bloco de garantirColuna() que acrescenta 'canal' -
         | e num banco novo aquele ALTER TABLE caia numa tabela que ainda nao
         | existia, derrubando a migracao inteira. Nunca aparecia em quem ja
         | rodava o sistema (a tabela vinha de uma versao anterior), so numa
         | instalacao do zero: o caso que ninguem testa.
         */
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS execucoes (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                canal        TEXT NOT NULL DEFAULT 'padrao',
                iniciado_em  TEXT NOT NULL,
                encerrado_em TEXT,
                coletados    INTEGER NOT NULL DEFAULT 0,
                aprovados    INTEGER NOT NULL DEFAULT 0,
                enviados     INTEGER NOT NULL DEFAULT 0,
                status       TEXT NOT NULL DEFAULT 'rodando',
                detalhe      TEXT
            )
        SQL);

        // bancos criados antes da regra de nao repetir produto de catalogo
        self::garantirColuna($pdo, 'produtos', 'assinatura', 'TEXT');
        self::garantirColuna($pdo, 'envios', 'assinatura', 'TEXT');

        // bancos criados antes dos canais
        self::garantirColuna($pdo, 'produtos', 'canal', "TEXT NOT NULL DEFAULT 'padrao'");
        self::garantirColuna($pdo, 'envios', 'canal', "TEXT NOT NULL DEFAULT 'padrao'");
        self::garantirColuna($pdo, 'execucoes', 'canal', "TEXT NOT NULL DEFAULT 'padrao'");

        // bancos criados antes do "furar a fila"
        self::garantirColuna($pdo, 'produtos', 'prioridade', 'INTEGER NOT NULL DEFAULT 0');

        /*
         | Bancos criados antes da Shopee.
         |
         | 'loja' separa a origem do produto: o link do Mercado Livre e montado
         | por template na hora de publicar, o da Shopee ja vem pronto da API e
         | nao pode ser remontado.
         |
         | 'link_afiliado' existe por causa disso: o produto que sai da fila e
         | reconstruido do banco, e um link que so vivia em memoria se perdia no
         | caminho - a oferta ia para o grupo sem rastreio.
         */
        self::garantirColuna($pdo, 'produtos', 'loja', "TEXT NOT NULL DEFAULT 'ml'");
        self::garantirColuna($pdo, 'produtos', 'link_afiliado', "TEXT NOT NULL DEFAULT ''");

        /*
         | O que o usuario mandou nao aparecer mais: um anuncio, uma marca ou um
         | vendedor. Por canal - a mesma marca pode incomodar num grupo e ser
         | irrelevante no outro.
         */
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS descartes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                canal      TEXT NOT NULL DEFAULT 'padrao',
                tipo       TEXT NOT NULL,
                valor      TEXT NOT NULL,
                rotulo     TEXT,
                criado_em  TEXT NOT NULL,
                UNIQUE (canal, tipo, valor)
            )
        SQL);

        self::chaveDeProdutoPorCanal($pdo);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_produtos_canal ON produtos (canal, pontuacao DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_envios_canal ON envios (canal, enviado_em DESC)');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_produtos_assinatura ON produtos (assinatura)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_envios_assinatura ON envios (assinatura, enviado_em DESC)');

    }

    /**
     * Troca a unicidade de ml_id para (canal, ml_id).
     *
     * O mesmo anuncio pode interessar a dois canais - uma furadeira serve tanto
     * ao grupo de ferramentas quanto a um de utilidades. Com ml_id unico, o
     * segundo canal sobrescrevia a linha do primeiro e o produto ficava
     * pulando de grupo a cada coleta.
     *
     * O SQLite nao altera constraint: a tabela e recriada e os dados copiados.
     */
    private static function chaveDeProdutoPorCanal(PDO $pdo): void
    {
        /*
         * A verificacao e na definicao da TABELA, nao nos indices.
         *
         * Um UNIQUE declarado dentro do CREATE TABLE vira um indice automatico
         * (sqlite_autoindex_produtos_1) cujo 'sql' no sqlite_master e NULL - nao
         * ha texto nenhum para procurar "UNIQUE" e "canal". Procurando ali, a
         * guarda nunca reconhecia a migracao como feita e a tabela inteira era
         * recriada a cada conexao: toda pagina do painel, todo comando de CLI,
         * todo ciclo de publicacao copiavam 887 linhas para uma tabela nova.
         */
        $definicao = (string) ($pdo->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'produtos'",
        )->fetchColumn() ?: '');

        if (preg_match('/UNIQUE\s*\(\s*canal\s*,\s*ml_id\s*\)/i', $definicao) === 1) {
            return;
        }

        $pdo->exec('DROP INDEX IF EXISTS idx_produtos_canal_ml_id');

        // a unicidade original veio da coluna declarada UNIQUE, entao a tabela
        // precisa ser refeita sem ela
        $colunas = $pdo->query('PRAGMA table_info(produtos)')->fetchAll();
        $nomes   = array_map(static fn (array $c): string => (string) $c['name'], $colunas);
        $lista   = implode(', ', $nomes);

        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();

        $pdo->exec('ALTER TABLE produtos RENAME TO produtos_antiga');

        $pdo->exec(<<<'SQL'
            CREATE TABLE produtos (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                canal             TEXT    NOT NULL DEFAULT 'padrao',
                ml_id             TEXT    NOT NULL,
                assinatura        TEXT,
                titulo            TEXT    NOT NULL,
                permalink         TEXT    NOT NULL,
                thumb             TEXT,
                categoria_id      TEXT,
                categoria_nome    TEXT,
                marca             TEXT,
                vendedor          TEXT,
                preco             REAL    NOT NULL DEFAULT 0,
                preco_original    REAL    NOT NULL DEFAULT 0,
                desconto          REAL    NOT NULL DEFAULT 0,
                comissao          REAL    NOT NULL DEFAULT 0,
                ganho_estimado    REAL    NOT NULL DEFAULT 0,
                frete_gratis      INTEGER NOT NULL DEFAULT 0,
                full              INTEGER NOT NULL DEFAULT 0,
                vendidos          INTEGER NOT NULL DEFAULT 0,
                avaliacao         REAL    NOT NULL DEFAULT 0,
                total_avaliacoes  INTEGER NOT NULL DEFAULT 0,
                pontuacao         REAL    NOT NULL DEFAULT 0,
                prioridade        INTEGER NOT NULL DEFAULT 0,
                origem            TEXT    NOT NULL DEFAULT 'api',
                loja              TEXT    NOT NULL DEFAULT 'ml',
                link_afiliado     TEXT    NOT NULL DEFAULT '',
                criado_em         TEXT    NOT NULL,
                atualizado_em     TEXT    NOT NULL,
                UNIQUE (canal, ml_id)
            )
        SQL);

        $pdo->exec('INSERT INTO produtos (' . $lista . ') SELECT ' . $lista . ' FROM produtos_antiga');
        $pdo->exec('DROP TABLE produtos_antiga');

        $pdo->commit();
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    /** ALTER TABLE ... ADD COLUMN nao aceita IF NOT EXISTS no SQLite. */
    private static function garantirColuna(PDO $pdo, string $tabela, string $coluna, string $tipo): void
    {
        $existentes = $pdo->query('PRAGMA table_info(' . $tabela . ')')->fetchAll();

        /*
         * Tabela que ainda nao existe nao tem coluna a acrescentar - ela vai
         * nascer ja com a coluna certa. Sem esta saida, PRAGMA devolve lista
         * vazia e o codigo seguia para um ALTER TABLE numa tabela inexistente,
         * derrubando a migracao. Foi assim que uma instalacao nova quebrava.
         */
        if ($existentes === []) {
            return;
        }

        foreach ($existentes as $campo) {
            if (($campo['name'] ?? '') === $coluna) {
                return;
            }
        }

        $pdo->exec('ALTER TABLE ' . $tabela . ' ADD COLUMN ' . $coluna . ' ' . $tipo);
    }
}
