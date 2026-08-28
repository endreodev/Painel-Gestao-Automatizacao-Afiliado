<?php

/**
 * Tabela de comissao do Programa de Afiliados do Mercado Livre.
 *
 * IMPORTANTE: o ML nao expoe a comissao por API - o percentual real aparece na
 * Central de Afiliados e muda por categoria e por campanha. Os valores abaixo
 * sao um ponto de partida; confira na sua conta e ajuste. Se este arquivo
 * estiver errado, o filtro de comissao e a pontuacao ficam errados junto.
 *
 * Precedencia: por_categoria (ID exato) > por_palavra (titulo) > padrao.
 */

declare(strict_types=1);

return [
    // usado quando nada mais casa
    'padrao' => 4.0,

    // teto de comissao por venda, em reais (0 = sem teto)
    'teto_por_venda' => 100.00,

    /*
     | IDs de categoria do MLB. Descubra os IDs reais com:
     |   php bin/mlgroup categorias ferramentas
     | e depois navegue os filhos em api.mercadolibre.com/categories/<ID>
     */
    'por_categoria' => [
        'MLB263532' => 5.0,   // Ferramentas
        'MLB264364' => 5.0,   // Ferramentas Eletricas
        'MLB264506' => 4.5,   // Ferramentas Manuais
        'MLB1500'   => 4.0,   // Construcao
    ],

    /*
     | Fallback por palavra no titulo, para quando a coleta vem do navegador e
     | nao traz o ID da categoria. A palavra mais longa vence, entao
     | "serra circular" ganha de "serra".
     */
    'por_palavra' => [
        // --- oficina mecanica ---
        'chave de impacto'      => 5.0,
        'pistola de impacto'    => 5.0,
        'torquimetro'           => 4.5,
        'jogo de soquete'       => 4.5,
        'chave catraca'         => 4.5,
        'macaco hidraulico'     => 4.5,
        'macaco jacare'         => 4.5,
        'cavalete automotivo'   => 4.0,
        'prensa hidraulica'     => 4.5,
        'extrator'              => 4.0,
        'saca polia'            => 4.0,
        'scanner automotivo'    => 5.0,
        'leitor obd'            => 5.0,
        'carregador de bateria' => 4.5,
        'auxiliar de partida'   => 4.5,
        'carrinho de ferramentas' => 4.5,
        'elevador automotivo'   => 3.5,
        'desmontadora de pneu'  => 3.5,
        'balanceadora'          => 3.5,
        'calibrador de pneu'    => 4.0,
        'paquimetro'            => 4.0,
        'morsa'                 => 4.0,

        // --- ferramentas gerais ---
        'parafusadeira'      => 5.5,
        'furadeira'          => 5.5,
        'esmerilhadeira'     => 5.0,
        'serra circular'     => 5.0,
        'serra tico tico'    => 5.0,
        'serra marmore'      => 5.0,
        'lixadeira'          => 5.0,
        'plaina'             => 5.0,
        'martelete'          => 5.0,
        'rompedor'           => 5.0,
        'compressor'         => 4.5,
        'lavadora de alta'   => 4.5,
        'solda'              => 4.5,
        'multimetro'         => 4.5,
        'trena'              => 4.0,
        'nivel a laser'      => 4.5,
        'jogo de ferramentas' => 4.5,
        'caixa de ferramentas' => 4.0,
        'chave de impacto'   => 5.0,
        'bateria'            => 3.5,
        'kit'                => 4.5,
    ],
];
