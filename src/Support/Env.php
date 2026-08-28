<?php

declare(strict_types=1);

namespace MlGroup\Support;

/**
 * Leitor de .env sem dependencia externa.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    private static bool $carregado = false;

    public static function carregar(string $caminho): void
    {
        if (self::$carregado) {
            return;
        }

        self::$carregado = true;

        if (!is_file($caminho)) {
            return;
        }

        $linhas = file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($linhas === false) {
            return;
        }

        foreach ($linhas as $linha) {
            $linha = trim($linha);

            if ($linha === '' || str_starts_with($linha, '#')) {
                continue;
            }

            $pos = strpos($linha, '=');

            if ($pos === false) {
                continue;
            }

            $chave = trim(substr($linha, 0, $pos));
            $valor = trim(substr($linha, $pos + 1));

            // remove aspas envolventes, se houver
            if (strlen($valor) >= 2) {
                $primeiro = $valor[0];
                $ultimo   = $valor[strlen($valor) - 1];

                if (($primeiro === '"' && $ultimo === '"') || ($primeiro === "'" && $ultimo === "'")) {
                    $valor = substr($valor, 1, -1);
                }
            }

            self::$vars[$chave] = $valor;
        }
    }

    public static function get(string $chave, mixed $padrao = null): mixed
    {
        $valor = self::$vars[$chave] ?? getenv($chave);

        if ($valor === false || $valor === null || $valor === '') {
            return $padrao;
        }

        return match (strtolower((string) $valor)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            default            => $valor,
        };
    }

    public static function texto(string $chave, string $padrao = ''): string
    {
        $valor = self::get($chave, $padrao);

        return is_scalar($valor) ? (string) $valor : $padrao;
    }

    public static function inteiro(string $chave, int $padrao = 0): int
    {
        $valor = self::get($chave, $padrao);

        return is_numeric($valor) ? (int) $valor : $padrao;
    }

    public static function decimal(string $chave, float $padrao = 0.0): float
    {
        $valor = self::get($chave, $padrao);

        return is_numeric($valor) ? (float) $valor : $padrao;
    }

    /**
     * Sobrescreve uma variavel apenas em memoria, sem tocar no .env.
     *
     * O autoteste usa isto para nunca herdar o grupo real de WHATSAPP_GRUPOS -
     * caso contrario ele gravaria historico de envio para o grupo de verdade.
     */
    public static function definir(string $chave, string $valor): void
    {
        self::$vars[$chave] = $valor;
    }

    /**
     * Grava uma chave no .env, preservando comentarios e a ordem das linhas.
     *
     * Usado pelos comandos interativos (escolher grupo, por exemplo) para o
     * usuario nao precisar abrir o arquivo na mao.
     */
    public static function salvar(string $chave, string $valor): bool
    {
        $caminho = MLG_ROOT . '/.env';

        if (!is_file($caminho)) {
            $modelo = MLG_ROOT . '/.env.example';

            if (is_file($modelo)) {
                copy($modelo, $caminho);
            } else {
                file_put_contents($caminho, '');
            }
        }

        $linhas = file($caminho, FILE_IGNORE_NEW_LINES);

        if ($linhas === false) {
            return false;
        }

        $substituiu = false;

        foreach ($linhas as $indice => $linha) {
            if (preg_match('/^\s*' . preg_quote($chave, '/') . '\s*=/', $linha) === 1) {
                $linhas[$indice] = $chave . '=' . $valor;
                $substituiu      = true;

                break;
            }
        }

        if (!$substituiu) {
            $linhas[] = $chave . '=' . $valor;
        }

        $gravou = file_put_contents($caminho, implode(PHP_EOL, $linhas) . PHP_EOL) !== false;

        if ($gravou) {
            self::$vars[$chave] = $valor;
        }

        return $gravou;
    }

    public static function booleano(string $chave, bool $padrao = false): bool
    {
        $valor = self::get($chave, $padrao);

        if (is_bool($valor)) {
            return $valor;
        }

        return in_array(strtolower((string) $valor), ['1', 'true', 'sim', 'yes', 'on'], true);
    }
}
