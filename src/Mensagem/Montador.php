<?php

declare(strict_types=1);

namespace MlGroup\Mensagem;

use MlGroup\Model\Produto;
use MlGroup\Support\Config;
use MlGroup\Support\Str;

/**
 * Renderiza o texto que vai para o grupo a partir de um arquivo em templates/.
 *
 * Sintaxe do template:
 *   {campo}                  -> substitui pelo valor
 *   {?campo}...{/campo}      -> so mantem o trecho quando o campo esta preenchido
 *
 * Os blocos condicionais sao resolvidos antes dos campos simples, entao um
 * trecho descartado nunca chega a ter placeholder trocado.
 */
final class Montador
{
    public function __construct(private readonly string $pastaTemplates = MLG_ROOT . '/templates')
    {
    }

    public function oferta(Produto $produto): string
    {
        return $this->renderizar(
            $this->carregar(Config::texto('config.mensagem.template', 'oferta')),
            $this->campos($produto),
        );
    }

    /**
     * Junta varias ofertas em uma unica mensagem (modo lote).
     *
     * @param Produto[] $produtos
     */
    public function lote(array $produtos): string
    {
        $cabecalho = $this->renderizar(
            $this->carregar(Config::texto('config.mensagem.template_cabecalho', 'cabecalho')),
            [
                'quantidade' => (string) count($produtos),
                'data'       => date('d/m/Y'),
                'hora'       => date('H:i'),
            ],
        );

        $blocos = [];

        foreach ($produtos as $indice => $produto) {
            $campos           = $this->campos($produto);
            $campos['indice'] = (string) ($indice + 1);

            $blocos[] = $this->renderizar(
                $this->carregar(Config::texto('config.mensagem.template_item', 'item')),
                $campos,
            );
        }

        $separador = Config::texto('config.mensagem.separador', "\n\n");

        return trim($cabecalho . "\n\n" . implode($separador, $blocos));
    }

    /** @return array<string,string> */
    private function campos(Produto $produto): array
    {
        $desconto = $produto->desconto();

        return [
            'titulo'          => Str::limitar($produto->titulo, Config::inteiro('config.mensagem.tamanho_titulo', 70)),
            'titulo_completo' => $produto->titulo,
            'preco'           => Str::dinheiro($produto->preco),
            'preco_original'  => $produto->precoOriginal > $produto->preco ? Str::dinheiro($produto->precoOriginal) : '',
            'desconto'        => $desconto > 0 ? Str::percentual($desconto) : '',
            'economia'        => $produto->economia() > 0 ? Str::dinheiro($produto->economia()) : '',
            'link'            => $produto->link(),
            'comissao'        => Str::percentual($produto->comissao, 1),
            'ganho'           => Str::dinheiro($produto->ganhoEstimado),
            'marca'           => $produto->marca,
            'vendedor'        => $produto->vendedor,
            'avaliacao'       => $produto->totalAvaliacoes > 0 ? number_format($produto->avaliacao, 1, ',', '') : '',
            'total_avaliacoes' => $produto->totalAvaliacoes > 0 ? (string) $produto->totalAvaliacoes : '',
            'vendidos'        => $produto->vendidos > 0 ? (string) $produto->vendidos : '',
            'frete_gratis'    => $produto->freteGratis ? Config::texto('config.mensagem.selo_frete', 'Frete gratis') : '',
            'full'            => $produto->full ? Config::texto('config.mensagem.selo_full', 'FULL') : '',
            'menor_preco'     => $produto->menorPrecoDeSempre() ? Config::texto('config.mensagem.selo_menor_preco', 'Menor preco ja visto') : '',
            'termometro'      => $this->termometro($desconto),
            'pontuacao'       => number_format($produto->pontuacao, 0, ',', ''),
            'data'            => date('d/m/Y'),
            'hora'            => date('H:i'),
        ];
    }

    /** Intensidade visual do desconto, para o grupo bater o olho e entender. */
    private function termometro(float $desconto): string
    {
        $faixas = Config::lista('config.mensagem.termometro');

        // faixas vem como [percentual_minimo => simbolo]; maior faixa atingida vence
        $escolhido = '';
        $maiorMin  = -1.0;

        foreach ($faixas as $minimo => $simbolo) {
            $minimo = (float) $minimo;

            if ($desconto >= $minimo && $minimo > $maiorMin) {
                $maiorMin  = $minimo;
                $escolhido = (string) $simbolo;
            }
        }

        return $escolhido;
    }

    private function carregar(string $nome): string
    {
        $caminho = $this->pastaTemplates . '/' . $nome . '.txt';

        if (!is_file($caminho)) {
            return '{titulo}' . "\n" . '{preco}' . "\n" . '{link}';
        }

        return (string) file_get_contents($caminho);
    }

    /** @param array<string,string> $campos */
    private function renderizar(string $template, array $campos): string
    {
        $texto = $this->resolverCondicionais($template, $campos);

        foreach ($campos as $campo => $valor) {
            $texto = str_replace('{' . $campo . '}', $valor, $texto);
        }

        // limpa marcadores que o template usa mas nao existem nos campos
        $texto = preg_replace('/\{[?\/]?[a-z_]+\}/', '', $texto) ?? $texto;

        return $this->limparEspacos($texto);
    }

    /**
     * Resolve os blocos {?campo}...{/campo}.
     *
     * Roda em passadas porque um bloco pode conter outro: a primeira passada
     * resolve o externo e devolve o interno ainda marcado, a seguinte o resolve.
     *
     * @param array<string,string> $campos
     */
    private function resolverCondicionais(string $template, array $campos): string
    {
        for ($passada = 0; $passada < 5; $passada++) {
            $resultado = preg_replace_callback(
                '/\{\?([a-z_]+)\}(.*?)\{\/\1\}/s',
                static function (array $achado) use ($campos): string {
                    $valor = $campos[$achado[1]] ?? '';

                    return trim($valor) === '' ? '' : $achado[2];
                },
                $template,
                -1,
                $trocas,
            );

            if ($resultado === null) {
                return $template;
            }

            $template = $resultado;

            if ($trocas === 0) {
                break;
            }
        }

        return $template;
    }

    /** Colapsa as linhas em branco que sobram dos blocos removidos. */
    private function limparEspacos(string $texto): string
    {
        $texto = preg_replace('/[ \t]+$/m', '', $texto) ?? $texto;
        $texto = preg_replace('/\n{3,}/', "\n\n", $texto) ?? $texto;

        return trim($texto);
    }
}
