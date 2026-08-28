<?php

declare(strict_types=1);

namespace MlGroup\Whatsapp;

use MlGroup\Support\Env;
use MlGroup\Support\Http;
use MlGroup\Support\Logger;

/**
 * Driver da Z-API (https://z-api.io) - servico pago brasileiro, sem servidor
 * proprio para manter.
 *
 * .env: ZAPI_INSTANCIA, ZAPI_TOKEN, ZAPI_CLIENT_TOKEN
 */
final class ZApi implements WhatsappInterface
{
    private readonly string $base;

    private readonly string $clientToken;

    public function __construct(private readonly Http $http = new Http())
    {
        $this->base = sprintf(
            'https://api.z-api.io/instances/%s/token/%s',
            Env::texto('ZAPI_INSTANCIA'),
            Env::texto('ZAPI_TOKEN'),
        );

        $this->clientToken = Env::texto('ZAPI_CLIENT_TOKEN');
    }

    public function nome(): string
    {
        return 'zapi';
    }

    public function enviarTexto(string $destino, string $mensagem): ResultadoEnvio
    {
        $resposta = $this->http->postJson(
            $this->base . '/send-text',
            [
                'phone'        => $destino,
                'message'      => $mensagem,
                'linkPreview'  => true,
                'delayMessage' => 1,
            ],
            $this->cabecalhos(),
        );

        return $this->interpretar($resposta->status, $resposta->json(), $resposta->erro);
    }

    public function enviarImagem(string $destino, string $urlImagem, string $legenda): ResultadoEnvio
    {
        $resposta = $this->http->postJson(
            $this->base . '/send-image',
            [
                'phone'   => $destino,
                'image'   => $urlImagem,
                'caption' => $legenda,
            ],
            $this->cabecalhos(),
        );

        if ($resposta->ok()) {
            return $this->interpretar($resposta->status, $resposta->json(), $resposta->erro);
        }

        Logger::i()->aviso('Envio com imagem falhou, caindo para texto', ['status' => $resposta->status]);

        return $this->enviarTexto($destino, $legenda);
    }

    public function conectado(): bool
    {
        $resposta = $this->http->get($this->base . '/status', $this->cabecalhos());

        if (!$resposta->ok()) {
            return false;
        }

        return (bool) ($resposta->json()['connected'] ?? false);
    }

    /** @return array<int,array{id:string,nome:string}> */
    public function grupos(): array
    {
        $resposta = $this->http->get($this->base . '/chats?page=1&pageSize=200', $this->cabecalhos());

        if (!$resposta->ok()) {
            Logger::i()->aviso('Nao foi possivel listar grupos', ['status' => $resposta->status]);

            return [];
        }

        $grupos = [];

        foreach ($resposta->json() as $chat) {
            if (!is_array($chat)) {
                continue;
            }

            $id = (string) ($chat['phone'] ?? '');

            // grupos na Z-API vem com o sufixo -group no identificador
            if ($id === '' || !($chat['isGroup'] ?? str_contains($id, '-'))) {
                continue;
            }

            $grupos[] = [
                'id'   => $id,
                'nome' => (string) ($chat['name'] ?? '(sem nome)'),
            ];
        }

        return $grupos;
    }

    /** @return array<string,string> */
    private function cabecalhos(): array
    {
        return $this->clientToken === '' ? [] : ['Client-Token' => $this->clientToken];
    }

    /** @param array<mixed> $dados */
    private function interpretar(int $status, array $dados, ?string $erroTransporte): ResultadoEnvio
    {
        if ($status >= 200 && $status < 300 && !isset($dados['error'])) {
            $id = $dados['messageId'] ?? ($dados['id'] ?? '');

            return ResultadoEnvio::ok(is_string($id) ? $id : '');
        }

        $mensagem = $dados['error'] ?? ($dados['message'] ?? $erroTransporte ?? 'HTTP ' . $status);

        return ResultadoEnvio::falha(is_string($mensagem) ? $mensagem : (json_encode($mensagem) ?: 'erro'));
    }
}
