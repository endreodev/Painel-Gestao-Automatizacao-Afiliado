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

        /*
         * A ponte tambem precisa ser religada.
         *
         * Ate aqui o monitor sabia dizer "WhatsApp PARADO" e nao fazia nada a
         * respeito - so o laco era religado. O laco entao rodava perfeitamente,
         * coletava, escolhia a oferta, e falhava no envio; 87 horas se passaram
         * assim, com o monitor rodando de cinco em cinco minutos e relatando o
         * problema a um log que ninguem lia.
         */
        if (!$estado['ponte']['ativo']) {
            $acoes[] = $this->religarPonte()
                ? 'ponte do WhatsApp reiniciada'
                : 'FALHA ao reiniciar a ponte do WhatsApp';
        }

        return $acoes;
    }

    /**
     * Derruba e sobe a ponte do WhatsApp.
     *
     * Para antes de subir mesmo quando ela parece viva: o caso que motivou isto
     * nao foi uma ponte morta, foi uma degradada - o processo respondia, mas em
     * 16 segundos, acima do tempo limite de 10 do cliente HTTP. Para quem
     * consulta, "lento demais" e indistinguivel de "fora do ar", e subir por
     * cima de um processo desses deixaria o problema de pe.
     *
     * A sessao fica em disco, entao reconectar nao pede QR de novo.
     */
    private function religarPonte(): bool
    {
        $gerenciador = new \MlGroup\Whatsapp\GerenciadorPonte();

        try {
            $gerenciador->parar();
            sleep(3);
            $gerenciador->garantir();
        } catch (\Throwable $erro) {
            Logger::i()->erro('Nao foi possivel religar a ponte', ['motivo' => $erro->getMessage()]);

            return false;
        }

        // reconectar leva alguns segundos; sem esta espera o monitor daria falha
        for ($tentativa = 0; $tentativa < 30; $tentativa++) {
            if ($gerenciador->conectado()) {
                return true;
            }

            sleep(2);
        }

        return false;
    }


    /**
     * Um caminho de log que aceite escrita agora.
     *
     * O lancador redireciona a saida com ">>". Se o arquivo estiver travado, o
     * cmd aborta ANTES de rodar o php - e o laco nunca sobe. Nao e hipotese:
     * uma coleta interrompida deixou 210 processos do Chrome vivos, herdeiros do
     * descritor de rodar.log, e o arquivo ficou preso por dez dias. O monitor
     * tentou religar a cada cinco minutos, falhou todas as vezes e o sistema
     * ficou parado sem que nada no log dissesse o motivo.
     *
     * Perder uma linha de log e menos grave do que nao publicar.
     */
    private function logGravavel(): string
    {
        $padrao = MLG_ROOT . '/storage/logs/rodar.log';
        $teste  = @fopen($padrao, 'a');

        if ($teste !== false) {
            fclose($teste);

            return $padrao;
        }

        $alternativo = MLG_ROOT . '/storage/logs/rodar-' . date('Ymd-His') . '.log';

        Logger::i()->aviso('rodar.log travado por outro processo, usando arquivo novo', [
            'arquivo' => basename($alternativo),
        ]);

        return $alternativo;
    }

    /**
     * Encerra navegadores que sobraram de coletas interrompidas.
     *
     * O coletor fecha o Chrome ao terminar, mas um laco que morre no meio (a
     * maquina dorme, o processo e morto) nao chega a fazer isso. Cada sobra
     * dessas mantem processos vivos, come memoria e - pior - segura descritores
     * herdados do laco morto, incluindo o do proprio log.
     *
     * So mata o que aponta para o perfil temporario deste projeto. O navegador
     * do usuario nao tem esse caminho na linha de comando e nao e tocado.
     */
    private function encerrarNavegadoresOrfaos(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return;
        }

        // se o laco esta vivo, o Chrome aberto pode ser dele - nao mexer
        if ($this->lerPulso(self::LACO) !== null && $this->estadoLaco()['ativo']) {
            return;
        }

        /*
         * O PowerShell vai para um arquivo, e nao para a linha de comando.
         *
         * A consulta precisa de aspas simples e duplas aninhadas; montada dentro
         * de uma string PHP que ainda passa pelo cmd, ela chega deformada e o
         * PowerShell ecoa o texto em vez de executar. Num arquivo, cada nivel de
         * aspas fica onde deveria.
         */
        $script = MLG_ROOT . '/storage/cache/limpar-navegadores.ps1';
        $marca  = str_replace('/', '\\', MLG_ROOT);

        $conteudo = implode("\r\n", [
            '# Gerado pelo monitor. Encerra Chrome que sobrou de coleta interrompida.',
            '$alvo = Get-CimInstance Win32_Process -Filter "Name=\'chrome.exe\'" -ErrorAction SilentlyContinue |',
            '  Where-Object { $_.CommandLine -like "*' . $marca . '*" }',
            'foreach ($p in $alvo) { Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue }',
            '$alvo.Count',
            '',
        ]);

        if (@file_put_contents($script, $conteudo) === false) {
            return;
        }

        @exec(
            'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
            . escapeshellarg(str_replace('/', '\\', $script)) . ' 2>&1',
            $saida,
            $codigo,
        );

        $quantos = (int) trim((string) end($saida));

        if ($codigo === 0 && $quantos > 0) {
            Logger::i()->aviso('Navegadores orfaos encerrados antes de religar o laco', [
                'processos' => $quantos,
            ]);
        }

        $this->apagarPerfisOrfaos();
    }

    /**
     * Remove os perfis temporarios que o Chrome deixou para tras.
     *
     * O coletor apaga o proprio perfil ao fechar; quem morre antes disso nao
     * apaga. Em dez dias juntaram 28 pastas e 914 MB - o disco enche em silencio
     * e ninguem relaciona com o robo de ofertas.
     *
     * Roda logo depois de encerrar os navegadores, entao nenhuma pasta aqui
     * ainda esta em uso.
     */
    private function apagarPerfisOrfaos(): void
    {
        $apagadas = 0;

        foreach (glob(MLG_ROOT . '/storage/cache/chrome-*', GLOB_ONLYDIR) ?: [] as $pasta) {
            if ($this->apagarPasta($pasta)) {
                $apagadas++;
            }
        }

        if ($apagadas > 0) {
            Logger::i()->info('Perfis de navegador orfaos apagados', ['pastas' => $apagadas]);
        }
    }

    private function apagarPasta(string $pasta): bool
    {
        $itens = scandir($pasta);

        if ($itens === false) {
            return false;
        }

        foreach ($itens as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $caminho = $pasta . '/' . $item;

            is_dir($caminho) ? $this->apagarPasta($caminho) : @unlink($caminho);
        }

        return @rmdir($pasta);
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
        $this->encerrarNavegadoresOrfaos();

        $log = $this->logGravavel();

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
