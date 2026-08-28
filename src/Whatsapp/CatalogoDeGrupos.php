<?php

declare(strict_types=1);

namespace MlGroup\Whatsapp;

use MlGroup\Support\Logger;
use Throwable;

/**
 * A lista de grupos do WhatsApp, com a ultima resposta guardada em disco.
 *
 * Consultar a ponte custa uma ida ao WhatsApp Web e falha inteira quando a
 * sessao cai. Sem cache, a tela de grupos ficava lenta no caminho feliz e
 * simplesmente vazia no caminho ruim - logo quando o usuario mais precisa dela,
 * que e para conferir onde o sistema deveria estar publicando.
 *
 * Entao a leitura ao vivo so acontece quando pedida, e o que ela devolve fica
 * salvo. Fora disso a tela mostra a ultima lista conhecida, dizendo de quando
 * ela e - uma lista de ontem com o aviso na tela e mais util do que nada.
 */
final class CatalogoDeGrupos
{
    /** @var array<int,array<string,mixed>>|null */
    private static ?array $memoria = null;

    /**
     * A lista atual.
     *
     * @return array{grupos:array<int,array<string,mixed>>,atualizado_em:?string,ao_vivo:bool,erro:string}
     */
    public static function atual(bool $aoVivo = false): array
    {
        if (!$aoVivo) {
            $cache = self::lerCache();

            if ($cache !== null) {
                return [
                    'grupos'        => $cache['grupos'],
                    'atualizado_em' => $cache['atualizado_em'],
                    'ao_vivo'       => false,
                    'erro'          => '',
                ];
            }
        }

        $erro   = '';
        $grupos = [];

        try {
            $grupos = Fabrica::criar()->grupos();
        } catch (Throwable $falha) {
            $erro = $falha->getMessage();
            Logger::i()->erro('Nao foi possivel listar grupos', ['motivo' => $erro]);
        }

        if ($grupos !== []) {
            self::gravarCache($grupos);

            return [
                'grupos'        => self::normalizar($grupos),
                'atualizado_em' => date('Y-m-d H:i:s'),
                'ao_vivo'       => true,
                'erro'          => '',
            ];
        }

        /*
         * Consulta vazia nao apaga o cache: desconectar do WhatsApp nao deveria
         * fazer o painel esquecer quais grupos existem.
         */
        $cache = self::lerCache();

        return [
            'grupos'        => $cache['grupos'] ?? [],
            'atualizado_em' => $cache['atualizado_em'] ?? null,
            'ao_vivo'       => false,
            'erro'          => $erro,
        ];
    }

    /** Nome do grupo pelo id, para mostrar onde so temos o id guardado. */
    public static function nomeDe(string $id): string
    {
        foreach (self::atual()['grupos'] as $grupo) {
            if ($grupo['id'] === $id) {
                return (string) $grupo['nome'];
            }
        }

        return '';
    }

    /** @return array{grupos:array<int,array<string,mixed>>,atualizado_em:?string}|null */
    private static function lerCache(): ?array
    {
        if (self::$memoria !== null) {
            return ['grupos' => self::$memoria, 'atualizado_em' => self::quando()];
        }

        $arquivo = self::arquivo();

        if (!is_file($arquivo)) {
            return null;
        }

        $dados = json_decode((string) file_get_contents($arquivo), true);

        if (!is_array($dados) || !is_array($dados['grupos'] ?? null)) {
            return null;
        }

        self::$memoria = self::normalizar($dados['grupos']);

        return [
            'grupos'        => self::$memoria,
            'atualizado_em' => isset($dados['atualizado_em']) ? (string) $dados['atualizado_em'] : self::quando(),
        ];
    }

    /** @param array<int,array<string,mixed>> $grupos */
    private static function gravarCache(array $grupos): void
    {
        self::$memoria = self::normalizar($grupos);

        $arquivo = self::arquivo();
        $pasta   = dirname($arquivo);

        if (!is_dir($pasta)) {
            @mkdir($pasta, 0777, true);
        }

        @file_put_contents($arquivo, json_encode(
            ['atualizado_em' => date('Y-m-d H:i:s'), 'grupos' => self::$memoria],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Preenche o que um driver mais simples nao devolve.
     *
     * Nem todo driver traz participantes ou restricao; a tela pode contar com as
     * chaves existindo sempre.
     *
     * @param  array<int,array<string,mixed>> $grupos
     * @return array<int,array<string,mixed>>
     */
    private static function normalizar(array $grupos): array
    {
        $limpos = [];

        foreach ($grupos as $grupo) {
            if (!is_array($grupo) || (string) ($grupo['id'] ?? '') === '') {
                continue;
            }

            $limpos[] = [
                'id'            => (string) $grupo['id'],
                'nome'          => (string) ($grupo['nome'] ?? '(sem nome)'),
                'participantes' => (int) ($grupo['participantes'] ?? 0),
                'somente_admin' => (bool) ($grupo['somente_admin'] ?? false),
                'sou_admin'     => array_key_exists('sou_admin', $grupo) && $grupo['sou_admin'] !== null
                    ? (bool) $grupo['sou_admin']
                    : null,
            ];
        }

        usort($limpos, static fn (array $a, array $b): int => strcoll(
            mb_strtolower((string) $a['nome']),
            mb_strtolower((string) $b['nome']),
        ));

        return $limpos;
    }

    private static function quando(): ?string
    {
        $arquivo = self::arquivo();

        return is_file($arquivo) ? date('Y-m-d H:i:s', (int) filemtime($arquivo)) : null;
    }

    private static function arquivo(): string
    {
        return MLG_ROOT . '/storage/grupos.json';
    }

    /** Usado pelos testes. */
    public static function limparCache(): void
    {
        self::$memoria = null;
    }
}
