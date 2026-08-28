<?php

/**
 * Canais: cada grupo de WhatsApp com o seu assunto.
 *
 * Um canal reúne um destino, um nicho e um conjunto de buscas. É o que permite
 * ter um grupo de ferramentas e outro de utilidades domésticas recebendo coisas
 * completamente diferentes, com filtros diferentes, no mesmo sistema.
 *
 * O que cada canal pode ter:
 *
 *   id       obrigatório e único. Identifica o canal no banco - trocar o id
 *            depois faz o sistema perder o histórico daquele grupo.
 *   nome     como aparece no painel e no log
 *   grupos   IDs de grupo do WhatsApp (php bin/mlgroup grupos lista os seus)
 *   ativo    false pausa o canal sem apagar nada
 *   nicho    substitui config/nicho.php inteiro para este canal
 *   buscas   substitui config/buscas.php para este canal
 *   ajustes  sobrepõe chaves de config/config.php, por caminho de ponto
 *
 * O que não é declarado aqui cai no valor global. Um canal que só precisa de um
 * teto de preço diferente declara só isso.
 */

declare(strict_types=1);

$dominiosDeFerramenta = 'MLB-WELDING_MACHINES$MLB-TOOLS$MLB-WELDING_BLOWTORCHES$MLB-WELDING_RODS'
    . '$MLB-DRILLS_SCREWDRIVERS$MLB-ELECTRIC_DRILLS$MLB-DRILL_BITS$MLB-POWER_GRINDERS'
    . '$MLB-COMBINED_TOOL_SETS$MLB-ELECTRIC_CIRCULAR_SAWS$MLB-TOOL_ACCESSORIES_AND_SPARES'
    . '$MLB-WRENCHES$MLB-WRENCH_SETS';

return [
    'canais' => [
        /*
         |------------------------------------------------------------------
         | Ferramentas - o canal que já existia
         |------------------------------------------------------------------
         | Sem 'nicho' e sem 'buscas' declarados: usa config/nicho.php e
         | config/buscas.php, que já estão calibrados para ferramentas.
         */
        [
            'id'     => 'ferramentas',
            'nome'   => 'Ferramentas',

            /*
             | Vazio de proposito: o ID do seu grupo nao mora aqui.
             |
             | Este arquivo vai para o repositorio, e ID de grupo diz em quais
             | grupos voce esta. Preencha pelo painel, em Grupos - o que voce
             | salvar la fica em storage/config-local.json, que o git ignora, e
             | tem prioridade sobre este valor.
             */
            'grupos' => [],
            'ativo'  => true,
        ],

        /*
         |------------------------------------------------------------------
         | Utilidades domésticas - canal novo
         |------------------------------------------------------------------
         | Preencha 'grupos' com o ID do grupo novo (php bin/mlgroup grupos) e
         | ligue o 'ativo'. Enquanto estiver sem grupo, fica desligado para não
         | coletar à toa.
         */
        [
            'id'     => 'utilidades',
            'nome'   => 'Utilidades domésticas',
            'grupos' => [],
            'ativo'  => false,

            'nicho' => [
                'ativo'             => true,
                'nome'              => 'Utilidades domésticas',
                'exigir_relevancia' => true,

                'essenciais' => [
                    // cozinha
                    'panela', 'frigideira', 'jogo de panelas', 'caçarola', 'wok',
                    'air fryer', 'fritadeira eletrica', 'liquidificador',
                    'batedeira', 'mixer', 'processador de alimentos',
                    'sanduicheira', 'cafeteira', 'chaleira eletrica', 'garrafa termica',
                    'panela de pressao', 'forma', 'assadeira', 'tabua de corte',
                    'jogo de facas', 'faca', 'descascador', 'ralador', 'escorredor',
                    'pote hermetico', 'organizador de geladeira', 'lixeira',
                    'jogo de talheres', 'copo', 'caneca', 'jarra', 'travessa',
                    'abridor', 'espremedor', 'peneira', 'concha', 'espatula',

                    // limpeza e lavanderia
                    'aspirador', 'vassoura', 'rodo', 'mop', 'esfregao', 'balde',
                    'varal', 'cesto de roupa', 'tabua de passar', 'ferro de passar',
                    'cabide', 'escova de limpeza', 'pano de microfibra',
                    'organizador', 'caixa organizadora', 'sapateira',

                    // casa
                    'luminaria', 'abajur', 'lampada led', 'fita led', 'extensao',
                    'ventilador', 'umidificador', 'purificador de ar',
                    'toalha', 'jogo de cama', 'edredom', 'travesseiro', 'cobertor',
                    'tapete', 'cortina', 'almofada', 'espelho', 'prateleira',
                    'suporte de parede', 'gancho adesivo', 'porta temperos',
                    'balanca de cozinha', 'termometro', 'relogio de parede',
                    'garrafa squeeze', 'marmita', 'lancheira',

                    // pet e jardim de casa
                    'comedouro', 'bebedouro', 'caixa de areia', 'arranhador',
                    'regador', 'vaso', 'mangueira de jardim',
                ],

                'apoio' => [
                    'pilha', 'bateria aa', 'bateria aaa', 'adaptador', 'tomada',
                    'filtro de linha', 'fita adesiva', 'cola', 'velcro',
                    'saco de lixo', 'esponja', 'pano de prato', 'luva de limpeza',
                    'refil', 'suporte', 'base', 'capa de sofa', 'protetor de colchao',
                ],

                'bloqueados' => [
                    'brinquedo', 'miniatura', 'infantil', 'pelucia', 'fantasia',
                    'camiseta', 'poster', 'quadro decorativo',
                    'para celular', 'para notebook', 'pelicula',
                    'unha', 'manicure', 'cilios', 'depilacao',
                    'bolo', 'chocolate', 'suplemento', 'vitamina',
                ],

                'peso_essencial' => 1.0,
                'peso_apoio'     => 0.8,
            ],

            /*
             | MLB1574 = Casa, Móveis e Decoração
             | MLB1039 = Eletrodomésticos
             |
             | Confirme os IDs com: php bin/mlgroup categorias casa
             | Se um deles vier vazio na coleta, o ID mudou - troque aqui.
             */
            'buscas' => [
                [
                    'nome'      => 'Casa e decoração, baratos',
                    'tipo'      => 'ofertas',
                    'categoria' => 'MLB1574',
                    'preco_min' => 10,
                    'preco_max' => 100,
                    'ativo'     => true,
                ],
                [
                    'nome'      => 'Eletrodomésticos baratos',
                    'tipo'      => 'ofertas',
                    'categoria' => 'MLB1039',
                    'preco_min' => 10,
                    'preco_max' => 100,
                    'ativo'     => true,
                ],
                [
                    'nome'      => 'Ofertas gerais (o nicho filtra)',
                    'tipo'      => 'ofertas',
                    'preco_min' => 10,
                    'preco_max' => 100,
                    'ativo'     => true,
                ],
            ],

            'ajustes' => [
                'config.filtros.preco_minimo' => 10.0,
                'config.filtros.preco_maximo' => 100.0,
            ],
        ],
    ],
];
