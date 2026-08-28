<?php

declare(strict_types=1);

namespace MlGroup\Cli;

/**
 * Escrita no terminal com cor ANSI.
 */
final class Saida
{
    public function linha(string $texto, string $cor = '0'): void
    {
        $this->escrever("\033[" . $cor . 'm' . $texto . "\033[0m" . PHP_EOL);
    }

    public function titulo(string $texto): void
    {
        $this->linha($texto, '1');
    }

    /** Texto sem formatacao nem quebra automatica (usado no desenho do QR). */
    public function cru(string $texto): void
    {
        $this->escrever($texto . PHP_EOL);
    }

    public function escrever(string $texto): void
    {
        fwrite(STDOUT, $texto);
    }

    /** Da para esperar o usuario digitar algo? */
    public function interativo(): bool
    {
        return stream_isatty(STDIN);
    }
}
