<?php

declare(strict_types=1);

namespace MlGroup\Painel;

use MlGroup\Analise\Descartes;
use MlGroup\Analise\Diversidade;
use MlGroup\Analise\Filtro;
use MlGroup\Analise\Nicho;
use MlGroup\App\Agendador;
use MlGroup\App\Canal;
use MlGroup\App\DestinosDeGrupo;
use MlGroup\App\Fila;
use MlGroup\App\Sentinela;
use MlGroup\App\TarefaAgendada;
use MlGroup\Database\Db;
use MlGroup\Mensagem\Montador;
use MlGroup\Support\Config;
use MlGroup\Support\ConfigLocal;
use MlGroup\Support\Env;
use MlGroup\Support\Str;
use MlGroup\Whatsapp\CatalogoDeGrupos;
use MlGroup\Whatsapp\Fabrica;
use Throwable;

/**
 * Controlador do painel web.
 *
 * Roda no servidor embutido do PHP, preso a 127.0.0.1 - e uma ferramenta de
 * configuracao local, nao um site. Por isso nao ha login: quem tem acesso ao
 * terminal ja tem acesso aos arquivos de config.
 */
final class Painel
{
    private readonly Visao $visao;

    /** @var array<int,array{tipo:string,texto:string}> */
    private array $recados = [];

    public function __construct()
    {
        $this->visao = new Visao();
    }

    /** @param array<string,mixed> $consulta os parametros de query string */
    public function responder(string $rota, string $metodo, array $post, array $consulta = []): string
    {
        if ($metodo === 'POST') {
            $this->processar($rota, $post);
        }

        return match ($rota) {
            '/canais'    => $this->canais(),
            '/grupos'    => $this->grupos(),
            '/buscas'    => $this->buscas((string) ($consulta['alvo'] ?? '')),
            '/nicho'     => $this->nicho((string) ($consulta['alvo'] ?? '')),
            '/fila'      => $this->fila((string) ($consulta['canal'] ?? '')),
            '/descartes' => $this->descartes((string) ($consulta['canal'] ?? '')),
            '/mensagem'  => $this->mensagem(),
            '/config'    => $this->configuracao(),
            default      => $this->inicio(),
        };
    }

    // ------------------------------------------------------------------
    // Paginas
    // ------------------------------------------------------------------

    private function inicio(): string
    {
        /*
         * Somado por canal, e nao com uma Fila sem canal ativo. Sem canal a
         * consulta cai em 'padrao', que hoje so guarda o que foi coletado antes
         * de os canais existirem - o cartao anunciava uma fila parada de 190
         * enquanto os canais que publicam de verdade tinham outras.
         */
        $porCanal = [];

        foreach (Canal::todos() as $canal) {
            $porCanal[$canal->nome()] = Canal::comCanal($canal, static fn (): int => (new Fila())->tamanho());
        }

        $driver = Fabrica::criar();

        try {
            $conectado = $driver->conectado();
        } catch (Throwable) {
            $conectado = false;
        }

        $grupos = array_filter(array_map('trim', explode(',', Env::texto('WHATSAPP_GRUPOS'))));

        $saude   = $this->saude();
        $proximas = $this->proximasPublicacoes();
        $serie   = $this->enviosPorDia(14);
        $faiscas = array_map(static fn (array $p): float => $p['valor'], $serie);

        $cartoes = [
            [
                'rotulo' => 'Publicadas hoje',
                'valor'  => (string) $this->enviadasHoje(),
                'nota'   => 'nos últimos 14 dias: ' . (int) array_sum($faiscas),
                'serie'  => $faiscas,
            ],
            [
                'rotulo' => 'Na fila',
                'valor'  => (string) array_sum($porCanal),
                'nota'   => $this->notaDaFila($porCanal),
            ],
            [
                'rotulo' => 'WhatsApp',
                'valor'  => $conectado ? 'Conectado' : 'Desconectado',
                'nota'   => 'driver ' . $driver->nome(),
                'estado' => $conectado ? 'bom' : 'critico',
            ],
            [
                'rotulo' => 'Comissão potencial',
                'valor'  => Str::dinheiro($this->comissaoPotencial(30)),
                'nota'   => 'soma dos últimos 30 dias, 1 venda por oferta',
            ],
        ];

        $janela = sprintf(
            '%s às %s · %s',
            Config::texto('config.agenda.hora_inicio'),
            Config::texto('config.agenda.hora_fim'),
            $this->diasResumidos(),
        );

        $ritmo = sprintf(
            '%d oferta(s) a cada %d min · coleta a cada %d min',
            Config::inteiro('config.envio.max_por_execucao', 1),
            Config::inteiro('config.agenda.intervalo_minutos', 10),
            Config::inteiro('config.coleta.intervalo_minutos', 60),
        );

        return $this->visao->inicio(
            $cartoes,
            $janela,
            $ritmo,
            $serie,
            $this->funil(30),
            $this->ultimasExecucoes(),
            $this->ultimosEnvios(),
            $this->recados,
            $saude,
            $proximas,
        );
    }

    private function configuracao(): string
    {
        $valores = [];
        $padroes = [];

        foreach (Campos::todos() as $campo) {
            $chave           = (string) $campo['chave'];
            $valores[$chave] = $this->valorAtual($campo);

            // do .env nao ha "padrao de fabrica" com que comparar
            $padroes[$chave] = ($campo['origem'] ?? 'config') === 'env'
                ? null
                : Config::padrao($chave);
        }

        /*
         * O nicho sai desta pagina e vive em /nicho.
         *
         * Com canais ele deixou de ser um so: cada canal pode ter o seu. Uma
         * aba aqui nao teria como dizer de qual canal estava falando, e editar
         * "o nicho" mexeria calado no de todo mundo que herda o geral.
         */
        $secoes = Campos::secoes();
        unset($secoes['nicho']);

        return $this->visao->configuracao(
            $secoes,
            $valores,
            $padroes,
            $this->recados,
            ConfigLocal::tudo() !== [],
        );
    }

    /**
     * O que sai na proxima publicacao, por canal.
     *
     * E a pergunta que o painel nao respondia: para saber o que ia sair era
     * preciso abrir a Fila e deduzir pela ordem. Aqui vem pronto, com a imagem,
     * o grupo de destino e quando deve acontecer.
     *
     * @return array<int,array<string,mixed>>
     */
    private function proximasPublicacoes(): array
    {
        $proximas = [];

        foreach (Canal::ativos() as $canal) {
            $produtos = Canal::comCanal($canal, static fn (): array => (new Fila())->proximos(1, true));

            if ($produtos === []) {
                $proximas[] = [
                    'canal'   => $canal->nome(),
                    'produto' => null,
                    'grupo'   => $this->nomeDoGrupo($canal),
                    'quando'  => 'fila vazia — aguardando a próxima coleta',
                    'furado'  => false,
                ];

                continue;
            }

            $produto = $produtos[0];
            $furados = Canal::comCanal($canal, static fn (): array => (new Fila())->furados());

            $proximas[] = [
                'canal'   => $canal->nome(),
                'produto' => $produto,
                'grupo'   => $this->nomeDoGrupo($canal),
                'quando'  => $this->quandoSai($canal),
                'furado'  => in_array($produto->mlId, $furados, true),
            ];
        }

        return $proximas;
    }

