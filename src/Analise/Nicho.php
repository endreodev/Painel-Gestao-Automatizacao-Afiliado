<?php

declare(strict_types=1);

namespace MlGroup\Analise;

use MlGroup\Model\Produto;
use MlGroup\Support\Config;
use MlGroup\Support\Str;

/**
 * Mede o quanto um produto pertence ao nicho configurado em config/nicho.php.
 *
 * A classificacao sai do titulo, nao da categoria do ML: a pagina /ofertas -
 * fonte mais confiavel de coleta - nao traz categoria por card, mas sempre traz
 * o titulo. Um item de oficina se descreve no proprio nome.
 *
 * O termo mais especifico vence: "chave de impacto" ganha de "chave", e
 * "compressor de ar" ganha de "compressor". Isso evita que uma correspondencia
 * generica rebaixe um produto que e claramente do ramo.
 */
final class Nicho
{
    /**
     * Indice de termos, por canal.
     *
     * Guardado por canal de proposito: com um cache unico, o segundo canal
     * reaproveitava o indice do primeiro e classificava tudo pelo nicho errado -
     * o grupo de utilidades reprovava panela porque o indice ainda era o de
     * ferramentas.
     *
     * @var array<string,array<string,array{0:float,1:string}>>
     */
    private static array $indices = [];

    /**
     * Relevancia de 0 a 1.
     *
     * 0 = nada a ver com o nicho (ou explicitamente bloqueado).
     */
    public function relevancia(Produto $produto): float
    {
        if (!Config::booleano('nicho.ativo', true)) {
            return 1.0;
        }

        $titulo = $this->normalizar($produto->titulo);

        if ($this->bloqueado($titulo)) {
            return 0.0;
        }

        return $this->melhorCasamento($this->palavras($titulo))['peso'];
    }

    /** O termo do nicho que casou, para explicar a decisao no log. */
    public function termoCasado(Produto $produto): string
    {
        return $this->melhorCasamento($this->palavras($this->normalizar($produto->titulo)))['termo'];
    }

    /**
     * O melhor termo que casa com o titulo.
     *
     * A ordem de desempate importa e ja mordeu: primeiro o PESO, depois o numero
     * de palavras. Comparando so por numero de palavras, "bateria 21v" (apoio,
     * duas palavras) ganhava de "parafusadeira" (essencial, uma palavra) em
     * "Parafusadeira Furadeira De Impacto 21v Bateria" - a ferramenta era
     * classificada como acessorio, perdia peso na pontuacao e ainda escapava da
     * regra de diversidade, que a via como outro tipo.
     *
     * @param  array<string,true> $palavras
     * @return array{peso:float,termo:string}
     */
    private function melhorCasamento(array $palavras): array
    {
        $melhorPeso  = 0.0;
        $melhorTermo = '';
        $melhorNota  = 0;

        foreach ($this->indice() as $termo => [$peso, $original]) {
            $nota = $this->especificidade($termo, $palavras);

            if ($nota === 0) {
                continue;
            }

            $ganha = $peso > $melhorPeso
                || ($peso === $melhorPeso && $nota > $melhorNota);

            if ($ganha) {
                $melhorPeso  = $peso;
                $melhorNota  = $nota;
                $melhorTermo = $original;
            }
        }

        return ['peso' => $melhorPeso, 'termo' => $melhorTermo];
    }

