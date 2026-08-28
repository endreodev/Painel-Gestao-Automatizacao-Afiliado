<?php

declare(strict_types=1);

namespace MlGroup\Support;

/**
 * Ajustes feitos pelo painel, guardados em storage/config-local.json.
 *
 * O painel nao reescreve os arquivos de config/: eles sao codigo comentado, que
 * explica cada regra, e um gerador de PHP acabaria com isso. Em vez disso, o que
 * o usuario muda vira uma camada por cima - os arquivos seguem sendo o padrao e
 * este JSON, a excecao.
 *
 * Efeito colateral util: apagar o JSON devolve tudo ao padrao.
 */
final class ConfigLocal
{
    /** Quantas versoes anteriores da configuracao ficam guardadas. */
    private const COPIAS_MANTIDAS = 10;

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** Data do arquivo no momento em que o cache foi montado. */
    private static string $versaoEmCache = '';

    /**
     * O que foi alterado nesta execucao, por caminho.
     *
     * Guardado separado do cache de propósito: na hora de gravar, o arquivo e
     * relido do disco e SO estas alteracoes sao aplicadas por cima. Assim duas
     * execucoes mexendo em chaves diferentes nao se atropelam.
     *
     * @var array<string,mixed>
     */
    /** Quando definido, substitui o arquivo padrao. Ver usarArquivo(). */
    private static ?string $arquivo = null;

    private static array $alteracoes = [];

    /** @var string[] caminhos removidos nesta execucao */
    private static array $remocoes = [];

    public static function caminho(): string
    {
        return self::$arquivo ?? MLG_ROOT . '/storage/config-local.json';
    }

    /**
     * Aponta a configuracao local para outro arquivo.
     *
     * Existe para o autoteste. Ele exercita o caminho real de gravacao - o
     * painel salvando canal, busca, ajuste - e sem isto escreve na configuracao
     * de quem esta rodando a suite. Nao e hipotese: uma execucao ja gravou dois
     * canais de teste por cima dos reais, apagando grupo e nicho configurados.
     *
     * As copias de seguranca acompanham, porque saem de dirname(caminho()).
     */
    public static function usarArquivo(?string $caminho): void
    {
        self::$arquivo       = $caminho;
        self::$cache         = null;
        self::$versaoEmCache = '';
        self::$alteracoes    = [];
        self::$remocoes      = [];
    }

    /** @return array<string,mixed> */
    public static function tudo(): array
    {
        $caminho = self::caminho();

        clearstatcache(true, $caminho);

        $versao = is_file($caminho) ? (int) filemtime($caminho) . ':' . (int) filesize($caminho) : '0';

        /*
         * O cache so vale enquanto o arquivo nao muda no disco.
         *
         * Sem esta checagem o painel corrompia a configuracao: o servidor
         * embutido do PHP e um processo unico e de vida longa, entao o cache
         * congelava no primeiro acesso. Qualquer alteracao feita pelo terminal
         * ficava invisivel para ele e, no salvamento seguinte, era sobrescrita
         * pelo retrato antigo - buscas sumiam, flags voltavam atras, e nada
         * aparecia no log.
         */
        if (self::$cache !== null && self::$versaoEmCache === $versao) {
            return self::$cache;
        }

        self::$versaoEmCache = $versao;

        if (!is_file($caminho)) {
            return self::$cache = [];
        }

        $dados = json_decode((string) file_get_contents($caminho), true);

        return self::$cache = is_array($dados) ? $dados : [];
    }

    /**
     * Grava um valor pelo caminho de ponto (ex.: config.filtros.preco_maximo).
     *
     * Nao escreve em disco - use gravar() depois de aplicar tudo, para o painel
     * salvar o formulario inteiro de uma vez.
     */
    public static function definir(string $caminho, mixed $valor): void
    {
        $dados  = self::tudo();
        $partes = explode('.', $caminho);
        $ref    = &$dados;

        foreach ($partes as $parte) {
            if (!isset($ref[$parte]) || !is_array($ref[$parte])) {
                $ref[$parte] = [];
            }

            $ref = &$ref[$parte];
        }

        $ref = $valor;

        self::$cache            = $dados;
        self::$alteracoes[$caminho] = $valor;

        unset(self::$remocoes[array_search($caminho, self::$remocoes, true)]);
    }

    /** Remove um ajuste, fazendo o valor voltar ao do arquivo de config. */
    public static function remover(string $caminho): void
    {
        $dados  = self::tudo();
        $partes = explode('.', $caminho);
        $ultima = array_pop($partes);
        $ref    = &$dados;

        foreach ($partes as $parte) {
            if (!isset($ref[$parte]) || !is_array($ref[$parte])) {
                return;
            }

            $ref = &$ref[$parte];
        }

        unset($ref[$ultima]);

        self::$cache      = $dados;
        self::$remocoes[] = $caminho;

        unset(self::$alteracoes[$caminho]);
    }

