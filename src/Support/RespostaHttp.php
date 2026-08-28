<?php

declare(strict_types=1);

namespace MlGroup\Support;

final class RespostaHttp
{
    public function __construct(
        public readonly int $status,
        public readonly string $corpo,
        public readonly ?string $erro = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        $dados = json_decode($this->corpo, true);

        return is_array($dados) ? $dados : [];
    }
}