    /**
     * Quanto o termo casa com o titulo; 0 quando nao casa.
     *
     * Casa quando TODAS as palavras significativas do termo aparecem no titulo,
     * em qualquer ordem. Comparar por trecho literal quebrava demais na pratica:
     * "lavadora de alta pressao" nao encontrava "Lavadora Alta Pressão Lav1300"
     * (falta o "de") e "serra marmore" nao encontrava "Serra Makita Mármore"
     * (palavras separadas). Sao titulos de anuncio, nao frases.
     *
     * A nota e a quantidade de palavras casadas, para "serra circular" ganhar de
     * "serra" no mesmo titulo.
     *
     * @param array<string,true> $palavrasTitulo
     */
    private function especificidade(string $termo, array $palavrasTitulo): int
    {
        $exigidas = $this->significativas($termo);

        if ($exigidas === []) {
            return 0;
        }

        foreach ($exigidas as $palavra) {
            // aceita o plural simples: "brocas" casa com "broca"
            if (!isset($palavrasTitulo[$palavra]) && !isset($palavrasTitulo[$palavra . 's'])) {
                return 0;
            }
        }

        return count($exigidas);
    }

    /**
     * Palavras do titulo como conjunto, para consulta direta.
     *
     * @return array<string,true>
     */
    private function palavras(string $textoNormalizado): array
    {
        $conjunto = [];

        foreach (explode(' ', $textoNormalizado) as $palavra) {
            if ($palavra !== '') {
                $conjunto[$palavra] = true;
            }
        }

        return $conjunto;
    }

    /**
     * Palavras do termo que de fato discriminam.
     *
     * Preposicao e artigo aparecem em quase todo titulo e, se exigidos,
     * derrubariam o casamento por um "de" que o vendedor nao escreveu.
     *
     * @return string[]
     */
    private function significativas(string $termo): array
    {
        $vazias = ['de', 'da', 'do', 'das', 'dos', 'a', 'o', 'e', 'em', 'com', 'para', 'por'];

        return array_values(array_filter(
            explode(' ', $termo),
            static fn (string $p): bool => $p !== '' && !in_array($p, $vazias, true),
        ));
    }

    /** Produto sem nenhuma relacao com o nicho deve ser descartado? */
    public function reprovar(Produto $produto): bool
    {
        if (!Config::booleano('nicho.ativo', true) || !Config::booleano('nicho.exigir_relevancia', true)) {
            return false;
        }

        return $this->relevancia($produto) <= 0.0;
    }

    private function bloqueado(string $tituloNormalizado): bool
    {
        $palavras = $this->palavras($tituloNormalizado);

        foreach (Config::lista('nicho.bloqueados') as $termo) {
            $termo = $this->normalizar((string) $termo);

            // mesma regra do casamento: por palavra, e nao por trecho literal.
            // Comparar trecho aqui bloquearia "capacete" por causa de "capa".
            if ($termo !== '' && $this->especificidade($termo, $palavras) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Termos normalizados -> [peso, termo original].
     *
     * @return array<string,array{0:float,1:string}>
     */
    private function indice(): array
    {
        $canal = \MlGroup\App\Canal::ativo()?->id() ?? 'padrao';

        if (isset(self::$indices[$canal])) {
            return self::$indices[$canal];
        }

        $indice = [];

        $grupos = [
            ['essenciais', Config::decimal('nicho.peso_essencial', 1.0)],
            ['apoio', Config::decimal('nicho.peso_apoio', 0.55)],
        ];

        foreach ($grupos as [$chave, $peso]) {
            foreach (Config::lista('nicho.' . $chave) as $termo) {
                $normalizado = $this->normalizar((string) $termo);

                if ($normalizado === '') {
                    continue;
                }

                // essenciais entram primeiro; nao deixa 'apoio' rebaixar o peso
                if (!isset($indice[$normalizado])) {
                    $indice[$normalizado] = [$peso, (string) $termo];
                }
            }
        }

        return self::$indices[$canal] = $indice;
    }

    /** Sem acento, sem pontuacao e com espacos colapsados nos dois lados. */
    private function normalizar(string $texto): string
    {
        $base = Str::normalizar($texto);
        $base = preg_replace('/[^a-z0-9]+/', ' ', $base) ?? $base;

        return trim(preg_replace('/\s+/', ' ', $base) ?? $base);
    }

    /** Usado pelos testes quando a config muda em memoria. */
    public static function limparCache(): void
    {
        self::$indices = [];
    }
}
