<?php

declare(strict_types=1);

namespace MlGroup\App;

use MlGroup\Database\Db;
use MlGroup\Support\Config;
use MlGroup\Support\Logger;

/**
 * Vigia do sistema: sabe o que deveria estar rodando, o que está, e religa.
 *
 * Existe porque a falha mais cara aqui é silenciosa. O laço de publicação pode
 * cair por reinício da máquina, Ctrl+C sem querer, ou um erro no meio da noite -
 * e nada avisa. O grupo simplesmente para de receber oferta, e a única pista é
 * ninguém reclamar de nada.
 *
 * O laço registra um pulso a cada volta. Se o pulso envelhece além do intervalo
 * esperado, ele morreu - mesmo que o processo ainda exista travado em algum
 * lugar.
 *
 * Feito para rodar de tempos em tempos pelo Agendador de Tarefas do Windows:
 *   php bin/mlgroup monitor
 */
final class Sentinela
{
    private const LACO = 'rodar';

    /** Escreve o pulso do laço. Chamado pelo Agendador a cada volta. */
    public static function pulsar(): void
    {
        $arquivo = self::arquivoPulso(self::LACO);
        $pasta   = dirname($arquivo);

        if (!is_dir($pasta)) {
            mkdir($pasta, 0775, true);
        }

        $anterior = is_file($arquivo)
            ? json_decode((string) file_get_contents($arquivo), true)
            : null;

        file_put_contents($arquivo, (string) json_encode([
            'pid'           => getmypid(),
            'atualizado_em' => date('Y-m-d H:i:s'),

            // guardados na primeira batida e mantidos: e a idade do processo,
            // nao a da ultima volta, que diz se ele carregou o codigo atual
            'iniciado_em'   => is_array($anterior) ? ($anterior['iniciado_em'] ?? time()) : time(),
            'codigo_em'     => is_array($anterior) ? ($anterior['codigo_em'] ?? self::versaoDoCodigo()) : self::versaoDoCodigo(),
        ]));
    }

    /**
     * Data da alteração mais recente no código.
     *
     * O PHP carrega as classes uma vez, na largada. Um laço iniciado antes de
     * uma correção continua executando a versão antiga por tempo indefinido -
     * ele publica, não dá erro, e o defeito corrigido continua acontecendo. Já
     * mordeu duas vezes; por isso o monitor compara e reinicia.
     */
    private static function versaoDoCodigo(): int
    {
        $mais = 0;

        $itens = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(MLG_ROOT . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($itens as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile() && $item->getExtension() === 'php') {
                $mais = max($mais, $item->getMTime());
            }
        }

        return $mais;
    }

    /** Apaga o pulso ao encerrar de propósito, para o monitor não religar. */
    public static function encerrarPulso(): void
    {
        $arquivo = self::arquivoPulso(self::LACO);

        if (is_file($arquivo)) {
            unlink($arquivo);
        }
    }

    /**
     * Situação de tudo que deveria estar de pé.
     *
     * @return array<string,array{nome:string,ativo:bool,detalhe:string}>
     */
    public function estado(): array
    {
        return [
            'laco'   => $this->estadoLaco(),
            'ponte'  => $this->estadoPonte(),
            'coleta' => $this->estadoColeta(),
        ];
    }

    /**
     * Religa o que estiver parado.
     *
     * @return array<int,string> o que foi feito
     */
    public function garantir(): array
    {
        $acoes = [];
        $estado = $this->estado();

        if (!$estado['laco']['ativo']) {
            // um laco vivo mas desatualizado precisa morrer antes de subir de novo
            $this->pararLaco();

            $acoes[] = $this->iniciarLaco()
                ? 'laço de publicação reiniciado'
                : 'FALHA ao reiniciar o laço de publicação';
        }

        return $acoes;
    }

    /** @return array{nome:string,ativo:bool,detalhe:string} */
    private function estadoLaco(): array
    {
        $pulso = $this->lerPulso(self::LACO);

        if ($pulso === null) {
            return ['nome' => 'Laço de publicação', 'ativo' => false, 'detalhe' => 'não está rodando'];
        }

        $idade = time() - (strtotime((string) ($pulso['atualizado_em'] ?? '')) ?: 0);

        /*
         * Tolerância = dois ciclos + folga. Um ciclo que coleta pode demorar
         * minutos (cada bloqueio do ML custa ~55s de pausa), então exigir pulso
         * recente demais faria o monitor matar e reiniciar um laço saudável.
         */
        $tolerancia = (Config::inteiro('config.agenda.intervalo_minutos', 10) * 60 * 2) + 900;

        if ($idade > $tolerancia) {
            return [
                'nome'    => 'Laço de publicação',
                'ativo'   => false,
                'detalhe' => 'sem sinal há ' . $this->tempo($idade),
            ];
        }

        if (!$this->processoVivo((int) ($pulso['pid'] ?? 0))) {
            return ['nome' => 'Laço de publicação', 'ativo' => false, 'detalhe' => 'processo não existe mais'];
        }

        // codigo alterado depois que o laco subiu: ele roda a versao velha
        $codigoAgora = self::versaoDoCodigo();

        if ($codigoAgora > (int) ($pulso['codigo_em'] ?? 0)) {
            return [
                'nome'    => 'Laço de publicação',
                'ativo'   => false,
                'detalhe' => 'rodando código desatualizado (alterado há ' . $this->tempo(time() - $codigoAgora) . ')',
            ];
        }

        return [
            'nome'    => 'Laço de publicação',
            'ativo'   => true,
            'detalhe' => 'último sinal há ' . $this->tempo($idade),
        ];
    }

