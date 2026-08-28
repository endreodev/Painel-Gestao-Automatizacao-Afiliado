<?php

declare(strict_types=1);

namespace MlGroup\App;

use MlGroup\Analise\Diversidade;
use MlGroup\Analise\Filtro;
use MlGroup\Database\Db;
use MlGroup\Model\Produto;
use MlGroup\Support\Config;
use MlGroup\Support\Logger;

/**
 * Fila de ofertas aprovadas esperando a vez de ir para o grupo.
 *
 * Existe para separar o ritmo da coleta do ritmo da publicacao. Publicar de 10
 * em 10 minutos nao pode significar varrer o Mercado Livre de 10 em 10 minutos:
 * isso derruba as buscas no anti-bot em pouco tempo. Entao a coleta enche a
 * fila de vez em quando e cada ciclo apenas tira o proximo da fila.
 */
final class Fila
{
    public function __construct(
        private readonly Filtro $filtro = new Filtro(),
        private readonly Diversidade $diversidade = new Diversidade(),
    ) {
    }

    /**
     * Proximos da fila, do melhor para o pior.
     *
     * Descarta o que ja foi publicado (por ID ou por assinatura), o que passou
     * da validade e as variacoes repetidas do mesmo produto.
     *
     * @return Produto[]
     */
    public function proximos(int $quantidade, bool $silencioso = false): array
    {
        $validade = date(
            'Y-m-d H:i:s',
            strtotime('-' . Config::inteiro('config.envio.validade_horas', 12) . ' hours') ?: time(),
        );

        $corteEnvio = date(
            'Y-m-d H:i:s',
            strtotime('-' . Config::inteiro('config.filtros.dias_sem_repetir', 7) . ' days') ?: time(),
        );

        /*
         * A prioridade vem antes da pontuacao: e o "furar a fila" do painel.
         * Vale mais que a validade tambem - quem escolheu um produto a mao nao
         * deveria perde-lo porque a coleta seguinte demorou e ele "envelheceu".
         * O que a prioridade NAO dispensa e o filtro e o ja-publicado: furar a
         * fila e escolher a ordem, nao ignorar as regras.
         */
        $linhas = Db::todos(
            "SELECT p.*
               FROM produtos p
              WHERE p.canal = :canal
                AND (p.prioridade > 0 OR p.atualizado_em >= :validade)
                AND p.pontuacao > 0
                AND NOT EXISTS (
                    SELECT 1 FROM envios e
                     WHERE e.status = 'enviado'
                       AND e.canal = :canal2
                       AND e.enviado_em >= :corte
                       AND (e.ml_id = p.ml_id OR e.assinatura = p.assinatura)
                )
              ORDER BY p.prioridade DESC, p.pontuacao DESC, p.atualizado_em DESC",
            [
                'canal'    => $this->canal(),
                'canal2'   => $this->canal(),
                'validade' => $validade,
                'corte'    => $corteEnvio,
            ],
        );

        $produtos  = [];
        $vistas    = [];
        $adiados   = [];
        $historico = $this->historicoRecente();

        foreach ($linhas as $linha) {
            $assinatura = (string) ($linha['assinatura'] ?? '');

            // duas variacoes do mesmo produto ainda na fila: so a melhor vai
            if ($assinatura !== '' && isset($vistas[$assinatura])) {
                continue;
            }

            $produto = Produto::doBanco($linha);

            /*
             * A fila guarda o que foi aprovado quando a coleta rodou, e as
             * regras podem ter mudado desde entao - troca de nicho, teto de
             * preco novo, comissao minima diferente. Vale a regra de agora, e
             * nao a de quando o produto entrou, entao o filtro roda inteiro em
             * vez de so um pedaco dele.
             */
            $motivo = $this->filtro->reprovar($produto);

            if ($motivo !== null) {
                continue;
            }

            $vistas[$assinatura] = true;

            /*
             * Diversidade: a fila vem ordenada por pontuacao, e produto do mesmo
             * tipo pontua parecido - sem esta regra saem cinco parafusadeiras
             * seguidas. Quem e adiado nao e descartado: volta na segunda passada
             * caso nao haja variedade suficiente para preencher o lote.
             *
             * Quem furou a fila passa direto: adiar por diversidade um produto
             * que o usuario acabou de escolher a mao seria desfazer a escolha
             * dele sem dizer nada.
             */
            $motivo = ((int) ($linha['prioridade'] ?? 0)) > 0
                ? null
                : $this->diversidade->adiar($produto, $historico);

            if ($motivo !== null) {
                $adiados[] = $produto;

                continue;
            }

            array_unshift($historico, $this->diversidade->assinaturaDe($produto));

            $produtos[] = $produto;

            if (count($produtos) >= $quantidade) {
                return $produtos;
            }
        }

        return $this->completarComAdiados($produtos, $adiados, $quantidade, $silencioso);
    }

    /**
     * Quando a variedade acaba antes do lote, publica o que foi adiado.
     *
     * Ficar em silencio seria pior: a fila pode ser inteira de um tipo so, e
     * nesse caso nao ha diversidade a ser criada - so a ordem a ser espalhada.
     *
     * @param  Produto[] $escolhidos
     * @param  Produto[] $adiados
     * @return Produto[]
     */
    private function completarComAdiados(
        array $escolhidos,
        array $adiados,
        int $quantidade,
        bool $silencioso = false,
    ): array {
        if ($adiados === [] || count($escolhidos) >= $quantidade) {
            return $escolhidos;
        }

        if (!Config::booleano('config.diversidade.relaxar_se_esgotar', true)) {
            return $escolhidos;
        }

        $faltam = $quantidade - count($escolhidos);

        // contar a fila nao publica nada: avisar ali seria mentira no log
        if (!$silencioso) {
            Logger::i()->info('Variedade insuficiente na fila, publicando repetido', [
                'faltavam' => $faltam,
                'adiados'  => count($adiados),
            ]);
        }

        return array_merge($escolhidos, array_slice($adiados, 0, $faltam));
    }