    /**
     * Quando a proxima publicacao deste canal deve sair.
     *
     * A conta e ultimo envio + intervalo, e nao um cronometro do laco: o painel
     * e um processo separado e nao tem como perguntar ao laco quanto falta. O
     * ultimo envio esta no banco e da a mesma resposta.
     */
    private function quandoSai(Canal $canal): string
    {
        $agendador = new Agendador();

        if (!$agendador->dentroDaJanela(new \DateTimeImmutable())) {
            return 'fora da janela de publicação — sai quando ela abrir';
        }

        $ultimo = Db::valor(
            "SELECT MAX(enviado_em) FROM envios WHERE status = 'enviado' AND canal = :canal",
            ['canal' => $canal->id()],
        );

        $intervalo = Config::inteiro('config.agenda.intervalo_minutos', 10);

        if ($ultimo === null) {
            return 'no próximo ciclo';
        }

        $faltam = (int) ceil(((strtotime((string) $ultimo) + $intervalo * 60) - time()) / 60);

        if ($faltam <= 0) {
            return 'a qualquer momento';
        }

        return 'em cerca de ' . $faltam . ' min';
    }

    /** O nome do grupo de destino, quando conhecido. */
    private function nomeDoGrupo(Canal $canal): string
    {
        $grupos = $canal->grupos();

        if ($grupos === []) {
            return '';
        }

        $nome = CatalogoDeGrupos::nomeDe($grupos[0]);
        $resto = count($grupos) - 1;

        if ($nome === '') {
            $nome = $grupos[0];
        }

        return $resto > 0 ? $nome . ' +' . $resto : $nome;
    }

    /**
     * O diagnostico do monitor, para o topo do painel.
     *
     * E a mesma leitura do comando `monitor`, mostrada onde o usuario ja esta.
     * Sem isso, descobrir que o laco de publicacao morreu exigia abrir um
     * terminal - e ninguem abre terminal para conferir um sistema que parece
     * estar funcionando. O grupo parado era o unico aviso.
     *
     * @return array<int,array{nome:string,ativo:bool,detalhe:string}>
     */
    private function saude(): array
    {
        $itens = (new Sentinela())->estado();

        $agendado = TarefaAgendada::existe();

        $itens[] = [
            'nome'    => 'Monitor automático',
            'ativo'   => $agendado,
            'detalhe' => $agendado
                ? 'o Windows religa o laço sozinho'
                : 'não agendado — rode: php bin/mlgroup agendar',
        ];

        return array_values($itens);
    }

    private function canais(): string
    {
        $canais = [];

        foreach (Canal::todos() as $canal) {
            $dados = $canal->paraArray();

            $canais[] = [
                'id'      => $canal->id(),
                'nome'    => $canal->nome(),
                'grupos'  => implode(', ', $canal->grupos()),
                'ativo'   => $canal->ligado(),
                'nicho'   => (string) ($dados['nicho']['nome'] ?? Config::texto('nicho.nome')),
                'buscas'  => count($canal->buscas()),
                'proprio' => isset($dados['nicho']),
                'fila'    => Canal::comCanal($canal, static fn (): int => (new Fila())->tamanho()),
                'enviados' => $this->enviadosDoCanal($canal->id()),
            ];
        }

        return $this->visao->canais($canais, $this->recados);
    }

    private function enviadosDoCanal(string $id): int
    {
        return (int) Db::valor(
            "SELECT COUNT(*) FROM envios WHERE canal = :c AND status = 'enviado'",
            ['c' => $id],
        );
    }

    /**
     * Salva o que dá para editar pela tela: nome, grupos e ligado/desligado.
     *
     * Nicho e buscas de canal ficam no arquivo: são listas grandes, e editá-las
     * num formulário genérico seria pior que abrir config/canais.php.
     */
    private function salvarCanais(array $post): void
    {
        $linhas = is_array($post['canal'] ?? null) ? $post['canal'] : [];
        $canais = [];

        foreach (Canal::todos() as $canal) {
            $dados   = $canal->paraArray();
            $enviado = $linhas[$canal->id()] ?? null;

            if (is_array($enviado)) {
                $nome = trim((string) ($enviado['nome'] ?? ''));

                $dados['nome']   = $nome !== '' ? $nome : $canal->nome();
                $dados['ativo']  = ($enviado['ativo'] ?? '0') === '1';
                $dados['grupos'] = array_values(array_filter(
                    array_map('trim', explode(',', (string) ($enviado['grupos'] ?? ''))),
                ));
            }

            $canais[] = $dados;
        }

        ConfigLocal::definir('canais.canais', $canais);
        ConfigLocal::gravar();
        Config::recarregar();

        $ligados = count(array_filter($canais, static fn ($c): bool => ($c['ativo'] ?? true) === true));

        $this->recados[] = ['tipo' => 'ok', 'texto' => count($canais) . ' canais salvos, ' . $ligados . ' ativo(s).'];

        foreach ($canais as $canal) {
            if (($canal['ativo'] ?? true) === true && ($canal['grupos'] ?? []) === []) {
                $this->recados[] = [
                    'tipo'  => 'atencao',
                    'texto' => 'O canal "' . $canal['nome'] . '" está ativo mas sem grupo: '
                        . 'ele vai coletar e não terá para onde publicar.',
                ];
            }
        }
    }

    /**
     * As buscas, por canal.
     *
     * Ate aqui esta pagina editava so o conjunto geral, e nao dizia isso. Um
     * canal com buscas proprias - o de utilidades ja tinha - simplesmente nao
     * aparecia: mexer aqui nao mudava nada para ele, sem nenhum aviso de que a
     * edicao estava indo para outro lugar.
     *
     * O alvo e '' para o conjunto geral, ou o id do canal.
     */
    private function buscas(string $alvo = ''): string
    {
        $canal = $alvo !== '' ? Canal::porId($alvo) : null;

        if ($alvo !== '' && $canal === null) {
            $alvo = '';
        }

        $proprias = $canal !== null ? ($canal->paraArray()['buscas'] ?? null) : null;
        $herda    = $canal !== null && !is_array($proprias);

        $buscas = match (true) {
            $canal === null => Config::lista('buscas.buscas'),
            $herda          => Config::lista('buscas.buscas'),
            default         => $proprias,
        };

        return $this->visao->buscas(
            is_array($buscas) ? $buscas : [],
            $this->abasDeBusca(),
            $alvo,
            $herda,
            $this->recados,
        );
    }

    /**
     * As abas: o conjunto geral primeiro, depois cada canal.
     *
     * O geral vem antes por ser o que a maioria dos canais usa - e a aba que
     * responde "onde mexo para valer para todo mundo".
     *
     * @return array<string,array{nome:string,quantas:int,proprias:bool,ativo:bool}>
     */
    private function abasDeBusca(): array
    {
        $gerais = Config::lista('buscas.buscas');

        $abas = ['' => [
            'nome'     => 'Gerais',
            'quantas'  => count($gerais),
            'proprias' => true,
            'ativo'    => true,
        ]];

        foreach (Canal::todos() as $canal) {
            $proprias = $canal->paraArray()['buscas'] ?? null;

            $abas[$canal->id()] = [
                'nome'     => $canal->nome(),
                'quantas'  => is_array($proprias) ? count($proprias) : count($gerais),
                'proprias' => is_array($proprias),
                'ativo'    => $canal->ligado(),
            ];
        }

        return $abas;
    }

    /** Copia as buscas gerais para dentro do canal, para ele seguir sozinho. */
    private function buscasProprias(array $post): void
    {
        $canal = Canal::porId(trim((string) ($post['alvo'] ?? '')));

        if ($canal === null) {
            return;
        }

        $this->gravarBuscasDoCanal($canal, Config::lista('buscas.buscas'));

        $this->recados[] = [
            'tipo'  => 'ok',
            'texto' => 'O canal "' . $canal->nome() . '" agora tem buscas próprias, '
                . 'copiadas das gerais. Mudar as gerais não afeta mais este canal.',
        ];
    }

