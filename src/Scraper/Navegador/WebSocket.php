<?php

declare(strict_types=1);

namespace MlGroup\Scraper\Navegador;

use RuntimeException;

/**
 * Cliente WebSocket minimo (RFC 6455), suficiente para falar com o Chrome
 * DevTools Protocol.
 *
 * Existe porque o projeto nao usa Composer e o PHP nao traz WebSocket nativo.
 * Cobre apenas o necessario: handshake, envio mascarado de texto, leitura de
 * frames com continuacao e resposta automatica a ping.
 */
final class WebSocket
{
    /** @var resource */
    private $socket;

    private function __construct($socket)
    {
        $this->socket = $socket;
    }

    public static function conectar(string $url, float $timeout = 20.0): self
    {
        $partes = parse_url($url);

        if ($partes === false || !isset($partes['host'])) {
            throw new RuntimeException('URL de WebSocket invalida: ' . $url);
        }

        $porta   = (int) ($partes['port'] ?? 80);
        $caminho = ($partes['path'] ?? '/') . (isset($partes['query']) ? '?' . $partes['query'] : '');

        $socket = @stream_socket_client(
            'tcp://' . $partes['host'] . ':' . $porta,
            $codigoErro,
            $mensagemErro,
            $timeout,
        );

        if ($socket === false) {
            throw new RuntimeException('Nao foi possivel abrir o socket: ' . $mensagemErro . ' (' . $codigoErro . ')');
        }

        stream_set_timeout($socket, (int) $timeout);

        $chave = base64_encode(random_bytes(16));

        $requisicao = implode("\r\n", [
            'GET ' . $caminho . ' HTTP/1.1',
            'Host: ' . $partes['host'] . ':' . $porta,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $chave,
            'Sec-WebSocket-Version: 13',
            '',
            '',
        ]);

        fwrite($socket, $requisicao);

        $cabecalho = '';

        while (!str_contains($cabecalho, "\r\n\r\n")) {
            $pedaco = fgets($socket, 1024);

            if ($pedaco === false) {
                fclose($socket);

                throw new RuntimeException('O navegador fechou a conexao durante o handshake');
            }

            $cabecalho .= $pedaco;
        }

        if (!str_contains($cabecalho, '101')) {
            fclose($socket);

            throw new RuntimeException('Handshake WebSocket recusado: ' . strtok($cabecalho, "\r\n"));
        }

        return new self($socket);
    }

    public function enviar(string $texto): void
    {
        fwrite($this->socket, $this->montarFrame($texto));
    }

    /**
     * Le uma mensagem completa (junta frames de continuacao).
     *
     * @return string|null null quando estoura o tempo ou a conexao cai
     */
    public function receber(float $timeout = 20.0): ?string
    {
        $limite    = microtime(true) + $timeout;
        $acumulado = '';

        while (microtime(true) < $limite) {
            $frame = $this->lerFrame($limite);

            if ($frame === null) {
                return null;
            }

            [$opcode, $carga, $final] = $frame;

            // 0x8 = close, 0x9 = ping, 0xA = pong
            if ($opcode === 0x8) {
                return null;
            }

            if ($opcode === 0x9) {
                fwrite($this->socket, $this->montarFrame($carga, 0xA));

                continue;
            }

            if ($opcode === 0xA) {
                continue;
            }

            $acumulado .= $carga;

            if ($final) {
                return $acumulado;
            }
        }

        return null;
    }

    public function fechar(): void
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, $this->montarFrame('', 0x8));
            @fclose($this->socket);
        }
    }

    /** @return array{0:int,1:string,2:bool}|null */
    private function lerFrame(float $limite): ?array
    {
        $cabecalho = $this->lerExato(2, $limite);

        if ($cabecalho === null) {
            return null;
        }

        $primeiro = ord($cabecalho[0]);
        $segundo  = ord($cabecalho[1]);

        $final     = ($primeiro & 0x80) !== 0;
        $opcode    = $primeiro & 0x0F;
        $mascarado = ($segundo & 0x80) !== 0;
        $tamanho   = $segundo & 0x7F;

        if ($tamanho === 126) {
            $extra = $this->lerExato(2, $limite);

            if ($extra === null) {
                return null;
            }

            $tamanho = unpack('n', $extra)[1] ?? 0;
        } elseif ($tamanho === 127) {
            $extra = $this->lerExato(8, $limite);

            if ($extra === null) {
                return null;
            }

            $tamanho = (int) (unpack('J', $extra)[1] ?? 0);
        }

        $mascara = '';

        if ($mascarado) {
            $mascara = $this->lerExato(4, $limite);

            if ($mascara === null) {
                return null;
            }
        }

        $carga = $tamanho > 0 ? $this->lerExato($tamanho, $limite) : '';

        if ($carga === null) {
            return null;
        }

        if ($mascarado && $mascara !== '') {
            $carga = $this->aplicarMascara($carga, $mascara);
        }

        return [$opcode, $carga, $final];
    }

    private function lerExato(int $quantidade, float $limite): ?string
    {
        $dados = '';

        while (strlen($dados) < $quantidade) {
            if (microtime(true) > $limite) {
                return null;
            }

            $pedaco = fread($this->socket, $quantidade - strlen($dados));

            if ($pedaco === false || ($pedaco === '' && feof($this->socket))) {
                return null;
            }

            if ($pedaco === '') {
                // socket ainda vivo, apenas sem dados no momento
                usleep(5000);

                continue;
            }

            $dados .= $pedaco;
        }

        return $dados;
    }

    /** Cliente sempre mascara a carga - o servidor recusa frame sem mascara. */
    private function montarFrame(string $carga, int $opcode = 0x1): string
    {
        $tamanho = strlen($carga);
        $frame   = chr(0x80 | $opcode);

        if ($tamanho <= 125) {
            $frame .= chr(0x80 | $tamanho);
        } elseif ($tamanho <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $tamanho);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $tamanho);
        }

        $mascara = random_bytes(4);

        return $frame . $mascara . $this->aplicarMascara($carga, $mascara);
    }

    private function aplicarMascara(string $dados, string $mascara): string
    {
        $resultado = '';
        $tamanho   = strlen($dados);

        for ($i = 0; $i < $tamanho; $i++) {
            $resultado .= $dados[$i] ^ $mascara[$i % 4];
        }

        return $resultado;
    }
}
