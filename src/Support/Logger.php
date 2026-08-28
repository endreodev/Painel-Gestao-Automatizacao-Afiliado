<?php

declare(strict_types=1);

namespace MlGroup\Support;

/**
 * Log em arquivo diario + console, com rotacao por retencao de dias.
 */
final class Logger
{
    public const DEBUG = 10;
    public const INFO  = 20;
    public const AVISO = 30;
    public const ERRO  = 40;

    /** @var array<string,int> */
    private const NIVEIS = [
        'debug' => self::DEBUG,
        'info'  => self::INFO,
        'aviso' => self::AVISO,
        'erro'  => self::ERRO,
    ];

    private static ?self $instancia = null;

    private function __construct(
        private readonly string $diretorio,
        private readonly int $nivelMinimo,
        private readonly bool $console,
        private readonly int $retencaoDias,
    ) {
    }

    public static function i(): self
    {
        if (self::$instancia === null) {
            $diretorio = MLG_ROOT . '/storage/logs';

            if (!is_dir($diretorio)) {
                mkdir($diretorio, 0775, true);
            }

            self::$instancia = new self(
                $diretorio,
                self::NIVEIS[strtolower(Config::texto('config.log.nivel', 'info'))] ?? self::INFO,
                Config::booleano('config.log.console', true),
                Config::inteiro('config.log.retencao_dias', 14),
            );

            self::$instancia->limparAntigos();
        }

        return self::$instancia;
    }

    public function debug(string $mensagem, array $contexto = []): void
    {
        $this->escrever(self::DEBUG, 'DEBUG', $mensagem, $contexto);
    }

    public function info(string $mensagem, array $contexto = []): void
    {
        $this->escrever(self::INFO, 'INFO', $mensagem, $contexto);
    }

    public function aviso(string $mensagem, array $contexto = []): void
    {
        $this->escrever(self::AVISO, 'AVISO', $mensagem, $contexto);
    }

    public function erro(string $mensagem, array $contexto = []): void
    {
        $this->escrever(self::ERRO, 'ERRO', $mensagem, $contexto);
    }

    private function escrever(int $nivel, string $rotulo, string $mensagem, array $contexto): void
    {
        if ($nivel < $this->nivelMinimo) {
            return;
        }

        $extra = $contexto === []
            ? ''
            : ' ' . json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $linha = sprintf(
            '[%s] %-5s %s%s',
            date('Y-m-d H:i:s'),
            $rotulo,
            $mensagem,
            $extra,
        );

        file_put_contents(
            $this->diretorio . '/' . date('Y-m-d') . '.log',
            $linha . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );

        /*
         * STDOUT so existe na CLI. Sob o servidor embutido (o painel) a
         * constante nao esta definida e o fwrite virava erro fatal - o painel
         * morria na primeira linha de log, sem nada na tela explicando.
         * O arquivo de log acima ja foi gravado de qualquer forma.
         */
        if ($this->console && PHP_SAPI === 'cli' && defined('STDOUT')) {
            $cor = match ($nivel) {
                self::ERRO  => "\033[31m",
                self::AVISO => "\033[33m",
                self::DEBUG => "\033[90m",
                default     => "\033[0m",
            };

            fwrite(STDOUT, $cor . $linha . "\033[0m" . PHP_EOL);
        }
    }

    private function limparAntigos(): void
    {
        $limite = time() - ($this->retencaoDias * 86400);

        foreach (glob($this->diretorio . '/*.log') ?: [] as $arquivo) {
            if (filemtime($arquivo) < $limite) {
                @unlink($arquivo);
            }
        }
    }
}
