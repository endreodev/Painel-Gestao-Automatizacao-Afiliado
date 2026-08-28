<?php

declare(strict_types=1);

namespace MlGroup\Analise;

use MlGroup\Model\Produto;
use MlGroup\Support\Config;

/**
 * Nota de 0 a 100 que decide a ordem de envio.
 *
 * Cada criterio vira um valor entre 0 e 1 e entra na media pelo peso de
 * config/config.php > pontuacao.pesos. Assim da para privilegiar comissao
 * (ganho) ou desconto (engajamento do grupo) sem mexer em codigo.
 */
final class Pontuador
{
    public function __construct(private readonly Nicho $nicho = new Nicho())
    {
    }

    public function pontuar(Produto $produto): float
    {
        $pesos = Config::lista('config.pontuacao.pesos');

        $criterios = [
            'desconto'   => $this->normalizarDesconto($produto),
            'comissao'   => $this->normalizarComissao($produto),
            'ganho'      => $this->normalizarGanho($produto),
            'reputacao'  => $this->normalizarReputacao($produto),
            'popularidade' => $this->normalizarPopularidade($produto),
            'logistica'  => $this->normalizarLogistica($produto),
            'historico'  => $produto->menorPrecoDeSempre() ? 1.0 : 0.0,
            'nicho'      => $this->nicho->relevancia($produto),
        ];

        $soma      = 0.0;
        $somaPesos = 0.0;

        foreach ($criterios as $nome => $valor) {
            $peso = (float) ($pesos[$nome] ?? 0);

            if ($peso <= 0) {
                continue;
            }

            $soma      += $valor * $peso;
            $somaPesos += $peso;
        }

        $nota = $somaPesos > 0 ? ($soma / $somaPesos) * 100 : 0.0;

        $produto->pontuacao = round(min(100.0, max(0.0, $nota)), 2);

        return $produto->pontuacao;
    }

    /** Desconto satura no teto configurado: 60% e 80% valem quase o mesmo. */
    private function normalizarDesconto(Produto $produto): float
    {
        $teto = Config::decimal('config.pontuacao.desconto_teto', 60.0);

        return $teto > 0 ? min(1.0, $produto->desconto() / $teto) : 0.0;
    }

    private function normalizarComissao(Produto $produto): float
    {
        $teto = Config::decimal('config.pontuacao.comissao_teto', 8.0);

        return $teto > 0 ? min(1.0, $produto->comissao / $teto) : 0.0;
    }

    private function normalizarGanho(Produto $produto): float
    {
        $teto = Config::decimal('config.pontuacao.ganho_teto', 40.0);

        return $teto > 0 ? min(1.0, $produto->ganhoEstimado / $teto) : 0.0;
    }

    /**
     * Nota media ponderada pela confianca: 5,0 com 3 avaliacoes vale menos que
     * 4,6 com 800. Sem avaliacao alguma, fica no meio da escala.
     */
    private function normalizarReputacao(Produto $produto): float
    {
        if ($produto->totalAvaliacoes <= 0 || $produto->avaliacao <= 0) {
            return 0.5;
        }

        $notaNormalizada = max(0.0, ($produto->avaliacao - 3.0) / 2.0);
        $confianca       = min(1.0, $produto->totalAvaliacoes / 200);

        return min(1.0, $notaNormalizada * (0.5 + (0.5 * $confianca)));
    }

    /** Escala log: 10 -> 100 vendidos pesa mais que 1000 -> 1090. */
    private function normalizarPopularidade(Produto $produto): float
    {
        if ($produto->vendidos <= 0) {
            return 0.0;
        }

        $teto = Config::decimal('config.pontuacao.vendidos_teto', 2000.0);

        if ($teto <= 1) {
            return 0.0;
        }

        return min(1.0, log10(1 + $produto->vendidos) / log10(1 + $teto));
    }

    private function normalizarLogistica(Produto $produto): float
    {
        $valor = 0.0;

        if ($produto->freteGratis) {
            $valor += 0.6;
        }

        if ($produto->full) {
            $valor += 0.4;
        }

        return min(1.0, $valor);
    }
}
