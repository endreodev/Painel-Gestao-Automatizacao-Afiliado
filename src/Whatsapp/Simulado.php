<?php

declare(strict_types=1);

namespace MlGroup\Whatsapp;

use MlGroup\Support\Logger;

/**
 * Driver de ensaio: imprime a mensagem em vez de mandar para o grupo.
 *
 * Use com WHATSAPP_DRIVER=simulado enquanto calibra filtros e template - assim
 * da para rodar o ciclo inteiro sem queimar o grupo com oferta ruim.
 */
final class Simulado implements WhatsappInterface
{
    public function nome(): string
    {
        return 'simulado';
    }

    public function enviarTexto(string $destino, string $mensagem): ResultadoEnvio
    {
        // STDOUT nao existe fora da CLI; pelo painel a mensagem vai so para o log
        if (PHP_SAPI === 'cli' && defined('STDOUT')) {
            $risco = str_repeat('-', 60);

            fwrite(STDOUT, PHP_EOL . $risco . PHP_EOL);
            fwrite(STDOUT, '[SIMULADO] destino: ' . $destino . PHP_EOL);
            fwrite(STDOUT, $risco . PHP_EOL);
            fwrite(STDOUT, $mensagem . PHP_EOL);
            fwrite(STDOUT, $risco . PHP_EOL . PHP_EOL);
        } else {
            Logger::i()->info('[SIMULADO] mensagem', ['destino' => $destino]);
        }

        return ResultadoEnvio::ok('simulado-' . bin2hex(random_bytes(4)));
    }

    public function enviarImagem(string $destino, string $urlImagem, string $legenda): ResultadoEnvio
    {
        Logger::i()->info('[SIMULADO] imagem', ['url' => $urlImagem]);

        return $this->enviarTexto($destino, $legenda);
    }

    public function conectado(): bool
    {
        return true;
    }

    /** @return array<int,array{id:string,nome:string}> */
    public function grupos(): array
    {
        return [
            ['id' => '000000000000000000@g.us', 'nome' => 'Grupo de teste (simulado)'],
        ];
    }
}
