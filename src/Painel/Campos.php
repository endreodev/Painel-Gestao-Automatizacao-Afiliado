<?php

declare(strict_types=1);

namespace MlGroup\Painel;

/**
 * Descreve os campos que o painel edita.
 *
 * O formulario e gerado a partir daqui, e nao escrito a mao: assim adicionar
 * uma regra nova em config/ custa uma linha, e nao um bloco de HTML.
 *
 * Tipos:
 *   texto · numero · inteiro · dinheiro · percentual · booleano
 *   escolha (com 'opcoes') · lista (uma por linha) · hora · dias
 *
 * Origem:
 *   'config' -> vai para storage/config-local.json, por cima de config/*.php
 *   'env'    -> vai para o .env (credenciais e identificadores)
 */
final class Campos
{
    /** @return array<string,array{titulo:string,descricao:string,campos:array<int,array<string,mixed>>}> */
    public static function secoes(): array
    {
        return [
            'filtros' => [
                'titulo'    => 'Filtros',
                'descricao' => 'O que NÃO vai para o grupo. Comece folgado e vá apertando conforme vê o ranking.',
                'campos'    => [
                    ['chave' => 'config.filtros.desconto_minimo', 'rotulo' => 'Desconto mínimo', 'tipo' => 'percentual', 'ajuda' => 'Abaixo disso a oferta é descartada.'],
                    ['chave' => 'config.filtros.desconto_maximo', 'rotulo' => 'Desconto máximo', 'tipo' => 'percentual', 'ajuda' => 'Acima disso quase sempre é preço falso. 0 desliga.'],
                    ['chave' => 'config.filtros.preco_minimo', 'rotulo' => 'Preço mínimo', 'tipo' => 'dinheiro'],
                    ['chave' => 'config.filtros.preco_maximo', 'rotulo' => 'Preço máximo', 'tipo' => 'dinheiro', 'ajuda' => '0 = sem teto.'],
                    ['chave' => 'config.filtros.comissao_minima', 'rotulo' => 'Comissão mínima', 'tipo' => 'percentual'],
                    ['chave' => 'config.filtros.ganho_minimo', 'rotulo' => 'Ganho mínimo por venda', 'tipo' => 'dinheiro', 'ajuda' => 'Cuidado: com comissão de ~5%, exigir R$ 2,50 descarta tudo abaixo de ~R$ 50.'],
                    ['chave' => 'config.filtros.avaliacao_minima', 'rotulo' => 'Nota mínima', 'tipo' => 'numero', 'ajuda' => 'De 0 a 5. Produto sem nenhuma avaliação não é reprovado por aqui.'],
                    ['chave' => 'config.filtros.avaliacoes_minimas', 'rotulo' => 'Mínimo de avaliações', 'tipo' => 'inteiro', 'ajuda' => 'Atenção: cards da página /ofertas não trazem avaliação. Exigir aqui descarta tudo que vem dela.'],
                    ['chave' => 'config.filtros.exigir_frete_gratis', 'rotulo' => 'Só com frete grátis', 'tipo' => 'booleano'],
                    ['chave' => 'config.filtros.exigir_full', 'rotulo' => 'Só com entrega FULL', 'tipo' => 'booleano'],
                    ['chave' => 'config.filtros.dias_sem_repetir', 'rotulo' => 'Dias sem repetir a mesma oferta', 'tipo' => 'inteiro'],
                    ['chave' => 'config.filtros.queda_para_reenviar', 'rotulo' => 'Queda que libera reenviar', 'tipo' => 'percentual', 'ajuda' => 'Se o preço cair isso, a oferta pode voltar antes do prazo.'],
                    ['chave' => 'config.filtros.palavras_bloqueadas', 'rotulo' => 'Palavras bloqueadas', 'tipo' => 'lista', 'ajuda' => 'Uma por linha. Se aparecer no título, descarta.'],
                    ['chave' => 'config.filtros.palavras_obrigatorias', 'rotulo' => 'Palavras obrigatórias', 'tipo' => 'lista', 'ajuda' => 'Uma por linha. Vazio = aceita qualquer título.'],
                    ['chave' => 'config.filtros.marcas_bloqueadas', 'rotulo' => 'Marcas bloqueadas', 'tipo' => 'lista'],
                    ['chave' => 'config.filtros.vendedores_bloqueados', 'rotulo' => 'Vendedores bloqueados', 'tipo' => 'lista'],
                ],
            ],

            'diversidade' => [
                'titulo'    => 'Diversidade',
                'descricao' => 'Evita mandar a mesma coisa em sequência. A fila é ordenada por pontuação, e produto do mesmo tipo pontua parecido — sem esta regra saem várias parafusadeiras seguidas, quase todas da mesma marca.',
                'campos'    => [
                    ['chave' => 'config.diversidade.ativa', 'rotulo' => 'Espalhar tipos e marcas', 'tipo' => 'booleano'],
                    ['chave' => 'config.diversidade.repetir_tipo_apos', 'rotulo' => 'Não repetir o tipo por', 'tipo' => 'inteiro', 'ajuda' => 'Quantos envios até o mesmo tipo (parafusadeira, lavadora) poder voltar. 0 desliga.'],
                    ['chave' => 'config.diversidade.repetir_marca_apos', 'rotulo' => 'Não repetir a marca por', 'tipo' => 'inteiro', 'ajuda' => 'Idem para a marca. As marcas reconhecidas ficam em config/marcas.php. 0 desliga.'],
                    ['chave' => 'config.diversidade.relaxar_se_esgotar', 'rotulo' => 'Publicar repetido se faltar variedade', 'tipo' => 'booleano', 'ajuda' => 'Desligado, o ciclo fica em silêncio quando só há um tipo na fila.'],
                    ['chave' => 'marcas.conhecidas', 'rotulo' => 'Marcas reconhecidas', 'tipo' => 'lista', 'ajuda' => 'Uma por linha. Usadas para detectar a marca no título, já que a página de ofertas do ML não traz esse campo.'],
                ],
            ],

            'agenda' => [
                'titulo'    => 'Agenda',
                'descricao' => 'Quando e com que frequência publicar. O fim do horário é exclusivo: com 22:00, às 21:59 ainda envia.',
                'campos'    => [
                    ['chave' => 'config.agenda.intervalo_minutos', 'rotulo' => 'Minutos entre ciclos', 'tipo' => 'inteiro'],
                    ['chave' => 'config.agenda.hora_inicio', 'rotulo' => 'Começa a enviar às', 'tipo' => 'hora'],
                    ['chave' => 'config.agenda.hora_fim', 'rotulo' => 'Para de enviar às', 'tipo' => 'hora'],
                    ['chave' => 'config.agenda.dias_semana', 'rotulo' => 'Dias da semana', 'tipo' => 'dias', 'ajuda' => 'Nenhum marcado equivale a todos os dias.'],
                ],
            ],

            'envio' => [
                'titulo'    => 'Envio',
                'descricao' => 'Como a oferta chega no grupo.',
                'campos'    => [
                    ['chave' => 'config.envio.modo', 'rotulo' => 'Modo', 'tipo' => 'escolha', 'opcoes' => ['individual' => 'Uma mensagem por oferta', 'lote' => 'Uma mensagem com várias']],
                    ['chave' => 'config.envio.max_por_execucao', 'rotulo' => 'Ofertas por ciclo', 'tipo' => 'inteiro', 'ajuda' => '1 + intervalo de 10 min = uma oferta a cada 10 minutos.'],
                    ['chave' => 'config.envio.intervalo_mensagens_s', 'rotulo' => 'Segundos entre mensagens', 'tipo' => 'inteiro', 'ajuda' => 'Espaçar reduz o risco de bloqueio por flood.'],
                    ['chave' => 'config.envio.enviar_imagem', 'rotulo' => 'Enviar com foto', 'tipo' => 'booleano'],
                    ['chave' => 'config.envio.link_em_mensagem_separada', 'rotulo' => 'Link em mensagem separada', 'tipo' => 'booleano', 'ajuda' => 'Ligue se o link na legenda da foto não ficar clicável no iPhone. Custa uma mensagem a mais por oferta.'],
                    ['chave' => 'config.envio.validade_horas', 'rotulo' => 'Validade da oferta (horas)', 'tipo' => 'inteiro', 'ajuda' => 'Oferta coletada há mais tempo que isso não é publicada: o preço pode ter mudado.'],
                ],
            ],

            'coleta' => [
                'titulo'    => 'Coleta',
                'descricao' => 'Como o sistema busca no Mercado Livre. Não diminua os intervalos: acesso rápido demais faz o ML servir página de verificação no lugar da lista.',
                'campos'    => [
                    ['chave' => 'config.coleta.modo', 'rotulo' => 'Modo', 'tipo' => 'escolha', 'opcoes' => ['auto' => 'Automático (API, cai para navegador)', 'navegador' => 'Sempre pelo navegador', 'api' => 'Só pela API']],
                    ['chave' => 'config.coleta.intervalo_minutos', 'rotulo' => 'Minutos entre coletas', 'tipo' => 'inteiro', 'ajuda' => 'Bem maior que o intervalo de envio: a fila cobre o resto.'],
                    ['chave' => 'config.coleta.itens_por_busca', 'rotulo' => 'Itens por busca', 'tipo' => 'inteiro'],
                    ['chave' => 'config.coleta.max_paginas', 'rotulo' => 'Páginas por busca', 'tipo' => 'inteiro'],
                    ['chave' => 'config.coleta.intervalo_requisicao_ms', 'rotulo' => 'Espera entre páginas (ms)', 'tipo' => 'inteiro'],
                    ['chave' => 'config.coleta.pausa_bloqueio_s', 'rotulo' => 'Pausa quando é barrado (s)', 'tipo' => 'inteiro'],
                ],
            ],

            'nicho' => [
                'titulo'    => 'Nicho',
                'descricao' => 'O que é (e o que não é) item do seu ramo. A classificação sai do título do produto.',
                'campos'    => [
                    ['chave' => 'nicho.ativo', 'rotulo' => 'Filtrar por nicho', 'tipo' => 'booleano'],
                    ['chave' => 'nicho.nome', 'rotulo' => 'Nome do nicho', 'tipo' => 'texto'],
                    ['chave' => 'nicho.exigir_relevancia', 'rotulo' => 'Descartar o que não casa com nada', 'tipo' => 'booleano', 'ajuda' => 'Desligado, apenas pontua sem barrar.'],
                    ['chave' => 'nicho.essenciais', 'rotulo' => 'Termos essenciais', 'tipo' => 'lista', 'ajuda' => 'Só existe no seu ramo. Nota cheia de relevância.'],
                    ['chave' => 'nicho.apoio', 'rotulo' => 'Termos de apoio', 'tipo' => 'lista', 'ajuda' => 'Usado no ramo, mas não exclusivo dele.'],
                    ['chave' => 'nicho.bloqueados', 'rotulo' => 'Termos bloqueados', 'tipo' => 'lista', 'ajuda' => 'Parece pelo nome, mas não serve (miniatura, brinquedo, capa).'],
                    ['chave' => 'nicho.peso_essencial', 'rotulo' => 'Peso do essencial', 'tipo' => 'numero'],
                    ['chave' => 'nicho.peso_apoio', 'rotulo' => 'Peso do apoio', 'tipo' => 'numero'],
                ],
            ],

            'pontuacao' => [
                'titulo'    => 'Pontuação',
                'descricao' => 'A ordem em que as ofertas são publicadas. Suba comissão e ganho para priorizar faturamento; suba desconto para priorizar engajamento.',
                'campos'    => [
                    ['chave' => 'config.pontuacao.pesos.desconto', 'rotulo' => 'Peso: desconto', 'tipo' => 'numero'],
                    ['chave' => 'config.pontuacao.pesos.comissao', 'rotulo' => 'Peso: comissão', 'tipo' => 'numero'],
                    ['chave' => 'config.pontuacao.pesos.ganho', 'rotulo' => 'Peso: ganho em reais', 'tipo' => 'numero'],
                    ['chave' => 'config.pontuacao.pesos.reputacao', 'rotulo' => 'Peso: reputação', 'tipo' => 'numero'],
                    ['chave' => 'config.pontuacao.pesos.popularidade', 'rotulo' => 'Peso: quantidade vendida', 'tipo' => 'numero'],
                    ['chave' => 'config.pontuacao.pesos.logistica', 'rotulo' => 'Peso: frete grátis e FULL', 'tipo' => 'numero'],
                    ['chave' => 'config.pontuacao.pesos.historico', 'rotulo' => 'Peso: menor preço já visto', 'tipo' => 'numero'],
                    ['chave' => 'config.pontuacao.pesos.nicho', 'rotulo' => 'Peso: relevância do nicho', 'tipo' => 'numero'],
                    ['chave' => 'config.pontuacao.desconto_teto', 'rotulo' => 'Teto: desconto', 'tipo' => 'percentual', 'ajuda' => 'A partir daqui o critério já vale nota cheia.'],
                    ['chave' => 'config.pontuacao.comissao_teto', 'rotulo' => 'Teto: comissão', 'tipo' => 'percentual'],
                    ['chave' => 'config.pontuacao.ganho_teto', 'rotulo' => 'Teto: ganho', 'tipo' => 'dinheiro', 'ajuda' => 'Deve acompanhar a faixa de preço, senão o critério para de diferenciar.'],
                    ['chave' => 'config.pontuacao.vendidos_teto', 'rotulo' => 'Teto: vendidos', 'tipo' => 'inteiro'],
                ],
            ],

            'afiliado' => [
                'titulo'    => 'Afiliado',
                'descricao' => 'Como o link de afiliado é montado.',
                'campos'    => [
                    ['chave' => 'config.afiliado.modo', 'rotulo' => 'Modo', 'tipo' => 'escolha', 'opcoes' => ['modelo' => 'Modelo (sem login, link sem "ref")', 'linkbuilder' => 'Tela oficial do ML (exige login)']],
                    ['chave' => 'ML_AFILIADO_TAG', 'rotulo' => 'Tag de afiliado', 'tipo' => 'texto', 'origem' => 'env'],
                    ['chave' => 'ML_AFILIADO_FERRAMENTA', 'rotulo' => 'Identificador da ferramenta', 'tipo' => 'texto', 'origem' => 'env'],
                    ['chave' => 'config.afiliado.modelo', 'rotulo' => 'Modelo do link', 'tipo' => 'texto', 'ajuda' => 'Marcadores: {url} {tag} {ferramenta} {url_encoded}'],
                ],
            ],

            'shopee' => [
                'titulo'    => 'Shopee',
                'descricao' => 'Segunda fonte de ofertas. As credenciais saem da Central de Afiliados da Shopee; sem elas, as buscas marcadas como Shopee são puladas e o ciclo roda só com o Mercado Livre.',
                'campos'    => [
                    ['chave' => 'SHOPEE_APP_ID', 'rotulo' => 'AppId', 'tipo' => 'texto', 'origem' => 'env'],
                    ['chave' => 'SHOPEE_SECRET', 'rotulo' => 'Secret', 'tipo' => 'texto', 'origem' => 'env', 'ajuda' => 'Confira depois de salvar com: php bin/mlgroup shopee'],
                    ['chave' => 'config.shopee.max_paginas', 'rotulo' => 'Páginas por busca', 'tipo' => 'inteiro', 'ajuda' => 'Cada página traz até 50 anúncios e custa uma chamada à API.'],
                    ['chave' => 'config.shopee.intervalo_requisicao_ms', 'rotulo' => 'Intervalo entre chamadas (ms)', 'tipo' => 'inteiro'],
                ],
            ],

            'whatsapp' => [
                'titulo'    => 'WhatsApp',
                'descricao' => 'Para onde as ofertas vão.',
                'campos'    => [
                    ['chave' => 'WHATSAPP_DRIVER', 'rotulo' => 'Driver', 'tipo' => 'escolha', 'origem' => 'env', 'opcoes' => ['ponte' => 'Ponte local (QR code)', 'evolution' => 'Evolution API', 'zapi' => 'Z-API', 'wppconnect' => 'WPPConnect', 'simulado' => 'Simulado (não envia)']],
                    ['chave' => 'WHATSAPP_GRUPOS', 'rotulo' => 'Grupos', 'tipo' => 'texto', 'origem' => 'env', 'ajuda' => 'IDs separados por vírgula. Use o botão abaixo para listar.'],
                ],
            ],

            'log' => [
                'titulo'    => 'Log',
                'descricao' => 'O que fica registrado.',
                'campos'    => [
                    ['chave' => 'config.log.nivel', 'rotulo' => 'Nível', 'tipo' => 'escolha', 'opcoes' => ['debug' => 'Debug (mostra cada reprovação)', 'info' => 'Info', 'aviso' => 'Aviso', 'erro' => 'Erro']],
                    ['chave' => 'config.log.retencao_dias', 'rotulo' => 'Dias de retenção', 'tipo' => 'inteiro'],
                    ['chave' => 'config.log.console', 'rotulo' => 'Escrever no terminal', 'tipo' => 'booleano'],
                ],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function daSecao(string $secao): array
    {
        return self::secoes()[$secao]['campos'] ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    public static function todos(): array
    {
        $campos = [];

        foreach (self::secoes() as $secao) {
            foreach ($secao['campos'] as $campo) {
                $campos[] = $campo;
            }
        }

        return $campos;
    }

    /** Dias da semana em ISO-8601. */
    public static function diasDaSemana(): array
    {
        return [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
    }
}
