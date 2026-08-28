<?php

/**
 * Marcas reconhecidas no titulo do anuncio.
 *
 * Existe porque a pagina /ofertas do ML nao traz o campo de marca nos cards -
 * dos 86 produtos coletados ate agora, zero tinham marca preenchida. Sem esta
 * lista nao ha como impedir que o grupo receba cinco parafusadeiras seguidas da
 * mesma marca, que foi exatamente o que aconteceu.
 *
 * O casamento e por palavra, igual ao do nicho: "the black tools" encontra
 * "Parafusadeira The Black Tools TB12A". A marca com mais palavras vence, para
 * "the black tools" ganhar de "black" em "Black+Decker".
 *
 * Marca nova aparecendo no grupo? Acrescente aqui - nenhum codigo muda.
 */

declare(strict_types=1);

return [
    'conhecidas' => [
        // as que mais aparecem na coleta atual
        'the black tools', 'wap', 'bosch', 'vonder', 'makita', 'karcher',
        'nakasaki', 'knakasaki', 'mestri',

        // demais marcas comuns de ferramenta no Mercado Livre
        'black decker', 'dewalt', 'stanley', 'tramontina', 'einhell', 'lynus',
        'hammer', 'worx', 'ryobi', 'milwaukee', 'metabo', 'skil', 'hikoki',
        'intech', 'gamma', 'schulz', 'motomil', 'kala', 'gedore', 'belzer',
        'sparta', 'starfer', 'tekna', 'foxlux', 'brasfort', 'nove54',
        'philco', 'mondial', 'britania', 'electrolux', 'ferrari', 'goodyear',
        'trapp', 'toyama', 'branco', 'kawashima', 'garthen', 'husqvarna',
        'stihl', 'echo', 'tekpix', 'boxer', 'chiaperini', 'pressure',
        'jacto', 'guarany', 'menegotti', 'csm', 'bandeirante', 'famastil',
        'irwin', 'fortgpro', 'noll', 'uyustools', 'total', 'ingco',
    ],
];
