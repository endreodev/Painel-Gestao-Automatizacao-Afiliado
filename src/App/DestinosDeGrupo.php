<?php

declare(strict_types=1);

namespace MlGroup\App;

/**
 * A regra de "qual canal publica em qual grupo".
 *
 * Fica sozinha aqui porque o painel e o CLI decidem a mesma coisa por caminhos
 * diferentes - a tela pergunta por grupo ("este grupo vai para qual canal?") e o
 * terminal pergunta por canal ("estes grupos vao para o canal X"). Escrita duas
 * vezes, ela ja divergiu: um lado tirava o grupo dos outros canais e o outro
 * nao, entao a mesma oferta saia duas vezes no mesmo grupo dependendo de onde a
 * escolha tinha sido feita.
 *
 * Sem estado e sem gravar nada: recebe o mapa atual, devolve o novo. Quem grava
 * decide onde.
 */
final class DestinosDeGrupo
{
    /**
     * Aponta cada grupo citado para o canal escolhido.
     *
     * Canal vazio (ou inexistente) significa "nenhum": o grupo sai de todos e
     * nao entra em lugar nenhum. Canal que nao aparece em $destinos fica como
     * estava - um POST parcial nao pode esvaziar canal que o usuario nem viu.
     *
     * @param  array<string,string[]> $porCanal id do canal -> grupos de hoje
     * @param  array<string,string>   $destinos id do grupo  -> id do canal
     * @return array<string,string[]> o mapa novo
     */
    public static function aplicar(array $porCanal, array $destinos): array
    {
        foreach ($destinos as $grupo => $canal) {
            $grupo = trim((string) $grupo);
            $canal = trim((string) $canal);

            if ($grupo === '') {
                continue;
            }

            $porCanal = self::remover($porCanal, [$grupo]);

            if ($canal !== '' && isset($porCanal[$canal])) {
                $porCanal[$canal][] = $grupo;
            }
        }

        return $porCanal;
    }

    /**
     * Move um conjunto de grupos para um canal de uma vez.
     *
     * O caminho do terminal: la a pergunta e "estes grupos vao para o canal X",
     * e o que ja estava no canal precisa continuar.
     *
     * @param  array<string,string[]> $porCanal
     * @param  string[]               $grupos
     * @return array<string,string[]>
     */
    public static function mover(array $porCanal, array $grupos, string $canal): array
    {
        $grupos = array_values(array_filter(array_map('trim', $grupos)));

        if ($grupos === [] || !isset($porCanal[$canal])) {
            return $porCanal;
        }

        $porCanal = self::remover($porCanal, $grupos);

        foreach ($grupos as $grupo) {
            $porCanal[$canal][] = $grupo;
        }

        return $porCanal;
    }

    /**
     * @param  array<string,string[]> $porCanal
     * @param  string[]               $grupos
     * @return array<string,string[]>
     */
    private static function remover(array $porCanal, array $grupos): array
    {
        foreach ($porCanal as $canal => $atuais) {
            $porCanal[$canal] = array_values(array_filter(
                $atuais,
                static fn (string $g): bool => !in_array($g, $grupos, true),
            ));
        }

        return $porCanal;
    }

    /**
     * O mapa de hoje, lido dos canais cadastrados.
     *
     * @return array<string,string[]>
     */
    public static function atual(): array
    {
        $mapa = [];

        foreach (Canal::todos() as $canal) {
            $mapa[$canal->id()] = $canal->grupos();
        }

        return $mapa;
    }
}