    /** Devolve o canal ao conjunto geral, apagando as buscas proprias dele. */
    private function buscasGerais(array $post): void
    {
        $canal = Canal::porId(trim((string) ($post['alvo'] ?? '')));

        if ($canal === null) {
            return;
        }

        $this->gravarBuscasDoCanal($canal, null);

        $this->recados[] = [
            'tipo'  => 'atencao',
            'texto' => 'O canal "' . $canal->nome() . '" voltou a usar as buscas gerais. '
                . 'As buscas próprias dele foram apagadas — a versão anterior está em '
                . 'storage/config-local-anterior/.',
        ];
    }

    /**
     * Grava (ou remove) as buscas de um canal.
     *
     * @param array<int,array<string,mixed>>|null $buscas null remove a chave
     */
    private function gravarBuscasDoCanal(Canal $alvo, ?array $buscas): void
    {
        $canais = [];

        foreach (Canal::todos() as $canal) {
            $dados = $canal->paraArray();

            if ($canal->id() === $alvo->id()) {
                if ($buscas === null) {
                    unset($dados['buscas']);
                } else {
                    $dados['buscas'] = array_values($buscas);
                }
            }

            $canais[] = $dados;
        }

        ConfigLocal::definir('canais.canais', $canais);
        ConfigLocal::gravar();
        Config::recarregar();
    }

    /**
     * A fila de publicacao, por canal.
     *
     * Antes mostrava uma fila so - e, com canais, mostrava a fila errada: sem
     * canal ativo a consulta caia em 'padrao', que hoje guarda apenas os
     * produtos coletados antes dos canais existirem. A tela dizia "60 na fila"
     * enquanto o canal que publica de verdade tinha outra fila, invisivel.
     */
    private function fila(string $canalPedido = ''): string
    {
        $abas    = $this->abasDeFila();
        $escolha = $canalPedido !== '' && isset($abas[$canalPedido]) ? $canalPedido : '';
        $limite  = $escolha === '' ? 25 : 200;
        $linhas  = [];

        foreach ($abas as $id => $aba) {
            if ($escolha !== '' && $id !== $escolha) {
                continue;
            }

            /*
             * 'padrao' fica de fora de "Todos". Sao produtos coletados antes dos
             * canais, que nenhum ciclo publica mais - misturados aos demais, so
             * inflavam a lista com estoque morto. Continuam acessiveis pela aba
             * propria, para quem quiser conferir o que ficou para tras.
             */
            if ($escolha === '' && $id === 'padrao') {
                continue;
            }

            $furados = array_flip($this->furadosDe($id));

            foreach ($this->naFila($id, $limite) as $produto) {
                $linhas[] = [
                    'produto'  => $produto,
                    'canal'    => $aba['nome'],
                    'canalId'  => $id,
                    'tipo'     => $this->tipoDe($produto, $id),
                    'furado'   => isset($furados[$produto->mlId]),
                    'marca'    => (new Diversidade())->marca($produto),
                    'vendedor' => $produto->vendedor,
                ];
            }
        }

        /*
         * Juntando canais, a ordem volta a ser por nota - mas quem furou a fila
         * fica em cima. Ordenar so por nota aqui esconderia justamente o produto
         * que o usuario acabou de escolher, e a tela pareceria ter ignorado o
         * clique.
         */
        if ($escolha === '') {
            usort($linhas, static function (array $a, array $b): int {
                if ($a['furado'] !== $b['furado']) {
                    return $a['furado'] ? -1 : 1;
                }

                return $b['produto']->pontuacao <=> $a['produto']->pontuacao;
            });
        }

        return $this->visao->fila($linhas, $abas, $escolha, $this->recados);
    }

    /**
     * Uma aba por canal, mais o 'padrao' quando ainda houver historico nele.
     *
     * O 'padrao' nao e um canal de verdade: e onde ficaram os produtos
     * coletados antes de os canais existirem. Some da tela sozinho quando esses
     * produtos acabarem de sair - por isso a aba so aparece se tiver conteudo.
     *
     * @return array<string,array{nome:string,grupos:string[],ativo:bool,total:int}>
     */
    private function abasDeFila(): array
    {
        $abas = [];

        foreach (Canal::todos() as $canal) {
            $abas[$canal->id()] = [
                'nome'   => $canal->nome(),
                'grupos' => $canal->grupos(),
                'ativo'  => $canal->ligado(),
                'total'  => Canal::comCanal($canal, static fn (): int => (new Fila())->tamanho()),
            ];
        }

        $legado = $this->naFila('padrao', 1);

        if ($legado !== []) {
            $abas['padrao'] = [
                'nome'   => 'Antes dos canais',
                'grupos' => [],
                'ativo'  => false,
                'total'  => count($this->naFila('padrao', 500)),
            ];
        }

        return $abas;
    }

    /**
     * A fila de um canal.
     *
     * @return \MlGroup\Model\Produto[]
     */
    private function naFila(string $canalId, int $limite): array
    {
        $canal = Canal::porId($canalId);

        if ($canal !== null) {
            return Canal::comCanal($canal, static fn (): array => (new Fila())->proximos($limite, true));
        }

        // 'padrao': sem canal ativo, a Fila consulta exatamente essa faixa
        $anterior = Canal::ativo();
        Canal::ativar(null);

        try {
            return (new Fila())->proximos($limite, true);
        } finally {
            Canal::ativar($anterior);
        }
    }

    /**
     * Quem furou a fila neste canal.
     *
     * @return string[] ml_id
     */
    private function furadosDe(string $canalId): array
    {
        $canal = Canal::porId($canalId);

        if ($canal !== null) {
            return Canal::comCanal($canal, static fn (): array => (new Fila())->furados());
        }

        $anterior = Canal::ativo();
        Canal::ativar(null);

        try {
            return (new Fila())->furados();
        } finally {
            Canal::ativar($anterior);
        }
    }

    /**
     * O termo do nicho que o produto casou - "parafusadeira", "panela".
     *
     * E o mesmo criterio que a regra de diversidade usa para nao repetir tipo,
     * entao ver isso na tela explica por que uma oferta bem pontuada esperou a
     * vez. Precisa rodar sob o canal certo: cada um tem o seu nicho, e o mesmo
     * titulo casa termos diferentes conforme o canal.
     */
    private function tipoDe(\MlGroup\Model\Produto $produto, string $canalId): string
    {
        $canal = Canal::porId($canalId);
        $achar = static fn (): string => (new Nicho())->termoCasado($produto);

        return $canal !== null ? Canal::comCanal($canal, $achar) : $achar();
    }

    /**
     * Quanto cada canal tem na fila, para o cartao do painel.
     *
     * @param array<string,int> $porCanal
     */
    private function notaDaFila(array $porCanal): string
    {
        if ($porCanal === []) {
            return 'nenhum canal cadastrado';
        }

        if (count($porCanal) === 1) {
            return 'ofertas aprovadas aguardando';
        }

        $peca = [];

        foreach ($porCanal as $nome => $quantas) {
            $peca[] = mb_strtolower($nome) . ' ' . $quantas;
        }

        return implode(' · ', $peca);
    }