    /** @return array{nome:string,ativo:bool,detalhe:string} */
    private function estadoPonte(): array
    {
        $gerenciador = new \MlGroup\Whatsapp\GerenciadorPonte();

        if (!$gerenciador->noAr()) {
            return ['nome' => 'WhatsApp', 'ativo' => false, 'detalhe' => 'ponte fora do ar'];
        }

        return $gerenciador->conectado()
            ? ['nome' => 'WhatsApp', 'ativo' => true, 'detalhe' => 'conectado']
            : ['nome' => 'WhatsApp', 'ativo' => false, 'detalhe' => 'ponte no ar, mas desconectada'];
    }

    /**
     * A coleta está trazendo alguma coisa?
     *
     * Um laço vivo publicando nada é o pior dos casos: tudo parece bem e o
     * grupo não recebe oferta. Só o resultado da última coleta denuncia.
     *
     * @return array{nome:string,ativo:bool,detalhe:string}
     */
    private function estadoColeta(): array
    {
        $ultima = Db::primeiro(
            "SELECT iniciado_em, coletados, aprovados
               FROM execucoes
              WHERE status = 'ok' AND coletados > 0
              ORDER BY id DESC LIMIT 1"
        );

        if ($ultima === null) {
            return ['nome' => 'Coleta', 'ativo' => false, 'detalhe' => 'nenhuma coleta bem-sucedida ainda'];
        }

        $idade  = time() - (strtotime((string) $ultima['iniciado_em']) ?: 0);
        $limite = Config::inteiro('config.coleta.intervalo_minutos', 60) * 60 * 3;

        if ($idade > $limite) {
            return [
                'nome'    => 'Coleta',
                'ativo'   => false,
                'detalhe' => 'última há ' . $this->tempo($idade) . ' (esperado a cada '
                    . Config::inteiro('config.coleta.intervalo_minutos', 60) . ' min)',
            ];
        }

        return [
            'nome'    => 'Coleta',
            'ativo'   => true,
            'detalhe' => sprintf(
                'há %s: %d coletados, %d aprovados',
                $this->tempo($idade),
                (int) $ultima['coletados'],
                (int) $ultima['aprovados'],
            ),
        ];
    }

    /** Encerra o laço atual, se houver, antes de subir outro. */
    private function pararLaco(): void
    {
        $pulso = $this->lerPulso(self::LACO);
        $pid   = (int) ($pulso['pid'] ?? 0);

        if ($pid <= 0 || !$this->processoVivo($pid)) {
            self::encerrarPulso();

            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /PID ' . $pid . ' /T /F 2>NUL');
        } else {
            @exec('kill ' . $pid . ' 2>/dev/null');
        }

        self::encerrarPulso();
        sleep(2);
    }

    private function iniciarLaco(): bool
    {
        $log = MLG_ROOT . '/storage/logs/rodar.log';

        if (DIRECTORY_SEPARATOR === '\\') {
            $lancador = MLG_ROOT . '/storage/cache/iniciar-laco.cmd';

            file_put_contents($lancador, implode("\r\n", [
                '@echo off',
                sprintf(
                    '"%s" "%s" rodar >> "%s" 2>&1',
                    str_replace('/', '\\', PHP_BINARY),
                    str_replace('/', '\\', MLG_ROOT . '/bin/mlgroup'),
                    str_replace('/', '\\', $log),
                ),
                '',
            ]));

            // Start-Process cria processo independente: o laco sobrevive ao monitor
            $comando = sprintf(
                'powershell -NoProfile -NonInteractive -Command "Start-Process -FilePath \'cmd.exe\''
                . ' -ArgumentList \'/c\',\'%s\' -WindowStyle Hidden"',
                str_replace('/', '\\', $lancador),
            );
        } else {
            $comando = sprintf(
                'nohup %s %s rodar >> %s 2>&1 &',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(MLG_ROOT . '/bin/mlgroup'),
                escapeshellarg($log),
            );
        }

        Logger::i()->aviso('Laço de publicação estava parado - religando');

        $handle = popen($comando, 'r');

        if ($handle === false) {
            return false;
        }

        pclose($handle);

        // dá tempo do laço registrar o primeiro pulso antes de conferir
        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            sleep(1);

            if ($this->lerPulso(self::LACO) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed>|null */
    private function lerPulso(string $nome): ?array
    {
        $arquivo = self::arquivoPulso($nome);

        if (!is_file($arquivo)) {
            return null;
        }

        $dados = json_decode((string) file_get_contents($arquivo), true);

        return is_array($dados) ? $dados : null;
    }

    private static function arquivoPulso(string $nome): string
    {
        return MLG_ROOT . '/storage/pulso/' . $nome . '.json';
    }

    private function processoVivo(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        $saida = [];
        @exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $saida);

        foreach ($saida as $linha) {
            if (str_contains($linha, (string) $pid)) {
                return true;
            }
        }

        return false;
    }

    private function tempo(int $segundos): string
    {
        if ($segundos < 60) {
            return $segundos . 's';
        }

        if ($segundos < 3600) {
            return intdiv($segundos, 60) . ' min';
        }

        if ($segundos < 86400) {
            return intdiv($segundos, 3600) . 'h';
        }

        return intdiv($segundos, 86400) . 'd';
    }
}
