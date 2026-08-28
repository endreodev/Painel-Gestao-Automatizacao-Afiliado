<?php

declare(strict_types=1);

namespace MlGroup\Afiliado;

use MlGroup\Model\Produto;
use MlGroup\Support\Config;
use MlGroup\Support\Str;

/**
 * Resolve o percentual de comissao de afiliado de um produto.
 *
 * O Mercado Livre nao publica a comissao por API - o valor sai da Central de
 * Afiliados e varia por categoria/campanha. Por isso a tabela fica em
 * config/comissoes.php e voce a mantem atualizada.
 *
 * Precedencia: categoria exata > palavra-chave no titulo > padrao.
 */
final class TabelaComissao
{
    public function percentual(Produto $produto): float
    {
        $porCategoria = Config::lista('comissoes.por_categoria');

        if ($produto->categoriaId !== '' && isset($porCategoria[$produto->categoriaId])) {
            return (float) $porCategoria[$produto->categoriaId];
        }

        $porPalavra = Config::lista('comissoes.por_palavra');
        $titulo     = Str::normalizar($produto->titulo);

        // palavra mais longa vence, para "serra circular" ganhar de "serra"
        $melhorPalavra = '';
        $melhorValor   = 0.0;

        foreach ($porPalavra as $palavra => $valor) {
            $palavraNormalizada = Str::normalizar((string) $palavra);

            if ($palavraNormalizada === '' || !str_contains($titulo, $palavraNormalizada)) {
                continue;
            }

            if (strlen($palavraNormalizada) > strlen($melhorPalavra)) {
                $melhorPalavra = $palavraNormalizada;
                $melhorValor   = (float) $valor;
            }
        }

        if ($melhorPalavra !== '') {
            return $melhorValor;
        }

        return Config::decimal('comissoes.padrao', 4.0);
    }

    /** Valor em reais que voce recebe, ja respeitando o teto por venda. */
    public function ganhoEstimado(Produto $produto, float $percentual): float
    {
        $ganho = $produto->preco * ($percentual / 100);
        $teto  = Config::decimal('comissoes.teto_por_venda', 0.0);

        if ($teto > 0 && $ganho > $teto) {
            $ganho = $teto;
        }

        return round($ganho, 2);
    }

    /** Aplica comissao e ganho no proprio produto. */
    public function aplicar(Produto $produto): Produto
    {
        $produto->comissao      = $this->percentual($produto);
        $produto->ganhoEstimado = $this->ganhoEstimado($produto, $produto->comissao);

        return $produto;
    }
}
