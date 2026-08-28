<?php

declare(strict_types=1);

namespace MlGroup\Support;

/**
 * Acesso aos arquivos de config/ com notacao de ponto: Config::get('busca.min_desconto').
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $dados = [];

    /** Valores dos arquivos, sem os ajustes por cima. */
    private static ?array $padroes = null;

    private static bool $carregado = false;

    public static function carregar(): void
    {
        if (self::$carregado) {
            return;
        }

        self::$carregado = true;

        foreach (glob(MLG_ROOT . '/config/*.php') ?: [] as $arquivo) {
            $nome = basename($arquivo, '.php');
            /** @var mixed $conteudo */
            $conteudo = require $arquivo;

            self::$dados[$nome] = is_array($conteudo) ? $conteudo : [];
        }

        // o que o painel ajustou entra por cima do padrao dos arquivos
        self::$dados = ConfigLocal::aplicar(self::$dados);
    }

    /** Relê os arquivos e os ajustes (usado depois de salvar pelo painel). */
    public static function recarregar(): void
    {
        self::$dados     = [];
        self::$carregado = false;

        // a camada de ajustes tambem pode ter mudado no disco desde a ultima vez
        ConfigLocal::invalidar();

        self::carregar();
    }

    /**
     * O valor que vem dos arquivos de config, ignorando os ajustes do painel.
     *
     * Serve para o painel mostrar o que foi alterado em relacao ao padrao - e
     * para oferecer o desfazer campo a campo, sem precisar restaurar tudo.
     */
    public static function padrao(string $caminho): mixed
    {
        if (self::$padroes === null) {
            self::$padroes = [];

            foreach (glob(MLG_ROOT . '/config/*.php') ?: [] as $arquivo) {
                /** @var mixed $conteudo */
                $conteudo = require $arquivo;

                self::$padroes[basename($arquivo, '.php')] = is_array($conteudo) ? $conteudo : [];
            }
        }

        $atual = self::$padroes;

        foreach (explode('.', $caminho) as $parte) {
            if (!is_array($atual) || !array_key_exists($parte, $atual)) {
                return null;
            }

            $atual = $atual[$parte];
        }

        return $atual;
    }

    public static function get(string $caminho, mixed $padrao = null): mixed
    {
        self::carregar();

        /*
         * O canal ativo responde primeiro.
         *
         * E o que permite dois grupos com nicho, buscas e filtros diferentes sem
         * passar o canal por parametro em toda a cadeia - coletor, filtro,
         * nicho, pontuador, fila e publicador continuam sem saber que canal
         * existe.
         */
        [$achou, $doCanal] = \MlGroup\App\Canal::sobrepor($caminho);

        if ($achou) {
            return $doCanal;
        }

        $partes = explode('.', $caminho);
        $atual  = self::$dados;

        foreach ($partes as $parte) {
            if (!is_array($atual) || !array_key_exists($parte, $atual)) {
                return $padrao;
            }

            $atual = $atual[$parte];
        }

        return $atual;
    }

    /** @return array<int|string,mixed> */
    public static function lista(string $caminho): array
    {
        $valor = self::get($caminho, []);

        return is_array($valor) ? $valor : [];
    }

    public static function inteiro(string $caminho, int $padrao = 0): int
    {
        $valor = self::get($caminho, $padrao);

        return is_numeric($valor) ? (int) $valor : $padrao;
    }

    public static function decimal(string $caminho, float $padrao = 0.0): float
    {
        $valor = self::get($caminho, $padrao);

        return is_numeric($valor) ? (float) $valor : $padrao;
    }

    public static function texto(string $caminho, string $padrao = ''): string
    {
        $valor = self::get($caminho, $padrao);

        return is_scalar($valor) ? (string) $valor : $padrao;
    }

    public static function booleano(string $caminho, bool $padrao = false): bool
    {
        $valor = self::get($caminho, $padrao);

        return is_bool($valor) ? $valor : $padrao;
    }

    /** Sobrescreve um valor em memoria (usado por flags de linha de comando). */
    public static function definir(string $caminho, mixed $valor): void
    {
        self::carregar();

        $partes = explode('.', $caminho);
        $ref    = &self::$dados;

        foreach ($partes as $parte) {
            if (!isset($ref[$parte]) || !is_array($ref[$parte])) {
                $ref[$parte] = [];
            }

            $ref = &$ref[$parte];
        }

        $ref = $valor;
    }
}