    /**
     * Previa da mensagem, com a proxima oferta de verdade.
     *
     * Precisa rodar sob um canal: sem isso pegava a fila 'padrao' (legado) e
     * mostrava uma previa de produto que nunca vai ser publicado.
     */
    private function mensagem(): string
    {
        $produtos = [];

        foreach (Canal::ativos() as $canal) {
            $produtos = Canal::comCanal($canal, static fn (): array => (new Fila())->proximos(1));

            if ($produtos !== []) {
                break;
            }
        }

        $texto = $produtos !== [] ? (new Montador())->oferta($produtos[0]) : '';

        return $this->visao->mensagem($texto, $this->recados);
    }

    /**
     * Os grupos do WhatsApp e a que canal cada um pertence.
     *
     * Junta duas listas que so faziam sentido juntas: os grupos de que o numero
     * participa (vem do WhatsApp) e os grupos que cada canal usa (vem da
     * configuracao). Separadas, sobrava para o usuario casar id com id na mao.
     */
    private function grupos(): string
    {
        $catalogo = CatalogoDeGrupos::atual();
        $canais   = Canal::todos();

        // id do grupo -> canal que o usa
        $dono = [];

        foreach ($canais as $canal) {
            foreach ($canal->grupos() as $id) {
                $dono[$id] = $canal;
            }
        }

        $linhas     = [];
        $conhecidos = [];

        foreach ($catalogo['grupos'] as $grupo) {
            $id                = (string) $grupo['id'];
            $conhecidos[$id]   = true;
            $canal             = $dono[$id] ?? null;
            $grupo['canal']    = $canal?->id() ?? '';
            $grupo['ausente']  = false;
            $grupo['aviso']    = $this->avisoDoGrupo($grupo, $canal !== null);
            $linhas[]          = $grupo;
        }

        /*
         * Grupo configurado num canal que nao aparece na lista do WhatsApp: id
         * errado, ou o numero saiu do grupo. Antes isso era invisivel - o canal
         * parecia certo no painel e nunca entregava. Aqui ele aparece com o
         * problema escrito.
         */
        foreach ($canais as $canal) {
            foreach ($canal->grupos() as $id) {
                if (isset($conhecidos[$id])) {
                    continue;
                }

                $conhecidos[$id] = true;

                $linhas[] = [
                    'id'            => $id,
                    'nome'          => '(fora da sua lista)',
                    'participantes' => 0,
                    'somente_admin' => false,
                    'sou_admin'     => null,
                    'canal'         => $canal->id(),
                    'ausente'       => true,
                    'aviso'         => $catalogo['grupos'] === []
                        ? 'Sem lista do WhatsApp para conferir.'
                        : 'Este ID não está entre os seus grupos: ou está errado, ou o número saiu do grupo.',
                ];
            }
        }

        $opcoes = ['' => '— nenhum —'];

        foreach ($canais as $canal) {
            $opcoes[$canal->id()] = $canal->nome() . ($canal->ligado() ? '' : ' (pausado)');
        }

        if ($catalogo['erro'] !== '') {
            $this->recados[] = ['tipo' => 'atencao', 'texto' => 'WhatsApp: ' . $catalogo['erro']];
        }

        return $this->visao->grupos($linhas, $opcoes, [
            'atualizado_em' => $catalogo['atualizado_em'],
            'ao_vivo'       => $catalogo['ao_vivo'],
            'conectado'     => $this->conectado(),
        ], $this->recados);
    }

    /** @param array<string,mixed> $grupo */
    private function avisoDoGrupo(array $grupo, bool $usado): string
    {
        if (!($grupo['somente_admin'] ?? false)) {
            return '';
        }

        $souAdmin = $grupo['sou_admin'] ?? null;

        if ($souAdmin === true) {
            return '';
        }

        /*
         * Grupo restrito a admins engole a mensagem de quem nao e admin sem
         * devolver erro: o envio volta como aceito e nada chega. So vale gritar
         * quando o grupo esta de fato em uso.
         */
        $texto = $souAdmin === false
            ? 'Só admins publicam aqui e este número não é admin: a mensagem sai sem erro e não chega.'
            : 'Só admins publicam aqui. Confirme que este número é admin.';

        return $usado ? $texto : '';
    }

