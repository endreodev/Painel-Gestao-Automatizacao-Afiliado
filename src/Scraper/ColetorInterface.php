<?php

declare(strict_types=1);

namespace MlGroup\Scraper;

use MlGroup\Model\Produto;

interface ColetorInterface
{
    /**
     * @param  array<string,mixed> $busca Definicao vinda de config/buscas.php
     * @return Produto[]
     */
    public function coletar(array $busca, int $limite): array;

    public function nome(): string;

    /** Coletor consegue operar agora? (token valido, chrome instalado, etc.) */
    public function disponivel(): bool;
}
