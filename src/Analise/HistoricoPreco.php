<?php

declare(strict_types=1);

namespace MlGroup\Analise;

use MlGroup\Database\Db;
use MlGroup\Model\Produto;
use MlGroup\Support\Config;

/**
 * Guarda a serie de precos de cada anuncio.
 *
 * Serve para duas coisas: marcar "menor preco ja visto" na mensagem e derrubar
 * o desconto falso - aquele em que a loja sobe o "de" e mantem o "por".
 */
final class HistoricoPreco
{
    public function registrar(Produto $produto): void
    {
        $ultimo = Db::valor(
            'SELECT preco FROM historico_precos WHERE ml_id = :id ORDER BY capturado_em DESC LIMIT 1',
            ['id' => $produto->mlId],
        );

        // so grava quando o preco muda, para o historico nao virar log de coleta
        if ($ultimo !== null && abs((float) $ultimo - $produto->preco) < 0.01) {
            return;
        }

        Db::executar(
            'INSERT INTO historico_precos (ml_id, preco, capturado_em) VALUES (:id, :preco, :agora)',
            [
                'id'    => $produto->mlId,
                'preco' => $produto->preco,
                'agora' => date('Y-m-d H:i:s'),
            ],
        );

        $this->podar($produto->mlId);
    }

    /** Menor preco registrado nos ultimos N dias (0 quando nao ha historico). */
    public function menorPreco(string $mlId): float
    {
        $dias  = Config::inteiro('config.analise.historico_dias', 90);
        $corte = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days') ?: time());

        $valor = Db::valor(
            'SELECT MIN(preco) FROM historico_precos WHERE ml_id = :id AND capturado_em >= :corte',
            ['id' => $mlId, 'corte' => $corte],
        );

        return $valor === null ? 0.0 : (float) $valor;
    }

    /** Preco tipico (mediana) do anuncio no periodo analisado. */
    public function precoTipico(string $mlId): float
    {
        $dias  = Config::inteiro('config.analise.historico_dias', 90);
        $corte = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days') ?: time());

        $linhas = Db::todos(
            'SELECT preco FROM historico_precos WHERE ml_id = :id AND capturado_em >= :corte ORDER BY preco',
            ['id' => $mlId, 'corte' => $corte],
        );

        if ($linhas === []) {
            return 0.0;
        }

        $precos = array_map(static fn (array $linha): float => (float) $linha['preco'], $linhas);
        $meio   = intdiv(count($precos), 2);

        return count($precos) % 2 === 1
            ? $precos[$meio]
            : round(($precos[$meio - 1] + $precos[$meio]) / 2, 2);
    }

    /**
     * Desconto falso: o "de" anunciado esta muito acima do preco que o anuncio
     * realmente praticou nos ultimos dias.
     */
    public function descontoSuspeito(Produto $produto): bool
    {
        if (!Config::booleano('config.analise.detectar_desconto_falso', true)) {
            return false;
        }

        $tipico = $this->precoTipico($produto->mlId);

        // sem historico suficiente nao da para acusar nada
        if ($tipico <= 0 || $this->quantidadeRegistros($produto->mlId) < 3) {
            return false;
        }

        $tolerancia = Config::decimal('config.analise.tolerancia_preco_inflado', 1.15);

        return $produto->precoOriginal > ($tipico * $tolerancia)
            && $produto->preco >= ($tipico * 0.97);
    }

    /** Carrega no produto o menor preco historico, para uso na mensagem. */
    public function enriquecer(Produto $produto): Produto
    {
        $produto->menorPrecoHistorico = $this->menorPreco($produto->mlId);

        return $produto;
    }

    private function quantidadeRegistros(string $mlId): int
    {
        return (int) Db::valor(
            'SELECT COUNT(*) FROM historico_precos WHERE ml_id = :id',
            ['id' => $mlId],
        );
    }

    /** Mantem a tabela enxuta: guarda apenas os ultimos N pontos por anuncio. */
    private function podar(string $mlId): void
    {
        // LIMIT interpolado: PDO/SQLite recusa placeholder ligado como string aqui,
        // e o valor vem da config ja convertido para int.
        $maximo = max(5, Config::inteiro('config.analise.historico_max_pontos', 60));

        Db::executar(
            'DELETE FROM historico_precos
              WHERE ml_id = :id
                AND id NOT IN (
                    SELECT id FROM historico_precos
                     WHERE ml_id = :id2
                     ORDER BY capturado_em DESC
                     LIMIT ' . $maximo . '
                )',
            ['id' => $mlId, 'id2' => $mlId],
        );
    }
}