    private function conectado(): bool
    {
        try {
            return Fabrica::criar()->conectado();
        } catch (Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Acoes
    // ------------------------------------------------------------------

    private function processar(string $rota, array $post): void
    {
        $acao = (string) ($post['acao'] ?? '');

        /*
         * Quando ha varios botoes de envio no mesmo formulario, so o clicado
         * chega no POST - entao o NOME do botao ja diz o que fazer, e diz melhor
         * do que um campo 'acao' escondido. A tela da fila depende disso: furar,
         * liberar e descartar convivem na mesma tabela, dentro do mesmo
         * formulario, e um 'acao' unico nao distinguiria os tres.
         */
        foreach (['furar', 'liberar', 'descartar', 'redescartar', 'remover-canal'] as $botao) {
            if (isset($post[$botao])) {
                $acao = $botao;

                break;
            }
        }

        try {
            match ($acao) {
                'salvar-config'  => $this->salvarConfig($post),
                'restaurar'      => $this->restaurar(),
                'salvar-buscas'  => $this->salvarBuscas($post),
                'buscas-proprias' => $this->buscasProprias($post),
                'buscas-gerais'  => $this->buscasGerais($post),
                'salvar-nicho'   => $this->salvarNicho($post),
                'nicho-proprio'  => $this->nichoProprio($post),
                'nicho-geral'    => $this->nichoGeral($post),
                'salvar-canais'  => $this->salvarCanais($post),
                'novo-canal'     => $this->novoCanal($post),
                'remover-canal'  => $this->removerCanal($post),
                'salvar-grupos'  => $this->salvarGrupos($post),
                'furar', 'liberar' => $this->reordenarFila($post),
                'descartar'      => $this->descartar($post),
                'redescartar'    => $this->desfazerDescarte($post),
                'atualizar-grupos' => $this->atualizarGrupos(),
                'listar-grupos'  => $this->listarGrupos(),
                default          => null,
            };
        } catch (Throwable $erro) {
            $this->recados[] = ['tipo' => 'ruim', 'texto' => 'Erro: ' . $erro->getMessage()];
        }
    }

    /**
     * Grava a que canal cada grupo pertence.
     *
     * A tela é por grupo ("este grupo vai para qual canal?"), mas a config é por
     * canal ("quais grupos este canal usa"). A conversão acontece aqui, e só
     * mexe nos canais citados no formulário: um canal cujo grupo nem apareceu na
     * tela fica como estava, em vez de ser esvaziado por omissão.
     */
    private function salvarGrupos(array $post): void
    {
        $destinos = is_array($post['destino'] ?? null) ? $post['destino'] : [];

        if ($destinos === []) {
            return;
        }

        $porCanal = DestinosDeGrupo::aplicar(
            DestinosDeGrupo::atual(),
            array_map('strval', $destinos),
        );

        $canais = [];

        foreach (Canal::todos() as $canal) {
            $dados           = $canal->paraArray();
            $dados['grupos'] = array_values($porCanal[$canal->id()] ?? []);
            $canais[]        = $dados;
        }

        ConfigLocal::definir('canais.canais', $canais);
        ConfigLocal::gravar();
        Config::recarregar();

        $comGrupo = 0;

        foreach ($canais as $canal) {
            if (($canal['grupos'] ?? []) !== []) {
                $comGrupo++;
            }
        }

        $this->recados[] = [
            'tipo'  => 'ok',
            'texto' => 'Destinos salvos: ' . $comGrupo . ' de ' . count($canais) . ' canais com grupo.',
        ];

        foreach ($canais as $canal) {
            if (($canal['ativo'] ?? true) === true && ($canal['grupos'] ?? []) === []) {
                $this->recados[] = [
                    'tipo'  => 'atencao',
                    'texto' => 'O canal "' . $canal['nome'] . '" está ativo e ficou sem grupo: '
                        . 'ele vai coletar e não terá para onde publicar.',
                ];
            }
        }
    }

    private function atualizarGrupos(): void
    {
        $catalogo = CatalogoDeGrupos::atual(true);

        if ($catalogo['grupos'] === []) {
            $this->recados[] = [
                'tipo'  => 'atencao',
                'texto' => $catalogo['erro'] !== ''
                    ? 'Não deu para consultar o WhatsApp: ' . $catalogo['erro']
                    : 'Nenhum grupo retornado. O WhatsApp está conectado? Rode: php bin/mlgroup conectar',
            ];

            return;
        }

        $this->recados[] = [
            'tipo'  => 'ok',
            'texto' => count($catalogo['grupos']) . ' grupos lidos do WhatsApp agora.',
        ];
    }

    /**
     * Furar a fila: manda um produto para o inicio, ou devolve a ordem normal.
     *
     * O botao carrega canal e produto juntos ("canal|MLB123") porque a tela
     * "Todos" mistura canais na mesma tabela - uma linha nao pode depender de um
     * campo escondido do formulario para saber a que canal pertence.
     */
    private function reordenarFila(array $post): void
    {
        $furar   = trim((string) ($post['furar'] ?? ''));
        $liberar = trim((string) ($post['liberar'] ?? ''));
        $alvo    = $furar !== '' ? $furar : $liberar;

        if ($alvo === '') {
            return;
        }

        [$canalId, $mlId] = array_pad(explode('|', $alvo, 2), 2, '');

        $canal = Canal::porId($canalId);

        if ($mlId === '' || ($canal === null && $canalId !== 'padrao')) {
            $this->recados[] = ['tipo' => 'atencao', 'texto' => 'Produto não encontrado.'];

            return;
        }

        $naFila = function (callable $acao) use ($canal): mixed {
            if ($canal !== null) {
                return Canal::comCanal($canal, $acao);
            }

            $anterior = Canal::ativo();
            Canal::ativar(null);

            try {
                return $acao();
            } finally {
                Canal::ativar($anterior);
            }
        };

        if ($liberar !== '') {
            $naFila(static fn (): bool => (new Fila())->liberar($mlId));

            $this->recados[] = ['tipo' => 'ok', 'texto' => 'Produto de volta à ordem por nota.'];

            return;
        }

        $posicao = $naFila(static fn (): ?int => (new Fila())->furar($mlId));

        if ($posicao === null) {
            $this->recados[] = ['tipo' => 'atencao', 'texto' => 'Produto não encontrado neste canal.'];

            return;
        }

        /*
         * Furar a fila muda a ordem, nao as regras. Se o produto nao passa mais
         * no filtro de agora - preco subiu, comissao caiu, nicho mudou - ele nao
         * vai ser publicado, e dizer isso na hora do clique evita esperar por
         * uma publicacao que nunca vem.
         */
        $motivo = $naFila(function () use ($mlId): ?string {
            $linha = Db::primeiro(
                'SELECT * FROM produtos WHERE canal = :canal AND ml_id = :ml_id',
                ['canal' => Canal::ativo()?->id() ?? 'padrao', 'ml_id' => $mlId],
            );

            return $linha !== null
                ? (new Filtro())->reprovar(\MlGroup\Model\Produto::doBanco($linha))
                : 'produto sumiu do banco';
        });

        if ($motivo !== null) {
            $this->recados[] = [
                'tipo'  => 'atencao',
                'texto' => 'Furou a fila, mas não vai ser publicado: ' . $motivo
                    . '. Ajuste o filtro em Configuração ou escolha outro produto.',
            ];

            return;
        }

        $this->recados[] = ['tipo' => 'ok', 'texto' => 'Furou a fila: é o próximo a ser publicado.'];
    }

    /**
     * O que este canal mandou nao aparecer mais.
     */
    private function descartes(string $canalPedido = ''): string
    {
        $abas    = $this->abasDeFila();
        $escolha = $canalPedido !== '' && isset($abas[$canalPedido]) ? $canalPedido : '';
        $listas  = [];

        foreach ($abas as $id => $aba) {
            if ($escolha !== '' && $id !== $escolha) {
                continue;
            }

            foreach ($this->noCanal($id, static fn (): array => (new Descartes())->todos()) as $item) {
                $item['canal']   = $aba['nome'];
                $item['canalId'] = $id;
                $listas[]        = $item;
            }
        }

        return $this->visao->descartes($listas, $abas, $escolha, $this->recados);
    }

    /**
     * Descarta um anuncio, uma marca ou um vendedor.
     *
     * O botao manda "tipo|canal|valor" - o canal precisa vir junto porque a aba
     * "Todos" mistura canais na mesma tabela.
     */
    private function descartar(array $post): void
    {
        $bruto = trim((string) ($post['descartar'] ?? ''));

        if ($bruto === '') {
            return;
        }

        [$tipo, $canalId, $valor] = array_pad(explode('|', $bruto, 3), 3, '');

        if ($valor === '') {
            $this->recados[] = ['tipo' => 'atencao', 'texto' => 'Nada a descartar.'];

            return;
        }

        /*
         * O rotulo sai do banco, e nao de um campo escondido no formulario.
         * Um <input hidden> por linha significaria dezenas deles com o mesmo
         * nome no mesmo POST, e o PHP fica com o ultimo - o descarte gravaria
         * sempre o titulo da ultima linha da tabela, nunca o da clicada.
         */
        $rotulo = $tipo === Descartes::PRODUTO
            ? (string) ($this->noCanal($canalId, static fn (): mixed => Db::valor(
                'SELECT titulo FROM produtos WHERE canal = :canal AND ml_id = :ml_id',
                ['canal' => Canal::ativo()?->id() ?? 'padrao', 'ml_id' => $valor],
            )) ?: $valor)
            : $valor;

        $guardou = $this->noCanal(
            $canalId,
            static fn (): bool => (new Descartes())->guardar($tipo, $valor, $rotulo),
        );

        if (!$guardou) {
            $this->recados[] = ['tipo' => 'atencao', 'texto' => 'Não deu para descartar isso.'];

            return;
        }

        $comoSeChama = [
            Descartes::PRODUTO  => 'Anúncio descartado',
            Descartes::MARCA    => 'Marca descartada',
            Descartes::VENDEDOR => 'Vendedor descartado',
        ];

        $this->recados[] = [
            'tipo'  => 'ok',
            'texto' => ($comoSeChama[$tipo] ?? 'Descartado') . ': ' . ($rotulo !== '' ? $rotulo : $valor)
                . '. Sai da fila agora e não volta nas próximas coletas — desfaça em Descartes.',
        ];
    }

    private function desfazerDescarte(array $post): void
    {
        $bruto = trim((string) ($post['redescartar'] ?? ''));

        [$canalId, $id] = array_pad(explode('|', $bruto, 2), 2, '');

        if ($id === '' || !ctype_digit($id)) {
            return;
        }

        $removeu = $this->noCanal(
            $canalId,
            static fn (): bool => (new Descartes())->remover((int) $id),
        );

        $this->recados[] = $removeu
            ? ['tipo' => 'ok', 'texto' => 'Descarte desfeito. Volta a aparecer na próxima coleta.']
            : ['tipo' => 'atencao', 'texto' => 'Esse descarte já não existe.'];
    }

    /**
     * Roda algo sob um canal, aceitando o 'padrao' (que nao e canal de verdade).
     */
    private function noCanal(string $canalId, callable $acao): mixed
    {
        $canal = Canal::porId($canalId);

        if ($canal !== null) {
            return Canal::comCanal($canal, $acao);
        }

        $anterior = Canal::ativo();
        Canal::ativar(null);

        try {
            return $acao();
        } finally {
            Canal::ativar($anterior);
        }
    }

    /**
     * Cria um canal.
     *
     * O id sai do nome, e nao de um campo a parte: e um detalhe interno - so
     * serve para amarrar as linhas do banco ao canal - e pedi-lo ao usuario so
     * criaria uma decisao a mais, com a chance de digitar algo que ja existe.
     *
     * Nasce herdando nicho e buscas gerais. Nao ha canal util assim - ele
     * publicaria o mesmo que o primeiro, em outro grupo - entao o recado ja
     * aponta para onde dar assunto proprio a ele.
     */
    private function novoCanal(array $post): void
    {
        $nome = trim((string) ($post['nome_canal'] ?? ''));

        if ($nome === '') {
            $this->recados[] = ['tipo' => 'atencao', 'texto' => 'Dê um nome ao canal.'];

            return;
        }

        $id = $this->idDisponivel($nome);

        $canais = array_map(static fn (Canal $canal): array => $canal->paraArray(), Canal::todos());

        $grupo = trim((string) ($post['grupo_canal'] ?? ''));

        $canais[] = [
            'id'     => $id,
            'nome'   => $nome,
            'grupos' => $grupo !== '' ? [$grupo] : [],
            // sem grupo nao ha para onde publicar: nasce parado para nao coletar a toa
            'ativo'  => $grupo !== '',
        ];

        ConfigLocal::definir('canais.canais', $canais);
        ConfigLocal::gravar();
        Config::recarregar();
        Nicho::limparCache();

        $this->recados[] = [
            'tipo'  => 'ok',
            'texto' => 'Canal "' . $nome . '" criado' . ($grupo === '' ? ', pausado até ter um grupo' : '') . '.',
        ];

        $this->recados[] = [
            'tipo'  => 'atencao',
            'texto' => 'Ele usa o nicho e as buscas gerais por enquanto — ou seja, publicaria o mesmo '
                . 'assunto dos outros. Dê assunto próprio a ele em Nicho e em Buscas.',
        ];
    }

    /**
     * Remove um canal da configuracao.
     *
     * O historico no banco fica: apagar envios e produtos junto perderia o
     * registro do que ja foi publicado naquele grupo, que e o unico jeito de
     * saber depois por que uma oferta nao voltou a sair.
     */
    private function removerCanal(array $post): void
    {
        $id    = trim((string) ($post['remover-canal'] ?? ''));
        $canal = Canal::porId($id);

        if ($canal === null) {
            return;
        }

        $restantes = [];

        foreach (Canal::todos() as $outro) {
            if ($outro->id() !== $id) {
                $restantes[] = $outro->paraArray();
            }
        }

        ConfigLocal::definir('canais.canais', $restantes);
        ConfigLocal::gravar();
        Config::recarregar();
        Nicho::limparCache();

        $naFila = (int) Db::valor(
            'SELECT COUNT(*) FROM produtos WHERE canal = :canal',
            ['canal' => $id],
        );

        $this->recados[] = [
            'tipo'  => 'ok',
            'texto' => 'Canal "' . $canal->nome() . '" removido. Ele para de coletar e de publicar.',
        ];

        if ($naFila > 0) {
            $this->recados[] = [
                'tipo'  => 'atencao',
                'texto' => $naFila . ' produto(s) e o histórico dele continuam no banco, sem aparecer no painel. '
                    . 'Recriar um canal com o mesmo nome faz tudo voltar.',
            ];
        }
    }

    /**
     * Um id livre, derivado do nome.
     *
     * Numera quando ja existe: dois canais com o mesmo id se confundiriam no
     * banco, e o segundo herdaria a fila e o historico do primeiro.
     */
    private function idDisponivel(string $nome): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '_', Str::normalizar($nome)) ?? '';
        $base = trim($base, '_');

        if ($base === '') {
            $base = 'canal';
        }

        $id      = $base;
        $sufixo  = 2;

        while (Canal::porId($id) !== null) {
            $id = $base . '_' . $sufixo++;
        }

        return $id;
    }

