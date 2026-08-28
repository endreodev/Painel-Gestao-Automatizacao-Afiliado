<?php

declare(strict_types=1);

namespace MlGroup\Whatsapp;

use MlGroup\Support\Env;
use MlGroup\Support\Http;
use MlGroup\Support\Logger;

/**
 * Driver do WPPConnect Server (https://github.com/wppconnect-team/wppconnect-server).
 *
 * .env: WPP_URL, WPP_SESSAO, WPP_TOKEN
 */
final class WppConnect implements WhatsappInterface
{
    private readonly string $base;

    private readonly string $sessao;

    private readonly string $token;

    public function __construct(private readonly Http $http = new Http())
    {
        $this->base   = rtrim(Env::texto('WPP_URL', 'http://localhost:21465'), '/');
        $this->sessao = Env::texto('WPP_SESSAO', 'mlgroup');
        $this->token  = Env::texto('WPP_TOKEN');
    }

    public function nome(): string
    {
        return 'wppconnect';
    }

    public function enviarTexto(string $destino, string $mensagem): ResultadoEnvio
    {
        $resposta = $this->http->postJson(
            $this->url('send-message'),
            [
                'phone'   => $destino,
                'message' => $mensagem,
                'isGroup' => $this->ehGrupo($destino),
            ],
            $this->cabecalhos(),
        );

        return $this->interpretar($resposta->status, $resposta->json(), $resposta->erro);
    }

    public function enviarImagem(string $destino, string $urlImagem, string $legenda): ResultadoEnvio
    {
        $resposta = $this->http->postJson(
            $this->url('send-image'),
            [
                'phone'   => $destino,
                'path'    => $urlImagem,
                'caption' => $legenda,
                'isGroup' => $this->ehGrupo($destino),
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
        $resposta = $this->http->get($this->url('status-session'), $this->cabecalhos());

        if (!$resposta->ok()) {
            return false;
        }

        return ($resposta->json()['status'] ?? '') === 'CONNECTED';
    }

    /** @return array<int,array{id:string,nome:string}> */
    public function grupos(): array
    {
        $resposta = $this->http->get($this->url('all-groups'), $this->cabecalhos());

        if (!$resposta->ok()) {
            Logger::i()->aviso('Nao foi possivel listar grupos', ['status' => $resposta->status]);

            return [];
        }

        $dados  = $resposta->json();
        $lista  = is_array($dados['response'] ?? null) ? $dados['response'] : $dados;
        $grupos = [];

        foreach ($lista as $grupo) {
            if (!is_array($grupo)) {
                continue;
            }

            $id = $grupo['id']['_serialized'] ?? ($grupo['id'] ?? '');

            if (!is_string($id) || $id === '') {
                continue;
            }

            $grupos[] = [
                'id'   => $id,
                'nome' => (string) ($grupo['name'] ?? $grupo['formattedTitle'] ?? '(sem nome)'),
            ];
        }

        return $grupos;
    }

    private function url(string $rota): string
    {
        return $this->base . '/api/' . rawurlencode($this->sessao) . '/' . $rota;
    }

    /** @return array<string,string> */
    private function cabecalhos(): array
    {
        return $this->token === '' ? [] : ['Authorization' => 'Bearer ' . $this->token];
    }

    private function ehGrupo(string $destino): bool
    {
        return str_contains($destino, '@g.us') || str_contains($destino, '-');
    }

    /** @param array<mixed> $dados */
    private function interpretar(int $status, array $dados, ?string $erroTransporte): ResultadoEnvio
    {
        if ($status >= 200 && $status < 300) {
            $id = $dados['response'][0]['id'] ?? ($dados['id'] ?? '');

            return ResultadoEnvio::ok(is_string($id) ? $id : '');
        }

        $mensagem = $dados['message'] ?? ($dados['error'] ?? $erroTransporte ?? 'HTTP ' . $status);

        return ResultadoEnvio::falha(is_string($mensagem) ? $mensagem : (json_encode($mensagem) ?: 'erro'));
    }
}
