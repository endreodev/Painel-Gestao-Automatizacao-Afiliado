<?php

declare(strict_types=1);

namespace MlGroup\Model;

use MlGroup\Support\Str;

/**
 * Produto normalizado - todo scraper devolve neste formato.
 */
final class Produto
{
    public float $comissao = 0.0;

    public float $ganhoEstimado = 0.0;

    public float $pontuacao = 0.0;

    public string $linkAfiliado = '';

    /** Menor preco ja visto no historico (0 = sem historico). */
    public float $menorPrecoHistorico = 0.0;

    public function __construct(
        public readonly string $mlId,
        public readonly string $titulo,
        public readonly string $permalink,
        public readonly float $preco,
        public readonly float $precoOriginal = 0.0,
        public readonly string $thumb = '',
        public readonly string $categoriaId = '',
        public readonly string $categoriaNome = '',
        public readonly string $marca = '',
        public readonly string $vendedor = '',
        public readonly bool $freteGratis = false,
        public readonly bool $full = false,
        public readonly int $vendidos = 0,
        public readonly float $avaliacao = 0.0,
        public readonly int $totalAvaliacoes = 0,
        public readonly string $origem = 'api',
    ) {
    }

    /**
     * Identidade do produto para fins de repeticao.
     *
     * O ml_id nao serve sozinho: o mesmo produto de catalogo aparece com IDs
     * diferentes para cada variacao (cor, voltagem), com titulo e preco
     * identicos - foi o caso das duas serras circulares MLB15462505/06. A
     * assinatura vem do titulo normalizado, que iguala esses casos.
     */
    public function assinatura(): string
    {
        $base = Str::normalizar($this->titulo);
        $base = preg_replace('/[^a-z0-9]+/', ' ', $base) ?? $base;
        $base = trim(preg_replace('/\s+/', ' ', $base) ?? $base);

        return md5($base);
    }

    /** Percentual de desconto sobre o preco original. */
    public function desconto(): float
    {
        if ($this->precoOriginal <= 0 || $this->precoOriginal <= $this->preco) {
            return 0.0;
        }

        return round((($this->precoOriginal - $this->preco) / $this->precoOriginal) * 100, 2);
    }

    public function economia(): float
    {
        return $this->precoOriginal > $this->preco
            ? round($this->precoOriginal - $this->preco, 2)
            : 0.0;
    }

    /** True quando o preco atual empata ou bate o menor preco ja registrado. */
    public function menorPrecoDeSempre(): bool
    {
        return $this->menorPrecoHistorico > 0 && $this->preco <= $this->menorPrecoHistorico;
    }

    public function link(): string
    {
        return $this->linkAfiliado !== '' ? $this->linkAfiliado : $this->permalink;
    }

    /**
     * Reconstroi o produto a partir de uma linha da tabela produtos.
     *
     * @param array<string,mixed> $linha
     */
    public static function doBanco(array $linha): self
    {
        $produto = new self(
            mlId:            (string) $linha['ml_id'],
            titulo:          (string) $linha['titulo'],
            permalink:       (string) $linha['permalink'],
            preco:           (float) $linha['preco'],
            precoOriginal:   (float) $linha['preco_original'],
            thumb:           (string) ($linha['thumb'] ?? ''),
            categoriaId:     (string) ($linha['categoria_id'] ?? ''),
            categoriaNome:   (string) ($linha['categoria_nome'] ?? ''),
            marca:           (string) ($linha['marca'] ?? ''),
            vendedor:        (string) ($linha['vendedor'] ?? ''),
            freteGratis:     (bool) ($linha['frete_gratis'] ?? false),
            full:            (bool) ($linha['full'] ?? false),
            vendidos:        (int) ($linha['vendidos'] ?? 0),
            avaliacao:       (float) ($linha['avaliacao'] ?? 0),
            totalAvaliacoes: (int) ($linha['total_avaliacoes'] ?? 0),
            origem:          (string) ($linha['origem'] ?? 'banco'),
        );

        $produto->comissao      = (float) ($linha['comissao'] ?? 0);
        $produto->ganhoEstimado = (float) ($linha['ganho_estimado'] ?? 0);
        $produto->pontuacao     = (float) ($linha['pontuacao'] ?? 0);

        return $produto;
    }

    /** @return array<string,mixed> */
    public function paraArray(): array
    {
        return [
            'ml_id'            => $this->mlId,
            'assinatura'       => $this->assinatura(),
            'titulo'           => $this->titulo,
            'permalink'        => $this->permalink,
            'thumb'            => $this->thumb,
            'categoria_id'     => $this->categoriaId,
            'categoria_nome'   => $this->categoriaNome,
            'marca'            => $this->marca,
            'vendedor'         => $this->vendedor,
            'preco'            => $this->preco,
            'preco_original'   => $this->precoOriginal,
            'desconto'         => $this->desconto(),
            'comissao'         => $this->comissao,
            'ganho_estimado'   => $this->ganhoEstimado,
            'frete_gratis'     => $this->freteGratis ? 1 : 0,
            'full'             => $this->full ? 1 : 0,
            'vendidos'         => $this->vendidos,
            'avaliacao'        => $this->avaliacao,
            'total_avaliacoes' => $this->totalAvaliacoes,
            'pontuacao'        => $this->pontuacao,
            'origem'           => $this->origem,
        ];
    }
}
