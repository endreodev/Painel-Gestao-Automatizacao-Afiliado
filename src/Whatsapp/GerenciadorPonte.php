<?php

declare(strict_types=1);

namespace MlGroup\Whatsapp;

use MlGroup\Support\Config;
use MlGroup\Support\Env;
use MlGroup\Support\Http;
use MlGroup\Support\Logger;
use RuntimeException;

/**
 * Cuida do processo da ponte (ponte/servidor.js).
 *
 * Encontra um Node compativel, sobe a ponte quando ela nao esta no ar e espera
 * ficar pronta. O objetivo e nao exigir nada do usuario: ele roda `conectar`,
 * le o QR e acabou.
 */
final class GerenciadorPonte
{
    /** O Baileys exige Node 20 ou superior. */
    private const NODE_MINIMO = 20;

    public function __construct(private readonly Http $http = new Http(timeout: 10, tentativas: 1))
    {
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->porta();
    }

    public function porta(): int
    {
        return Env::inteiro('PONTE_PORTA', Config::inteiro('config.whatsapp.ponte_porta', 8787));
    }

    /** A ponte esta no ar? */
    public function noAr(): bool
    {
        return $this->http->get($this->url() . '/status')->ok();
    }

    /**
     * Garante a ponte rodando; sobe em segundo plano se preciso.
     *
     * @throws RuntimeException quando falta Node compativel ou as dependencias
     */
    public function garantir(): void
    {
        if ($this->noAr()) {
            return;
        }

        $this->conferirDependencias();

        $node = $this->node();

        if ($node === null) {
            throw new RuntimeException(
                'Node.js ' . self::NODE_MINIMO . '+ nao encontrado. Instale em https://nodejs.org '
                . 'ou informe o caminho em config/config.php > whatsapp.node'
            );
        }

        Logger::i()->debug('Subindo a ponte do WhatsApp', ['node' => $node, 'porta' => $this->porta()]);

        $this->iniciar($node);
        $this->esperarSubir();
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $resposta = $this->http->get($this->url() . '/status');

        return $resposta->ok() ? $resposta->json() : [];
    }

    public function conectado(): bool
    {
        return (bool) ($this->status()['conectado'] ?? false);
    }

    /** Encerra o processo da ponte, mantendo a sessao salva. */
    public function parar(): void
    {
        if ($this->noAr()) {
            $this->http->postJson($this->url() . '/encerrar', []);
        }
    }

    /** Encerra a sessao: o proximo `conectar` vai pedir QR de novo. */
    public function sair(): bool
    {
        if (!$this->noAr()) {
            return false;
        }

        return $this->http->postJson($this->url() . '/sair', [])->ok();
    }

    /**
     * Sobe a ponte totalmente desanexada.
     *
     * Ela precisa continuar viva depois que o comando PHP termina - e o que
     * evita pedir QR a cada ciclo. Por isso nao basta um proc_open: o filho
     * herdaria os descritores do PHP e, se a saida estivesse num pipe
     * (`php bin/mlgroup ciclo | tee log`), o pipe so fecharia quando a ponte
     * morresse, travando o terminal. O lancamento passa por um script que
     * redireciona tudo para arquivo, sem herdar nada.
     */
    private function iniciar(string $node): void
    {
        $log     = MLG_ROOT . '/storage/logs/ponte.log';
        $script  = MLG_ROOT . '/ponte/servidor.js';
        $windows = DIRECTORY_SEPARATOR === '\\';

        if ($windows) {
            $lancador = MLG_ROOT . '/storage/cache/iniciar-ponte.cmd';

            file_put_contents($lancador, implode("\r\n", [
                '@echo off',
                'set MLG_PONTE_PORTA=' . $this->porta(),
                sprintf('"%s" "%s" >> "%s" 2>&1', $this->paraWindows($node), $this->paraWindows($script), $this->paraWindows($log)),
                '',
            ]));

            // "start /B" nao serve: ele mantem o console e os handles do PHP,
            // entao um `| tee` so fecharia quando a ponte morresse. O
            // Start-Process cria um processo de fato independente.
            $comando = sprintf(
                'powershell -NoProfile -NonInteractive -Command "Start-Process -FilePath \'cmd.exe\''
                . ' -ArgumentList \'/c\',\'%s\' -WindowStyle Hidden"',
                $this->paraWindows($lancador),
            );
        } else {
            $comando = sprintf(
                'MLG_PONTE_PORTA=%d nohup %s %s >> %s 2>&1 &',
                $this->porta(),
                escapeshellarg($node),
                escapeshellarg($script),
                escapeshellarg($log),
            );
        }

        $handle = popen($comando, 'r');

        if ($handle === false) {
            throw new RuntimeException('Nao foi possivel iniciar a ponte do WhatsApp');
        }

        pclose($handle);
    }

