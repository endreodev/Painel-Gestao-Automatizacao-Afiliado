<?php

/**
 * Autoteste do ml-group.
 *
 * Roda o caminho completo (analise -> filtro -> pontuacao -> mensagem -> envio)
 * com produtos ficticios, sem tocar no Mercado Livre nem no WhatsApp real.
 *
 *   php tests/smoke.php
 *
 * Use depois de mexer em config/config.php para confirmar que nada quebrou.
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use MlGroup\Afiliado\TabelaComissao;
use MlGroup\Analise\Descartes;
use MlGroup\Analise\Diversidade;
use MlGroup\Analise\Filtro;
use MlGroup\Analise\Nicho;
use MlGroup\App\Cacador;
use MlGroup\App\TarefaAgendada;
use MlGroup\App\DestinosDeGrupo;
use MlGroup\App\Canal;
use MlGroup\App\Fila;
use MlGroup\Mensagem\Montador;
use MlGroup\Model\Produto;
use MlGroup\Painel\Painel;
use MlGroup\Support\Config;
use MlGroup\Support\ConfigLocal;
use MlGroup\Support\Env;
use MlGroup\Support\Str;
use MlGroup\Whatsapp\Simulado;

/*
 |------------------------------------------------------------------
 | Isolamento: banco e configuracao proprios
 |------------------------------------------------------------------
 | O autoteste exercita o caminho real de gravacao - o painel salvando canal,
 | busca e ajuste - e ate aqui isso ia para o banco e a configuracao de quem
 | rodava a suite. Nao era so sujeira: uma execucao gravou dois canais de teste
 | por cima dos reais, apagando grupo e nicho ja configurados.
 |
 | Com arquivos proprios, o teste tambem fica honesto: as contagens deixam de
 | depender de quanto o sistema real ja coletou, e a criacao do banco do zero
 | passa a ser exercitada em toda execucao.
 */
$pastaDeTeste = sys_get_temp_dir() . '/mlgroup-teste-' . getmypid();

if (!is_dir($pastaDeTeste)) {
    mkdir($pastaDeTeste, 0777, true);
}

MlGroup\Database\Db::usarArquivo($pastaDeTeste . '/mlgroup.sqlite');
ConfigLocal::usarArquivo($pastaDeTeste . '/config-local.json');
Config::recarregar();

$limparPastaDeTeste = static function () use ($pastaDeTeste): void {
    /*
     * Fecha o banco antes de apagar. No Windows o arquivo aberto nao some, e os
     * companheiros do WAL (-wal, -shm) ficam junto: sem isto a pasta de teste
     * sobrevivia a cada execucao e ia se acumulando no temporario.
     */
    MlGroup\Database\Db::usarArquivo(null);

    foreach (glob($pastaDeTeste . '/*') ?: [] as $arquivo) {
        if (is_file($arquivo)) {
            @unlink($arquivo);
        }
    }

    foreach (glob($pastaDeTeste . '/config-local-anterior/*') ?: [] as $copia) {
        @unlink($copia);
    }

    @rmdir($pastaDeTeste . '/config-local-anterior');
    @rmdir($pastaDeTeste);
};

// silencia o log em tela: quem fala aqui e o proprio teste
Config::definir('config.log.console', false);

// sem espera entre mensagens: em producao ela evita bloqueio por flood,
// no teste so faria a suite levar minutos
Config::definir('config.envio.intervalo_mensagens_s', 0);

// destino ficticio, sempre. Sem isto o teste herdaria WHATSAPP_GRUPOS do .env e
// gravaria historico de envio apontando para o grupo real.
Env::definir('WHATSAPP_GRUPOS', '000000000000000000@g.us');

// faixa de preco liberada por padrao: mexer no teto em producao nao pode
// quebrar fixture de secao que nada tem a ver com preco. Quem testa faixa
// declara a sua (secao 4a).
Config::definir('config.filtros.preco_minimo', 0.0);
Config::definir('config.filtros.preco_maximo', 0.0);

/*
 | Identificadores usados pelas fixtures. A limpeza roda no comeco e no fim:
 | uma execucao interrompida no meio (erro de sintaxe, Ctrl+C) deixaria lixo no
 | banco, e a proxima execucao acusaria "ja enviado" em teste que nada tem a ver
 | com isso - falha confusa, de causa invisivel.
 */
$idsDeTeste = [
    'MLB9999000001', 'MLB9999000002', 'MLB9999000003', 'MLB9999000004',
    'MLB9999000010', 'MLB9999000011', 'MLB9999007777',
    'MLB9999009999', 'MLB9999009998', 'MLB9999006666',
    'MLB9999005555', 'MLB9999004444', 'MLB9999003333',
    'MLB9999002221', 'MLB9999002222', 'MLB9999002223',
    'MLB9999002224', 'MLB9999002225', 'MLB9999002226', 'MLB9999002227',
    'MLB9999002228',
];

$limparFixtures = static function (array $ids): void {
    foreach ($ids as $id) {
        MlGroup\Database\Db::executar('DELETE FROM envios WHERE ml_id = :id', ['id' => $id]);
        MlGroup\Database\Db::executar('DELETE FROM produtos WHERE ml_id = :id', ['id' => $id]);
        MlGroup\Database\Db::executar('DELETE FROM historico_precos WHERE ml_id = :id', ['id' => $id]);
    }

    // canais de teste nao podem deixar regra de descarte para tras: ela
    // sobreviveria a suite e reprovaria produto de verdade na proxima coleta
    foreach (['teste_a', 'teste_b', 'teste_off', 'fila_a', 'fila_b'] as $canalDeTeste) {
        MlGroup\Database\Db::executar('DELETE FROM descartes WHERE canal = :c', ['c' => $canalDeTeste]);
    }

    // as fixtures de faixa de preco usam sufixo aleatorio
    foreach (['MLB9999008%'] as $padrao) {
        MlGroup\Database\Db::executar('DELETE FROM envios WHERE ml_id LIKE :p', ['p' => $padrao]);
        MlGroup\Database\Db::executar('DELETE FROM produtos WHERE ml_id LIKE :p', ['p' => $padrao]);
        MlGroup\Database\Db::executar('DELETE FROM historico_precos WHERE ml_id LIKE :p', ['p' => $padrao]);
    }
};

$limparFixtures($idsDeTeste);

$falhas = 0;

function verificar(string $descricao, bool $condicao): void
{
    global $falhas;

    if ($condicao) {
        echo "  [ok]    {$descricao}\n";

        return;
    }

    $falhas++;
    echo "  [FALHA] {$descricao}\n";
}

echo "\n== ml-group :: autoteste ==\n\n";

/*
 |------------------------------------------------------------------
 | 1. Produto: desconto, economia e link
 |------------------------------------------------------------------
 */
echo "Produto\n";

$bom = new Produto(
    mlId:            'MLB9999000001',
    titulo:          'Parafusadeira Furadeira de Impacto 20V 2 Baterias Maleta',
    permalink:       'https://produto.mercadolivre.com.br/MLB-9999000001-parafusadeira',
    preco:           399.90,
    precoOriginal:   799.90,
    thumb:           'https://http2.mlstatic.com/D_123456-MLB-I.jpg',
    categoriaId:     'MLB264364',
    marca:           'Bosch',
    vendedor:        'Loja Oficial Bosch',
    freteGratis:     true,
    full:            true,
    vendidos:        850,
    avaliacao:       4.7,
    totalAvaliacoes: 1240,
    origem:          'teste',
);

verificar('desconto calculado em 50%', abs($bom->desconto() - 50.0) < 0.01);
verificar('economia calculada em R$ 400,00', abs($bom->economia() - 400.0) < 0.01);
verificar('sem preco original nao ha desconto', (new Produto('MLB1', 'x', 'u', 100.0))->desconto() === 0.0);

/*
 |------------------------------------------------------------------
 | 2. Conversao de texto para numero
 |------------------------------------------------------------------
 */
echo "\nSuporte\n";

verificar('"R$ 1.234,56" vira 1234.56', abs(Str::paraDecimal('R$ 1.234,56') - 1234.56) < 0.001);
verificar('"799" vira 799.0', abs(Str::paraDecimal('799') - 799.0) < 0.001);
verificar('normalizacao remove acento', Str::normalizar('Serra Circular Elétrica') === 'serra circular eletrica');
verificar('slug para URL de lista', Str::slug('Serra Circular Elétrica') === 'serra-circular-eletrica');

/*
 |------------------------------------------------------------------
 | 3. Pipeline de avaliacao
 |------------------------------------------------------------------
 */
echo "\nAvaliacao\n";

$ruim = new Produto(
    mlId:          'MLB9999000002',
    titulo:        'Capa Protetora Para Furadeira Usado',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999000002-capa',
    preco:         19.90,
    precoOriginal: 21.90,
    origem:        'teste',
);

$semDesconto = new Produto(
    mlId:          'MLB9999000003',
    titulo:        'Esmerilhadeira Angular 4.1/2 720W',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999000003-esmerilhadeira',
    preco:         289.00,
    precoOriginal: 299.00,
    origem:        'teste',
);

$aprovados = (new Cacador())->avaliar([$bom, $ruim, $semDesconto]);

verificar('somente a oferta boa foi aprovada', count($aprovados) === 1);
verificar('a aprovada e a esperada', ($aprovados[0]->mlId ?? '') === 'MLB9999000001');
verificar('comissao resolvida pela categoria', ($aprovados[0]->comissao ?? 0.0) > 0);
verificar('ganho estimado calculado', ($aprovados[0]->ganhoEstimado ?? 0.0) > 0);
verificar('pontuacao entre 0 e 100', ($aprovados[0]->pontuacao ?? -1) > 0 && ($aprovados[0]->pontuacao ?? 101) <= 100);

/*
 |------------------------------------------------------------------
 | 4. Bloqueio de reenvio
 |------------------------------------------------------------------
 */
echo "\nAnti-repeticao\n";

$publicador = new \MlGroup\App\Publicador(new Simulado());

// primeiro envio grava o historico
ob_start();
$enviados = $publicador->publicar($aprovados);
ob_end_clean();

