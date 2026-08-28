<?php

declare(strict_types=1);

namespace MlGroup\Whatsapp;

use MlGroup\Support\Env;
use MlGroup\Support\Http;
use MlGroup\Support\Logger;

/**
 * Driver da Evolution API (https://github.com/EvolutionAPI/evolution-api).
 *
 * E o padrao do projeto por ser gratuita, self-hosted e por enviar para grupos -
 * coisa que a Cloud API oficial da Meta nao faz.
 *
 * .env: WHATSAPP_URL, WHATSAPP_INSTANCIA, WHATSAPP_TOKEN
 */
final class EvolutionApi implements WhatsappInterface
{
    private readonly string $base;

    private readonly string $instancia;

    private readonly string $token;

    public function __construct(private readonly Http $http = new Http())
    {
        $this->base      = rtrim(Env::texto('WHATSAPP_URL', 'http://localhost:8080'), '/');
        $this->instancia = Env::texto('WHATSAPP_INSTANCIA', 'default');
        $this->token     = Env::texto('WHATSAPP_TOKEN');
    }

    public function nome(): string
    {
        return 'evolution';
    }

    public function enviarTexto(string $destino, string $mensagem): ResultadoEnvio
    {
        $resposta = $this->http->postJson(
            $this->base . '/message/sendText/' . rawurlencode($this->instancia),
            [
                'number'      => $destino,
                'text'        => $mensagem,
                'linkPreview' => true,
                'delay'       => 1200,
            ],
            $this->cabecalhos(),
        );

        return $this->interpretar($resposta->status, $resposta->json(), $resposta->erro);
    }

    public function enviarImagem(string $destino, string $urlImagem, string $legenda): ResultadoEnvio
    {
        $resposta = $this->http->postJson(
            $this->base . '/message/sendMedia/' . rawurlencode($this->instancia),
            [
                'number'    => $destino,
                'mediatype' => 'image',
                'media'     => $urlImagem,
                'caption'   => $legenda,
                'delay'     => 1200,
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
        $resposta = $this->http->get(
            $this->base . '/instance/connectionState/' . rawurlencode($this->instancia),
            $this->cabecalhos(),
        );

        if (!$resposta->ok()) {
            return false;
        }

        $dados  = $resposta->json();
        $estado = $dados['instance']['state'] ?? ($dados['state'] ?? '');

        return $estado === 'open';
    }

    /** @return array<int,array{id:string,nome:string}> */
    public function grupos(): array
    {
        $resposta = $this->http->get(
            $this->base . '/group/fetchAllGroups/' . rawurlencode($this->instancia) . '?getParticipants=false',
            $this->cabecalhos(),
        );

        if (!$resposta->ok()) {
            Logger::i()->aviso('Nao foi possivel listar grupos', ['status' => $resposta->status]);

            return [];
        }

        $lista  = $resposta->json();
        $grupos = [];

        foreach ($lista as $grupo) {
            if (!is_array($grupo)) {
                continue;
            }

            $id = (string) ($grupo['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $grupos[] = [
                'id'   => $id,
                'nome' => (string) ($grupo['subject'] ?? $grupo['name'] ?? '(sem nome)'),
            ];
        }

        return $grupos;
    }

    /** @return array<string,string> */
    private function cabecalhos(): array
    {
        return $this->token === '' ? [] : ['apikey' => $this->token];
    }

    /** @param array<mixed> $dados */
    private function interpretar(int $status, array $dados, ?string $erroTransporte): ResultadoEnvio
    {
        if ($status >= 200 && $status < 300) {
            $id = $dados['key']['id'] ?? ($dados['messageId'] ?? '');

            return ResultadoEnvio::ok(is_string($id) ? $id : '');
        }

        $mensagem = $dados['message'] ?? ($dados['error'] ?? $erroTransporte ?? 'HTTP ' . $status);

        return ResultadoEnvio::falha(is_string($mensagem) ? $mensagem : (json_encode($mensagem) ?: 'erro'));
    }
}
