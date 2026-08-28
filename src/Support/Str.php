<?php

declare(strict_types=1);

namespace MlGroup\Support;

final class Str
{
    /** Remove acentos e baixa a caixa - usado em comparacoes de palavra-chave. */
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');

        $de = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ'];
        $para = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];

        return str_replace($de, $para, $texto);
    }

    /** Verifica se qualquer termo aparece no texto (comparacao sem acento/caixa). */
    public static function contemAlgum(string $texto, array $termos): bool
    {
        $normalizado = self::normalizar($texto);

        foreach ($termos as $termo) {
            $termo = self::normalizar((string) $termo);

            if ($termo !== '' && str_contains($normalizado, $termo)) {
                return true;
            }
        }

        return false;
    }

    public static function dinheiro(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    public static function percentual(float $valor, int $casas = 0): string
    {
        return number_format($valor, $casas, ',', '.') . '%';
    }

    public static function limitar(string $texto, int $tamanho, string $sufixo = '...'): string
    {
        if (mb_strlen($texto, 'UTF-8') <= $tamanho) {
            return $texto;
        }

        return rtrim(mb_substr($texto, 0, $tamanho - mb_strlen($sufixo, 'UTF-8'), 'UTF-8')) . $sufixo;
    }

    /** Converte "R$ 1.234,56" em 1234.56. */
    public static function paraDecimal(string $texto): float
    {
        $limpo = preg_replace('/[^0-9,.]/', '', $texto) ?? '';

        if ($limpo === '') {
            return 0.0;
        }

        // formato brasileiro: ponto e milhar, virgula e decimal
        if (str_contains($limpo, ',')) {
            $limpo = str_replace('.', '', $limpo);
            $limpo = str_replace(',', '.', $limpo);
        }

        return (float) $limpo;
    }

    public static function slug(string $texto): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', self::normalizar($texto)) ?? '';

        return trim($slug, '-');
    }

    /**
     * Corta e completa o texto ate ocupar exatamente $largura colunas.
     *
     * Nao da para usar %-38s do sprintf para alinhar coluna no terminal: ele
     * conta bytes, e um nome com acento ja sai torto. mb_strlen conta
     * caracteres, o que resolve o acento e erra no emoji - o terminal desenha
     * emoji com o dobro da largura, entao um nome de grupo com quatro deles
     * empurrava a coluna seguinte quatro casas para a esquerda.
     */
    public static function preencher(string $texto, int $largura): string
    {
        $saida = '';
        $usado = 0;

        foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $letra) {
            $custo = self::colunas($letra);

            if ($usado + $custo > $largura - 1) {
                $saida .= '~';
                $usado++;

                break;
            }

            $saida .= $letra;
            $usado += $custo;
        }

        return $saida . str_repeat(' ', max(0, $largura - $usado));
    }

    /** Quantas colunas do terminal um caractere ocupa: 2 para emoji e CJK. */
    public static function colunas(string $letra): int
    {
        $ponto = mb_ord($letra, 'UTF-8');

        if ($ponto === false) {
            return 1;
        }

        // marca de variacao, juntor e tom de pele nao ocupam coluna propria
        if ($ponto === 0xFE0F || $ponto === 0x200D || ($ponto >= 0x1F3FB && $ponto <= 0x1F3FF)) {
            return 0;
        }

        $largos = [
            [0x1100, 0x115F], [0x2E80, 0xA4CF], [0xAC00, 0xD7A3],
            [0xF900, 0xFAFF], [0xFE30, 0xFE6F], [0xFF00, 0xFF60],
            [0xFFE0, 0xFFE6], [0x1F300, 0x1F64F], [0x1F680, 0x1F6FF],
            [0x1F900, 0x1F9FF], [0x1FA70, 0x1FAFF], [0x2600, 0x27BF],
        ];

        foreach ($largos as [$de, $ate]) {
            if ($ponto >= $de && $ponto <= $ate) {
                return 2;
            }
        }

        return 1;
    }
}