    /**
     * O nicho, por canal.
     *
     * Mesma ideia das buscas: o canal tem o seu ou herda o geral. Sem isto, um
     * canal criado pelo painel so conseguiria repetir o assunto do primeiro em
     * outro grupo - que e o oposto do motivo de existirem canais.
     */
    private function nicho(string $alvo = ''): string
    {
        $canal = $alvo !== '' ? Canal::porId($alvo) : null;

        if ($alvo !== '' && $canal === null) {
            $alvo = '';
        }

        $proprio = $canal !== null ? ($canal->paraArray()['nicho'] ?? null) : null;
        $herda   = $canal !== null && !is_array($proprio);

        $valores = [];

        foreach (Campos::daSecao('nicho') as $campo) {
            $chave = (string) $campo['chave'];
            $curto = substr($chave, strlen('nicho.'));

            $valores[$chave] = match (true) {
                $canal === null, $herda => Config::get($chave),
                default                 => $proprio[$curto] ?? Config::get($chave),
            };
        }

        return $this->visao->nicho(
            Campos::daSecao('nicho'),
            $valores,
            $this->abasDeNicho(),
            $alvo,
            $herda,
            $this->recados,
        );
    }

    /**
     * @return array<string,array{nome:string,proprio:bool}>
     */
    private function abasDeNicho(): array
    {
        $abas = ['' => ['nome' => 'Geral', 'proprio' => true]];

        foreach (Canal::todos() as $canal) {
            $abas[$canal->id()] = [
                'nome'    => $canal->nome(),
                'proprio' => is_array($canal->paraArray()['nicho'] ?? null),
            ];
        }

        return $abas;
    }

    private function salvarNicho(array $post): void
    {
        $alvo  = trim((string) ($post['alvo'] ?? ''));
        $canal = $alvo !== '' ? Canal::porId($alvo) : null;

        if ($alvo !== '' && $canal === null) {
            $this->recados[] = ['tipo' => 'atencao', 'texto' => 'Canal não encontrado.'];

            return;
        }

        $enviados = is_array($post['campo'] ?? null) ? $post['campo'] : [];
        $nicho    = [];

        foreach (Campos::daSecao('nicho') as $campo) {
            $chave = (string) $campo['chave'];
            $bruto = $enviados[$chave] ?? null;

            if ($bruto === null) {
                continue;
            }

            $valor = $this->converter((string) $campo['tipo'], $bruto, $post, $chave);

            if ($valor !== null) {
                $nicho[substr($chave, strlen('nicho.'))] = $valor;
            }
        }

        if ($canal === null) {
            foreach ($nicho as $curto => $valor) {
                ConfigLocal::definir('nicho.' . $curto, $valor);
            }

            ConfigLocal::gravar();
        } else {
            $this->gravarNichoDoCanal($canal, $nicho);
        }

        Config::recarregar();
        Nicho::limparCache();

        $this->recados[] = [
            'tipo'  => 'ok',
            'texto' => 'Nicho ' . ($canal !== null ? 'do canal "' . $canal->nome() . '"' : 'geral') . ' salvo.',
        ];

        /*
         * Nicho sem nenhum termo essencial reprova tudo quando a exigencia de
         * relevancia esta ligada - o canal coleta e nao aprova nada, sem erro
         * nenhum no log.
         */
        $essenciais = $nicho['essenciais'] ?? [];

        if (($nicho['exigir_relevancia'] ?? false) && (!is_array($essenciais) || $essenciais === [])) {
            $this->recados[] = [
                'tipo'  => 'atencao',
                'texto' => 'Sem termos essenciais e exigindo relevância, nada vai passar no filtro.',
            ];
        }
    }

