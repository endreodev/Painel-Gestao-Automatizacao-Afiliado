<?php

/**
 * O que o cacador procura a cada ciclo - perfil OFICINA MECANICA.
 *
 * Tres tipos de busca:
 *
 *   'ofertas' - varre a pagina /ofertas do ML (promocoes do dia).
 *               E a fonte mais confiavel: raramente cai no anti-bot.
 *               Campos: categoria (opcional), container (opcional)
 *
 *   'termo'   - monta a URL de lista a partir da palavra-chave + filtros.
 *               Traz itens muito mais especificos, mas as listas do ML sao
 *               protegidas: se o acesso for frequente, voltam pagina de
 *               verificacao de trafego.
 *               Campos: termo, preco_min, preco_max, desconto_min,
 *                       apenas_frete_gratis, apenas_full, ordem
 *
 *   'url'     - usa uma URL colada do navegador, com os filtros ja aplicados.
 *               Campo: url
 *
 * Coloque 'ativo' => false para desligar sem apagar.
 *
 * LOJA
 * ----
 * Por padrao a busca vai ao Mercado Livre. Para procurar na Shopee, acrescente
 * 'loja' => 'shopee' - e a unica diferenca. Ela usa a API oficial de afiliados
 * (nada de navegador) e aceita:
 *
 *   'termo'     palavra-chave, igual as buscas do ML
 *   'listType'  0 = tudo · 2 = mais vendidos · 3 e 4 = categoria · 5 = loja
 *   'matchId'   o id da categoria (listType 3 e 4) ou da loja (listType 5)
 *   'sortType'  codigo de ordenacao da Shopee (veja a documentacao deles)
 *
 * Sem 'termo' e sem 'listType', a busca vira listType 0 - o equivalente as
 * ofertas do dia. Exige SHOPEE_APP_ID e SHOPEE_SECRET no .env; sem elas a
 * busca e pulada e o ciclo segue com o Mercado Livre.
 *
 * O que chega da Shopee passa pelo mesmo nicho, pelos mesmos filtros e pela
 * mesma pontuacao - so a fonte muda. Preco_min/preco_max e desconto_min NAO
 * sao enviados a ela (a API nao aceita esses cortes): quem descarta e o filtro,
 * depois.
 *
 * As faixas de preco daqui acompanham o teto de config/config.php >
 * filtros.preco_maximo (hoje R$ 300). Pedir ao ML faixas mais largas so faz o
 * coletor trazer o que o filtro vai descartar depois.
 *
 * IMPORTANTE: o que chega aqui ainda passa pelo perfil de config/nicho.php.
 * Uma busca ampla como "ofertas do dia" nao suja o grupo: roçadeira, motosserra
 * e serra para madeira sao descartadas por estarem fora do nicho.
 */

declare(strict_types=1);

