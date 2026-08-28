<?php

declare(strict_types=1);

namespace MlGroup\Analise;

use MlGroup\App\Canal;
use MlGroup\Database\Db;
use MlGroup\Model\Produto;
use MlGroup\Support\Str;

/**
 * O que o usuario mandou nao aparecer mais.
 *
 * Existe porque calibrar filtro nao resolve caso particular. Quando aparece um
 * anuncio ruim, uma marca que decepcionou ou um vendedor em quem nao se confia,
 * a unica saida era mexer nos termos bloqueados do nicho e torcer para nao
 * derrubar junto meia duzia de produtos bons - o descarte e o oposto disso:
 * atinge exatamente o que foi apontado e nada mais.
 *
 * E por canal. A mesma marca pode ser indesejada no grupo de ferramentas e
 * irrelevante no de utilidades, e um descarte global obrigaria a pensar nos dois
 * grupos toda vez que se clica em um.
 */
final class Descartes
{
    public const PRODUTO  = 'produto';
    public const MARCA    = 'marca';
    public const VENDEDOR = 'vendedor';

    /**
     * Cache por canal: o filtro chama isto uma vez por produto avaliado, e uma
     * coleta avalia centenas.
     *
     * @var array<string,array<string,array<string,string>>>
     */
    private static array $cache = [];

    public function __construct(private readonly Diversidade $diversidade = new Diversidade())
    {
    }

    /** @return string|null O motivo, quando o produto foi descartado. */
    public function motivo(Produto $produto): ?string
    {
        $regras = $this->doCanal();

        if ($regras === []) {
            return null;
        }

        if (isset($regras[self::PRODUTO][$produto->mlId])) {
            return 'descartado a mão';
        }

        $marca = $this->diversidade->marca($produto);

        if ($marca !== '' && isset($regras[self::MARCA][$marca])) {
            return 'marca "' . $regras[self::MARCA][$marca] . '" descartada';
        }

        $vendedor = Str::normalizar($produto->vendedor);

        if ($vendedor !== '' && isset($regras[self::VENDEDOR][$vendedor])) {
            return 'vendedor "' . $regras[self::VENDEDOR][$vendedor] . '" descartado';
        }

        return null;
    }

    /**
     * Guarda um descarte.
     *
     * O rotulo e so para a tela de descartes: sem ele a lista mostraria um
     * "MLB2093580153" que ninguem reconhece.
     */
    public function guardar(string $tipo, string $valor, string $rotulo = ''): bool
    {
        $valor = $tipo === self::PRODUTO ? trim($valor) : Str::normalizar($valor);

        if ($valor === '' || !in_array($tipo, [self::PRODUTO, self::MARCA, self::VENDEDOR], true)) {
            return false;
        }

        Db::executar(
            'INSERT OR IGNORE INTO descartes (canal, tipo, valor, rotulo, criado_em)
             VALUES (:canal, :tipo, :valor, :rotulo, :agora)',
            [
                'canal'  => $this->canal(),
                'tipo'   => $tipo,
                'valor'  => $valor,
                'rotulo' => $rotulo !== '' ? $rotulo : $valor,
                'agora'  => date('Y-m-d H:i:s'),
            ],
        );

        self::limparCache();

        return true;
    }

    /** Desfaz um descarte pelo id. */
    public function remover(int $id): bool
    {
        $afetadas = Db::executar(
            'DELETE FROM descartes WHERE id = :id AND canal = :canal',
            ['id' => $id, 'canal' => $this->canal()],
        )->rowCount();

        self::limparCache();

        return $afetadas > 0;
    }

    /**
     * Tudo o que foi descartado neste canal, do mais recente para o mais antigo.
     *
     * @return array<int,array<string,mixed>>
     */
    public function todos(): array
    {
        return Db::todos(
            'SELECT id, tipo, valor, rotulo, criado_em FROM descartes
              WHERE canal = :canal
              ORDER BY criado_em DESC, id DESC',
            ['canal' => $this->canal()],
        );
    }

    public function quantidade(): int
    {
        return (int) Db::valor(
            'SELECT COUNT(*) FROM descartes WHERE canal = :canal',
            ['canal' => $this->canal()],
        );
    }

    /**
     * As regras deste canal, indexadas para consulta direta.
     *
     * @return array<string,array<string,string>> tipo -> valor normalizado -> rotulo
     */
    private function doCanal(): array
    {
        $canal = $this->canal();

        if (isset(self::$cache[$canal])) {
            return self::$cache[$canal];
        }

        $regras = [];

        foreach ($this->todos() as $linha) {
            $regras[(string) $linha['tipo']][(string) $linha['valor']] = (string) ($linha['rotulo'] ?: $linha['valor']);
        }

        return self::$cache[$canal] = $regras;
    }

    private function canal(): string
    {
        return Canal::ativo()?->id() ?? 'padrao';
    }

    public static function limparCache(): void
    {
        self::$cache = [];
    }
}
