<?php

declare(strict_types=1);

namespace MlGroup\App;

use MlGroup\Support\Config;

/**
 * Um canal: um grupo de WhatsApp com nicho, buscas e filtros próprios.
 *
 * Antes o sistema tinha um nicho e um conjunto de buscas globais - servia para
 * um grupo só. Com dois grupos de assuntos diferentes (ferramentas e
 * utilidades), cada um precisa procurar coisas diferentes e recusar coisas
 * diferentes.
 *
 * Como isso chega no resto do código: em vez de passar o canal por parâmetro
 * em toda a cadeia (coletor, filtro, nicho, pontuador, fila, publicador), o
 * canal fica "ativo" durante o ciclo e o Config consulta as sobreposições dele
 * antes de responder. Uma mudança num ponto, em vez de vinte.
 */
final class Canal
{
    private static ?self $ativo = null;

    /**
     * @param array<string,mixed> $dados
     */
    private function __construct(private readonly array $dados)
    {
    }

    /** @return self[] todos os canais cadastrados */
    public static function todos(): array
    {
        $canais = [];

        foreach (Config::lista('canais.canais') as $bruto) {
            if (is_array($bruto) && ($bruto['id'] ?? '') !== '') {
                $canais[] = new self($bruto);
            }
        }

        return $canais;
    }

    /** @return self[] só os ligados */
    public static function ativos(): array
    {
        return array_values(array_filter(
            self::todos(),
            static fn (self $canal): bool => $canal->ligado(),
        ));
    }

    public static function porId(string $id): ?self
    {
        foreach (self::todos() as $canal) {
            if ($canal->id() === $id) {
                return $canal;
            }
        }

        return null;
    }

    /** O canal em uso agora. Null quando o sistema roda sem canais. */
    public static function ativo(): ?self
    {
        return self::$ativo;
    }

    public static function ativar(?self $canal): void
    {
        self::$ativo = $canal;
    }

    /**
     * Roda algo com o canal ativo e devolve o estado anterior no fim.
     *
     * O finally importa: uma exceção no meio do ciclo de um canal não pode
     * deixar o canal seguinte herdando a configuração do anterior.
     */
    public static function comCanal(self $canal, callable $acao): mixed
    {
        $anterior = self::$ativo;
        self::$ativo = $canal;

        try {
            return $acao();
        } finally {
            self::$ativo = $anterior;
        }
    }

    /**
     * Valor sobreposto pelo canal ativo, se houver.
     *
     * @return array{0:bool,1:mixed} [achou, valor]
     */
    public static function sobrepor(string $caminho): array
    {
        $canal = self::$ativo;

        if ($canal === null) {
            return [false, null];
        }

        // o canal pode trocar o perfil de nicho inteiro
        if (str_starts_with($caminho, 'nicho.') && is_array($canal->dados['nicho'] ?? null)) {
            $chave = substr($caminho, strlen('nicho.'));

            return array_key_exists($chave, $canal->dados['nicho'])
                ? [true, $canal->dados['nicho'][$chave]]
                : [false, null];
        }

        if ($caminho === 'buscas.buscas' && is_array($canal->dados['buscas'] ?? null)) {
            return [true, $canal->dados['buscas']];
        }

        $ajustes = $canal->dados['ajustes'] ?? [];

        if (is_array($ajustes) && array_key_exists($caminho, $ajustes)) {
            return [true, $ajustes[$caminho]];
        }

        return [false, null];
    }

    public function id(): string
    {
        return (string) $this->dados['id'];
    }

    public function nome(): string
    {
        return (string) ($this->dados['nome'] ?? $this->id());
    }

    public function ligado(): bool
    {
        return ($this->dados['ativo'] ?? true) === true;
    }

    /** @return string[] IDs de grupo do WhatsApp deste canal */
    public function grupos(): array
    {
        $grupos = $this->dados['grupos'] ?? [];

        if (is_string($grupos)) {
            $grupos = explode(',', $grupos);
        }

        return array_values(array_filter(array_map('trim', array_map('strval', (array) $grupos))));
    }

    /** @return array<int,array<string,mixed>> */
    public function buscas(): array
    {
        $buscas = $this->dados['buscas'] ?? null;

        return is_array($buscas) ? $buscas : Config::lista('buscas.buscas');
    }

    /** @return array<string,mixed> */
    public function paraArray(): array
    {
        return $this->dados;
    }
}