    private function nichoProprio(array $post): void
    {
        $canal = Canal::porId(trim((string) ($post['alvo'] ?? '')));

        if ($canal === null) {
            return;
        }

        $copia = [];

        foreach (Campos::daSecao('nicho') as $campo) {
            $curto         = substr((string) $campo['chave'], strlen('nicho.'));
            $copia[$curto] = Config::get((string) $campo['chave']);
        }

        $this->gravarNichoDoCanal($canal, $copia);
        Nicho::limparCache();

        $this->recados[] = [
            'tipo'  => 'ok',
            'texto' => 'O canal "' . $canal->nome() . '" agora tem nicho próprio, copiado do geral. '
                . 'Troque os termos abaixo pelo assunto dele.',
        ];
    }

    private function nichoGeral(array $post): void
    {
        $canal = Canal::porId(trim((string) ($post['alvo'] ?? '')));

        if ($canal === null) {
            return;
        }

        $this->gravarNichoDoCanal($canal, null);
        Nicho::limparCache();

        $this->recados[] = [
            'tipo'  => 'atencao',
            'texto' => 'O canal "' . $canal->nome() . '" voltou ao nicho geral. Os termos próprios dele '
                . 'foram apagados — a versão anterior está em storage/config-local-anterior/.',
        ];
    }

    /** @param array<string,mixed>|null $nicho null remove a chave */
    private function gravarNichoDoCanal(Canal $alvo, ?array $nicho): void
    {
        $canais = [];

        foreach (Canal::todos() as $canal) {
            $dados = $canal->paraArray();

            if ($canal->id() === $alvo->id()) {
                if ($nicho === null) {
                    unset($dados['nicho']);
                } else {
                    $dados['nicho'] = $nicho;
                }
            }

            $canais[] = $dados;
        }

        ConfigLocal::definir('canais.canais', $canais);
        ConfigLocal::gravar();
        Config::recarregar();
    }

    private function salvarConfig(array $post): void
    {
        $enviados = is_array($post['campo'] ?? null) ? $post['campo'] : [];
        $mudou    = 0;

        foreach (Campos::todos() as $campo) {
            $chave = (string) $campo['chave'];
            $tipo  = (string) $campo['tipo'];

            /*
             * Campo ausente e ignorado, nunca interpretado como "vazio".
             * Checkbox desmarcado nao aparece no POST, por isso cada um tem um
             * hidden com "0" antes - assim a chave existe sempre que o
             * formulario foi de fato enviado, e um POST parcial nao desliga
             * opcao que o usuario nem viu.
             */
            $bruto = $enviados[$chave] ?? null;

            if ($bruto === null && $tipo !== 'dias') {
                continue;
            }

            $valor = $this->converter($tipo, $bruto, $post, $chave);

            if ($valor === null) {
                continue;
            }

            if (($campo['origem'] ?? 'config') === 'env') {
                Env::salvar($chave, is_array($valor) ? implode(',', $valor) : (string) $valor);
                $mudou++;

                continue;
            }

            if ($valor !== Config::get($chave)) {
                $mudou++;
            }

            ConfigLocal::definir($chave, $valor);
        }

        ConfigLocal::gravar();
        Config::recarregar();

        $this->recados[] = ['tipo' => 'ok', 'texto' => 'Configuração salva. ' . $mudou . ' campo(s) ajustado(s).'];
    }

    private function restaurar(): void
    {
        ConfigLocal::limpar();
        Config::recarregar();

        $this->recados[] = ['tipo' => 'ok', 'texto' => 'Ajustes descartados. Valem os padrões dos arquivos de config.'];
    }

    /**
     * Salva as buscas no lugar certo: o conjunto geral ou o de um canal.
     *
     * O alvo vem escondido no formulario, e nao da query string: quem salva
     * depois de trocar de aba estaria enviando para o alvo da aba anterior se a
     * pagina dependesse so da URL.
     */
    private function salvarBuscas(array $post): void
    {
        $alvo   = trim((string) ($post['alvo'] ?? ''));
        $canal  = $alvo !== '' ? Canal::porId($alvo) : null;
        $buscas = $this->lerBuscasDoPost($post);

        if ($alvo !== '' && $canal === null) {
            $this->recados[] = ['tipo' => 'atencao', 'texto' => 'Canal não encontrado.'];

            return;
        }

        $antes = $canal !== null
            ? ($canal->paraArray()['buscas'] ?? Config::lista('buscas.buscas'))
            : Config::lista('buscas.buscas');

        $ativasAntes  = $this->quantasAtivas(is_array($antes) ? $antes : []);
        $ativasDepois = $this->quantasAtivas($buscas);

        if ($canal !== null) {
            $this->gravarBuscasDoCanal($canal, $buscas);

            $onde = 'do canal "' . $canal->nome() . '"';
        } else {
            ConfigLocal::definir('buscas.buscas', $buscas);
            ConfigLocal::gravar();
            Config::recarregar();

            $onde = 'gerais';
        }

        $this->recados[] = [
            'tipo'  => 'ok',
            'texto' => count($buscas) . ' busca(s) ' . $onde . ' salva(s).',
        ];

        /*
         * Sem nenhuma busca ativa o sistema para de coletar e a fila seca - o
         * ciclo continua rodando e publicando nada, sem erro nenhum. Ja
         * aconteceu de a configuracao chegar assim; melhor gritar.
         */
        if ($ativasDepois === 0 && $ativasAntes > 0) {
            $this->recados[] = [
                'tipo'  => 'atencao',
                'texto' => $canal !== null
                    ? 'O canal "' . $canal->nome() . '" ficou sem busca ativa: ele vai parar de coletar. '
                        . 'A versão anterior está em storage/config-local-anterior/.'
                    : 'Nenhuma busca geral ficou ativa: os canais que usam as gerais vão parar de coletar. '
                        . 'A versão anterior está em storage/config-local-anterior/.',
            ];
        }
    }

    /**
     * Le as linhas do formulario de buscas.
     *
     * @return array<int,array<string,mixed>>
     */
    private function lerBuscasDoPost(array $post): array
    {
        $linhas = is_array($post['busca'] ?? null) ? $post['busca'] : [];
        $buscas = [];

        foreach ($linhas as $linha) {
            if (!is_array($linha)) {
                continue;
            }

            $nome  = trim((string) ($linha['nome'] ?? ''));
            $tipo  = (string) ($linha['tipo'] ?? 'termo');
            $termo = trim((string) ($linha['termo'] ?? ''));

            // linha em branco significa remover
            if ($nome === '' && $termo === '') {
                continue;
            }

            $busca = [
                'nome'  => $nome !== '' ? $nome : $termo,
                'tipo'  => $tipo,
                'ativo' => ($linha['ativo'] ?? '0') === '1',
            ];

            if ($tipo === 'ofertas') {
                $busca['categoria'] = $termo;
            } elseif ($tipo === 'url') {
                $busca['url'] = $termo;
            } else {
                $busca['termo'] = $termo;
            }

            foreach (['preco_min', 'preco_max', 'desconto_min'] as $numerico) {
                $valor = trim((string) ($linha[$numerico] ?? ''));

                if ($valor !== '' && is_numeric($valor)) {
                    $busca[$numerico] = (int) $valor;
                }
            }

            $buscas[] = $busca;
        }

        return $buscas;
    }