return [
    'buscas' => [
        /*
         | Ofertas do dia ja filtradas pelos dominios de ferramenta (solda,
         | furadeira, esmerilhadeira, serra, chaves, brocas, jogos). E a melhor
         | fonte que temos: numa coleta de teste trouxe 60 itens, 16 tipos
         | diferentes, e nenhum deles precisou ser descartado pelo nicho.
         |
         | A URL foi copiada do navegador com os filtros aplicados. O
         | container_id pertence a uma campanha do ML e pode expirar - se um dia
         | esta busca voltar vazia, abra mercadolivre.com.br/ofertas, refaca os
         | filtros e cole a URL nova aqui.
         */
        [
            'nome'  => 'Ofertas do dia - dominios de ferramenta',
            'tipo'  => 'url',
            'url'   => 'https://www.mercadolivre.com.br/ofertas?container_id=MLB779540-1&domain_id=MLB-WELDING_MACHINES$MLB-TOOLS$MLB-WELDING_BLOWTORCHES$MLB-WELDING_RODS$MLB-DRILLS_SCREWDRIVERS$MLB-ELECTRIC_DRILLS$MLB-DRILL_BITS$MLB-POWER_GRINDERS$MLB-COMBINED_TOOL_SETS$MLB-ELECTRIC_CIRCULAR_SAWS$MLB-TOOL_ACCESSORIES_AND_SPARES$MLB-WRENCHES$MLB-WRENCH_SETS#filter_applied=domain_id&filter_position=12&is_recommended_domain=false&origin=scut',
            'ativo' => true,
        ],

        /*
         | Fonte principal. MLB263532 = Ferramentas (ID confirmado em coleta).
         |
         | Para mirar direto na categoria automotiva: abra
         | mercadolivre.com.br/ofertas no navegador, filtre pela categoria
         | desejada e copie o "category=MLB..." da barra de enderecos.
         */
        [
            'nome'      => 'Ofertas do dia - Ferramentas',
            'tipo'      => 'ofertas',
            'categoria' => 'MLB263532',
            'ativo'     => true,
        ],

        // --- aperto e torque: o coracao da oficina ---
        [
            'nome'         => 'Chave de impacto',
            'tipo'         => 'termo',
            'termo'        => 'chave de impacto',
            'preco_min'    => 150,
            'preco_max'    => 300,
            'desconto_min' => 15,
            'ativo'        => true,
        ],
        [
            'nome'         => 'Jogo de soquetes e catraca',
            'tipo'         => 'termo',
            'termo'        => 'jogo de soquetes catraca',
            'preco_min'    => 80,
            'preco_max'    => 300,
            'desconto_min' => 20,
            'ativo'        => true,
        ],
        [
            'nome'         => 'Torquimetro',
            'tipo'         => 'termo',
            'termo'        => 'torquimetro',
            'preco_min'    => 80,
            'preco_max'    => 300,
            'desconto_min' => 15,
            'ativo'        => true,
        ],

        // --- elevacao ---
        [
            'nome'         => 'Macaco hidraulico jacare',
            'tipo'         => 'termo',
            'termo'        => 'macaco hidraulico jacare',
            'preco_min'    => 150,
            'preco_max'    => 300,
            'desconto_min' => 15,
            'ativo'        => true,
        ],

        // --- diagnostico ---
        [
            'nome'         => 'Scanner automotivo / OBD2',
            'tipo'         => 'termo',
            'termo'        => 'scanner automotivo obd2',
            'preco_min'    => 100,
            'preco_max'    => 300,
            'desconto_min' => 15,
            'ativo'        => true,
        ],
        [
            'nome'         => 'Carregador e auxiliar de partida',
            'tipo'         => 'termo',
            'termo'        => 'carregador de bateria automotivo',
            'preco_min'    => 100,
            'preco_max'    => 300,
            'desconto_min' => 20,
            'ativo'        => true,
        ],

        // --- bancada e organizacao ---
        [
            'nome'         => 'Carrinho / caixa de ferramentas',
            'tipo'         => 'termo',
            'termo'        => 'carrinho de ferramentas oficina',
            'preco_min'    => 150,
            'preco_max'    => 300,
            'desconto_min' => 20,
            'ativo'        => true,
        ],
        [
            'nome'         => 'Compressor de ar',
            'tipo'         => 'termo',
            'termo'        => 'compressor de ar',
            'preco_min'    => 100,
            'preco_max'    => 300,
            'desconto_min' => 15,
            'ativo'        => true,
        ],

        /*
         | Desligadas por padrao. Cada busca por termo que cai no anti-bot custa
         | ~50s de pausa, entao mantenha ativas so as que rendem. Ative aos
         | poucos e acompanhe com: php bin/mlgroup analisar --verbose
         */
        [
            // fora da faixa ate R$ 300 - so faz sentido se o teto subir
            'nome'         => 'Prensa hidraulica',
            'tipo'         => 'termo',
            'termo'        => 'prensa hidraulica oficina',
            'preco_min'    => 300,
            'desconto_min' => 15,
            'ativo'        => false,
        ],
        [
            'nome'         => 'Extrator / saca polia',
            'tipo'         => 'termo',
            'termo'        => 'extrator saca polia',
            'preco_min'    => 50,
            'desconto_min' => 20,
            'ativo'        => false,
        ],
        [
            'nome'         => 'Maquina de solda',
            'tipo'         => 'termo',
            'termo'        => 'maquina de solda inversora',
            'preco_min'    => 150,
            'preco_max'    => 300,
            'desconto_min' => 20,
            'ativo'        => false,
        ],
        [
            'nome'         => 'Pistola de pintura automotiva',
            'tipo'         => 'termo',
            'termo'        => 'pistola de pintura automotiva hvlp',
            'preco_min'    => 100,
            'desconto_min' => 20,
            'ativo'        => false,
        ],
        [
            // fora da faixa ate R$ 300 - so faz sentido se o teto subir
            'nome'         => 'Elevador automotivo',
            'tipo'         => 'termo',
            'termo'        => 'elevador automotivo',
            'preco_min'    => 1500,
            'desconto_min' => 10,
            'ativo'        => false,
        ],

        [
            'nome'  => 'URL colada do navegador (exemplo desligado)',
            'tipo'  => 'url',
            'url'   => 'https://lista.mercadolivre.com.br/ferramentas/chave-de-impacto_Discount_30-100',
            'ativo' => false,
        ],

        /*
         |----------------------------------------------------------------------
         | Shopee - descomente depois de por SHOPEE_APP_ID e SHOPEE_SECRET no
         | .env e de conferir com:  php bin/mlgroup shopee
         |
         | Comece pelas duas de baixo e olhe o ranking (php bin/mlgroup analisar
         | --verbose) antes de abrir mais: a Shopee tem muito item barato de
         | catalogo generico, e quem separa o que presta e o perfil de nicho.
         |----------------------------------------------------------------------
         */
        // [
        //     'nome'  => 'Shopee - ofertas em destaque',
        //     'loja'  => 'shopee',
        //     'tipo'  => 'ofertas',
        //     'listType' => 2,
        //     'ativo' => true,
        // ],
        // [
        //     'nome'  => 'Shopee - furadeira',
        //     'loja'  => 'shopee',
        //     'tipo'  => 'termo',
        //     'termo' => 'furadeira',
        //     'ativo' => true,
        // ],
    ],
];