    /**
     * Tipo e marca dos ultimos envios, do mais novo para o mais antigo.
     *
     * Calculado a partir do titulo, e nao lido de uma coluna: as listas de nicho
     * e de marcas mudam, e um valor gravado no passado responderia pela regra
     * antiga.
     *
     * @return array<int,array{tipo:string,marca:string}>
     */
    private function historicoRecente(): array
    {
        $janela = $this->diversidade->janela();

        if (!$this->diversidade->ativa() || $janela <= 0) {
            return [];
        }

        $linhas = Db::todos(
            "SELECT p.*
               FROM envios e
               JOIN produtos p ON p.ml_id = e.ml_id AND p.canal = e.canal
              WHERE e.status = 'enviado'
                AND e.canal = :canal
              ORDER BY e.id DESC
              LIMIT " . $janela,
            ['canal' => $this->canal()],
        );

        return array_map(
            fn (array $linha): array => $this->diversidade->assinaturaDe(Produto::doBanco($linha)),
            $linhas,
        );
    }

    /** O canal em uso; 'padrao' quando o sistema roda sem canais. */
    private function canal(): string
    {
        return Canal::ativo()?->id() ?? 'padrao';
    }

    /** Quantas ofertas ainda ha para publicar. */
    public function tamanho(): int
    {
        return count($this->proximos(500, true));
    }

    /** Passou tempo suficiente desde a ultima coleta bem-sucedida? */
    public function precisaColetar(): bool
    {
        $intervalo = Config::inteiro('config.coleta.intervalo_minutos', 60);

        if ($intervalo <= 0) {
            return true;
        }

        $ultima = Db::valor(
            "SELECT MAX(iniciado_em) FROM execucoes
              WHERE status = 'ok' AND coletados > 0 AND canal = :canal",
            ['canal' => $this->canal()]
        );

        if ($ultima === null) {
            return true;
        }

        $limite = strtotime((string) $ultima) + ($intervalo * 60);

        if (time() >= $limite) {
            return true;
        }

        // fila vazia antes da hora: nao adianta esperar, nao ha o que publicar
        return $this->tamanho() === 0;
    }

    /**
     * Manda o produto para o inicio da fila.
     *
     * A prioridade e um contador crescente, e nao um "sim/nao": furando dois
     * produtos, o ultimo escolhido fica na frente. Um booleano faria o segundo
     * clique empatar com o primeiro e a ordem entre eles voltaria a ser decidida
     * pela pontuacao - o oposto do que quem clicou pediu.
     *
     * Devolve a posicao em que o produto ficou, ou null se ele nao esta neste
     * canal.
     */
    public function furar(string $mlId): ?int
    {
        $existe = Db::valor(
            'SELECT 1 FROM produtos WHERE canal = :canal AND ml_id = :ml_id',
            ['canal' => $this->canal(), 'ml_id' => $mlId],
        );

        if ($existe === null || $existe === false) {
            return null;
        }

        $topo = (int) (Db::valor(
            'SELECT MAX(prioridade) FROM produtos WHERE canal = :canal',
            ['canal' => $this->canal()],
        ) ?? 0);

        Db::executar(
            'UPDATE produtos SET prioridade = :prioridade WHERE canal = :canal AND ml_id = :ml_id',
            ['prioridade' => $topo + 1, 'canal' => $this->canal(), 'ml_id' => $mlId],
        );

        return 1;
    }

    /** Devolve o produto a ordem normal, pela pontuacao. */
    public function liberar(string $mlId): bool
    {
        $afetadas = Db::executar(
            'UPDATE produtos SET prioridade = 0
              WHERE canal = :canal AND ml_id = :ml_id AND prioridade > 0',
            ['canal' => $this->canal(), 'ml_id' => $mlId],
        )->rowCount();

        return $afetadas > 0;
    }

    /**
     * Os produtos que furaram a fila, do primeiro para o ultimo.
     *
     * @return string[] ml_id
     */
    public function furados(): array
    {
        $linhas = Db::todos(
            'SELECT ml_id FROM produtos
              WHERE canal = :canal AND prioridade > 0
              ORDER BY prioridade DESC',
            ['canal' => $this->canal()],
        );

        return array_map(static fn (array $linha): string => (string) $linha['ml_id'], $linhas);
    }

    /**
     * Tira a prioridade de quem ja foi publicado.
     *
     * Sem isso o contador so cresce: um produto publicado ha uma semana
     * continuaria com prioridade alta e, se voltasse a ser coletado depois da
     * janela de nao-repetir, furaria a fila de novo sozinho - sem ninguem ter
     * clicado.
     */
    public function limparPrioridadesPublicadas(): int
    {
        return Db::executar(
            "UPDATE produtos SET prioridade = 0
              WHERE canal = :canal
                AND prioridade > 0
                AND EXISTS (
                    SELECT 1 FROM envios e
                     WHERE e.status = 'enviado'
                       AND e.canal = :canal2
                       AND e.ml_id = produtos.ml_id
                )",
            ['canal' => $this->canal(), 'canal2' => $this->canal()],
        )->rowCount();
    }
}
