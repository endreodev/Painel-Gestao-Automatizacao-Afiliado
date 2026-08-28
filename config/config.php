<?php

/**
 * Configuracao principal do ml-group.
 *
 * Credenciais NAO ficam aqui - vao no .env. Este arquivo guarda as regras de
 * negocio: o que e uma boa oferta, com que frequencia publicar e como a
 * mensagem e montada.
 */

declare(strict_types=1);

return [
    'fuso' => 'America/Sao_Paulo',

    /*
     |--------------------------------------------------------------------------
     | Coleta
     |--------------------------------------------------------------------------
     | modo: 'auto'      tenta a API do ML e cai para o navegador se ela barrar
     |       'navegador' sempre pelo Chrome headless (enxerga a pagina real)
     |       'api'       so pela API (exige ML_ACCESS_TOKEN na maioria dos casos)
     */
    'coleta' => [
        'modo'                     => 'auto',
        /*
         | Medido: a pagina /ofertas filtrada por dominio rende ~50 itens em 2
         | paginas e 191 em 6, com 147 deles ineditos - e custa 48s em vez de
         | 15s, sem risco extra de anti-bot (essa rota nao e barrada).
         |
         | Os dois tetos precisam subir juntos: max_paginas manda parar de
         | virar pagina, itens_por_busca corta o resultado. Um sozinho nao
         | adianta.
         */
        'itens_por_busca'          => 200,
        'max_paginas'              => 6,

        // espacamento entre paginas. Nao baixe muito: o ML classifica acesso
        // rapido demais como automatizado e passa a servir pagina de
        // verificacao no lugar da lista.
        'intervalo_requisicao_ms'  => 3000,

        // pausa antes de tentar de novo quando a verificacao aparece
        'pausa_bloqueio_s'         => 45,

        // minutos entre coletas. Fica proposital e bem acima do intervalo de
        // envio: a fila permite publicar de 10 em 10 minutos sem varrer o ML
        // na mesma frequencia, que e o que queima o acesso as buscas.
        'intervalo_minutos'        => 60,
    ],

    /*
     |--------------------------------------------------------------------------
     | HTTP
     |--------------------------------------------------------------------------
     | O PHP para Windows nao traz certificados raiz. Deixe vazio para o sistema
     | procurar um bundle ja instalado (Git for Windows, XAMPP, Laragon...). Se
     | nao houver nenhum, baixe https://curl.se/ca/cacert.pem e aponte aqui.
     */
    'http' => [
        'ca_bundle' => '',
    ],

    /*
     |--------------------------------------------------------------------------
     | Navegador (Chrome headless)
     |--------------------------------------------------------------------------
     | 'executavel'      vazio = detecta Chrome/Edge automaticamente
     | 'user_agent'      vazio = usa o UA real do navegador instalado
     | 'aquecer'         visita a home do ML antes da primeira lista. O ML
     |                   devolve pagina de "trafego suspeito" para quem cai
     |                   direto numa URL de lista; a visita previa resolve.
     | 'espera_render_ms' margem apos o load para o conteudo tardio aparecer.
     |                   Aumente se as paginas voltarem sem produtos.
     | 'porta_devtools'  0 = escolhe uma porta livre a cada execucao
     */
    'navegador' => [
        'executavel'       => '',
        'user_agent'       => '',
        'aquecer'          => true,
        'url_aquecimento'  => 'https://www.mercadolivre.com.br',
        'espera_render_ms' => 2500,
        'timeout_s'        => 60,
        'porta_devtools'   => 0,

        // idade, em minutos, a partir da qual um perfil temporario largado por
        // uma execucao interrompida e apagado
        'perfil_vencido_min' => 60,
    ],

    /*
     |--------------------------------------------------------------------------
     | Afiliado
     |--------------------------------------------------------------------------
     | 'modelo' aceita {url}, {url_encoded}, {tag} e {ferramenta}. Se o ML mudar
     | o formato de rastreio, ajuste so esta linha.
     */
    'afiliado' => [
        /*
         | modo 'modelo'      -> monta a URL pelo template abaixo. O link vai
         |                       DIRETO para a pagina do produto, com a tag de
         |                       rastreio, mas sem o parametro "ref".
         | modo 'linkbuilder' -> gera pela tela oficial do ML, com o "ref" que
         |                       nao da para montar na mao. Exige login uma vez:
         |                       php bin/mlgroup ml-login
         |
         | ESCOLHA ATUAL: 'modelo', decidida depois de medir na conta real.
         | O Link Builder funciona (esta automatizado e testado), mas com o canal
         | 'play_connect_mobile' tanto o link curto quanto o completo abrem o
         | PERFIL SOCIAL do afiliado, e nao o produto - um clique a mais entre a
         | mensagem e a compra, que num grupo de WhatsApp custa conversao.
         |
         | Vale reavaliar se a conta de afiliado ganhar um canal do tipo "Links":
         | ai o Link Builder passaria a devolver link direto ao produto e o
         | modo 'linkbuilder' juntaria as duas vantagens.
         */
        'modo'                => 'modelo',

        'tag'                 => '',
        'ferramenta'          => '',
        'modelo'              => '{url}?matt_word={tag}&matt_tool={ferramenta}&forceInApp=true',

        /*
         | --- modo linkbuilder ---
         |
         | ATENCAO, medido na conta real: com o canal 'play_connect_mobile', os
         | dois tipos de link levam ao PERFIL SOCIAL do afiliado, e nao a pagina
         | do produto - o comprador precisa de mais um clique. Confira o que
         | rende mais no seu caso antes de trocar 'modo' para 'linkbuilder'.
         |
         | 'tipo_link'  'curto'    -> meli.la/xxxx
         |              'completo' -> mercadolivre.com.br/... (mostra o dominio
         |                            do ML na mensagem, que gera mais confianca
         |                            num grupo de WhatsApp)
         */
        'tipo_link'             => 'curto',
        'perfil_navegador'      => '',   // vazio = storage/navegador-ml
        'espera_linkbuilder_ms' => 12000,

        // preencha so se a deteccao automatica falhar (o comando `link` mostra
        // os campos e botoes que existem na tela)
        'seletor_campo'         => '',
        'seletor_botao'         => '',

        'encurtar'            => false,
        'encurtador_url'      => '',
        'encurtador_campos'   => ['short_url', 'shortUrl', 'link', 'url'],
    ],

    /*
     |--------------------------------------------------------------------------
     | Filtros - o que NAO vai para o grupo
     |--------------------------------------------------------------------------
     | Comece folgado (desconto 15, comissao 3) e va apertando conforme ve o
     | ranking do comando `analisar`.
     */
    'filtros' => [
        'desconto_minimo'       => 20.0,
        'desconto_maximo'       => 85.0,   // acima disso quase sempre e preco falso
        'comissao_minima'       => 3.0,
        /*
         | ganho_minimo tem que acompanhar a faixa de preco, senao ele a anula
         | por dentro: com R$ 2,50 exigidos e comissao de ~5%, todo produto
         | abaixo de R$ 50 era reprovado - a faixa dizia 10 a 100, mas na
         | pratica so passava de 50 para cima.
         */
        'ganho_minimo'          => 0.30,   // em reais, por venda
        'preco_minimo'          => 10.0,
        'preco_maximo'          => 100.0,  // 0 = sem teto
        'avaliacao_minima'      => 4.0,
        'avaliacoes_minimas'    => 0,      // exija >= 5 para so mandar produto rodado
        'exigir_frete_gratis'   => false,
        'exigir_full'           => false,
        'dias_sem_repetir'      => 7,
        'queda_para_reenviar'   => 10.0,   // % de queda que libera reenviar antes do prazo

        'palavras_bloqueadas' => [
            'usado', 'recondicionado', 'defeito', 'para retirar pecas',
            'capa', 'adesivo', 'manual', 'miniatura', 'brinquedo',
            'somente a bateria', 'apenas o carregador', 'replica',
        ],

        // vazio = aceita qualquer titulo que tenha passado nas buscas
        'palavras_obrigatorias' => [],

        'marcas_bloqueadas'     => [],
        'vendedores_bloqueados' => [],
    ],

    /*
     |--------------------------------------------------------------------------
     | Analise de preco
     |--------------------------------------------------------------------------
     | O detector de desconto falso compara o "de" anunciado com a mediana do
     | preco praticado. Ele so age depois de 3 coletas do mesmo anuncio.
     */
    'analise' => [
        'historico_dias'            => 90,
        'historico_max_pontos'      => 60,
        'detectar_desconto_falso'   => true,
        'tolerancia_preco_inflado'  => 1.15,
    ],

    /*
     |--------------------------------------------------------------------------
     | Pontuacao - a ordem em que as ofertas sao publicadas
     |--------------------------------------------------------------------------
     | Suba 'comissao'/'ganho' para priorizar faturamento; suba 'desconto' para
     | priorizar engajamento do grupo.
     */
    'pontuacao' => [
        'pesos' => [
            'desconto'     => 3.0,
            'comissao'     => 2.0,
            'ganho'        => 1.5,
            'reputacao'    => 1.5,
            'popularidade' => 1.0,
            'logistica'    => 0.8,
            'historico'    => 1.2,

            // o quanto o item e cara de oficina (config/nicho.php).
            // Peso alto: um item claramente do ramo com 30% de desconto
            // interessa mais ao grupo que um item generico com 50%.
            'nicho'        => 3.0,
        ],

        /*
         | Valor a partir do qual o criterio ja vale nota cheia.
         |
         | ganho_teto acompanha a faixa de preco: com teto de R$ 300 e comissao
         | de ~5%, o ganho real fica entre R$ 3 e R$ 15. Um teto de R$ 40 faria
         | todo produto tirar nota baixa nesse criterio, que entao deixaria de
         | diferenciar qualquer coisa. Se voltar a vender itens caros, suba.
         */
        'desconto_teto' => 60.0,
        'comissao_teto' => 8.0,
        'ganho_teto'    => 15.0,
        'vendidos_teto' => 2000.0,
    ],

    /*
     |--------------------------------------------------------------------------
     | Envio
     |--------------------------------------------------------------------------
     | modo 'individual' da preview de link melhor; 'lote' faz menos barulho.
     */
    'envio' => [
        'modo'                    => 'individual',

        // uma oferta por ciclo; com agenda.intervalo_minutos = 10, da uma
        // mensagem a cada 10 minutos
        'max_por_execucao'        => 1,

        'intervalo_mensagens_s'   => 12,
        'enviar_imagem'           => true,

        /*
         | Manda o link tambem numa mensagem de texto logo depois da imagem.
         |
         | No iPhone, link dentro de legenda de imagem nem sempre vira link
         | tocavel; em mensagem de texto, sempre vira. Ligue se o link na legenda
         | nao estiver clicavel - custa uma mensagem a mais por oferta.
         */
        'link_em_mensagem_separada' => false,

        // oferta coletada ha mais tempo que isso nao vai para o grupo: o preco
        // ja pode ter mudado
        'validade_horas'          => 12,
    ],

    /*
     |--------------------------------------------------------------------------
     | Diversidade - nao mandar a mesma coisa em sequencia
     |--------------------------------------------------------------------------
     | A fila e ordenada por pontuacao, e produto do mesmo tipo pontua parecido.
     | Sem esta regra saem cinco parafusadeiras seguidas, quase todas da mesma
     | marca - foi o que aconteceu.
     |
     | 'repetir_tipo_apos'  quantos envios antes de repetir o mesmo tipo
     |                      (parafusadeira, lavadora, esmerilhadeira). 0 desliga.
     | 'repetir_marca_apos' idem para a marca (config/marcas.php). 0 desliga.
     | 'relaxar_se_esgotar' quando nao ha variedade suficiente, publica repetido
     |                      em vez de ficar em silencio.
     */
    'diversidade' => [
        'ativa'              => true,
        'repetir_tipo_apos'  => 5,
        'repetir_marca_apos' => 3,
        'relaxar_se_esgotar' => true,
    ],

    /*
     |--------------------------------------------------------------------------
     | Agenda
     |--------------------------------------------------------------------------
     | Janela de envio. O fim e exclusivo: com 09:00-22:00, as 21:59 ainda
     | envia e as 22:00 nao envia mais.
     |
     | dias_semana em ISO-8601: 1 = segunda ... 7 = domingo.
     | Deixe [] para nao restringir por dia.
     */
    'agenda' => [
        'intervalo_minutos' => 10,
        'hora_inicio'       => '09:00',
        'hora_fim'          => '22:00',
        'dias_semana'       => [1, 2, 3, 4, 5, 6, 7],
    ],

    /*
     |--------------------------------------------------------------------------
     | WhatsApp
     |--------------------------------------------------------------------------
     | O driver e os IDs de grupo normalmente vem do .env; o que esta aqui e
     | apenas o fallback.
     */
    'whatsapp' => [
        // 'ponte' e o padrao: conecta lendo o QR code, sem servidor externo.
        // Outros: evolution, zapi, wppconnect, simulado
        'driver'      => 'ponte',
        'grupos'      => [],
        'ponte_porta' => 8787,

        // vazio = procura o Node 20+ sozinho (inclusive versoes do nvm)
        'node'        => '',
    ],

    /*
     |--------------------------------------------------------------------------
     | Mensagem
     |--------------------------------------------------------------------------
     */
    'mensagem' => [
        'template'           => 'oferta',
        'template_cabecalho' => 'cabecalho',
        'template_item'      => 'item',
        'separador'          => "\n\n",
        'tamanho_titulo'     => 70,
        'selo_frete'         => 'Frete gratis',
        'selo_full'          => 'Entrega FULL',
        'selo_menor_preco'   => 'MENOR PRECO JA VISTO',

        // desconto minimo => marcador exibido na mensagem
        'termometro' => [
            20 => 'OFERTA',
            35 => 'OFERTAO',
            50 => 'PRECO RARO',
            65 => 'CORRE QUE ACABA',
        ],
    ],

    /*
     |--------------------------------------------------------------------------
     | Painel web
     |--------------------------------------------------------------------------
     | Responde apenas em 127.0.0.1. O que voce muda por la vai para
     | storage/config-local.json, por cima destes arquivos - que continuam
     | sendo o padrao. Apagar aquele JSON desfaz tudo.
     */
    'painel' => [
        'porta' => 8321,
    ],

    /*
     |--------------------------------------------------------------------------
     | Log
     |--------------------------------------------------------------------------
     */
    'log' => [
        'nivel'         => 'info',
        'console'       => true,
        'retencao_dias' => 14,
    ],
];
