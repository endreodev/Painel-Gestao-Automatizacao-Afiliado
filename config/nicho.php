<?php

/**
 * Perfil do nicho: o que caracteriza uma ferramenta.
 *
 * Serve para duas coisas ao mesmo tempo:
 *   - filtrar o que nao tem nada a ver (com 'exigir_relevancia');
 *   - pontuar mais alto o que e claramente do ramo.
 *
 * Por que por titulo e nao por categoria do ML: a pagina /ofertas, que e a
 * fonte mais confiavel de coleta, nao informa a categoria de cada card. O
 * titulo, sim, sempre vem. E ferramenta se descreve no titulo.
 *
 * Perfil atual: FERRAMENTAS EM GERAL - mecanica, marcenaria, construcao,
 * jardinagem, eletrica, pintura, solda, hidraulica e medicao. Para estreitar de
 * novo (so oficina mecanica, por exemplo), apague das listas o que nao interessa
 * ou desligue 'ativo'. Nenhum codigo muda.
 */

declare(strict_types=1);

return [
    'ativo' => true,

    'nome' => 'Ferramentas em geral',

    /*
     | Reprova o produto que nao casa com nenhum termo de 'essenciais' nem de
     | 'apoio'. Deixe false para apenas pontuar, sem barrar nada.
     |
     | Mantenha true mesmo com a lista larga: e o que impede o grupo de receber
     | miniatura de furadeira, capa de parafusadeira e camiseta de mecanico -
     | coisas que a busca por "ferramenta" traz junto.
     */
    'exigir_relevancia' => true,

    /*
     |----------------------------------------------------------------------
     | Essenciais - a ferramenta ou o equipamento em si. Nota cheia.
     |----------------------------------------------------------------------
     */
    'essenciais' => [
        // --- eletricas de uso geral ---
        'parafusadeira', 'furadeira', 'martelete', 'rompedor', 'esmerilhadeira',
        'lixadeira', 'politriz', 'plaina eletrica', 'tupia', 'fresadora',
        'serra circular', 'serra tico tico', 'serra sabre', 'serra marmore',
        'serra de bancada', 'serra meia esquadria', 'serra fita', 'esmeril',
        'moto serra',
        'retifica', 'micro retifica', 'soprador termico', 'pistola de calor',
        'multi ferramenta', 'chave de impacto', 'pistola de impacto',

        // --- manuais ---
        'chave de fenda', 'chave philips', 'chave allen', 'chave torx',
        'chave combinada', 'chave estrela', 'chave fixa', 'chave inglesa',
        'chave grifo', 'chave catraca', 'catraca', 'jogo de soquete',
        'jogo de soquetes', 'soquete', 'alicate', 'alicate de pressao',
        'alicate universal', 'torques', 'martelo', 'marreta', 'formao',
        'talhadeira', 'serrote', 'arco de serra', 'grampeador', 'rebitador',
        'arrebitador', 'saca pino', 'extrator', 'saca polia', 'sacador',
        'morsa', 'torno de bancada', 'prensa hidraulica', 'macaco hidraulico',
        'macaco jacare', 'macaco garrafa', 'cavalete', 'torquimetro',
        'chave de roda', 'chave de vela', 'jogo de ferramentas',
        'kit de ferramentas', 'maleta de ferramentas', 'caixa de ferramentas',
        'carrinho de ferramentas', 'organizador de ferramentas',

        // --- medicao ---
        'trena', 'trena laser', 'nivel a laser', 'nivel laser', 'nivel de bolha',
        'esquadro', 'paquimetro', 'micrometro', 'relogio comparador', 'prumo',
        'multimetro', 'alicate amperimetro', 'termometro infravermelho',
        'detector de metais', 'detector de fiacao', 'manometro',
        'calibrador de pneu', 'trena digital',

        // --- construcao ---
        'betoneira', 'vibrador de concreto', 'cortadora de piso', 'policorte',
        'desempenadeira', 'colher de pedreiro', 'regua de aluminio',
        'carrinho de mao', 'andaime', 'escada', 'guincho', 'talha',
        'misturador de argamassa', 'cortador de ceramica', 'cortador de piso',

        // --- jardinagem ---
        'rocadeira', 'motosserra', 'aparador de grama', 'cortador de grama',
        'soprador', 'soprador de folhas', 'podador', 'tesoura de poda', 'motopoda',
        'pulverizador', 'atomizador', 'aparador de cerca viva', 'lamina de roco',

        // --- solda ---
        'maquina de solda', 'inversora de solda', 'solda mig', 'solda tig',
        'mascara de solda', 'ferro de solda', 'estacao de solda', 'macarico',
        'kit de solda',

        // --- pintura e limpeza ---
        'pistola de pintura', 'compressor de ar', 'motocompressor', 'compressor',
        'lavadora', 'lavadora de alta pressao', 'lava jato', 'aspirador de po',
        'cabine de pintura', 'desobstruidora',

        // --- eletrica e hidraulica ---
        'passa fio', 'crimpador', 'decapador', 'furadeira de bancada',
        'bomba de agua', 'bomba submersa', 'soldador de cano', 'soldador ppr',
        'cortador de tubo', 'desentupidora',

        // --- diagnostico automotivo ---
        'scanner automotivo', 'leitor obd', 'obd2', 'carregador de bateria',
        'auxiliar de partida', 'teste de bateria', 'desmontadora de pneu',
        'balanceadora', 'coletor de oleo', 'bomba de graxa',

        // --- energia ---
        'gerador de energia', 'gerador a gasolina', 'estabilizador de solda',
        'inversor de energia', 'bateria estacionaria',
    ],

    /*
     |----------------------------------------------------------------------
     | Apoio - acessorio, consumivel ou item de bancada. Relevancia parcial.
     |----------------------------------------------------------------------
     | Entram no grupo, mas atras de uma ferramenta de verdade com o mesmo
     | desconto - e o que a pontuacao faz.
     */
    'apoio' => [
        'jogo de brocas', 'broca', 'bits', 'jogo de bits', 'ponteira',
        'disco de corte', 'disco de desbaste', 'disco diamantado', 'lixa',
        'rebolo', 'serra copo', 'fresa', 'eletrodo', 'arame de solda',
        'bateria 20v', 'bateria 21v', 'carregador de bateria de ferramenta',
        'extensao eletrica', 'cabo de forca', 'filtro de linha',
        'luminaria de oficina', 'lanterna de inspecao', 'refletor',
        'bancada', 'cavalete de apoio', 'escadinha', 'cinta de amarracao',
        'lona', 'carrinho plataforma', 'mangueira', 'engate rapido',
        'oculos de protecao', 'luva de protecao', 'protetor auricular',
        'mascara respiratoria', 'capacete de seguranca', 'bota de seguranca',
        'cinto de seguranca altura', 'avental de raspa',
    ],

    /*
     |----------------------------------------------------------------------
     | Bloqueados - parecem ferramenta pelo termo, mas nao sao
     |----------------------------------------------------------------------
     */
    'bloqueados' => [
        'brinquedo', 'miniatura', 'infantil', 'chaveiro', 'adesivo', 'camiseta',
        'boné', 'quadro decorativo', 'poster', 'almofada', 'caneca', 'pelucia',
        'unha', 'manicure', 'cilios', 'depilacao', 'alisador de cabelo',
        'para celular', 'para notebook', 'capa protetora', 'pelicula',
        'fantasia', 'bolo', 'chocolate',
    ],

    /*
     |----------------------------------------------------------------------
     | Pesos de relevancia (0 a 1)
     |----------------------------------------------------------------------
     */
    'peso_essencial' => 1.0,
    'peso_apoio'     => 0.55,
];