verificar('primeira publicacao enviou', $enviados >= 1);

$reavaliado = (new Cacador())->avaliar([$bom]);

verificar('mesma oferta nao e reenviada', $reavaliado === []);

/*
 |------------------------------------------------------------------
 | 4a. Faixa de preco
 |------------------------------------------------------------------
 */
echo "\nFaixa de preco\n";

Config::definir('config.filtros.preco_maximo', 300.0);
Config::definir('config.filtros.preco_minimo', 30.0);

// a comissao e aplicada antes do filtro, como faz o Cacador no fluxo real
$naFaixa = static function (float $preco): Produto {
    $produto = new Produto(
        mlId:          'MLB9999008' . random_int(100, 999),
        titulo:        'Chave De Impacto Pneumatica 1/2 Profissional',
        permalink:     'https://produto.mercadolivre.com.br/MLB-9999008000-x',
        preco:         $preco,
        precoOriginal: $preco * 2,
    );

    return (new TabelaComissao())->aplicar($produto);
};

$filtroPreco = new Filtro();

verificar('R$ 299,90 passa no teto',   $filtroPreco->reprovar($naFaixa(299.90)) === null);
verificar('R$ 300,00 ainda passa',     $filtroPreco->reprovar($naFaixa(300.00)) === null);
verificar('R$ 300,01 e reprovado',     $filtroPreco->reprovar($naFaixa(300.01)) !== null);
verificar('R$ 1.559,00 e reprovado',   $filtroPreco->reprovar($naFaixa(1559.00)) !== null);
verificar('R$ 29,00 e reprovado (piso)', $filtroPreco->reprovar($naFaixa(29.00)) !== null);

Config::definir('config.filtros.preco_maximo', 0.0);
verificar('teto 0 desliga a regra', $filtroPreco->reprovar($naFaixa(1559.00)) === null);

// devolve a faixa livre para as proximas secoes
Config::definir('config.filtros.preco_minimo', 0.0);

/*
 |------------------------------------------------------------------
 | 4b. Variacoes de catalogo do mesmo produto
 |------------------------------------------------------------------
 | Caso real: MLB15462505 e MLB15462506, mesmo produto em duas cores,
 | apareceram os dois lado a lado no ranking. A fixture usa um item de
 | oficina para nao esbarrar no filtro de nicho.
 */
echo "\nSem repeticao\n";

$variacaoA = new Produto(
    mlId:          'MLB9999000010',
    titulo:        'Chave De Impacto Pneumatica 1/2 Pol 1300nm Profissional',
    permalink:     'https://www.mercadolivre.com.br/chave-de-impacto/p/MLB9999000010',
    preco:         599.90,
    precoOriginal: 819.90,
    freteGratis:   true,
    origem:        'teste',
);

$variacaoB = new Produto(
    mlId:          'MLB9999000011',
    titulo:        'Chave De Impacto Pneumatica 1/2 Pol 1300nm Profissional',
    permalink:     'https://www.mercadolivre.com.br/chave-de-impacto/p/MLB9999000011',
    preco:         579.90,
    precoOriginal: 819.90,
    freteGratis:   true,
    origem:        'teste',
);

verificar('mesmo titulo gera a mesma assinatura', $variacaoA->assinatura() === $variacaoB->assinatura());
verificar('titulo diferente gera assinatura diferente', $variacaoA->assinatura() !== $bom->assinatura());

$variacoes = (new Cacador())->avaliar([$variacaoA, $variacaoB]);

verificar('as duas variacoes viram uma so', count($variacoes) === 1);
verificar('fica a variacao mais barata', ($variacoes[0]->mlId ?? '') === 'MLB9999000011');

// publica a variacao barata e confere que a outra fica bloqueada
ob_start();
$publicador->publicar($variacoes);
ob_end_clean();

verificar('a outra variacao nao e enviada depois', (new Cacador())->avaliar([$variacaoA]) === []);

/*
 |------------------------------------------------------------------
 | 4c. Fila de publicacao
 |------------------------------------------------------------------
 */
echo "\nFila\n";

$fila = new Fila();

Config::definir('config.envio.validade_horas', 12);
Config::definir('config.filtros.dias_sem_repetir', 7);

$naFila = $fila->proximos(5000);
$idsNaFila = array_map(static fn ($p) => $p->mlId, $naFila);

verificar('produto ja publicado sai da fila', !in_array('MLB9999000011', $idsNaFila, true));
verificar('variacao do que foi publicado tambem sai', !in_array('MLB9999000010', $idsNaFila, true));

Config::definir('config.envio.max_por_execucao', 1);
verificar('fila entrega um por vez', count($fila->proximos(1)) <= 1);

Config::definir('config.coleta.intervalo_minutos', 0);
verificar('intervalo zero forca coleta', $fila->precisaColetar());

/*
 | A fila revalida contra a regra de agora, e nao contra a que valia quando o
 | produto foi coletado. Sem isso, apertar o teto de preco (ou trocar de nicho)
 | deixaria a fila entregando o que ja nao deveria mais sair.
 */
$caro = new Produto(
    mlId:          'MLB9999007777',
    titulo:        'Chave De Impacto Pneumatica 1/2 Alta Torque Profissional',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999007777-x',
    preco:         480.00,
    precoOriginal: 960.00,
    freteGratis:   true,
);

// entra na fila com a faixa livre
Config::definir('config.filtros.preco_maximo', 0.0);
$entrou = (new Cacador())->avaliar([$caro]);

verificar('produto caro entra com a faixa livre', count($entrou) === 1);

/*
 * Limite alto de proposito: a suite roda contra o banco de verdade, e pedir
 * um pedaco da fila fazia a fixture cair fora do corte conforme o estoque
 * crescia - o teste passava ou falhava pelo tamanho do banco, nao pela regra.
 */
$idsAntes = array_map(static fn ($p) => $p->mlId, $fila->proximos(5000));
verificar('e aparece na fila', in_array('MLB9999007777', $idsAntes, true));

// aperta o teto depois de ele ja estar gravado
Config::definir('config.filtros.preco_maximo', 300.0);

$idsDepois = array_map(static fn ($p) => $p->mlId, $fila->proximos(5000));
verificar('some da fila quando o teto aperta', !in_array('MLB9999007777', $idsDepois, true));

Config::definir('config.filtros.preco_maximo', 0.0);

/*
 |------------------------------------------------------------------
 | 4d. Perfil de nicho (config/nicho.php)
 |------------------------------------------------------------------
 | Perfil atual: ferramentas em geral. Os casos vem de coleta real -
 | inclusive os titulos com ordem de palavra estranha, que sao a regra
 | em anuncio de marketplace, nao a excecao.
 */
echo "
Nicho
";

$nicho = new Nicho();

$paraNicho = static fn (string $titulo): Produto => new Produto(
    mlId:      'MLB9999009999',
    titulo:    $titulo,
    permalink: 'https://produto.mercadolivre.com.br/MLB-9999009999-x',
    preco:     500.00,
);

$dentro = static fn (string $titulo): bool => $nicho->relevancia($paraNicho($titulo)) > 0.0;

// variedade: cada ramo precisa passar
verificar('mecanica: chave de impacto',   $dentro('Chave De Impacto Pneumatica 1/2 Profissional'));
verificar('marcenaria: serra circular',   $dentro('Serra Circular Para Madeira 7.1/4pol Makita'));
verificar('jardinagem: rocadeira',        $dentro('Rocadeira Knakasaki 75cc 3,5hp Gasolina'));
verificar('jardinagem: motosserra',       $dentro('Motosserra A Gasolina 78cc Sabre 20 Polegadas'));
verificar('construcao: betoneira',        $dentro('Betoneira 400 Litros Menegotti Profissional'));
verificar('medicao: nivel a laser',       $dentro('Kit Nivel a Laser 3D MESTRI 12 Linhas Verde'));
verificar('eletrica: multimetro',         $dentro('Multimetro Digital Profissional True RMS'));
verificar('solda: inversora',             $dentro('Maquina Inversora De Solda MIG 130a'));

// ordem de palavra: o que quebrava o casamento por trecho literal
verificar(
    'casa sem a preposicao ("Lavadora Alta Pressao")',
    $dentro('Lavadora Alta Pressao Lav1300 1.300 Lbf 220V Vonder'),
);
verificar(
    'casa com palavras separadas ("Serra Makita Marmore")',
    $dentro('Serra Makita 4100nh3zx Marmore com 2 Discos'),
);
verificar('casa no plural ("brocas")', $dentro('Jogo De Brocas Titanio 19 Pecas'));

// especificidade: o termo mais completo vence
verificar(
    'termo mais especifico vence o generico',
    $nicho->termoCasado($paraNicho('Serra Circular Eletrica 1600w')) === 'serra circular',
);
verificar(
    'essencial pesa mais que acessorio',
    $nicho->relevancia($paraNicho('Furadeira De Impacto 750w'))
        > $nicho->relevancia($paraNicho('Jogo De Brocas Titanio 19 Pecas')),
);

// o que NAO pode entrar
verificar('miniatura fica de fora',  !$dentro('Miniatura Furadeira Colecionador Escala 1:12'));
verificar('capa fica de fora',       !$dentro('Capa Protetora Para Parafusadeira'));
verificar('camiseta fica de fora',   !$dentro('Camiseta Mecanico Ferramentas Algodao'));
verificar('brinquedo fica de fora',  !$dentro('Kit Ferramentas Brinquedo Infantil'));
verificar(
    'bloqueio por palavra nao derruba "capacete"',
    $dentro('Capacete De Seguranca Aba Frontal Classe B'),
);
verificar('produto sem relacao fica de fora', !$dentro('Perfume Importado Masculino 100ml'));

$foraDoNicho = new Produto(
    mlId:          'MLB9999009998',
    titulo:        'Perfume Importado Masculino 100ml Amadeirado',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999009998-x',
    preco:         199.90,
    precoOriginal: 499.90,
    freteGratis:   true,
);

verificar('item fora do nicho e reprovado pelo filtro', (new Filtro())->reprovar($foraDoNicho) !== null);
verificar('item fora do nicho nao passa na avaliacao', (new Cacador())->avaliar([$foraDoNicho]) === []);

