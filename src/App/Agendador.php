<?php

declare(strict_types=1);

namespace MlGroup\App;

use DateTimeImmutable;
use MlGroup\Support\Config;
use MlGroup\Support\Logger;

/**
 * Laco continuo que dispara o Ciclo de tempos em tempos.
 *
 * A janela de funcionamento (dias da semana e horario) evita o pior erro de um
 * bot de ofertas: acordar o grupo as 3 da manha.
 */
final class Agendador
{
    private bool $parar = false;

    public function __construct(private readonly Ciclo $ciclo = new Ciclo())
    {
    }

    public function rodar(?int $intervaloMinutos = null, ?int $maximoCiclos = null): void
    {
        $intervalo = $intervaloMinutos ?? Config::inteiro('config.agenda.intervalo_minutos', 60);
        $intervalo = max(1, $intervalo);

        $this->capturarSinais();

        Logger::i()->info('Agendador iniciado', [
            'intervalo_min' => $intervalo,
            'janela'        => Config::texto('config.agenda.hora_inicio', '08:00')
                . '-' . Config::texto('config.agenda.hora_fim', '22:00'),
        ]);

        $ciclos = 0;

        Sentinela::pulsar();

        while (!$this->parar) {
            // o pulso e o que permite ao monitor saber que o laco esta vivo
            Sentinela::pulsar();

            /*
             * Reler a configuracao a cada volta faz o que o painel salvou valer
             * no proximo ciclo. Sem isso, o laco ficaria com a copia carregada
             * na largada e so obedeceria a um ajuste depois de reiniciado - que
             * e exatamente o tipo de surpresa que faz alguem achar que salvou
             * errado.
             */
            Config::recarregar();

            $agora = new DateTimeImmutable();

            if ($this->dentroDaJanela($agora)) {
                $this->ciclo->executar();
                $ciclos++;
            } else {
                Logger::i()->info('Fora da janela de envio, aguardando', ['agora' => $agora->format('D H:i')]);
            }

            if ($maximoCiclos !== null && $ciclos >= $maximoCiclos) {
                Logger::i()->info('Limite de ciclos atingido, encerrando');
                break;
            }

            $this->dormir($intervalo * 60);
        }

        // encerramento de proposito: sem pulso, o monitor nao religa
        Sentinela::encerrarPulso();

        Logger::i()->info('Agendador encerrado', ['ciclos' => $ciclos]);
    }

    public function dentroDaJanela(DateTimeImmutable $momento): bool
    {
        $dias = Config::lista('config.agenda.dias_semana');

        // 1 = segunda ... 7 = domingo (ISO-8601)
        if ($dias !== [] && !in_array((int) $momento->format('N'), array_map('intval', $dias), true)) {
            return false;
        }

        $inicio = Config::texto('config.agenda.hora_inicio', '09:00');
        $fim    = Config::texto('config.agenda.hora_fim', '22:00');
        $hora   = $momento->format('H:i');

        /*
         * O fim e exclusivo: "das 9 as 22" quer dizer que as 22:00 ja nao envia
         * mais. Fosse inclusivo, uma mensagem escaparia exatamente na virada.
         */
        if ($inicio <= $fim) {
            return $hora >= $inicio && $hora < $fim;
        }

        // janela que atravessa a meia-noite (ex.: 22:00 as 02:00)
        return $hora >= $inicio || $hora < $fim;
    }

    /** Dorme em fatias para responder ao Ctrl+C sem esperar o intervalo inteiro. */
    private function dormir(int $segundos): void
    {
        $fim = time() + $segundos;

        while (time() < $fim && !$this->parar) {
            sleep(min(5, max(1, $fim - time())));

            // pulsa durante a espera: um ciclo longo nao pode parecer morte
            Sentinela::pulsar();

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    private function capturarSinais(): void
    {
        if (!function_exists('pcntl_signal')) {
            // Windows sem pcntl: o Ctrl+C encerra o processo direto
            return;
        }

        $encerrar = function (): void {
            Logger::i()->info('Sinal de parada recebido, finalizando o ciclo atual');
            $this->parar = true;
        };

        pcntl_signal(SIGINT, $encerrar);
        pcntl_signal(SIGTERM, $encerrar);
    }
}
