<?php

declare(strict_types=1);

namespace MlGroup\Cli;

use MlGroup\Support\Config;

/**
 * Sobe o painel web no servidor embutido do PHP.
 *
 * Fica em primeiro plano de proposito: enquanto o terminal estiver aberto o
 * painel responde, e Ctrl+C encerra. Nao ha o que instalar nem servico para
 * deixar rodando.
 */
final class ComandoPainel
{
    public function __construct(private readonly Saida $saida)
    {
    }

    public function executar(int $porta, bool $abrirNavegador = true): int
    {
        $porta = $porta > 0 ? $porta : Config::inteiro('config.painel.porta', 8321);

        if ($this->ocupada($porta)) {
            $this->saida->linha('');
            $this->saida->linha('  A porta ' . $porta . ' já está em uso.', '31');
            $this->saida->linha('  Use outra: php bin/mlgroup painel --porta=8322', '90');
            $this->saida->linha('');

            return 1;
        }

        $endereco = 'http://127.0.0.1:' . $porta;

        $this->saida->linha('');
        $this->saida->titulo('  Painel do ml-group');
        $this->saida->linha('');
        $this->saida->linha('  ' . $endereco, '36');
        $this->saida->linha('');
        $this->saida->linha('  Ctrl+C encerra.', '90');
        $this->saida->linha('');

        if ($abrirNavegador) {
            $this->abrir($endereco);
        }

        // o servidor embutido assume o processo; nada roda depois disto
        $comando = [
            PHP_BINARY,
            '-S', '127.0.0.1:' . $porta,
            '-t', MLG_ROOT . '/publico',
            MLG_ROOT . '/publico/index.php',
        ];

        $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $canos, MLG_ROOT);

        if (!is_resource($processo)) {
            $this->saida->linha('  Não foi possível iniciar o servidor.', '31');

            return 1;
        }

        // só o essencial na tela: requisicao de asset nao interessa a ninguem
        while (!feof($canos[2])) {
            $linha = fgets($canos[2]);

            if ($linha === false) {
                break;
            }

            if (preg_match('/(Fatal error|Warning|Uncaught)/i', $linha) === 1) {
                $this->saida->linha('  ' . trim($linha), '31');
            }
        }

        foreach ($canos as $cano) {
            if (is_resource($cano)) {
                fclose($cano);
            }
        }

        return proc_close($processo);
    }

    private function ocupada(int $porta): bool
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $porta, $codigo, $erro, 0.4);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function abrir(string $endereco): void
    {
        $comando = match (PHP_OS_FAMILY) {
            'Windows' => 'start "" ' . escapeshellarg($endereco),
            'Darwin'  => 'open ' . escapeshellarg($endereco),
            default   => 'xdg-open ' . escapeshellarg($endereco) . ' > /dev/null 2>&1 &',
        };

        $handle = popen($comando, 'r');

        if ($handle !== false) {
            pclose($handle);
        }
    }
}
