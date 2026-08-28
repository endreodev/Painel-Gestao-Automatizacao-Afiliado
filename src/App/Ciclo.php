<?php

declare(strict_types=1);

namespace MlGroup\App;

use MlGroup\Database\Db;
use MlGroup\Support\Config;
use MlGroup\Support\Logger;
use Throwable;

/**
 * Uma rodada completa: cacar -> publicar -> registrar.
 *
 * Existe separada do Agendador para poder ser chamada tanto pelo laco continuo
 * quanto por uma tarefa agendada do Windows / cron, sem duplicar regra.
 */
final class Ciclo
{
    public function __construct(
        private readonly Cacador $cacador = new Cacador(),
        private readonly Publicador $publicador = new Publicador(),
        private readonly Fila $fila = new Fila(),
    ) {
    }

    /**
     * Uma rodada em todos os canais ligados.
     *
     * @return array{coletados:int,aprovados:int,enviados:int}
     */
    public function executar(bool $somenteAnalise = false): array
    {
        $canais = Canal::ativos();

        if ($canais === []) {
            return $this->executarCanal($somenteAnalise);
        }

        $total = ['coletados' => 0, 'aprovados' => 0, 'enviados' => 0];

        foreach ($canais as $canal) {
            Logger::i()->info('Canal', ['nome' => $canal->nome(), 'grupos' => count($canal->grupos())]);

            $resumo = Canal::comCanal($canal, fn (): array => $this->executarCanal($somenteAnalise));

            foreach ($total as $chave => $_) {
                $total[$chave] += $resumo[$chave];
            }
        }

        return $total;
    }

    /** @return array{coletados:int,aprovados:int,enviados:int} */
    private function executarCanal(bool $somenteAnalise = false): array
    {
        $execucao = $this->abrirExecucao();
        $resumo   = ['coletados' => 0, 'aprovados' => 0, 'enviados' => 0];

        try {
            if ($this->fila->precisaColetar()) {
                $aprovados = $this->cacador->cacar();

                $resumo['aprovados'] = count($aprovados);
                $resumo['coletados'] = $this->cacador->coletados();
            } else {
                Logger::i()->info('Coleta ainda no prazo, usando a fila', [
                    'na_fila' => $this->fila->tamanho(),
                ]);
            }

            if ($somenteAnalise) {
                Logger::i()->info('Modo analise: nada foi enviado');
            } else {
                // a fila decide o que sai, e nao o resultado da coleta: assim o
                // ciclo publica na cadencia certa mesmo quando nao coletou nada
                $lote = $this->fila->proximos(Config::inteiro('config.envio.max_por_execucao', 1));

                $resumo['enviados'] = $this->publicador->publicar($lote);

                /*
                 * Quem furou a fila e ja saiu perde a prioridade. Sem isso o
                 * marcador ficaria para sempre: passada a janela de nao-repetir,
                 * o produto voltaria a ser coletado e furaria a fila de novo
                 * sozinho, sem ninguem ter clicado.
                 */
                $this->fila->limparPrioridadesPublicadas();
            }

            $this->fecharExecucao($execucao, $resumo, 'ok', null);
        } catch (Throwable $erro) {
            Logger::i()->erro('Ciclo abortado', [
                'motivo'  => $erro->getMessage(),
                'arquivo' => basename($erro->getFile()) . ':' . $erro->getLine(),
            ]);

            $this->fecharExecucao($execucao, $resumo, 'erro', $erro->getMessage());
        }

        Logger::i()->info('Ciclo encerrado', $resumo);

        return $resumo;
    }

    private function abrirExecucao(): int
    {
        Db::executar(
            "INSERT INTO execucoes (iniciado_em, canal, status) VALUES (:agora, :canal, 'rodando')",
            ['agora' => date('Y-m-d H:i:s'), 'canal' => Canal::ativo()?->id() ?? 'padrao'],
        );

        return (int) Db::conexao()->lastInsertId();
    }

    /** @param array{coletados:int,aprovados:int,enviados:int} $resumo */
    private function fecharExecucao(int $id, array $resumo, string $status, ?string $detalhe): void
    {
        Db::executar(
            'UPDATE execucoes
                SET encerrado_em = :agora,
                    coletados    = :coletados,
                    aprovados    = :aprovados,
                    enviados     = :enviados,
                    status       = :status,
                    detalhe      = :detalhe
              WHERE id = :id',
            [
                'agora'     => date('Y-m-d H:i:s'),
                'coletados' => $resumo['coletados'],
                'aprovados' => $resumo['aprovados'],
                'enviados'  => $resumo['enviados'],
                'status'    => $status,
                'detalhe'   => $detalhe,
                'id'        => $id,
            ],
        );
    }
}