    /**
     * Mantido para o botao antigo na tela de configuracao.
     *
     * Antes despejava todos os grupos numa unica linha de aviso, concatenados -
     * ilegivel com mais de tres grupos. Agora atualiza a lista e manda para a
     * tela que sabe mostrar isso.
     */
    private function listarGrupos(): void
    {
        $catalogo = CatalogoDeGrupos::atual(true);

        if ($catalogo['grupos'] === []) {
            $this->recados[] = [
                'tipo'  => 'atencao',
                'texto' => 'Nenhum grupo retornado. O WhatsApp está conectado? Rode: php bin/mlgroup conectar',
            ];

            return;
        }

        $this->recados[] = [
            'tipo'  => 'ok',
            'texto' => count($catalogo['grupos']) . ' grupos encontrados. Veja e escolha o destino em Grupos.',
        ];
    }

    // ------------------------------------------------------------------
    // Apoio
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $campo */
    private function valorAtual(array $campo): mixed
    {
        $chave = (string) $campo['chave'];

        if (($campo['origem'] ?? 'config') === 'env') {
            return Env::texto($chave);
        }

        return Config::get($chave);
    }

    private function converter(string $tipo, mixed $bruto, array $post, string $chave): mixed
    {
        return match ($tipo) {
            'booleano'   => in_array((string) $bruto, ['1', 'on', 'true'], true),
            'inteiro'    => is_numeric($bruto) ? (int) $bruto : null,
            'numero', 'dinheiro', 'percentual' => is_numeric(str_replace(',', '.', (string) $bruto))
                ? (float) str_replace(',', '.', (string) $bruto)
                : null,
            'lista'      => $this->paraLista((string) $bruto),
            'dias'       => $this->diasEscolhidos($post),
            default      => trim((string) $bruto),
        };
    }

    /** @return string[] */
    private function paraLista(string $texto): array
    {
        $itens = preg_split('/\r?\n/', $texto) ?: [];

        return array_values(array_filter(array_map('trim', $itens), static fn (string $i): bool => $i !== ''));
    }

    /** @return int[] */
    private function diasEscolhidos(array $post): array
    {
        $marcados = is_array($post['dias'] ?? null) ? $post['dias'] : [];

        $dias = array_values(array_filter(
            array_map('intval', $marcados),
            static fn (int $d): bool => $d >= 1 && $d <= 7,
        ));

        sort($dias);

        return $dias;
    }

    private function diasResumidos(): string
    {
        $dias  = array_map('intval', Config::lista('config.agenda.dias_semana'));
        $nomes = Campos::diasDaSemana();

        if ($dias === [] || count($dias) === 7) {
            return 'todos os dias';
        }

        return implode(', ', array_map(static fn (int $d): string => $nomes[$d] ?? (string) $d, $dias));
    }

    /**
     * Envios por dia, com os dias sem envio preenchidos com zero.
     *
     * Sem o preenchimento, uma pausa de dois dias apareceria como duas barras
     * vizinhas - o gráfico esconderia justamente a interrupção.
     *
     * @return array<int,array{rotulo:string,valor:float,detalhe:string}>
     */
    private function enviosPorDia(int $dias): array
    {
        $inicio = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days') ?: time());

        $linhas = Db::todos(
            "SELECT substr(enviado_em, 1, 10) AS dia, COUNT(*) AS total
               FROM envios
              WHERE status = 'enviado' AND enviado_em >= :inicio
              GROUP BY dia",
            ['inicio' => $inicio . ' 00:00:00'],
        );

        $porDia = [];

        foreach ($linhas as $linha) {
            $porDia[(string) $linha['dia']] = (int) $linha['total'];
        }

        $serie = [];

        for ($i = $dias - 1; $i >= 0; $i--) {
            $data  = date('Y-m-d', strtotime('-' . $i . ' days') ?: time());
            $total = $porDia[$data] ?? 0;

            $serie[] = [
                'rotulo'  => date('d/m', strtotime($data) ?: time()),
                'valor'   => (float) $total,
                'detalhe' => $total === 1 ? '1 envio' : $total . ' envios',
            ];
        }

        return $serie;
    }

    /**
     * Coletados -> aprovados -> enviados no período.
     *
     * @return array<int,array{rotulo:string,valor:int,nota:string}>
     */
    private function funil(int $dias): array
    {
        $corte = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days') ?: time());

        $linha = Db::primeiro(
            'SELECT COALESCE(SUM(coletados), 0) AS coletados,
                    COALESCE(SUM(aprovados), 0) AS aprovados,
                    COALESCE(SUM(enviados), 0)  AS enviados
               FROM execucoes
              WHERE iniciado_em >= :corte',
            ['corte' => $corte],
        ) ?? [];

        $coletados = (int) ($linha['coletados'] ?? 0);
        $aprovados = (int) ($linha['aprovados'] ?? 0);
        $enviados  = (int) ($linha['enviados'] ?? 0);

        $proporcao = static fn (int $parte, int $todo): string => $todo > 0
            ? number_format(($parte / $todo) * 100, 0) . '% do que foi coletado'
            : '—';

        return [
            ['rotulo' => 'Coletados', 'valor' => $coletados, 'nota' => 'produtos vistos no Mercado Livre'],
            ['rotulo' => 'Aprovados', 'valor' => $aprovados, 'nota' => $proporcao($aprovados, $coletados)],
            ['rotulo' => 'Publicados', 'valor' => $enviados, 'nota' => $proporcao($enviados, $coletados)],
        ];
    }

    /** @param array<int,mixed> $buscas */
    private function quantasAtivas(array $buscas): int
    {
        return count(array_filter(
            $buscas,
            static fn ($busca): bool => is_array($busca) && ($busca['ativo'] ?? true) === true,
        ));
    }

    private function comissaoPotencial(int $dias): float
    {
        $corte = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days') ?: time());

        $valor = Db::valor(
            "SELECT SUM(p.ganho_estimado)
               FROM envios e
               JOIN produtos p ON p.ml_id = e.ml_id
              WHERE e.status = 'enviado' AND e.enviado_em >= :corte",
            ['corte' => $corte],
        );

        return (float) ($valor ?? 0);
    }

    private function enviadasHoje(): int
    {
        return (int) Db::valor(
            "SELECT COUNT(*) FROM envios WHERE status = 'enviado' AND enviado_em >= :inicio",
            ['inicio' => date('Y-m-d') . ' 00:00:00'],
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function ultimasExecucoes(): array
    {
        return Db::todos('SELECT * FROM execucoes ORDER BY id DESC LIMIT 8');
    }

    /** @return array<int,array<string,mixed>> */
    private function ultimosEnvios(): array
    {
        /*
         * A juncao precisa do canal. Sem ele, o mesmo anuncio coletado por dois
         * canais casa com as duas linhas e o painel mostra o titulo da copia
         * errada - e, agora que ha imagem, a imagem errada junto.
         */
        return Db::todos(
            'SELECT e.enviado_em, e.status, e.preco, e.desconto, e.canal,
                    p.titulo, p.thumb, p.permalink
               FROM envios e
               LEFT JOIN produtos p ON p.ml_id = e.ml_id AND p.canal = e.canal
              ORDER BY e.id DESC
              LIMIT 10',
        );
    }
}
