<?php

declare(strict_types=1);

namespace MlGroup\App;

use MlGroup\Support\Logger;

/**
 * A tarefa do Windows que mantem o sistema de pe.
 *
 * O monitor sabe religar o laco de publicacao, mas alguem precisa chamar o
 * monitor. Sem isso o sistema depende de o usuario lembrar de rodar um comando:
 * a maquina reinicia, o laco nao volta, e as publicacoes param em silencio ate
 * alguem estranhar o grupo parado.
 *
 * A tarefa aponta para um arquivo em storage/, e nao para o php.exe direto. O
 * caminho do PHP instalado pelo winget tem espacos e parenteses, e montar isso
 * dentro do /tr do schtasks - que ja usa aspas por fora - quebra de formas
 * dificeis de diagnosticar. Um arquivo intermediario tem caminho simples e ainda
 * pode ser executado a mao para ver o que da errado.
 */
final class TarefaAgendada
{
    public const NOME = 'ml-group monitor';

    /** A tarefa esta cadastrada no Windows? */
    public static function existe(): bool
    {
        if (!self::noWindows()) {
            return false;
        }

        exec('schtasks /query /tn ' . escapeshellarg(self::NOME) . ' 2>NUL', $saida, $codigo);

        return $codigo === 0;
    }

    /**
     * Cria (ou refaz) a tarefa.
     *
     * @return array{ok:bool,mensagem:string}
     */
    public static function criar(int $minutos = 5): array
    {
        if (!self::noWindows()) {
            return ['ok' => false, 'mensagem' => 'O agendamento automático só está pronto para Windows.'];
        }

        $minutos = max(1, min(1439, $minutos));

        self::escreverLancadores();

        $comando = 'schtasks /create'
            . ' /tn ' . escapeshellarg(self::NOME)
            . ' /tr ' . escapeshellarg(self::lancadorOculto())
            . ' /sc minute /mo ' . $minutos
            . ' /f';

        exec($comando . ' 2>&1', $saida, $codigo);

        if ($codigo !== 0) {
            $motivo = trim(implode(' ', $saida));

            Logger::i()->erro('Nao foi possivel agendar o monitor', ['motivo' => $motivo]);

            return ['ok' => false, 'mensagem' => $motivo !== '' ? $motivo : 'schtasks devolveu erro ' . $codigo];
        }

        return [
            'ok'       => true,
            'mensagem' => 'Tarefa "' . self::NOME . '" criada: roda a cada ' . $minutos . ' minuto(s).',
        ];
    }

    public static function remover(): bool
    {
        if (!self::noWindows()) {
            return false;
        }

        exec('schtasks /delete /tn ' . escapeshellarg(self::NOME) . ' /f 2>&1', $saida, $codigo);

        return $codigo === 0;
    }

    /**
     * Escreve os dois lancadores.
     *
     * O .cmd faz o trabalho; o .vbs so o chama com a janela escondida. Sem o
     * .vbs, uma janela de console piscaria na tela a cada cinco minutos, para
     * sempre - o tipo de incomodo que faz alguem desligar o agendamento e voltar
     * a ficar sem monitor nenhum.
     */
    private static function escreverLancadores(): void
    {
        $pasta = MLG_ROOT . '/storage';

        if (!is_dir($pasta)) {
            @mkdir($pasta, 0777, true);
        }

        $aspas = static fn (string $caminho): string => '"' . self::comBarraDoWindows($caminho) . '"';

        $cmd = "@echo off\r\n"
            . "rem Gerado por: php bin/mlgroup agendar\r\n"
            . 'cd /d ' . $aspas(MLG_ROOT) . "\r\n"
            . $aspas(PHP_BINARY) . ' ' . $aspas(MLG_ROOT . '/bin/mlgroup') . ' monitor'
            . ' >> ' . $aspas(MLG_ROOT . '/storage/monitor.log') . " 2>&1\r\n";

        file_put_contents(self::lancador(), $cmd);

        // no VBScript, aspas dentro de texto se escrevem dobradas
        $vbs = "' Gerado por: php bin/mlgroup agendar\r\n"
            . "' Chama o monitor.cmd sem abrir janela.\r\n"
            . 'CreateObject("WScript.Shell").Run """'
            . self::comBarraDoWindows(self::lancador())
            . '""", 0, False' . "\r\n";

        file_put_contents(self::lancadorOculto(), $vbs);
    }

    public static function lancador(): string
    {
        return self::comBarraDoWindows(MLG_ROOT . '/storage/monitor.cmd');
    }

    public static function lancadorOculto(): string
    {
        return self::comBarraDoWindows(MLG_ROOT . '/storage/monitor.vbs');
    }

    /** MLG_ROOT usa barra normal; cmd e schtasks esperam a do Windows. */
    private static function comBarraDoWindows(string $caminho): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, $caminho);
    }

    private static function noWindows(): bool
    {
        return stripos(PHP_OS_FAMILY, 'Windows') !== false;
    }
}