    /**
     * Grava relendo o disco e aplicando so o que esta execucao mudou.
     *
     * Escrever o retrato que estava em memoria causava perda de alteracao: o
     * painel roda no servidor embutido, que e um processo unico e longo, entao
     * o retrato dele envelhecia e o proximo salvamento apagava tudo que tinha
     * sido mudado pelo terminal no meio - buscas sumiam, flags voltavam atras.
     */
    public static function gravar(): bool
    {
        $doDisco = self::doDisco();

        foreach (self::$alteracoes as $caminho => $valor) {
            self::aplicarEm($doDisco, $caminho, $valor);
        }

        foreach (self::$remocoes as $caminho) {
            self::removerEm($doDisco, $caminho);
        }

        self::$cache = $doDisco;

        $json = json_encode(
            $doDisco,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if ($json === false) {
            return false;
        }

        $pasta = dirname(self::caminho());

        if (!is_dir($pasta)) {
            mkdir($pasta, 0775, true);
        }

        self::guardarCopia();

        $gravou = file_put_contents(self::caminho(), $json . PHP_EOL) !== false;

        if ($gravou) {
            clearstatcache(true, self::caminho());

            self::$versaoEmCache = (int) filemtime(self::caminho()) . ':' . (int) filesize(self::caminho());
            self::$alteracoes    = [];
            self::$remocoes      = [];
        }

        return $gravou;
    }

    /**
     * Guarda a versao anterior antes de sobrescrever.
     *
     * A configuracao inteira do sistema mora neste arquivo, e uma gravacao
     * errada apaga em silencio o que levou horas para calibrar - ja aconteceu:
     * as buscas voltaram todas desativadas e o ciclo passou a coletar nada, sem
     * nenhum erro na tela. Uma copia das ultimas versoes torna isso reversivel.
     */
    private static function guardarCopia(): void
    {
        $atual = self::caminho();

        if (!is_file($atual) || filesize($atual) === 0) {
            return;
        }

        $pasta = dirname($atual) . '/config-local-anterior';

        if (!is_dir($pasta)) {
            mkdir($pasta, 0775, true);
        }

        copy($atual, $pasta . '/' . date('Ymd-His') . '.json');

        // mantem so as ultimas copias; o resto e ruido
        $copias = glob($pasta . '/*.json') ?: [];

        if (count($copias) <= self::COPIAS_MANTIDAS) {
            return;
        }

        sort($copias);

        foreach (array_slice($copias, 0, count($copias) - self::COPIAS_MANTIDAS) as $velha) {
            @unlink($velha);
        }
    }

    /** @return string[] copias disponiveis, da mais nova para a mais antiga */
    public static function copias(): array
    {
        $copias = glob(dirname(self::caminho()) . '/config-local-anterior/*.json') ?: [];

        rsort($copias);

        return $copias;
    }

    /** @return array<string,mixed> */
    private static function doDisco(): array
    {
        $caminho = self::caminho();

        clearstatcache(true, $caminho);

        if (!is_file($caminho)) {
            return [];
        }

        $dados = json_decode((string) file_get_contents($caminho), true);

        return is_array($dados) ? $dados : [];
    }

    /** @param array<string,mixed> $alvo */
    private static function aplicarEm(array &$alvo, string $caminho, mixed $valor): void
    {
        $ref = &$alvo;

        foreach (explode('.', $caminho) as $parte) {
            if (!isset($ref[$parte]) || !is_array($ref[$parte])) {
                $ref[$parte] = [];
            }

            $ref = &$ref[$parte];
        }

        $ref = $valor;
    }

    /** @param array<string,mixed> $alvo */
    private static function removerEm(array &$alvo, string $caminho): void
    {
        $partes = explode('.', $caminho);
        $ultima = array_pop($partes);
        $ref    = &$alvo;

        foreach ($partes as $parte) {
            if (!isset($ref[$parte]) || !is_array($ref[$parte])) {
                return;
            }

            $ref = &$ref[$parte];
        }

        unset($ref[$ultima]);
    }

    /** Forca a proxima leitura a vir do disco. */
    public static function invalidar(): void
    {
        self::$cache         = null;
        self::$versaoEmCache = '';
    }

    /** @return array<string,mixed> o que esta execucao alterou */
    public static function alteracoes(): array
    {
        return self::$alteracoes;
    }

    /** Descarta todos os ajustes e volta ao padrao dos arquivos. */
    public static function limpar(): void
    {
        self::$cache         = [];
        self::$versaoEmCache = '';
        self::$alteracoes    = [];
        self::$remocoes      = [];

        if (is_file(self::caminho())) {
            unlink(self::caminho());
        }
    }

    /**
     * Aplica os ajustes por cima dos valores vindos de config/*.php.
     *
     * Lista (array de indices numericos) e substituida inteira: meia lista de
     * palavras bloqueadas nao faria sentido. Mapa e mesclado chave a chave.
     *
     * @param  array<string,mixed> $base
     * @return array<string,mixed>
     */
    public static function aplicar(array $base): array
    {
        return self::mesclar($base, self::tudo());
    }

    /**
     * @param  array<mixed> $base
     * @param  array<mixed> $novo
     * @return array<mixed>
     */
    private static function mesclar(array $base, array $novo): array
    {
        foreach ($novo as $chave => $valor) {
            if (is_array($valor) && isset($base[$chave]) && is_array($base[$chave]) && !array_is_list($valor)) {
                $base[$chave] = self::mesclar($base[$chave], $valor);

                continue;
            }

            $base[$chave] = $valor;
        }

        return $base;
    }
}