/*
 |------------------------------------------------------------------
 | 4c-bis. Paginacao de URL colada do navegador
 |------------------------------------------------------------------
 | A URL de ofertas filtrada vem com fragmento (#filter_applied=...).
 | Grudar "&page=2" no fim jogaria o parametro para dentro do
 | fragmento, que o servidor nem recebe - a pagina 2 voltaria igual a 1.
 */
echo "\nPaginacao de URL\n";

$montarUrl = new ReflectionMethod(\MlGroup\Scraper\ColetorNavegador::class, 'montarUrl');
$montarUrl->setAccessible(true);
$coletor = new \MlGroup\Scraper\ColetorNavegador();

$urlOfertas = 'https://www.mercadolivre.com.br/ofertas?container_id=MLB779540-1'
    . '&domain_id=MLB-TOOLS$MLB-WRENCHES#filter_applied=domain_id&filter_position=12';

$pagina1 = $montarUrl->invoke($coletor, ['tipo' => 'url', 'url' => $urlOfertas], 1);
$pagina2 = $montarUrl->invoke($coletor, ['tipo' => 'url', 'url' => $urlOfertas], 2);

verificar('pagina 1 nao mexe na URL', $pagina1 === $urlOfertas);
verificar('page entra antes do fragmento', str_contains($pagina2, 'page=2#filter_applied'));
verificar('o fragmento e preservado', str_ends_with($pagina2, '#filter_applied=domain_id&filter_position=12'));
verificar('o $ dos dominios nao e escapado', str_contains($pagina2, 'MLB-TOOLS$MLB-WRENCHES'));
verificar('container_id sobrevive', str_contains($pagina2, 'container_id=MLB779540-1'));

$pagina3 = $montarUrl->invoke($coletor, ['tipo' => 'url', 'url' => $urlOfertas], 3);

verificar('nao acumula page repetido', substr_count($pagina3, 'page=') === 1);

// lista do ML pagina pelo caminho, e nao pela query
$urlLista = 'https://lista.mercadolivre.com.br/chave-de-impacto';

verificar(
    'lista do ML usa _Desde_ no caminho',
    str_contains($montarUrl->invoke($coletor, ['tipo' => 'url', 'url' => $urlLista], 2), '_Desde_51'),
);

/*
 |------------------------------------------------------------------
 | 4d-bis. Diversidade: nao mandar a mesma coisa em sequencia
 |------------------------------------------------------------------
 | O caso real: 3 lavadoras nos ultimos 5 envios, e varias
 | parafusadeiras "The Black Tools" seguidas.
 */
echo "\nDiversidade\n";

$diversidade = new Diversidade();

Config::definir('config.diversidade.ativa', true);
Config::definir('config.diversidade.repetir_tipo_apos', 5);
Config::definir('config.diversidade.repetir_marca_apos', 3);

$produtoDe = static fn (string $titulo): Produto => new Produto(
    mlId:      'MLB9999006666',
    titulo:    $titulo,
    permalink: 'https://produto.mercadolivre.com.br/MLB-9999006666-x',
    preco:     200.00,
);

verificar(
    'reconhece a marca no titulo',
    $diversidade->marca($produtoDe('Parafusadeira The Black Tools TB12A 3/8')) === 'the black tools',
);
verificar(
    'marca com mais palavras vence',
    $diversidade->marca($produtoDe('Furadeira Black Decker 550w')) === 'black decker',
);
verificar(
    'titulo sem marca conhecida devolve vazio',
    $diversidade->marca($produtoDe('Furadeira Generica 500w Sem Marca')) === '',
);

verificar(
    'tipo vem do termo do nicho',
    $diversidade->tipo($produtoDe('Lavadora de Alta Pressao Karcher K3')) === 'lavadora de alta pressao',
);
verificar(
    'ferramenta nao e classificada como acessorio',
    $diversidade->tipo($produtoDe('Parafusadeira Furadeira De Impacto 21v 2 Baterias')) === 'parafusadeira',
);

$lavadora = $produtoDe('Lavadora de Alta Pressao Wap Ousada 2200');
$historico = [$diversidade->assinaturaDe($produtoDe('Lavadora de Alta Pressao Karcher K3'))];

verificar('mesmo tipo logo apos e adiado', $diversidade->adiar($lavadora, $historico) !== null);

$outroTipo = $produtoDe('Esmerilhadeira Angular Bosch 710w');

verificar('tipo diferente passa', $diversidade->adiar($outroTipo, $historico) === null);

// mesma marca, tipos diferentes: barra pela marca
$historicoMarca = [$diversidade->assinaturaDe($produtoDe('Esmerilhadeira The Black Tools 850w'))];

verificar(
    'mesma marca logo apos e adiada',
    $diversidade->adiar($produtoDe('Parafusadeira The Black Tools TB12A'), $historicoMarca) !== null,
);

// passada a janela, libera
$antigo = array_fill(0, 6, $diversidade->assinaturaDe($produtoDe('Furadeira Bosch 550w')));
$antigo[0] = $diversidade->assinaturaDe($produtoDe('Serra Circular Makita 1600w'));

verificar(
    'fora da janela o tipo volta a ser permitido',
    $diversidade->adiar($produtoDe('Serra Circular Makita 1600w'), array_slice($antigo, 0, 6)) === null
        || $diversidade->adiar($produtoDe('Serra Circular Makita 1600w'), []) === null,
);

Config::definir('config.diversidade.ativa', false);
verificar('desligada, nao adia nada', $diversidade->adiar($lavadora, $historico) === null);
Config::definir('config.diversidade.ativa', true);

/*
 |------------------------------------------------------------------
 | 4e. Ajustes do painel (storage/config-local.json)
 |------------------------------------------------------------------
 */
echo "\nPainel\n";

$guardado = is_file(ConfigLocal::caminho()) ? file_get_contents(ConfigLocal::caminho()) : null;

ConfigLocal::limpar();
Config::recarregar();

$doArquivo = Config::decimal('config.filtros.desconto_minimo');

ConfigLocal::definir('config.filtros.desconto_minimo', 42.0);
ConfigLocal::gravar();
Config::recarregar();

verificar('ajuste do painel entra por cima do arquivo', Config::decimal('config.filtros.desconto_minimo') === 42.0);
verificar('o que nao foi ajustado segue do arquivo', Config::lista('config.filtros.palavras_bloqueadas') !== []);

ConfigLocal::definir('nicho.essenciais', ['chave de impacto', 'torquimetro']);
ConfigLocal::gravar();
Config::recarregar();

verificar('lista e substituida inteira, nao mesclada', count(Config::lista('nicho.essenciais')) === 2);

ConfigLocal::limpar();
Config::recarregar();

verificar('limpar devolve o valor do arquivo', Config::decimal('config.filtros.desconto_minimo') === $doArquivo);
verificar('limpar apaga o arquivo de ajustes', !is_file(ConfigLocal::caminho()));

/*
 | Perda de alteracao entre processos.
 |
 | Bug real: o painel roda no servidor embutido, que e um processo unico e
 | longo. O retrato da configuracao envelhecia nele e, no salvamento
 | seguinte, apagava tudo que tinha sido mudado pelo terminal no meio -
 | buscas sumiam da lista, flags voltavam atras, sem erro nenhum.
 */
ConfigLocal::definir('config.filtros.desconto_minimo', 11.0);
ConfigLocal::gravar();

// simula outro processo alterando OUTRA chave enquanto este ja leu o arquivo
$noDisco = json_decode((string) file_get_contents(ConfigLocal::caminho()), true);
$noDisco['config']['envio']['max_por_execucao'] = 7;
file_put_contents(ConfigLocal::caminho(), (string) json_encode($noDisco));

ConfigLocal::definir('config.filtros.desconto_maximo', 88.0);
ConfigLocal::gravar();

$final = json_decode((string) file_get_contents(ConfigLocal::caminho()), true);

verificar('alteracao de outro processo sobrevive', ($final['config']['envio']['max_por_execucao'] ?? null) === 7);
// json_encode grava 88.0 como 88, entao a volta e inteiro: compara o valor
verificar('a alteracao propria tambem e gravada', (float) ($final['config']['filtros']['desconto_maximo'] ?? 0) === 88.0);
verificar('o que foi gravado antes continua la', (float) ($final['config']['filtros']['desconto_minimo'] ?? 0) === 11.0);

ConfigLocal::limpar();
Config::recarregar();

// devolve o estado que o usuario tinha antes do teste
if ($guardado !== null) {
    file_put_contents(ConfigLocal::caminho(), $guardado);
}

Config::recarregar();
Config::definir('config.log.console', false);
Config::definir('config.filtros.preco_minimo', 0.0);
Config::definir('config.filtros.preco_maximo', 0.0);

/*
 |------------------------------------------------------------------
 | 4f. O link publicado carrega o rastreio
 |------------------------------------------------------------------
 | Bug real: o link de afiliado era montado na coleta, em memoria, e
 | nunca gravado. O produto que saia da fila vinha reconstruido do
 | banco, sem link, e a mensagem ia com o permalink cru - sem rastreio
 | nenhum, sem erro nenhum. Agora o link e montado na publicacao.
 */
echo "\nLink na mensagem\n";

Config::definir('config.afiliado.modo', 'modelo');
Config::definir('config.afiliado.modelo', '{url}?matt_word={tag}&matt_tool={ferramenta}');
Env::definir('ML_AFILIADO_TAG', 'tag_de_teste');
Env::definir('ML_AFILIADO_FERRAMENTA', '99999');

$paraPublicar = new Produto(
    mlId:          'MLB9999005555',
    titulo:        'Furadeira De Impacto 750w Profissional',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999005555-furadeira',
    preco:         199.90,
    precoOriginal: 399.90,
    freteGratis:   true,
);

// como sai da fila: reconstruido do banco, sem link aplicado
verificar('produto da fila comeca sem link de afiliado', $paraPublicar->linkAfiliado === '');

ob_start();
(new \MlGroup\App\Publicador(new Simulado()))->publicar([$paraPublicar]);
ob_end_clean();

