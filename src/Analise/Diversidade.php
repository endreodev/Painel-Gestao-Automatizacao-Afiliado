<?php

declare(strict_types=1);

namespace MlGroup\Analise;

use MlGroup\Model\Produto;
use MlGroup\Support\Config;
use MlGroup\Support\Str;

/**
 * Impede que o grupo receba a mesma coisa em sequencia.
 *
 * O problema que ela resolve: a fila e ordenada por pontuacao, e produtos do
 * mesmo tipo pontuam parecido - entao cinco parafusadeiras seguidas, quase
 * todas da mesma marca, e o resultado natural de ordenar por nota. Bom para o
 * ranking, pessimo para quem le o grupo.
 *
 * Dois eixos, ambos configuraveis em config/config.php > diversidade:
 *   - tipo  (o termo do nicho que o produto casou: parafusadeira, lavadora...)
 *   - marca (reconhecida no titulo por config/marcas.php)
 *
 * Nao inventa variedade que nao existe: se a fila so tem lavadora, em algum
 * momento sai lavadora. O que ela faz e espalhar o maximo que o estoque permite.
 */
final class Diversidade
{
    /** @var array<string,string>|null */
    private static ?array $marcas = null;

    public function __construct(private readonly Nicho $nicho = new Nicho())
    {
    }

    public function ativa(): bool
    {
        return Config::booleano('config.diversidade.ativa', true);
    }

    /** O tipo do produto: o termo do nicho que ele casou. */
    public function tipo(Produto $produto): string
    {
        $termo = $this->nicho->termoCasado($produto);

        if ($termo !== '') {
            return Str::normalizar($termo);
        }

        // sem termo do nicho, a primeira palavra do titulo ja separa razoavelmente
        $palavras = explode(' ', Str::normalizar($produto->titulo));

        return $palavras[0] ?? '';
    }

    /** A marca, do campo do produto ou reconhecida no titulo. */
    public function marca(Produto $produto): string
    {
        if ($produto->marca !== '') {
            return Str::normalizar($produto->marca);
        }

        $palavras = $this->palavras($produto->titulo);
        $melhor   = '';
        $maior    = 0;

        foreach ($this->marcasConhecidas() as $normalizada => $original) {
            $partes = explode(' ', $normalizada);
            $casou  = true;

            foreach ($partes as $parte) {
                if (!isset($palavras[$parte])) {
                    $casou = false;

                    break;
                }
            }

            // a marca com mais palavras vence: "the black tools" ganha de "black"
            if ($casou && count($partes) > $maior) {
                $maior  = count($partes);
                $melhor = $normalizada;
            }
        }

        return $melhor;
    }

    /**
     * O produto pode sair agora, diante do que ja foi publicado?
     *
     * @param  array<int,array{tipo:string,marca:string}> $recentes Do mais novo para o mais antigo.
     * @return string|null Motivo do adiamento, ou null quando pode sair.
     */
    public function adiar(Produto $produto, array $recentes): ?string
    {
        if (!$this->ativa()) {
            return null;
        }

        $janelaTipo  = Config::inteiro('config.diversidade.repetir_tipo_apos', 5);
        $janelaMarca = Config::inteiro('config.diversidade.repetir_marca_apos', 3);

        $tipo  = $this->tipo($produto);
        $marca = $this->marca($produto);

        foreach ($recentes as $posicao => $recente) {
            if ($janelaTipo > 0 && $posicao < $janelaTipo && $tipo !== '' && $recente['tipo'] === $tipo) {
                return 'tipo "' . $tipo . '" publicado ha ' . ($posicao + 1) . ' envio(s)';
            }

            if ($janelaMarca > 0 && $posicao < $janelaMarca && $marca !== '' && $recente['marca'] === $marca) {
                return 'marca "' . $marca . '" publicada ha ' . ($posicao + 1) . ' envio(s)';
            }
        }

        return null;
    }

    /** Quantos envios anteriores precisam ser considerados. */
    public function janela(): int
    {
        return max(
            Config::inteiro('config.diversidade.repetir_tipo_apos', 5),
            Config::inteiro('config.diversidade.repetir_marca_apos', 3),
        );
    }

    /** @return array{tipo:string,marca:string} */
    public function assinaturaDe(Produto $produto): array
    {
        return ['tipo' => $this->tipo($produto), 'marca' => $this->marca($produto)];
    }

    /** @return array<string,true> */
    private function palavras(string $texto): array
    {
        $limpo = preg_replace('/[^a-z0-9]+/', ' ', Str::normalizar($texto)) ?? '';
        $conjunto = [];

        foreach (explode(' ', $limpo) as $palavra) {
            if ($palavra !== '') {
                $conjunto[$palavra] = true;
            }
        }

        return $conjunto;
    }

    /** @return array<string,string> normalizada => original */
    private function marcasConhecidas(): array
    {
        if (self::$marcas !== null) {
            return self::$marcas;
        }

        $marcas = [];

        foreach (Config::lista('marcas.conhecidas') as $marca) {
            $normalizada = Str::normalizar((string) $marca);

            if ($normalizada !== '') {
                $marcas[$normalizada] = (string) $marca;
            }
        }

        return self::$marcas = $marcas;
    }

    /** Usado pelos testes quando a config muda em memoria. */
    public static function limparCache(): void
    {
        self::$marcas = null;
    }
}
