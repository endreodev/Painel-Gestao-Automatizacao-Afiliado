<?php

declare(strict_types=1);

namespace MlGroup\Whatsapp;

interface WhatsappInterface
{
    public function nome(): string;

    /** Envia texto puro (com preview de link quando o gateway suporta). */
    public function enviarTexto(string $destino, string $mensagem): ResultadoEnvio;

    /** Envia imagem com legenda; cai para texto quando a imagem falha. */
    public function enviarImagem(string $destino, string $urlImagem, string $legenda): ResultadoEnvio;

    /** Conexao/credenciais estao valendo? */
    public function conectado(): bool;

    /**
     * Lista os grupos visiveis pela instancia, para descobrir o ID do grupo.
     *
     * @return array<int,array{id:string,nome:string}>
     */
    public function grupos(): array;
}