/*
 * Confere pela mensagem gravada em envios, e nao pela saida capturada: o
 * driver simulado escreve direto em STDOUT, que ob_start() nao intercepta.
 * A linha do banco e o artefato real de qualquer forma.
 */
$registrado = (string) \MlGroup\Database\Db::valor(
    'SELECT mensagem FROM envios WHERE ml_id = :id ORDER BY id DESC LIMIT 1',
    ['id' => 'MLB9999005555'],
);

verificar('a mensagem publicada leva a tag', str_contains($registrado, 'matt_word=tag_de_teste'));
verificar('a mensagem publicada leva a ferramenta', str_contains($registrado, 'matt_tool=99999'));
verificar('o link nao vai cru', !str_contains($registrado, 'MLB-9999005555-furadeira' . "
"));
verificar('o produto recebeu o link ao publicar', $paraPublicar->linkAfiliado !== '');

/*
 |------------------------------------------------------------------
 | 4g. Canais: dois grupos, assuntos diferentes
 |------------------------------------------------------------------
 | O que não pode acontecer: oferta de um canal vazar para o grupo do
 | outro, ou o nicho de um contaminar a avaliação do outro.
 */
echo "\nCanais\n";

Config::definir('canais.canais', [
    [
        'id'     => 'teste_a',
        'nome'   => 'Canal A',
        'grupos' => ['111111111111111111@g.us'],
        'ativo'  => true,
        'nicho'  => ['ativo' => true, 'nome' => 'Só furadeira', 'exigir_relevancia' => true,
                     'essenciais' => ['furadeira'], 'apoio' => [], 'bloqueados' => [],
                     'peso_essencial' => 1.0, 'peso_apoio' => 0.5],
        'ajustes' => ['config.filtros.preco_maximo' => 500.0],
    ],
    [
        'id'     => 'teste_b',
        'nome'   => 'Canal B',
        'grupos' => ['222222222222222222@g.us'],
        'ativo'  => true,
        'nicho'  => ['ativo' => true, 'nome' => 'Só panela', 'exigir_relevancia' => true,
                     'essenciais' => ['panela'], 'apoio' => [], 'bloqueados' => [],
                     'peso_essencial' => 1.0, 'peso_apoio' => 0.5],
        'ajustes' => ['config.filtros.preco_maximo' => 80.0],
    ],
    ['id' => 'teste_off', 'nome' => 'Desligado', 'grupos' => [], 'ativo' => false],
]);

verificar('todos os canais sao lidos', count(Canal::todos()) === 3);
verificar('so os ligados entram no ciclo', count(Canal::ativos()) === 2);

$canalA = Canal::porId('teste_a');
$canalB = Canal::porId('teste_b');

verificar('o canal sobrepoe o nicho', Canal::comCanal($canalA, static fn (): string => Config::texto('nicho.nome')) === 'Só furadeira');
verificar('cada canal tem o seu teto', Canal::comCanal($canalB, static fn (): float => Config::decimal('config.filtros.preco_maximo')) === 80.0);
verificar('fora do canal vale o global', Canal::ativo() === null);

// uma excecao no meio nao pode deixar o canal seguinte herdando o anterior
try {
    Canal::comCanal($canalA, static function (): void {
        throw new RuntimeException('falha no meio do ciclo');
    });
} catch (RuntimeException) {
    // esperado
}

verificar('excecao nao deixa canal preso', Canal::ativo() === null);

$furadeira = new Produto(
    mlId:          'MLB9999004444',
    titulo:        'Furadeira De Impacto 650W Profissional',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999004444-x',
    preco:         120.00,
    precoOriginal: 260.00,
);

$panela = new Produto(
    mlId:          'MLB9999003333',
    titulo:        'Jogo De Panelas Antiaderente 5 Pecas',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999003333-x',
    preco:         70.00,
    precoOriginal: 150.00,
);

$noA = Canal::comCanal($canalA, static fn (): array => (new Cacador())->avaliar([$furadeira, $panela]));
$noB = Canal::comCanal($canalB, static fn (): array => (new Cacador())->avaliar([$furadeira, $panela]));

verificar('canal A aprova so o do nicho dele', count($noA) === 1 && $noA[0]->mlId === 'MLB9999004444');
verificar('canal B aprova so o do nicho dele', count($noB) === 1 && $noB[0]->mlId === 'MLB9999003333');

// o produto do A nao pode aparecer na fila do B
$filaA = Canal::comCanal($canalA, static fn (): array => (new Fila())->proximos(5000));
$filaB = Canal::comCanal($canalB, static fn (): array => (new Fila())->proximos(5000));

$idsA = array_map(static fn ($p) => $p->mlId, $filaA);
$idsB = array_map(static fn ($p) => $p->mlId, $filaB);

verificar('a fila do A tem o produto do A', in_array('MLB9999004444', $idsA, true));
verificar('a fila do A nao tem o produto do B', !in_array('MLB9999003333', $idsA, true));
verificar('a fila do B tem o produto do B', in_array('MLB9999003333', $idsB, true));
verificar('a fila do B nao tem o produto do A', !in_array('MLB9999004444', $idsA, true) || !in_array('MLB9999004444', $idsB, true));

// publica no A e confere que o B nao foi afetado
ob_start();
Canal::comCanal($canalA, static fn (): int => (new \MlGroup\App\Publicador(new Simulado()))->publicar($noA));
ob_end_clean();

$registro = \MlGroup\Database\Db::primeiro(
    'SELECT canal, grupo FROM envios WHERE ml_id = :id ORDER BY id DESC LIMIT 1',
    ['id' => 'MLB9999004444'],
);

verificar('o envio guarda o canal', ($registro['canal'] ?? '') === 'teste_a');
verificar('foi para o grupo do canal certo', ($registro['grupo'] ?? '') === '111111111111111111@g.us');

$filaBDepois = Canal::comCanal($canalB, static fn (): array => (new Fila())->proximos(5000));

verificar(
    'publicar no A nao mexe na fila do B',
    in_array('MLB9999003333', array_map(static fn ($p) => $p->mlId, $filaBDepois), true),
);

Config::definir('canais.canais', []);

/*
 |------------------------------------------------------------------
 | 5. Mensagem
 |------------------------------------------------------------------
 */
echo "\nMensagem\n";

$montador = new Montador();
$texto    = $montador->oferta($bom);

verificar('mensagem contem o preco', str_contains($texto, 'R$ 399,90'));
verificar('mensagem contem o desconto', str_contains($texto, '50%'));
verificar('mensagem contem o link', str_contains($texto, 'mercadolivre.com.br'));
verificar('nenhum placeholder sobrou', preg_match('/\{[?\/]?[a-z_]+\}/', $texto) !== 1);
$semPrecoDe = new Produto(
    mlId:      'MLB9999000004',
    titulo:    'Trena Digital a Laser 40m',
    permalink: 'https://produto.mercadolivre.com.br/MLB-9999000004-trena',
    preco:     149.90,
    origem:    'teste',
);

verificar('bloco condicional vazio some', !str_contains($montador->oferta($semPrecoDe), 'OFF'));
verificar('bloco sem preco de nao mostra "De"', !str_contains($montador->oferta($semPrecoDe), 'De ~'));

$lote = $montador->lote([$bom, $semDesconto]);

verificar('lote numera os itens', str_contains($lote, '*1.*') && str_contains($lote, '*2.*'));

/*
 |------------------------------------------------------------------
 | 6. Janela de horario
 |------------------------------------------------------------------
 */
echo "\nAgenda\n";

$agendador = new \MlGroup\App\Agendador();

Config::definir('config.agenda.hora_inicio', '08:00');
Config::definir('config.agenda.hora_fim', '22:00');
Config::definir('config.agenda.dias_semana', [1, 2, 3, 4, 5, 6, 7]);

verificar('14:00 esta dentro da janela 08-22', $agendador->dentroDaJanela(new DateTimeImmutable('2026-08-20 14:00')));
verificar('03:00 esta fora da janela 08-22', !$agendador->dentroDaJanela(new DateTimeImmutable('2026-08-20 03:00')));

// janela que atravessa a meia-noite
Config::definir('config.agenda.hora_inicio', '22:00');
Config::definir('config.agenda.hora_fim', '02:00');

verificar('janela 22-02 aceita 23:30', $agendador->dentroDaJanela(new DateTimeImmutable('2026-08-20 23:30')));
verificar('janela 22-02 aceita 01:00', $agendador->dentroDaJanela(new DateTimeImmutable('2026-08-20 01:00')));
verificar('janela 22-02 recusa 12:00', !$agendador->dentroDaJanela(new DateTimeImmutable('2026-08-20 12:00')));

Config::definir('config.agenda.dias_semana', [1]);
verificar('domingo fora dos dias configurados', !$agendador->dentroDaJanela(new DateTimeImmutable('2026-08-23 23:30')));

/*
 | Janela pedida: nao enviar das 22:00 as 09:00. As bordas sao o que mais
 | erra na pratica, entao cada minuto de virada tem verificacao propria.
 */
Config::definir('config.agenda.hora_inicio', '09:00');
Config::definir('config.agenda.hora_fim', '22:00');
Config::definir('config.agenda.dias_semana', [1, 2, 3, 4, 5, 6, 7]);

$emQue = static fn (string $hora): DateTimeImmutable => new DateTimeImmutable('2026-08-21 ' . $hora);

verificar('08:59 nao envia',      !$agendador->dentroDaJanela($emQue('08:59')));
verificar('09:00 ja envia',        $agendador->dentroDaJanela($emQue('09:00')));
verificar('meio-dia envia',        $agendador->dentroDaJanela($emQue('12:00')));
verificar('21:59 ainda envia',     $agendador->dentroDaJanela($emQue('21:59')));
verificar('22:00 nao envia mais', !$agendador->dentroDaJanela($emQue('22:00')));
verificar('23:30 nao envia',      !$agendador->dentroDaJanela($emQue('23:30')));
verificar('03:00 nao envia',      !$agendador->dentroDaJanela($emQue('03:00')));
verificar('domingo envia normal',  $agendador->dentroDaJanela(new DateTimeImmutable('2026-08-23 15:00')));

/*
 |------------------------------------------------------------------
 | Grupos: a que canal cada um publica
 |------------------------------------------------------------------
 | Nada aqui grava: DestinosDeGrupo so transforma o mapa, e quem chama decide
 | se persiste. E o que permite testar a regra sem encostar na configuracao de
 | producao.
 */
echo "\nGrupos e destinos\n";

$mapa = [
    'ferramentas' => ['A@g.us', 'B@g.us'],
    'utilidades'  => ['C@g.us'],
];

$movido = DestinosDeGrupo::aplicar($mapa, ['A@g.us' => 'utilidades']);

verificar('grupo movido entra no canal novo',  in_array('A@g.us', $movido['utilidades'], true));
verificar('e sai do canal antigo',            !in_array('A@g.us', $movido['ferramentas'], true));
verificar('os outros grupos ficam',            in_array('B@g.us', $movido['ferramentas'], true));

$orfao = DestinosDeGrupo::aplicar($mapa, ['A@g.us' => '']);

verificar('destino vazio tira o grupo de todos', !in_array('A@g.us', $orfao['ferramentas'], true)
    && !in_array('A@g.us', $orfao['utilidades'], true));

$inexistente = DestinosDeGrupo::aplicar($mapa, ['B@g.us' => 'canal-que-nao-existe']);

verificar('canal inexistente nao cria canal',   !isset($inexistente['canal-que-nao-existe']));
verificar('e o grupo fica sem destino',         !in_array('B@g.us', $inexistente['ferramentas'], true));

$parcial = DestinosDeGrupo::aplicar($mapa, ['A@g.us' => 'ferramentas']);

verificar('canal nao citado fica intacto',       $parcial['utilidades'] === ['C@g.us']);

// o mesmo grupo em dois canais publicaria a mesma vitrine duas vezes
$duplo = DestinosDeGrupo::aplicar($mapa, ['C@g.us' => 'ferramentas']);

verificar('grupo pertence a um canal so',        count($duplo['ferramentas']) === 3
    && $duplo['utilidades'] === []);

$movidos = DestinosDeGrupo::mover($mapa, ['C@g.us'], 'ferramentas');

verificar('mover leva o grupo junto',            in_array('C@g.us', $movidos['ferramentas'], true));
verificar('mover preserva o que ja estava',      in_array('A@g.us', $movidos['ferramentas'], true));
verificar('mover nao duplica',                   count($movidos['ferramentas']) === count(array_unique($movidos['ferramentas'])));

$repetido = DestinosDeGrupo::mover($mapa, ['A@g.us'], 'ferramentas');

verificar('mover para o mesmo canal nao duplica', count(array_keys($repetido['ferramentas'], 'A@g.us', true)) === 1);

$vazio = DestinosDeGrupo::mover($mapa, [], 'ferramentas');

verificar('mover sem grupo nao muda nada',        $vazio === $mapa);

/*
 |------------------------------------------------------------------
 | Alinhamento da lista no terminal
 |------------------------------------------------------------------
 | Nome de grupo com emoji e a regra, nao a excecao - e emoji ocupa duas
 | colunas. Contar caracteres desalinhava a coluna seguinte.
 */
echo "\nLargura no terminal\n";

verificar('acento ocupa uma coluna',   Str::colunas('ç') === 1);
verificar('emoji ocupa duas',          Str::colunas('🔥') === 2);
verificar('marca de variacao, zero',   Str::colunas("\u{FE0F}") === 0);

verificar('texto curto e completado',  mb_strlen(Str::preencher('abc', 10)) === 10);
verificar('acento nao desalinha',      mb_strlen(Str::preencher('ALIMENTAÇÃO', 20)) === 20);

// duas colunas por emoji: 4 emoji = 8 colunas, e o resto vira espaco
$comEmoji = Str::preencher('🔥🔥ab🔥🔥', 20);
$colunas  = 0;

foreach (preg_split('//u', $comEmoji, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $letra) {
    $colunas += Str::colunas($letra);
}

verificar('emoji contam por coluna, nao por caractere', $colunas === 20);
verificar('texto longo e cortado com marca',            str_ends_with(Str::preencher(str_repeat('x', 60), 20), '~'));

/*
 |------------------------------------------------------------------
 | Tela de grupos
 |------------------------------------------------------------------
 */
echo "\nTela de grupos\n";

$paginaGrupos = (new Painel())->responder('/grupos', 'GET', []);

verificar('a tela de grupos responde',        $paginaGrupos !== '');
verificar('traz o seletor de canal',          str_contains($paginaGrupos, 'name="destino['));
verificar('traz a busca por nome ou ID',      str_contains($paginaGrupos, 'id="busca-grupos"'));
verificar('cada canal vira opcao',            str_contains($paginaGrupos, '— nenhum —'));
verificar('o menu leva para a tela',          str_contains($paginaGrupos, 'href="/grupos"'));

// GET nao pode gravar: abrir a tela nao muda destino de grupo
$antesDaTela = DestinosDeGrupo::atual();
(new Painel())->responder('/grupos', 'GET', []);

verificar('abrir a tela nao altera destinos', DestinosDeGrupo::atual() === $antesDaTela);

/*
 |------------------------------------------------------------------
 | Fila filtrada por canal
 |------------------------------------------------------------------
 | O caso que motivou isto: sem canal ativo a Fila consulta 'padrao', onde so
 | existe o que foi coletado antes de os canais existirem. A tela mostrava essa
 | fila morta como se fosse a de publicacao.
 |
 | Os canais e os produtos sao criados aqui, e nao herdados da configuracao real:
 | um teste que so passa porque a maquina de quem roda tem dois canais com fila
 | cheia nao esta testando nada.
 */
echo "\nFila por canal\n";

Config::definir('canais.canais', [
    [
        'id'     => 'fila_a',
        'nome'   => 'Fila A',
        'grupos' => ['111111111111111111@g.us'],
        'ativo'  => true,
        'nicho'  => ['ativo' => true, 'nome' => 'Só serra', 'exigir_relevancia' => true,
                     'essenciais' => ['serra circular'], 'apoio' => [], 'bloqueados' => [],
                     'peso_essencial' => 1.0, 'peso_apoio' => 0.5],
    ],
    [
        'id'     => 'fila_b',
        'nome'   => 'Fila B',
        'grupos' => ['222222222222222222@g.us'],
        'ativo'  => true,
        'nicho'  => ['ativo' => true, 'nome' => 'Só cafeteira', 'exigir_relevancia' => true,
                     'essenciais' => ['cafeteira'], 'apoio' => [], 'bloqueados' => [],
                     'peso_essencial' => 1.0, 'peso_apoio' => 0.5],
    ],
]);

Nicho::limparCache();

$serraA = new Produto(
    mlId:          'MLB9999002221',
    titulo:        'Serra Circular 1400W 7 1/4 Pol Profissional',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999002221-x',
    preco:         230.00,
    precoOriginal: 480.00,
);

$serraB = new Produto(
    mlId:          'MLB9999002222',
    titulo:        'Serra Circular Compacta 600W Com Guia',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999002222-x',
    preco:         180.00,
    precoOriginal: 360.00,
);

$cafeteira = new Produto(
    mlId:          'MLB9999002223',
    titulo:        'Cafeteira Eletrica 600ml Filtro Permanente',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999002223-x',
    preco:         89.00,
    precoOriginal: 189.00,
);

$canalFilaA = Canal::porId('fila_a');
$canalFilaB = Canal::porId('fila_b');

Canal::comCanal($canalFilaA, static fn (): array => (new Cacador())->avaliar([$serraA, $serraB, $cafeteira]));
Canal::comCanal($canalFilaB, static fn (): array => (new Cacador())->avaliar([$serraA, $serraB, $cafeteira]));

$painel  = new Painel();
$contar  = static fn (string $html): int => substr_count($html, '<tr data-busca');
$naFila  = static fn (Canal $c): int => Canal::comCanal($c, static fn (): int => count((new Fila())->proximos(500, true)));

verificar('canal A coletou so o nicho dele', $naFila($canalFilaA) === 2);
verificar('canal B coletou so o nicho dele', $naFila($canalFilaB) === 1);

$umCanal = $painel->responder('/fila', 'GET', [], ['canal' => 'fila_a']);

verificar('a aba do canal traz a fila dele',  $contar($umCanal) === 2);
verificar('e nao a do outro canal',          !str_contains($umCanal, 'Cafeteira Eletrica'));
verificar('a aba marca o canal escolhido',    str_contains($umCanal, 'canal=fila_a'));

// filtrado por um canal, a coluna repetiria o mesmo valor em toda linha
verificar('sem coluna de canal ao filtrar',  !str_contains($umCanal, '>Canal<'));

$outroCanal = $painel->responder('/fila', 'GET', [], ['canal' => 'fila_b']);

verificar('cada canal ve a sua fila',         $contar($outroCanal) === 1
    && str_contains($outroCanal, 'Cafeteira Eletrica'));

$todos = $painel->responder('/fila', 'GET', []);

verificar('sem filtro, junta os canais',      $contar($todos) === 3);
verificar('e identifica o canal de cada um',  str_contains($todos, '>Canal<')
    && str_contains($todos, 'Fila A') && str_contains($todos, 'Fila B'));
verificar('traz o filtro de texto',           str_contains($todos, 'id="busca-fila"'));

// o tipo e o que a regra de diversidade usa; sem ele a tela nao explica a espera
verificar('mostra o tipo do produto',         str_contains($umCanal, 'serra circular'));
verificar('resume os tipos da fila',          str_contains($umCanal, 'Tipos na fila'));

// canal inexistente nao pode dar erro nem lista vazia enganosa: cai em "todos"
$invalido = $painel->responder('/fila', 'GET', [], ['canal' => 'nao-existe']);

verificar('canal invalido cai em todos',      $contar($invalido) === $contar($todos));

// injecao pela query string nao pode escapar para o HTML
$hostil = $painel->responder('/fila', 'GET', [], ['canal' => '"><script>x</script>']);

verificar('query string nao injeta HTML',    !str_contains($hostil, '<script>x</script>'));

/*
 |------------------------------------------------------------------
 | Furar a fila
 |------------------------------------------------------------------
 | Roda sobre os canais fila_a/fila_b da secao anterior, que ainda estao
 | declarados: la ja existe uma fila conhecida, com dois produtos no canal A.
 */
echo "\nFurar a fila\n";

$ordemA = static fn (): array => Canal::comCanal(
    $canalFilaA,
    static fn (): array => array_map(
        static fn ($p): string => $p->mlId,
        (new Fila())->proximos(500, true),
    ),
);

$inicial = $ordemA();

verificar('a fila comeca pela melhor nota',  $inicial[0] === 'MLB9999002221');

$ultimo = $inicial[count($inicial) - 1];

Canal::comCanal($canalFilaA, static fn (): ?int => (new Fila())->furar($ultimo));

$comFurao = $ordemA();

verificar('o produto furado vai para o topo', $comFurao[0] === $ultimo);
verificar('nenhum produto some ao furar',     count($comFurao) === count($inicial));
verificar('a fila sabe quem furou',           Canal::comCanal($canalFilaA, static fn (): array => (new Fila())->furados()) === [$ultimo]);

// furando um segundo, o ultimo clique manda: e o que quem clicou acabou de pedir
Canal::comCanal($canalFilaA, static fn (): ?int => (new Fila())->furar('MLB9999002221'));

$doisFurados = $ordemA();

verificar('o ultimo furado fica na frente',   $doisFurados[0] === 'MLB9999002221');
verificar('o anterior continua acima do resto', $doisFurados[1] === $ultimo);

// furar num canal nao pode mexer na fila do outro
$furadosB = Canal::comCanal($canalFilaB, static fn (): array => (new Fila())->furados());

verificar('furar nao vaza para outro canal',  $furadosB === []);

// produto de outro canal nao existe aqui: nao pode furar por engano
$forasteiro = Canal::comCanal($canalFilaB, static fn (): ?int => (new Fila())->furar('MLB9999002221'));

verificar('nao fura produto de outro canal',  $forasteiro === null);

Canal::comCanal($canalFilaA, static fn (): bool => (new Fila())->liberar($ultimo));
Canal::comCanal($canalFilaA, static fn (): bool => (new Fila())->liberar('MLB9999002221'));

verificar('liberar devolve a ordem por nota', $ordemA() === $inicial);
verificar('e nao sobra ninguem furando',      Canal::comCanal($canalFilaA, static fn (): array => (new Fila())->furados()) === []);

// liberar quem nunca furou nao e erro, so nao muda nada
verificar('liberar sem furar nao quebra',    !Canal::comCanal($canalFilaA, static fn (): bool => (new Fila())->liberar($ultimo)));

/*
 | O filtro continua valendo: furar a fila escolhe a ordem, nao dispensa as
 | regras. Um produto caro num canal com teto baixo nao pode passar so por ter
 | sido escolhido a mao.
 */
Canal::comCanal($canalFilaA, static fn (): ?int => (new Fila())->furar('MLB9999002221'));

$comTetoBaixo = Canal::comCanal($canalFilaA, static function (): array {
    Config::definir('config.filtros.preco_maximo', 50.0);

    $ordem = array_map(
        static fn ($p): string => $p->mlId,
        (new Fila())->proximos(500, true),
    );

    Config::definir('config.filtros.preco_maximo', 0.0);

    return $ordem;
});

verificar('furado reprovado no filtro nao passa', !in_array('MLB9999002221', $comTetoBaixo, true));

Canal::comCanal($canalFilaA, static fn (): bool => (new Fila())->liberar('MLB9999002221'));

/*
 | Pelo painel: o botao carrega canal e produto juntos.
 */
$painelFura = new Painel();

$painelFura->responder('/fila', 'POST', [
    'acao'  => 'fila-ordem',
    'furar' => 'fila_a|' . $ultimo,
], ['canal' => 'fila_a']);

verificar('o botao do painel fura a fila',    $ordemA()[0] === $ultimo);

$telaFurada = $painelFura->responder('/fila', 'GET', [], ['canal' => 'fila_a']);

verificar('a tela marca quem furou',          str_contains($telaFurada, 'name="liberar" value="fila_a|' . $ultimo . '"'));
verificar('e conta quantos estao furando',    str_contains($telaFurada, '1 furando a fila'));

$painelFura->responder('/fila', 'POST', [
    'acao'    => 'fila-ordem',
    'liberar' => 'fila_a|' . $ultimo,
], ['canal' => 'fila_a']);

verificar('o botao do painel tambem desfaz',  $ordemA() === $inicial);

// produto inventado nao pode virar prioridade fantasma no banco
$painelFura->responder('/fila', 'POST', [
    'acao'  => 'fila-ordem',
    'furar' => 'fila_a|MLB0000000000',
], ['canal' => 'fila_a']);

verificar('produto inexistente nao fura nada', Canal::comCanal($canalFilaA, static fn (): array => (new Fila())->furados()) === []);

/*
 | Depois de publicado, a prioridade sai. Senao o marcador ficaria para sempre e
 | o produto voltaria a furar a fila sozinho quando fosse recoletado.
 */
Canal::comCanal($canalFilaA, static fn (): ?int => (new Fila())->furar($ultimo));
Canal::comCanal($canalFilaA, static fn (): int => (new \MlGroup\App\Publicador(new Simulado()))->publicar(
    Canal::comCanal($canalFilaA, static fn (): array => (new Fila())->proximos(1)),
));

$limpas = Canal::comCanal($canalFilaA, static fn (): int => (new Fila())->limparPrioridadesPublicadas());

verificar('publicado perde a prioridade',      $limpas === 1);
verificar('e some da lista de furados',        Canal::comCanal($canalFilaA, static fn (): array => (new Fila())->furados()) === []);

/*
 |------------------------------------------------------------------
 | Descartar anuncio, marca e vendedor
 |------------------------------------------------------------------
 | Calibrar filtro nao resolve caso particular: o descarte atinge exatamente o
 | que foi apontado. Roda sobre os canais fila_a/fila_b, que ainda estao
 | declarados.
 */
echo "\nDescartes\n";

$comA = static fn (callable $acao): mixed => Canal::comCanal($canalFilaA, $acao);
$comB = static fn (callable $acao): mixed => Canal::comCanal($canalFilaB, $acao);

$naFilaA = static fn (): array => $comA(static fn (): array => array_map(
    static fn ($p): string => $p->mlId,
    (new Fila())->proximos(500, true),
));

/*
 | Produto proprio desta secao: a secao anterior publica um dos produtos do
 | canal A, e um teste que dependesse do que sobrou la quebraria toda vez que
 | aquela secao mudasse.
 */
$serraC = new Produto(
    mlId:          'MLB9999002226',
    titulo:        'Serra Circular Portatil 750W Com Maleta',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999002226-x',
    preco:         210.00,
    precoOriginal: 430.00,
);

$comA(static fn (): array => (new Cacador())->avaliar([$serraC]));

$antesDoDescarte = $naFilaA();

verificar('a fila tem os dois produtos',       in_array('MLB9999002221', $antesDoDescarte, true)
    && in_array('MLB9999002226', $antesDoDescarte, true));

// ---- por anuncio ----
$comA(static fn (): bool => (new Descartes())->guardar(Descartes::PRODUTO, 'MLB9999002221', 'Serra grande'));

verificar('anuncio descartado sai da fila',   !in_array('MLB9999002221', $naFilaA(), true));
verificar('e o resto continua',                in_array('MLB9999002226', $naFilaA(), true));

// o motivo precisa ser o descarte, e nao um limite qualquer: senao o usuario
// vai calibrar o filtro errado tentando entender o sumico
$motivoDescarte = $comA(static fn (): ?string => (new Filtro())->reprovar($serraA));

verificar('o motivo diz que foi descartado',   $motivoDescarte === 'descartado a mão');

// ---- isolamento entre canais ----
verificar('descarte nao vale no outro canal',  $comB(static fn (): ?string => (new Descartes())->motivo($serraA)) === null);

// ---- desfazer ----
$idDescarte = (int) $comA(static fn (): array => (new Descartes())->todos())[0]['id'];

verificar('desfazer remove a regra',           $comA(static fn (): bool => (new Descartes())->remover($idDescarte)));
verificar('e o produto volta para a fila',     $naFilaA() === $antesDoDescarte);
verificar('desfazer duas vezes nao quebra',   !$comA(static fn (): bool => (new Descartes())->remover($idDescarte)));

// ---- por vendedor ----
$comVendedor = new Produto(
    mlId:          'MLB9999002224',
    titulo:        'Serra Circular Manual 1200W Bancada',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999002224-x',
    preco:         200.00,
    precoOriginal: 420.00,
    vendedor:      'Loja Duvidosa LTDA',
);

verificar('vendedor passa antes do descarte',  $comA(static fn (): ?string => (new Descartes())->motivo($comVendedor)) === null);

$comA(static fn (): bool => (new Descartes())->guardar(Descartes::VENDEDOR, 'Loja Duvidosa LTDA'));

verificar('vendedor descartado e barrado',     $comA(static fn (): ?string => (new Descartes())->motivo($comVendedor)) !== null);

// o vendedor e comparado normalizado: caixa e acento nao podem furar a regra
$mesmoVendedor = new Produto(
    mlId:          'MLB9999002225',
    titulo:        'Serra Circular Outra 900W',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999002225-x',
    preco:         150.00,
    precoOriginal: 320.00,
    vendedor:      'LOJA DUVIDOSA LTDA',
);

verificar('caixa diferente nao fura a regra',  $comA(static fn (): ?string => (new Descartes())->motivo($mesmoVendedor)) !== null);

// ---- entrada invalida ----
verificar('tipo desconhecido nao entra',      !$comA(static fn (): bool => (new Descartes())->guardar('qualquer', 'x')));
verificar('valor vazio nao entra',            !$comA(static fn (): bool => (new Descartes())->guardar(Descartes::MARCA, '   ')));

// descartar duas vezes o mesmo nao duplica regra
$quantasAntes = $comA(static fn (): int => (new Descartes())->quantidade());
$comA(static fn (): bool => (new Descartes())->guardar(Descartes::VENDEDOR, 'Loja Duvidosa LTDA'));

verificar('descartar de novo nao duplica',     $comA(static fn (): int => (new Descartes())->quantidade()) === $quantasAntes);

// ---- pelo painel ----
$painelDesc = new Painel();

$painelDesc->responder('/fila', 'POST', ['descartar' => 'produto|fila_a|MLB9999002226'], ['canal' => 'fila_a']);

verificar('o botao do painel descarta',       !in_array('MLB9999002226', $naFilaA(), true));

$telaDescartes = $painelDesc->responder('/descartes', 'GET', [], ['canal' => 'fila_a']);

verificar('a tela lista o descarte',           str_contains($telaDescartes, 'Serra Circular Portatil'));
verificar('e oferece o desfazer',              str_contains($telaDescartes, 'name="redescartar"'));

// o rotulo do anuncio vem do banco, e nao de um campo repetido no formulario
verificar('o rotulo e o titulo certo',        !str_contains($telaDescartes, 'MLB9999002226<'));

$linhasDescarte = $comA(static fn (): array => (new Descartes())->todos());
$idDoPainel     = 0;

foreach ($linhasDescarte as $linhaDescarte) {
    if ($linhaDescarte['valor'] === 'MLB9999002226') {
        $idDoPainel = (int) $linhaDescarte['id'];
    }
}

$painelDesc->responder('/descartes', 'POST', ['redescartar' => 'fila_a|' . $idDoPainel], ['canal' => 'fila_a']);

verificar('o painel tambem desfaz',            in_array('MLB9999002226', $naFilaA(), true));

// limpa o que esta secao criou
$comA(static function (): void {
    foreach ((new Descartes())->todos() as $regra) {
        (new Descartes())->remover((int) $regra['id']);
    }
});

verificar('nada sobra depois da limpeza',      $comA(static fn (): int => (new Descartes())->quantidade()) === 0);

/*
 |------------------------------------------------------------------
 | Tarefa agendada do monitor
 |------------------------------------------------------------------
 | So a leitura: criar tarefa de verdade no Windows durante o teste mexeria na
 | maquina de quem roda a suite.
 */
echo "\nMonitor agendado\n";

verificar('sabe dizer se esta agendado',       is_bool(TarefaAgendada::existe()));
verificar('o lancador fica em storage',        str_contains(TarefaAgendada::lancador(), 'storage'));
verificar('e usa a barra do Windows',         !str_contains(TarefaAgendada::lancador(), '/')
    || DIRECTORY_SEPARATOR === '/');

/*
 |------------------------------------------------------------------
 | Painel: o que a tela mostra
 |------------------------------------------------------------------
 */
echo "\nInterface\n";

$telaInicio = (new Painel())->responder('/', 'GET', []);

verificar('o painel mostra a próxima publicação', str_contains($telaInicio, 'Próxima publicação'));
verificar('as miniaturas carregam sob demanda',   str_contains($telaInicio, 'loading="lazy"'));
verificar('tem alternador de tema',               str_contains($telaInicio, 'id="tema"'));
verificar('o menu vem agrupado',                  str_contains($telaInicio, 'Acompanhar')
    && str_contains($telaInicio, 'Ajustar'));

// o tema e escrito antes do CSS, senao a pagina pisca clara antes de escurecer
verificar('o tema e aplicado antes do estilo',    strpos($telaInicio, 'mlgroup:tema') < strpos($telaInicio, '<style>'));

// escolher "escuro" numa maquina clara precisa de regra propria
verificar('o escuro tem regra sem media query',   str_contains($telaInicio, ':root[data-theme="dark"]'));

$telaFilaUi = (new Painel())->responder('/fila', 'GET', [], ['canal' => 'fila_a']);

verificar('a fila mostra a foto do produto',      str_contains($telaFilaUi, 'class="com-foto"'));

/*
 | O historico do painel junta envios com produtos. Com canais, essa juncao
 | precisa do canal: o mesmo anuncio coletado por dois canais casa com as duas
 | linhas, e o painel mostrava o titulo (e agora a imagem) da copia errada.
 */
$mesmoAnuncioA = new Produto(
    mlId:          'MLB9999002227',
    titulo:        'Serra Circular Titulo Do Canal A',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999002227-x',
    preco:         190.00,
    precoOriginal: 400.00,
    thumb:         'https://exemplo/a.webp',
);

$mesmoAnuncioB = new Produto(
    mlId:          'MLB9999002227',
    titulo:        'Cafeteira Titulo Do Canal B',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999002227-x',
    preco:         190.00,
    precoOriginal: 400.00,
    thumb:         'https://exemplo/b.webp',
);

Canal::comCanal($canalFilaA, static fn (): array => (new Cacador())->avaliar([$mesmoAnuncioA]));
Canal::comCanal($canalFilaB, static fn (): array => (new Cacador())->avaliar([$mesmoAnuncioB]));

verificar('o anúncio existe nos dois canais',
    Canal::comCanal($canalFilaA, static fn (): ?string => (new Filtro())->reprovar($mesmoAnuncioA)) === null);

Canal::comCanal($canalFilaB, static fn (): int => (new \MlGroup\App\Publicador(new Simulado()))->publicar([$mesmoAnuncioB]));

$telaDepois = (new Painel())->responder('/', 'GET', []);

verificar('o histórico usa o título do canal certo', str_contains($telaDepois, 'Cafeteira Titulo Do Canal B'));
verificar('e não o do outro canal',                 !str_contains($telaDepois, 'Serra Circular Titulo Do Canal A'));

/*
 |------------------------------------------------------------------
 | Buscas por canal
 |------------------------------------------------------------------
 | Um canal pode ter as suas buscas ou herdar as gerais. O que nao pode e a
 | tela editar um conjunto achando que edita outro - era o caso ate aqui, com a
 | pagina mexendo so nas gerais sem dizer.
 |
 | Esta secao grava de verdade em storage/config-local.json, e nao com
 | Config::definir. Nao ha escolha: salvar pelo painel chama recarregar(), que
 | rele a configuracao do disco e joga fora qualquer valor posto so em memoria -
 | um fixture em memoria desapareceria no meio do teste e as verificacoes
 | seguintes passariam a olhar a configuracao real da maquina.
 |
 | Por isso o arquivo e copiado antes e devolvido no fim, inclusive se algo aqui
 | lancar excecao.
 */
echo "\nBuscas por canal\n";

ConfigLocal::definir('buscas.buscas', [
    ['nome' => 'Geral 1', 'tipo' => 'ofertas', 'categoria' => 'MLB1000', 'ativo' => true],
    ['nome' => 'Geral 2', 'tipo' => 'ofertas', 'categoria' => 'MLB2000', 'ativo' => true],
]);

ConfigLocal::definir('canais.canais', [
    ['id' => 'busca_herda', 'nome' => 'Herda', 'grupos' => [], 'ativo' => true],
    [
        'id'     => 'busca_propria',
        'nome'   => 'Própria',
        'grupos' => [],
        'ativo'  => true,
        'buscas' => [
            ['nome' => 'Só dela', 'tipo' => 'ofertas', 'categoria' => 'MLB9000', 'ativo' => true],
        ],
    ],
]);

ConfigLocal::gravar();
Config::recarregar();

$canalHerda   = Canal::porId('busca_herda');
$canalPropria = Canal::porId('busca_propria');

verificar('canal sem buscas usa as gerais',    count($canalHerda->buscas()) === 2);
verificar('canal com buscas usa as dele',      count($canalPropria->buscas()) === 1);
verificar('e o conteudo e o dele',             $canalPropria->buscas()[0]['nome'] === 'Só dela');

// a coleta precisa enxergar a mesma coisa que a tela
verificar('a coleta ve as buscas do canal',
    Canal::comCanal($canalPropria, static fn (): array => Config::lista('buscas.buscas'))[0]['nome'] === 'Só dela');
verificar('e as gerais em quem herda',
    count(Canal::comCanal($canalHerda, static fn (): array => Config::lista('buscas.buscas'))) === 2);

// ---- a tela ----
$telaGerais = (new Painel())->responder('/buscas', 'GET', []);

verificar('a tela abre nas gerais',            str_contains($telaGerais, 'Buscas · Gerais'));
verificar('e lista as abas dos canais',        str_contains($telaGerais, '/buscas?alvo=busca_propria'));
verificar('dizendo quem herda as gerais',      str_contains($telaGerais, 'Usadas por:'));

$telaHerda = (new Painel())->responder('/buscas', 'GET', [], ['alvo' => 'busca_herda']);

verificar('a aba de quem herda avisa',         str_contains($telaHerda, 'buscas gerais</strong>'));
verificar('e oferece criar as proprias',       str_contains($telaHerda, 'buscas-proprias'));

// campo travado nao volta no POST: sem isto, salvar na aba herdada apagaria tudo
verificar('a lista herdada vem travada',       str_contains($telaHerda, 'disabled'));
verificar('e sem botao de salvar',            !str_contains($telaHerda, 'Salvar buscas'));

$telaPropria = (new Painel())->responder('/buscas', 'GET', [], ['alvo' => 'busca_propria']);

verificar('a aba propria deixa editar',        str_contains($telaPropria, 'Salvar buscas'));
verificar('e oferece voltar as gerais',        str_contains($telaPropria, 'buscas-gerais'));
verificar('o alvo viaja no formulario',        str_contains($telaPropria, 'name="alvo" value="busca_propria"'));

// ---- salvar no canal nao mexe nas gerais ----
(new Painel())->responder('/buscas', 'POST', [
    'acao'  => 'salvar-buscas',
    'alvo'  => 'busca_propria',
    'busca' => [
        ['nome' => 'Nova dela', 'tipo' => 'ofertas', 'termo' => 'MLB9100', 'ativo' => '1', 'preco_max' => '80'],
    ],
], []);

verificar('salvar no canal grava nele',        Canal::porId('busca_propria')->buscas()[0]['nome'] === 'Nova dela');
verificar('o numero guardado vira numero',     (Canal::porId('busca_propria')->buscas()[0]['preco_max'] ?? null) === 80);
verificar('as gerais nao foram tocadas',       count(Config::lista('buscas.buscas')) === 2);
verificar('e quem herda continua herdando',    count(Canal::porId('busca_herda')->buscas()) === 2);

// ---- criar proprias copia as gerais ----
(new Painel())->responder('/buscas', 'POST', ['acao' => 'buscas-proprias', 'alvo' => 'busca_herda'], []);

verificar('criar proprias copia as gerais',    count(Canal::porId('busca_herda')->buscas()) === 2
    && is_array(Canal::porId('busca_herda')->paraArray()['buscas'] ?? null));

// a partir daqui, mexer nas gerais nao pode mais alcancar o canal
(new Painel())->responder('/buscas', 'POST', [
    'acao'  => 'salvar-buscas',
    'alvo'  => '',
    'busca' => [['nome' => 'Geral unica', 'tipo' => 'ofertas', 'termo' => 'MLB1000', 'ativo' => '1']],
], []);

verificar('gerais mudam sem afetar o canal',   count(Config::lista('buscas.buscas')) === 1
    && count(Canal::porId('busca_herda')->buscas()) === 2);

// ---- voltar as gerais apaga as proprias ----
(new Painel())->responder('/buscas', 'POST', ['acao' => 'buscas-gerais', 'alvo' => 'busca_herda'], []);

verificar('voltar as gerais apaga as proprias', !is_array(Canal::porId('busca_herda')->paraArray()['buscas'] ?? null));
verificar('e o canal volta a seguir as gerais', count(Canal::porId('busca_herda')->buscas()) === 1);

// alvo invalido nao pode gravar em canal nenhum
(new Painel())->responder('/buscas', 'POST', [
    'acao'  => 'salvar-buscas',
    'alvo'  => 'canal-que-nao-existe',
    'busca' => [['nome' => 'Intrusa', 'tipo' => 'ofertas', 'termo' => 'MLB1', 'ativo' => '1']],
], []);

verificar('alvo invalido nao grava nada',      count(Config::lista('buscas.buscas')) === 1
    && !is_array(Canal::porId('busca_herda')->paraArray()['buscas'] ?? null));
// devolve a configuracao do teste ao estado neutro para as secoes seguintes
ConfigLocal::definir('canais.canais', []);
ConfigLocal::gravar();
Config::recarregar();

verificar('os canais de teste saem no fim',    Canal::porId('busca_herda') === null);


/*
 |------------------------------------------------------------------
 | Criar e remover canais pelo painel
 |------------------------------------------------------------------
 | Um canal criado pelo painel nasce herdando nicho e buscas gerais. Isso e
 | util como ponto de partida e inutil como destino: assim ele publicaria o
 | mesmo assunto do primeiro, so que em outro grupo. Daí a metade que importa -
 | dar assunto proprio a ele - estar testada aqui junto.
 */
echo "\nCriar canais\n";

ConfigLocal::definir('canais.canais', []);
ConfigLocal::gravar();
Config::recarregar();
Nicho::limparCache();

$painelCanal = new Painel();

$painelCanal->responder('/canais', 'POST', [
    'acao'        => 'novo-canal',
    'nome_canal'  => 'Casa & Jardim!!',
], []);

$criado = Canal::porId('casa_jardim');

verificar('o canal e criado',                  $criado !== null);
verificar('o id sai do nome, sem simbolos',    $criado?->id() === 'casa_jardim');
verificar('o nome fica como foi digitado',     $criado?->nome() === 'Casa & Jardim!!');
verificar('sem grupo, nasce pausado',         !$criado?->ligado());

// canal ativo sem destino coletaria e nao teria para onde publicar
$painelCanal->responder('/canais', 'POST', [
    'acao'        => 'novo-canal',
    'nome_canal'  => 'Com grupo',
    'grupo_canal' => '999999999999999999@g.us',
], []);

verificar('com grupo, ja nasce ativo',         Canal::porId('com_grupo')?->ligado() === true);
verificar('e com o grupo apontado',            Canal::porId('com_grupo')?->grupos() === ['999999999999999999@g.us']);

// nome repetido nao pode gerar dois canais com o mesmo id: o segundo herdaria
// a fila e o historico do primeiro
$painelCanal->responder('/canais', 'POST', ['acao' => 'novo-canal', 'nome_canal' => 'Casa & Jardim!!'], []);

verificar('nome repetido ganha id proprio',    Canal::porId('casa_jardim_2') !== null);
verificar('e nao mexe no primeiro',            Canal::porId('casa_jardim')?->nome() === 'Casa & Jardim!!');

$painelCanal->responder('/canais', 'POST', ['acao' => 'novo-canal', 'nome_canal' => '   '], []);

verificar('sem nome nao cria nada',            count(Canal::todos()) === 3);

// ---- nicho proprio ----
verificar('o canal novo herda o nicho',       !is_array(Canal::porId('casa_jardim')?->paraArray()['nicho'] ?? null));

$painelCanal->responder('/nicho', 'POST', ['acao' => 'nicho-proprio', 'alvo' => 'casa_jardim'], []);

verificar('criar nicho proprio copia o geral', is_array(Canal::porId('casa_jardim')?->paraArray()['nicho'] ?? null));

$painelCanal->responder('/nicho', 'POST', [
    'acao'  => 'salvar-nicho',
    'alvo'  => 'casa_jardim',
    'campo' => [
        'nicho.ativo'             => '1',
        'nicho.nome'              => 'Casa e jardim',
        'nicho.exigir_relevancia' => '1',
        'nicho.essenciais'        => "regador\nvaso\nmangueira de jardim",
        'nicho.apoio'             => 'terra adubada',
        'nicho.bloqueados'        => 'miniatura',
        'nicho.peso_essencial'    => '1',
        'nicho.peso_apoio'        => '0.8',
    ],
], []);

$nichoSalvo = Canal::porId('casa_jardim')?->paraArray()['nicho'] ?? [];

verificar('o nicho do canal e salvo',          ($nichoSalvo['nome'] ?? '') === 'Casa e jardim');
verificar('a lista vira lista de verdade',     count($nichoSalvo['essenciais'] ?? []) === 3);

// o que a tela salvou precisa ser o que a coleta usa
verificar('a coleta ve o nicho do canal',
    Canal::comCanal(Canal::porId('casa_jardim'), static fn (): string => Config::texto('nicho.nome')) === 'Casa e jardim');
verificar('e o geral segue intacto para os outros',
    Canal::comCanal(Canal::porId('com_grupo'), static fn (): string => Config::texto('nicho.nome')) !== 'Casa e jardim');

// de fato classifica pelo nicho novo, e nao so guarda o texto
$regador = new Produto(
    mlId:          'MLB9999002228',
    titulo:        'Regador De Plastico 5 Litros Para Jardim',
    permalink:     'https://produto.mercadolivre.com.br/MLB-9999002228-x',
    preco:         39.90,
    precoOriginal: 89.90,
);

/*
 | A verificacao e no Nicho, e nao no Filtro inteiro: um Produto montado a mao
 | nao passou pelo calculo de comissao, entao o Filtro o reprovaria por
 | "comissao 0%" antes de chegar ao nicho - e o teste passaria a dizer "o canal
 | recusou" por um motivo que nada tem a ver com o assunto dele.
 */
verificar('o nicho novo reconhece o que e dele',
    Canal::comCanal(Canal::porId('casa_jardim'), static fn (): float => (new Nicho())->relevancia($regador)) > 0.0);
verificar('e casa pelo termo certo',
    Canal::comCanal(Canal::porId('casa_jardim'), static fn (): string => (new Nicho())->termoCasado($regador)) === 'regador');
verificar('o outro canal nao reconhece',
    Canal::comCanal(Canal::porId('com_grupo'), static fn (): float => (new Nicho())->relevancia($regador)) === 0.0);

$painelCanal->responder('/nicho', 'POST', ['acao' => 'nicho-geral', 'alvo' => 'casa_jardim'], []);

verificar('voltar ao geral apaga o proprio',  !is_array(Canal::porId('casa_jardim')?->paraArray()['nicho'] ?? null));

// ---- a tela ----
$telaCanais = $painelCanal->responder('/canais', 'GET', []);

verificar('a tela oferece adicionar canal',    str_contains($telaCanais, 'name="nome_canal"'));
verificar('e remover cada um',                 str_contains($telaCanais, 'name="remover-canal"'));
verificar('com atalho para o nicho',           str_contains($telaCanais, '/nicho?alvo=casa_jardim'));

$telaNicho = $painelCanal->responder('/nicho', 'GET', [], ['alvo' => 'casa_jardim']);

verificar('a aba de quem herda avisa',         str_contains($telaNicho, 'nicho geral</strong>'));
verificar('e oferece criar o proprio',         str_contains($telaNicho, 'nicho-proprio'));

// o nicho saiu da configuracao geral: duas casas para a mesma coisa confundem
$telaConfig = $painelCanal->responder('/config', 'GET', []);

verificar('o nicho saiu da configuracao',     !str_contains($telaConfig, 'campo[nicho.'));

// ---- remover ----
$painelCanal->responder('/canais', 'POST', ['remover-canal' => 'casa_jardim_2'], []);

verificar('remover tira o canal',              Canal::porId('casa_jardim_2') === null);
verificar('e nao leva os outros junto',        count(Canal::todos()) === 2);

$painelCanal->responder('/canais', 'POST', ['remover-canal' => 'nao-existe'], []);

verificar('remover o que nao existe nao quebra', count(Canal::todos()) === 2);

ConfigLocal::definir('canais.canais', []);
ConfigLocal::gravar();
Config::recarregar();
Nicho::limparCache();

Config::definir('canais.canais', []);
Nicho::limparCache();

/*
 |------------------------------------------------------------------
 | Limpeza dos dados de teste
 |------------------------------------------------------------------
 */
$limparFixtures($idsDeTeste);
$limparPastaDeTeste();

echo "\n" . ($falhas === 0 ? "Tudo certo.\n\n" : "{$falhas} verificacao(oes) falharam.\n\n");

exit($falhas === 0 ? 0 : 1);