    private function paraWindows(string $caminho): string
    {
        return str_replace('/', '\\', $caminho);
    }

    private function esperarSubir(): void
    {
        $limite = time() + 45;

        while (time() < $limite) {
            if ($this->noAr()) {
                return;
            }

            usleep(500000);
        }

        throw new RuntimeException(
            'A ponte nao respondeu a tempo. Veja storage/logs/ponte.log'
        );
    }

    /** As dependencias do Node ja foram instaladas? */
    public function dependenciasInstaladas(): bool
    {
        return is_dir(MLG_ROOT . '/ponte/node_modules/@whiskeysockets/baileys');
    }

    private function conferirDependencias(): void
    {
        if ($this->dependenciasInstaladas()) {
            return;
        }

        throw new RuntimeException(
            'Dependencias da ponte ausentes. Rode: php bin/mlgroup instalar-ponte'
        );
    }

    /**
     * Instala as dependencias do Node dentro de ponte/.
     *
     * @return array{0:bool,1:string}
     */
    public function instalarDependencias(): array
    {
        $node = $this->node();

        if ($node === null) {
            return [false, 'Node.js ' . self::NODE_MINIMO . '+ nao encontrado.'];
        }

        $npm = $this->npmDe($node);

        if ($npm === null) {
            return [false, 'npm nao encontrado junto do Node em ' . dirname($node)];
        }

        // o npm chama "node" pelo PATH; sem isso ele acha uma versao antiga
        $caminho = dirname($node) . PATH_SEPARATOR . (getenv('PATH') ?: '');

        $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $processo = proc_open(
            [$npm, 'install', '--no-audit', '--no-fund'],
            $descritores,
            $canos,
            MLG_ROOT . '/ponte',
            ['PATH' => $caminho, 'APPDATA' => getenv('APPDATA') ?: '', 'SystemRoot' => getenv('SystemRoot') ?: ''],
        );

        if (!is_resource($processo)) {
            return [false, 'nao foi possivel executar o npm'];
        }

        $saida = (string) stream_get_contents($canos[1]) . (string) stream_get_contents($canos[2]);

        foreach ($canos as $cano) {
            if (is_resource($cano)) {
                fclose($cano);
            }
        }

        $codigo = proc_close($processo);

        return [$codigo === 0, trim($saida)];
    }

    /** Caminho de um Node compativel, ou null. */
    public function node(): ?string
    {
        $configurado = Config::texto('config.whatsapp.node');

        if ($configurado !== '' && is_file($configurado)) {
            return $configurado;
        }

        foreach ($this->candidatosNode() as $candidato) {
            if ($this->versaoNode($candidato) >= self::NODE_MINIMO) {
                return $candidato;
            }
        }

        return null;
    }

    /** @return string[] */
    private function candidatosNode(): array
    {
        $candidatos = [];

        // versoes do nvm-windows, da mais nova para a mais antiga
        foreach (['LOCALAPPDATA', 'APPDATA', 'USERPROFILE'] as $variavel) {
            $base = getenv($variavel);

            if ($base === false || $base === '') {
                continue;
            }

            $achados = glob($base . '/nvm/v*/node.exe') ?: [];

            usort($achados, static fn (string $a, string $b): int => version_compare(
                basename(dirname($b)),
                basename(dirname($a)),
            ));

            $candidatos = array_merge($candidatos, $achados);
        }

        $candidatos[] = 'C:\Program Files\nodejs\node.exe';
        $candidatos[] = 'C:\nvm4w\nodejs\node.exe';
        $candidatos[] = '/usr/bin/node';
        $candidatos[] = '/usr/local/bin/node';
        $candidatos[] = 'node';

        return $candidatos;
    }

    private function versaoNode(string $executavel): int
    {
        if ($executavel !== 'node' && !is_file($executavel)) {
            return 0;
        }

        $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processo    = @proc_open([$executavel, '-v'], $descritores, $canos);

        if (!is_resource($processo)) {
            return 0;
        }

        $saida = (string) stream_get_contents($canos[1]);

        foreach ($canos as $cano) {
            if (is_resource($cano)) {
                fclose($cano);
            }
        }

        proc_close($processo);

        return preg_match('/v?(\d+)\./', trim($saida), $achado) === 1 ? (int) $achado[1] : 0;
    }

    private function npmDe(string $node): ?string
    {
        if ($node === 'node') {
            return 'npm';
        }

        foreach (['npm.cmd', 'npm'] as $nome) {
            $caminho = dirname($node) . DIRECTORY_SEPARATOR . $nome;

            if (is_file($caminho)) {
                return $caminho;
            }
        }

        return null;
    }
}
