<?php

declare(strict_types=1);

namespace MlGroup\Whatsapp;

use MlGroup\Support\Http;
use MlGroup\Support\Logger;
use RuntimeException;
use Throwable;

/**
 * Driver que fala com a ponte local (ponte/servidor.js).
 *
 * E o driver padrao: nao exige servidor externo nem conta em servico pago -
 * so ler o QR code uma vez. A sessao fica em storage/whatsapp-sessao/.
 */
final class Ponte implements WhatsappInterface
{
    public function __construct(
        private readonly GerenciadorPonte $gerenciador = new GerenciadorPonte(),
        private readonly Http $http = new Http(timeout: 45, tentativas: 2),
    ) {
    }

    public function nome(): string
    {
        return 'ponte';
    }

    public function gerenciador(): GerenciadorPonte
    {
        return $this->gerenciador;
    }

    public function enviarTexto(string $destino, string $mensagem): ResultadoEnvio
    {
        return $this->postar('/enviar', ['destino' => $destino, 'texto' => $mensagem]);
    }

    public function enviarImagem(string $destino, string $urlImagem, string $legenda): ResultadoEnvio
    {
        $resultado = $this->postar('/enviar-imagem', [
            'destino' => $destino,
            'imagem'  => $urlImagem,
            'legenda' => $legenda,
        ]);

        if ($resultado->sucesso) {
            return $resultado;
        }

        Logger::i()->aviso('Envio com imagem falhou, caindo para texto', ['erro' => $resultado->erro]);

        return $this->enviarTexto($destino, $legenda);
    }

    public function conectado(): bool
    {
        try {
            $this->gerenciador->garantir();
        } catch (Throwable $erro) {
            Logger::i()->erro('Ponte do WhatsApp indisponivel', ['motivo' => $erro->getMessage()]);

            return false;
        }

        return $this->gerenciador->conectado();
    }

    /** @return array<int,array{id:string,nome:string,participantes:int,somente_admin:bool,sou_admin:bool|null}> */
    public function grupos(): array
    {
        try {
            $this->gerenciador->garantir();
        } catch (Throwable $erro) {
            Logger::i()->erro('Ponte do WhatsApp indisponivel', ['motivo' => $erro->getMessage()]);

            return [];
        }

        $resposta = $this->http->get($this->gerenciador->url() . '/grupos');

        if (!$resposta->ok()) {
            return [];
        }

        $grupos = [];

        foreach ($resposta->json() as $grupo) {
            if (!is_array($grupo) || ($grupo['id'] ?? '') === '') {
                continue;
            }

            $grupos[] = [
                'id'            => (string) $grupo['id'],
                'nome'          => (string) ($grupo['nome'] ?? '(sem nome)'),
                'participantes' => (int) ($grupo['participantes'] ?? 0),
                'somente_admin' => (bool) ($grupo['somenteAdmin'] ?? false),
                // null = nao deu para saber; ver souAdminDe() na ponte
                'sou_admin'     => isset($grupo['souAdmin']) ? (bool) $grupo['souAdmin'] : null,
            ];
        }

        return $grupos;
    }

    /** @param array<string,mixed> $corpo */
    private function postar(string $rota, array $corpo): ResultadoEnvio
    {
        try {
            $this->gerenciador->garantir();
        } catch (RuntimeException $erro) {
            return ResultadoEnvio::falha($erro->getMessage());
        }

        $resposta = $this->http->postJson($this->gerenciador->url() . $rota, $corpo);
        $dados    = $resposta->json();

        if ($resposta->ok()) {
            $id = $dados['id'] ?? '';

            return ResultadoEnvio::ok(is_string($id) ? $id : '');
        }

        $erro = $dados['erro'] ?? ($resposta->erro ?? 'HTTP ' . $resposta->status);

        return ResultadoEnvio::falha(is_string($erro) ? $erro : 'falha no envio');
    }
}
