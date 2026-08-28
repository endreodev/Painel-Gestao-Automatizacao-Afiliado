<?php

declare(strict_types=1);

namespace MlGroup\Whatsapp;

final class ResultadoEnvio
{
    private function __construct(
        public readonly bool $sucesso,
        public readonly string $idMensagem = '',
        public readonly string $erro = '',
    ) {
    }

    public static function ok(string $idMensagem = ''): self
    {
        return new self(true, $idMensagem);
    }

    public static function falha(string $erro): self
    {
        return new self(false, '', $erro);
    }
}
