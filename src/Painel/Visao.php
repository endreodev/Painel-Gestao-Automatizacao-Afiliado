<?php

declare(strict_types=1);

namespace MlGroup\Painel;

use MlGroup\Model\Produto;
use MlGroup\Support\Str;

/**
 * Renderiza as telas do painel.
 *
 * HTML e CSS ficam aqui, sem template engine: o painel é uma ferramenta local
 * de meia dúzia de telas, e uma dependência a mais só para isso não se paga.
 *
 * As cores dos gráficos vêm de uma paleta validada para daltonismo e contraste
 * (ver Grafico). Texto nunca usa a cor da série: identidade fica na marca, a
 * leitura fica na tinta.
 */
final class Visao
{
    private readonly Grafico $grafico;

    public function __construct()
    {
        $this->grafico = new Grafico();
    }

    /** @param array<int,array{nome:string,ativo:bool,detalhe:string}> $saude */
    public function inicio(
        array $cartoes,
        string $janela,
        string $ritmo,
        array $serie,
        array $funil,
        array $execucoes,
        array $envios,
        array $recados,
        array $saude = [],
        array $proximas = [],
    ): string {
        $html = $this->faixaDeSaude($saude) . '<div class="indicadores">';

        foreach ($cartoes as $cartao) {
            $estado = isset($cartao['estado']) ? ' ' . $cartao['estado'] : '';
            $marca  = isset($cartao['estado'])
                ? '<span class="ponto ' . $this->e($cartao['estado']) . '"></span>'
                : '';

            $html .= '<article class="indicador">'
                . '<span class="indicador-rotulo">' . $this->e($cartao['rotulo']) . '</span>'
                . '<span class="indicador-valor' . $estado . '">' . $marca . $this->e($cartao['valor']) . '</span>'
                . '<span class="indicador-nota">' . $this->e($cartao['nota']) . '</span>'
                . (isset($cartao['serie']) ? $this->grafico->faisca($cartao['serie']) : '')
                . '</article>';
        }

        $html .= '</div>';

        $html .= $this->proximaPublicacao($proximas);

        $html .= '<div class="grade principal">';

        $html .= '<section class="cartao">'
            . $this->cabecalhoCartao('Envios por dia', 'Últimos 14 dias')
            . $this->grafico->barras($serie)
            . '</section>';

        $html .= '<section class="cartao">'
            . $this->cabecalhoCartao('Funil de coleta', 'Últimos 30 dias')
            . $this->grafico->funil($funil)
            . '</section>';

        $html .= '</div>';

        $html .= '<div class="grade secundaria">';

        $html .= '<section class="cartao">'
            . $this->cabecalhoCartao('Publicação', 'Regras em vigor')
            . '<dl class="propriedades">'
            . '<div><dt>Janela</dt><dd>' . $this->e($janela) . '</dd></div>'
            . '<div><dt>Ritmo</dt><dd>' . $this->e($ritmo) . '</dd></div>'
            . '</dl>'
            . '<p class="dica">Para iniciar o laço: <code>php bin/mlgroup rodar</code></p>'
            . '</section>';

        $linhasExec = '';

        foreach ($execucoes as $execucao) {
            $linhasExec .= '<tr>'
                . '<td class="mono">' . $this->e(substr((string) $execucao['iniciado_em'], 5, 11)) . '</td>'
                . '<td class="num">' . (int) $execucao['coletados'] . '</td>'
                . '<td class="num">' . (int) $execucao['aprovados'] . '</td>'
                . '<td class="num">' . (int) $execucao['enviados'] . '</td>'
                . '<td>' . $this->selo((string) $execucao['status'], $execucao['status'] === 'ok') . '</td>'
                . '</tr>';
        }

        $html .= '<section class="cartao">'
            . $this->cabecalhoCartao('Últimas execuções', '')
            . $this->tabela(
                ['Início', 'Coletados', 'Aprovados', 'Enviados', 'Status'],
                $linhasExec,
                'Nenhum ciclo ainda.',
                5,
            )
            . '</section>';

        $html .= '</div>';

        $linhasEnvio = '';

        foreach ($envios as $envio) {
            $linhasEnvio .= '<tr>'
                . '<td class="mono">' . $this->e(substr((string) $envio['enviado_em'], 5, 11)) . '</td>'
                . '<td><div class="com-foto">'
                . $this->miniatura((string) ($envio['thumb'] ?? ''), (string) ($envio['titulo'] ?? ''))
                . '<span>' . $this->e(Str::limitar((string) ($envio['titulo'] ?? '—'), 52)) . '</span>'
                . '</div></td>'
                . '<td class="num">' . $this->e(Str::dinheiro((float) $envio['preco'])) . '</td>'
                . '<td class="num">' . $this->e(Str::percentual((float) $envio['desconto'])) . '</td>'
                . '<td>' . $this->selo((string) $envio['status'], $envio['status'] === 'enviado') . '</td>'
                . '</tr>';
        }

        $html .= '<section class="cartao">'
            . $this->cabecalhoCartao('Últimos envios', '')
            . $this->tabela(
                ['Quando', 'Produto', 'Preço', 'Desconto', 'Status'],
                $linhasEnvio,
                'Nada publicado ainda.',
                5,
            )
            . '</section>';

        return $this->layout('Visão geral', '/', $html, $recados);
    }

    /**
     * Tela de configuração.
     *
     * As seções são abas de verdade, mas trocadas por CSS: os campos escondidos
     * continuam no formulário e são enviados junto. Fossem páginas separadas,
     * cada aba precisaria de um salvamento próprio - e salvar uma apagaria as
     * outras, que é a armadilha clássica de formulário dividido.
     */
    public function configuracao(
        array $secoes,
        array $valores,
        array $padroes,
        array $recados,
        bool $temAjustes,
    ): string {
        $abas    = '';
        $paineis = '';
        $primeira = true;

        foreach ($secoes as $id => $secao) {
            $alterados = 0;

            foreach ($secao['campos'] as $campo) {
                if ($this->foiAlterado($campo, $valores, $padroes)) {
                    $alterados++;
                }
            }

            $abas .= '<button type="button" class="aba' . ($primeira ? ' atual' : '') . '"'
                . ' data-aba="' . $this->e($id) . '">'
                . $this->e($secao['titulo'])
                . ($alterados > 0 ? '<span class="conta">' . $alterados . '</span>' : '')
                . '</button>';

            $paineis .= '<section class="cartao painel-aba' . ($primeira ? '' : ' oculto') . '"'
                . ' data-painel="' . $this->e($id) . '">'
                . $this->cabecalhoCartao($secao['titulo'], '')
                . '<p class="descricao">' . $this->e($secao['descricao']) . '</p>'
                . '<div class="campos">';

            foreach ($secao['campos'] as $campo) {
                $paineis .= $this->campo(
                    $campo,
                    $valores[$campo['chave']] ?? null,
                    $padroes[$campo['chave']] ?? null,
                );
            }

            $paineis .= '</div>';

            if ($id === 'whatsapp') {
                $paineis .= '<p class="dica">Não sabe o ID do grupo? '
                    . '<button type="submit" name="acao" value="listar-grupos" class="botao fantasma">Listar meus grupos</button>'
                    . '</p>';
            }

            $paineis .= '</section>';
            $primeira = false;
        }

        $html = '<form method="post" id="form-config">'
            . '<input type="hidden" name="acao" value="salvar-config">'
            . '<div class="barra-abas">'
            . '<nav class="abas">' . $abas . '</nav>'
            . '<label class="busca-campos">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>'
            . '<input type="search" id="busca-campo" placeholder="Buscar ajuste..." autocomplete="off">'
            . '</label>'
            . '</div>'
            . '<p class="nada-encontrado oculto">Nenhum ajuste com esse nome.</p>'
            . $paineis
            . '<div class="barra-acoes">'
            . '<span class="pendentes oculto"></span>';

        if ($temAjustes) {
            $html .= '<button type="submit" name="acao" value="restaurar" class="botao perigo"'
                . ' onclick="return confirm(\'Descartar TODOS os ajustes e voltar aos padrões dos arquivos?\')">'
                . 'Restaurar padrões</button>';
        }

        $html .= '<button type="submit" class="botao primario">Salvar configuração</button>'
            . '</div></form>';

        return $this->layout('Configuração', '/config', $html, $recados);
    }

    /** @param array<string,mixed> $campo */
    private function foiAlterado(array $campo, array $valores, array $padroes): bool
    {
        $chave = (string) $campo['chave'];

        if (($campo['origem'] ?? 'config') === 'env') {
            return false;
        }

        return $this->paraComparar($valores[$chave] ?? null) !== $this->paraComparar($padroes[$chave] ?? null);
    }

    /** Normaliza para comparar: o JSON devolve 88.0 como 88, e lista como lista. */
    private function paraComparar(mixed $valor): string
    {
        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if (is_array($valor)) {
            return implode('|', array_map('strval', $valor));
        }

        if (is_numeric($valor)) {
            return rtrim(rtrim(number_format((float) $valor, 4, '.', ''), '0'), '.');
        }

        return (string) $valor;
    }

    /**
     * As buscas do alvo escolhido: o conjunto geral ou o de um canal.
     *
     * @param array<int,array<string,mixed>>                                              $buscas
     * @param array<string,array{nome:string,quantas:int,proprias:bool,ativo:bool}>       $abas
     * @param array<int,array<string,string>>                                             $recados
     */
    public function buscas(array $buscas, array $abas, string $alvo, bool $herda, array $recados): string
    {
        $corpo    = '';
        $linhas   = array_values($buscas);
        $travado  = $herda ? ' disabled' : '';

        // linha em branco para acrescentar - so faz sentido no que da para editar
        if (!$herda) {
            $linhas[] = [];
        }

        foreach ($linhas as $indice => $busca) {
            $tipo = (string) ($busca['tipo'] ?? 'termo');

            $valor = match ($tipo) {
                'ofertas' => (string) ($busca['categoria'] ?? ''),
                'url'     => (string) ($busca['url'] ?? ''),
                default   => (string) ($busca['termo'] ?? ''),
            };

            $n = 'busca[' . $indice . ']';

            $corpo .= '<tr>'
                . '<td class="meio"><input type="hidden" name="' . $n . '[ativo]" value="0">'
                . '<input type="checkbox" name="' . $n . '[ativo]" value="1"'
                . (($busca['ativo'] ?? true) ? ' checked' : '') . $travado . '></td>'
                . '<td><input type="text" name="' . $n . '[nome]" value="'
                . $this->e((string) ($busca['nome'] ?? '')) . '" placeholder="nome da busca"' . $travado . '></td>'
                . '<td>' . $this->selecao(
                    $n . '[tipo]',
                    ['termo' => 'Termo', 'ofertas' => 'Ofertas', 'url' => 'URL'],
                    $tipo,
                    '',
                    $herda,
                ) . '</td>'
                . '<td><input type="text" name="' . $n . '[termo]" value="' . $this->e($valor)
                . '" placeholder="chave de impacto"' . $travado . '></td>'
                . '<td><input type="number" name="' . $n . '[preco_min]" value="'
                . $this->e((string) ($busca['preco_min'] ?? '')) . '" class="curto"' . $travado . '></td>'
                . '<td><input type="number" name="' . $n . '[preco_max]" value="'
                . $this->e((string) ($busca['preco_max'] ?? '')) . '" class="curto"' . $travado . '></td>'
                . '<td><input type="number" name="' . $n . '[desconto_min]" value="'
                . $this->e((string) ($busca['desconto_min'] ?? '')) . '" class="curto"' . $travado . '></td>'
                . '</tr>';
        }

        $nomeDoAlvo = (string) ($abas[$alvo]['nome'] ?? 'Gerais');

        $html = '<section class="cartao">'
            . $this->cabecalhoCartao('Buscas · ' . $nomeDoAlvo, count($buscas) . ' cadastradas')
            . '<p class="descricao">O que o sistema procura a cada coleta. A página '
            . '<strong>/ofertas</strong> é a fonte mais confiável; buscas por termo trazem itens mais '
            . 'específicos, mas o Mercado Livre as protege contra acesso automatizado.</p>'
            . $this->abasDeBusca($abas, $alvo)
            . $this->avisoDeHeranca($alvo, $herda, $abas)
            . '<form method="post">'
            . '<input type="hidden" name="acao" value="salvar-buscas">'
            . '<input type="hidden" name="alvo" value="' . $this->e($alvo) . '">'
            . $this->tabela(
                ['Ativa', 'Nome', 'Tipo', 'Termo / categoria / URL', 'Preço mín', 'Preço máx', 'Desc. mín'],
                $corpo,
                '',
                7,
                'editavel',
            )
            . ($herda
                ? ''
                : '<p class="dica">A última linha está em branco para adicionar uma busca. '
                    . 'Para remover, apague o nome e o termo.</p>'
                    . '<div class="barra-acoes">'
                    . '<button type="submit" class="botao primario">Salvar buscas</button>'
                    . ($alvo !== ''
                        ? '<button type="submit" class="botao perigo" name="acao" value="buscas-gerais" '
                            . 'formnovalidate>Voltar a usar as gerais</button>'
                        : '')
                    . '</div>')
            . '</form></section>';

        return $this->layout('Buscas', '/buscas', $html, $recados);
    }

    /**
     * O aviso de que o canal nao tem buscas proprias.
     *
     * Sem isto a tela mentiria por omissao: mostraria as buscas gerais dentro da
     * aba de um canal, e quem editasse acharia estar mexendo so naquele canal
     * quando na verdade mexeria em todos os que herdam.
     *
     * @param array<string,array{nome:string,quantas:int,proprias:bool,ativo:bool}> $abas
     */
    private function avisoDeHeranca(string $alvo, bool $herda, array $abas): string
    {
        if ($alvo === '') {
            $usam = [];

            foreach ($abas as $id => $aba) {
                if ($id !== '' && !$aba['proprias']) {
                    $usam[] = $aba['nome'];
                }
            }

            if ($usam === []) {
                return '<div class="recado atencao"><span>Nenhum canal usa as buscas gerais hoje — '
                    . 'todos têm as suas. Editar aqui não muda nada até algum canal voltar a usá-las.'
                    . '</span></div>';
            }

            return '<p class="dica">Usadas por: <strong>' . $this->e(implode(', ', $usam)) . '</strong>.</p>';
        }

        if (!$herda) {
            return '';
        }

        return '<div class="recado atencao">'
            . '<span>Este canal usa as <strong>buscas gerais</strong>. A lista abaixo é só leitura — '
            . 'mudá-la aqui mexeria em todos os canais que as herdam.</span>'
            . '</div>'
            . '<form method="post" class="barra-acoes">'
            . '<input type="hidden" name="acao" value="buscas-proprias">'
            . '<input type="hidden" name="alvo" value="' . $this->e($alvo) . '">'
            . '<button type="submit" class="botao primario">Criar buscas próprias deste canal</button>'
            . '</form>';
    }

    /**
     * As abas de busca.
     *
     * @param array<string,array{nome:string,quantas:int,proprias:bool,ativo:bool}> $abas
     */
    private function abasDeBusca(array $abas, string $alvo): string
    {
        if (count($abas) < 2) {
            return '';
        }

        $html = '<nav class="abas-fila">';

        foreach ($abas as $id => $aba) {
            $dica = $id === ''
                ? 'valem para todo canal que não tenha as suas'
                : ($aba['proprias'] ? 'este canal tem buscas próprias' : 'este canal usa as gerais');

            $html .= '<a href="' . ($id === '' ? '/buscas' : '/buscas?alvo=' . rawurlencode($id)) . '"'
                . ($alvo === $id ? ' class="atual"' : '')
                . ' data-dica="' . $this->e($dica) . '">'
                . $this->e($aba['nome'])
                . ($id !== '' && !$aba['proprias'] ? ' <span class="etiqueta">herda</span>' : '')
                . ' <span class="conta">' . (int) $aba['quantas'] . '</span></a>';
        }

        return $html . '</nav>';
    }

    /** @param array<int,array<string,mixed>> $canais */
    public function canais(array $canais, array $recados): string
    {
        $corpo = '';

        foreach ($canais as $canal) {
            $n = 'canal[' . $this->e($canal['id']) . ']';

            $corpo .= '<tr>'
                . '<td class="meio"><input type="hidden" name="' . $n . '[ativo]" value="0">'
                . '<label class="chave"><input type="checkbox" name="' . $n . '[ativo]" value="1"'
                . ($canal['ativo'] ? ' checked' : '') . '><span class="trilho-chave"></span></label></td>'
                . '<td><input type="text" name="' . $n . '[nome]" value="' . $this->e($canal['nome']) . '"></td>'
                . '<td><input type="text" name="' . $n . '[grupos]" value="' . $this->e($canal['grupos'])
                . '" placeholder="120363...@g.us"></td>'
                . '<td><a href="/nicho?alvo=' . rawurlencode((string) $canal['id']) . '">'
                . $this->e($canal['nicho']) . '</a>'
                . ($canal['proprio'] ? '' : ' <span class="etiqueta">herda</span>') . '</td>'
                . '<td class="num"><a href="/buscas?alvo=' . rawurlencode((string) $canal['id']) . '">'
                . (int) $canal['buscas'] . '</a></td>'
                . '<td class="num">' . (int) $canal['fila'] . '</td>'
                . '<td class="num">' . (int) $canal['enviados'] . '</td>'
                . '<td><button type="submit" name="remover-canal" value="'
                . $this->e((string) $canal['id']) . '" class="furar remover" formnovalidate'
                . ' data-dica="Remover este canal. O histórico dele continua no banco."'
                . ' aria-label="Remover canal">&#10005;</button></td>'
                . '</tr>';
        }

        $html = '<section class="cartao">'
            . $this->cabecalhoCartao('Canais', count($canais) . ' cadastrados')
            . '<p class="descricao">Cada canal é um grupo com o seu assunto: nicho, buscas e filtros '
            . 'próprios. Um grupo de ferramentas e um de utilidades recebem coisas diferentes, '
            . 'sem um interferir no outro.</p>'
            . '<form method="post"><input type="hidden" name="acao" value="salvar-canais">'
            . $this->tabela(
                ['Ativo', 'Nome', 'Grupos do WhatsApp', 'Nicho', 'Buscas', 'Na fila', 'Enviados', ''],
                $corpo,
                'Nenhum canal cadastrado.',
                8,
                'editavel',
            )
            . '<p class="dica">Clique no nicho ou no número de buscas para dar assunto próprio ao canal. '
            . 'Para descobrir o ID de um grupo, use a página <a href="/grupos">Grupos</a>.</p>'
            . '<div class="barra-acoes"><button type="submit" class="botao primario">Salvar canais</button></div>'
            . '</form>'
            . '<form method="post" class="novo-canal">'
            . '<input type="hidden" name="acao" value="novo-canal">'
            . '<h3>Adicionar canal</h3>'
            . '<div class="campos-linha">'
            . '<label>Nome<input type="text" name="nome_canal" placeholder="Utilidades domésticas" required></label>'
            . '<label>Grupo do WhatsApp <span class="auxiliar">(opcional)</span>'
            . '<input type="text" name="grupo_canal" placeholder="120363...@g.us"></label>'
            . '<button type="submit" class="botao primario">Criar</button>'
            . '</div>'
            . '<p class="dica">Sem grupo, o canal nasce pausado — ativo e sem destino ele coletaria à toa. '
            . 'O canal novo começa herdando o nicho e as buscas gerais; dê assunto próprio a ele para '
            . 'não repetir o que os outros já publicam.</p>'
            . '</form>'
            . '</section>';

        return $this->layout('Canais', '/canais', $html, $recados);
    }

    /**
     * A miniatura do produto.
     *
     * O sistema guarda a imagem de todo anuncio e o painel nunca a usava - a
     * fila era uma lista de titulos truncados, que se leem um a um. Com a foto
     * ela vira algo que se varre de relance, que e como alguem de fato confere
     * uma fila de ofertas.
     *
     * loading="lazy" porque a fila mostra dezenas de linhas e as imagens vem do
     * servidor do Mercado Livre: carregar todas de uma vez travaria a pagina por
     * segundos sem nenhum ganho.
     */
    private function miniatura(string $url, string $titulo): string
    {
        if (trim($url) === '') {
            return '<span class="foto vazia" aria-hidden="true"></span>';
        }

        return '<img class="foto" src="' . $this->e($url) . '" alt="" loading="lazy" decoding="async"'
            . ' title="' . $this->e(Str::limitar($titulo, 80)) . '">';
    }

    /**
     * O cartao "o que sai agora".
     *
     * @param array<int,array<string,mixed>> $proximas
     */
    private function proximaPublicacao(array $proximas): string
    {
        if ($proximas === []) {
            return '';
        }

        $cartoes = '';

        foreach ($proximas as $item) {
            $produto = $item['produto'] ?? null;

            $corpo = $produto === null
                ? '<div class="proxima-vazia">Nada na fila deste canal.</div>'
                : '<div class="com-foto grande">'
                    . $this->miniatura($produto->thumb, $produto->titulo)
                    . '<div>'
                    . '<a href="' . $this->e($produto->link()) . '" target="_blank" rel="noreferrer">'
                    . $this->e(Str::limitar($produto->titulo, 72)) . '</a>'
                    . '<div class="proxima-preco">'
                    . '<strong>' . $this->e(Str::dinheiro($produto->preco)) . '</strong>'
                    . ($produto->desconto() > 0
                        ? ' <span class="etiqueta">' . $this->e(Str::percentual($produto->desconto())) . ' OFF</span>'
                        : '')
                    . ($item['furado'] ? ' <span class="etiqueta atencao">furou a fila</span>' : '')
                    . '</div></div></div>';

            $destino = (string) $item['grupo'];

            $cartoes .= '<article class="proxima">'
                . '<header><span class="proxima-canal">' . $this->e((string) $item['canal']) . '</span>'
                . ($destino !== '' ? '<span class="auxiliar">→ ' . $this->e($destino) . '</span>' : '')
                . '</header>'
                . $corpo
                . '<footer class="auxiliar">' . $this->e((string) $item['quando']) . '</footer>'
                . '</article>';
        }

        return '<section class="cartao">'
            . $this->cabecalhoCartao('Próxima publicação', 'o que sai agora, por canal')
            . '<div class="proximas">' . $cartoes . '</div>'
            . '<p class="dica">Para trocar a ordem, use a seta na <a href="/fila">Fila</a>.</p>'
            . '</section>';
    }

    /**
     * A faixa de diagnostico no topo do painel.
     *
     * Fica escondida quando esta tudo certo. Uma faixa verde permanente vira
     * paisagem em dois dias e ninguem mais a le - o que anula justamente o dia
     * em que ela ficaria vermelha.
     *
     * @param array<int,array{nome:string,ativo:bool,detalhe:string}> $saude
     */
    private function faixaDeSaude(array $saude): string
    {
        $problemas = array_values(array_filter($saude, static fn (array $i): bool => !$i['ativo']));

        if ($problemas === []) {
            return '';
        }

        $itens = '';

        foreach ($problemas as $item) {
            $itens .= '<li><strong>' . $this->e($item['nome']) . '</strong> '
                . $this->e($item['detalhe']) . '</li>';
        }

        return '<section class="faixa-saude">'
            . '<div class="ponto critico"></div>'
            . '<div><h3>' . count($problemas) . ' ponto(s) de atenção</h3>'
            . '<ul>' . $itens . '</ul></div>'
            . '</section>';
    }

    /**
     * Grupos do WhatsApp e o canal de cada um.
     *
     * A busca filtra no navegador, por nome e por id: com trinta grupos, achar o
     * certo rolando a lista e pior do que digitar tres letras.
     *
     * @param array<int,array<string,mixed>> $grupos
     * @param array<string,string>           $canais
     * @param array<string,mixed>            $estado
     * @param array<int,array<string,string>> $recados
     */
    public function grupos(array $grupos, array $canais, array $estado, array $recados): string
    {
        $corpo    = '';
        $usados   = 0;
        $alertas  = 0;

        foreach ($grupos as $grupo) {
            $id     = (string) $grupo['id'];
            $canal  = (string) $grupo['canal'];
            $aviso  = (string) $grupo['aviso'];
            $usados += $canal !== '' ? 1 : 0;
            $alertas += $aviso !== '' ? 1 : 0;

            $marcas = '';

            if ($grupo['ausente']) {
                $marcas .= '<span class="etiqueta ruim">fora da lista</span>';
            }

            if ($grupo['somente_admin']) {
                $marcas .= $grupo['sou_admin'] === true
                    ? '<span class="etiqueta">admin ok</span>'
                    : '<span class="etiqueta atencao">só admins</span>';
            }

            $busca = $this->e(mb_strtolower($grupo['nome'] . ' ' . $id . ' ' . ($canais[$canal] ?? '')));

            $corpo .= '<tr data-busca="' . $busca . '"' . ($canal !== '' ? ' class="usado"' : '') . '>'
                . '<td><div class="grupo-nome">' . $this->e($grupo['nome']) . $marcas . '</div>'
                . ($aviso !== '' ? '<div class="grupo-aviso">' . $this->e($aviso) . '</div>' : '')
                . '</td>'
                . '<td class="num">' . ((int) $grupo['participantes'] > 0
                    ? (int) $grupo['participantes']
                    : '<span class="auxiliar">—</span>') . '</td>'
                . '<td><button type="button" class="id-grupo" data-id="' . $this->e($id)
                . '" title="Copiar o ID">' . $this->e($this->encurtarId($id)) . '</button></td>'
                . '<td>' . $this->selecao('destino[' . $this->e($id) . ']', $canais, $canal) . '</td>'
                . '</tr>';
        }

        $quando = $estado['atualizado_em'] !== null
            ? 'lista de ' . $this->e(date('d/m H:i', (int) strtotime((string) $estado['atualizado_em'])))
            : 'nunca consultada';

        $html = '<section class="cartao">'
            . $this->cabecalhoCartao('Grupos do WhatsApp', count($grupos) . ' grupos · ' . $quando)
            . '<p class="descricao">Escolha para qual canal cada grupo publica. Um grupo pertence a um '
            . 'canal só — o que sobra fica em <em>nenhum</em> e não recebe nada.</p>'
            . ($estado['conectado'] ? '' : '<div class="recado atencao">WhatsApp desconectado. '
                . 'A lista abaixo é a última conhecida; para atualizar, rode '
                . '<code>php bin/mlgroup conectar</code>.</div>')
            . '<form method="post"><input type="hidden" name="acao" value="salvar-grupos">'
            . '<div class="filtro-grupos">'
            . '<input type="search" id="busca-grupos" placeholder="Buscar por nome ou ID..." autocomplete="off">'
            . '<span class="auxiliar" id="conta-grupos">' . $usados . ' com destino</span>'
            . '</div>'
            . $this->tabela(
                ['Grupo', 'Membros', 'ID', 'Publica no canal'],
                $corpo,
                'Nenhum grupo. Conecte o WhatsApp e clique em "Atualizar do WhatsApp".',
                4,
                'editavel grupos',
            )
            . '<div class="barra-acoes">'
            . '<button type="submit" class="botao primario">Salvar destinos</button>'
            . '<button type="submit" class="botao" name="acao" value="atualizar-grupos" formnovalidate>'
            . 'Atualizar do WhatsApp</button>'
            . '</div></form>'
            . ($alertas > 0 ? '<p class="dica">' . $alertas . ' grupo(s) com aviso: leia a linha vermelha '
                . 'antes de contar com a entrega.</p>' : '')
            . '</section>';

        return $this->layout('Grupos', '/grupos', $html, $recados);
    }

    /** ID inteiro nao cabe na coluna e ninguem le; o clique copia o valor completo. */
    private function encurtarId(string $id): string
    {
        $numero = explode('@', $id)[0];

        return mb_strlen($numero) > 12
            ? mb_substr($numero, 0, 6) . '…' . mb_substr($numero, -4)
            : $numero;
    }

    /**
     * O nicho do alvo escolhido: o geral ou o de um canal.
     *
     * Reaproveita os mesmos campos e o mesmo renderizador da tela de
     * configuracao. O nicho saiu de la justamente para nao ter duas casas: com
     * canais, "o nicho" deixou de ser um so, e uma aba unica na configuracao
     * geral nao teria como dizer de qual canal estava falando.
     *
     * @param array<int,array<string,mixed>>                    $campos
     * @param array<string,mixed>                               $valores
     * @param array<string,array{nome:string,proprio:bool}>     $abas
     * @param array<int,array<string,string>>                   $recados
     */
    public function nicho(array $campos, array $valores, array $abas, string $alvo, bool $herda, array $recados): string
    {
        $lista = '';

        foreach ($campos as $campo) {
            $lista .= $this->campo($campo, $valores[$campo['chave']] ?? null);
        }

        $nomeDoAlvo = (string) ($abas[$alvo]['nome'] ?? 'Geral');

        $html = '<section class="cartao">'
            . $this->cabecalhoCartao('Nicho · ' . $nomeDoAlvo, 'o que é item deste canal')
            . '<p class="descricao">O que é (e o que não é) do assunto deste canal. A classificação sai '
            . 'do título do anúncio, não da categoria do Mercado Livre — a página de ofertas não traz '
            . 'categoria por item, mas sempre traz o título.</p>'
            . $this->abasDeNicho($abas, $alvo)
            . $this->avisoDeNicho($alvo, $herda, $abas)
            . ($herda
                ? '<div class="campos travados">' . $lista . '</div>'
                : '<form method="post">'
                    . '<input type="hidden" name="acao" value="salvar-nicho">'
                    . '<input type="hidden" name="alvo" value="' . $this->e($alvo) . '">'
                    . '<div class="campos">' . $lista . '</div>'
                    . '<div class="barra-acoes">'
                    . '<button type="submit" class="botao primario">Salvar nicho</button>'
                    . ($alvo !== ''
                        ? '<button type="submit" class="botao perigo" name="acao" value="nicho-geral" '
                            . 'formnovalidate>Voltar ao nicho geral</button>'
                        : '')
                    . '</div></form>')
            . '</section>';

        return $this->layout('Nicho', '/nicho', $html, $recados);
    }

    /**
     * @param array<string,array{nome:string,proprio:bool}> $abas
     */
    private function avisoDeNicho(string $alvo, bool $herda, array $abas): string
    {
        if ($alvo === '') {
            $usam = [];

            foreach ($abas as $id => $aba) {
                if ($id !== '' && !$aba['proprio']) {
                    $usam[] = $aba['nome'];
                }
            }

            return $usam === []
                ? '<div class="recado atencao"><span>Nenhum canal usa o nicho geral hoje — todos têm o '
                    . 'seu. Editar aqui não muda nada até algum canal voltar a usá-lo.</span></div>'
                : '<p class="dica">Usado por: <strong>' . $this->e(implode(', ', $usam)) . '</strong>.</p>';
        }

        if (!$herda) {
            return '';
        }

        return '<div class="recado atencao">'
            . '<span>Este canal usa o <strong>nicho geral</strong> — ou seja, publica o mesmo assunto de '
            . 'quem também o usa. Os campos abaixo são só leitura.</span>'
            . '</div>'
            . '<form method="post" class="barra-acoes">'
            . '<input type="hidden" name="acao" value="nicho-proprio">'
            . '<input type="hidden" name="alvo" value="' . $this->e($alvo) . '">'
            . '<button type="submit" class="botao primario">Criar nicho próprio deste canal</button>'
            . '</form>';
    }

    /**
     * @param array<string,array{nome:string,proprio:bool}> $abas
     */
    private function abasDeNicho(array $abas, string $alvo): string
    {
        if (count($abas) < 2) {
            return '';
        }

        $html = '<nav class="abas-fila">';

        foreach ($abas as $id => $aba) {
            $html .= '<a href="' . ($id === '' ? '/nicho' : '/nicho?alvo=' . rawurlencode($id)) . '"'
                . ($alvo === $id ? ' class="atual"' : '') . '>'
                . $this->e($aba['nome'])
                . ($id !== '' && !$aba['proprio'] ? ' <span class="etiqueta">herda</span>' : '')
                . '</a>';
        }

        return $html . '</nav>';
    }

    /**
     * A fila de publicacao, filtravel por canal.
     *
     * @param array<int,array{produto:Produto,canal:string,canalId:string,tipo:string,furado:bool}> $linhas
     * @param array<string,array{nome:string,grupos:string[],ativo:bool,total:int}>                 $abas
     * @param array<int,array<string,string>>                                                       $recados
     */
    public function fila(array $linhas, array $abas, string $escolha, array $recados): string
    {
        $corpo   = '';
        $tipos   = [];
        $furados = 0;

        foreach ($linhas as $linha) {
            $produto = $linha['produto'];
            $tipo    = (string) $linha['tipo'];
            $furado  = (bool) ($linha['furado'] ?? false);

            if ($tipo !== '') {
                $tipos[$tipo] = ($tipos[$tipo] ?? 0) + 1;
            }

            if ($furado) {
                $furados++;
            }

            $busca = $this->e(mb_strtolower(
                $produto->titulo . ' ' . $tipo . ' ' . $linha['canal'] . ' ' . $produto->mlId,
            ));

            $corpo .= '<tr data-busca="' . $busca . '"' . ($furado ? ' class="furado"' : '') . '>'
                . '<td class="acoes-linha">' . $this->botaoDeFurar($linha)
                . $this->menuDeDescarte($linha) . '</td>'
                . '<td><span class="nota-produto">' . number_format($produto->pontuacao, 0) . '</span></td>'
                . ($escolha === '' ? '<td><span class="etiqueta">'
                    . $this->e($linha['canal']) . '</span></td>' : '')
                . '<td><div class="com-foto">'
                . $this->miniatura($produto->thumb, $produto->titulo)
                . '<a href="' . $this->e($produto->link()) . '" target="_blank" rel="noreferrer">'
                . $this->e(Str::limitar($produto->titulo, 50)) . '</a>'
                . '</div></td>'
                . '<td>' . ($tipo !== ''
                    ? '<span class="tipo-produto">' . $this->e($tipo) . '</span>'
                    : '<span class="auxiliar">—</span>') . '</td>'
                . '<td class="num">' . $this->e(Str::dinheiro($produto->preco)) . '</td>'
                . '<td class="num riscado">' . ($produto->precoOriginal > 0
                    ? $this->e(Str::dinheiro($produto->precoOriginal)) : '—') . '</td>'
                . '<td class="num">' . $this->e(Str::percentual($produto->desconto())) . '</td>'
                . '<td class="num">' . $this->e(Str::percentual($produto->comissao, 1)) . '</td>'
                . '<td class="num">' . $this->e(Str::dinheiro($produto->ganhoEstimado)) . '</td>'
                . '</tr>';
        }

        $cabecalhos = ['', 'Nota'];

        if ($escolha === '') {
            $cabecalhos[] = 'Canal';
        }

        array_push($cabecalhos, 'Produto', 'Tipo', 'Preço', 'De', 'Desconto', 'Comissão', 'Ganho');

        $html = '<section class="cartao">'
            . $this->cabecalhoCartao(
                'Fila de publicação',
                $escolha === ''
                    ? count($linhas) . ' das melhores, de todos os canais'
                    : count($linhas) . ' aguardando',
            )
            . '<p class="descricao">Ofertas aprovadas esperando a vez, da melhor para a pior. '
            . 'A fila é revalidada contra os filtros atuais toda vez que é consultada.</p>'
            . $this->abasDeFila($abas, $escolha)
            . '<div class="filtro-grupos">'
            . '<input type="search" id="busca-fila" placeholder="Filtrar por produto, tipo ou canal..." autocomplete="off">'
            . '<span class="auxiliar" id="conta-fila">' . count($linhas) . ' na lista</span>'
            . ($furados > 0 ? '<span class="etiqueta atencao">' . $furados . ' furando a fila</span>' : '')
            . '</div>'
            . '<form method="post" action="' . $this->e($escolha === '' ? '/fila' : '/fila?canal=' . rawurlencode($escolha)) . '">'
            . '<input type="hidden" name="acao" value="fila-ordem">'
            . $this->tabela(
                $cabecalhos,
                $corpo,
                'Fila vazia. Rode php bin/mlgroup ciclo para coletar.',
                count($cabecalhos),
            )
            . '</form>'
            . '<p class="dica">A seta manda a oferta para o início da fila — ela sai na próxima '
            . 'publicação, na frente de quem tem nota melhor. Clique de novo para desfazer.</p>'
            . $this->resumoDeTipos($tipos)
            . '</section>';

        return $this->layout('Fila', '/fila', $html, $recados);
    }

    /**
     * O botao de furar a fila, ou de desfazer.
     *
     * O valor carrega canal e produto juntos: na aba "Todos" a mesma tabela tem
     * linhas de canais diferentes, e um campo escondido unico do formulario
     * mandaria todas para o canal errado.
     *
     * @param array{produto:Produto,canalId:string,furado:bool} $linha
     */
    private function botaoDeFurar(array $linha): string
    {
        $alvo   = $this->e($linha['canalId'] . '|' . $linha['produto']->mlId);
        $furado = (bool) ($linha['furado'] ?? false);

        if ($furado) {
            return '<button type="submit" name="liberar" value="' . $alvo . '" class="furar ativo"'
                . ' data-dica="Está furando a fila. Clique para voltar à ordem por nota."'
                . ' aria-label="Devolver à ordem normal">&#9650;</button>';
        }

        return '<button type="submit" name="furar" value="' . $alvo . '" class="furar"'
            . ' data-dica="Mandar para o início da fila"'
            . ' aria-label="Mandar para o início da fila">&#9650;</button>';
    }

    /**
     * O menu de descarte da linha.
     *
     * Usa <details>, que abre e fecha sozinho no navegador - sem JavaScript,
     * entao continua funcionando se o script quebrar. As tres opcoes so
     * aparecem quando ha o que descartar: produto sem marca reconhecida ou sem
     * vendedor nao mostra um botao que nao faria nada.
     *
     * @param array{produto:Produto,canalId:string,marca:string,vendedor:string} $linha
     */
    private function menuDeDescarte(array $linha): string
    {
        $produto  = $linha['produto'];
        $canal    = (string) $linha['canalId'];
        $marca    = trim((string) ($linha['marca'] ?? ''));
        $vendedor = trim((string) ($linha['vendedor'] ?? ''));

        $opcoes = '<button type="submit" name="descartar" value="'
            . $this->e('produto|' . $canal . '|' . $produto->mlId) . '">'
            . 'Só este anúncio</button>';

        if ($marca !== '') {
            $opcoes .= '<button type="submit" name="descartar" value="'
                . $this->e('marca|' . $canal . '|' . $marca) . '">'
                . 'Tudo da marca <strong>' . $this->e($marca) . '</strong></button>';
        }

        if ($vendedor !== '') {
            $opcoes .= '<button type="submit" name="descartar" value="'
                . $this->e('vendedor|' . $canal . '|' . $vendedor) . '">'
                . 'Tudo do vendedor <strong>' . $this->e(Str::limitar($vendedor, 24)) . '</strong></button>';
        }

        return '<details class="descartar">'
            . '<summary data-dica="Não quero mais ver isto" aria-label="Descartar">&#10005;</summary>'
            . '<div class="menu-descarte">' . $opcoes . '</div></details>';
    }

    /**
     * A lista do que foi descartado, com o desfazer.
     *
     * Sem esta tela o descarte seria uma porta de mao unica: um clique errado
     * numa marca sumiria com dezenas de ofertas boas e nao haveria onde ver o
     * que aconteceu.
     *
     * @param array<int,array<string,mixed>>                                        $itens
     * @param array<string,array{nome:string,grupos:string[],ativo:bool,total:int}> $abas
     * @param array<int,array<string,string>>                                       $recados
     */
    public function descartes(array $itens, array $abas, string $escolha, array $recados): string
    {
        $comoSeChama = [
            'produto'  => 'Anúncio',
            'marca'    => 'Marca',
            'vendedor' => 'Vendedor',
        ];

        $corpo = '';

        foreach ($itens as $item) {
            $tipo   = (string) $item['tipo'];
            $rotulo = (string) ($item['rotulo'] ?: $item['valor']);

            $corpo .= '<tr>'
                . '<td><span class="etiqueta">' . $this->e($comoSeChama[$tipo] ?? $tipo) . '</span></td>'
                . ($escolha === '' ? '<td>' . $this->e((string) $item['canal']) . '</td>' : '')
                . '<td>' . $this->e($rotulo) . '</td>'
                . '<td class="auxiliar">' . $this->e(date('d/m/Y H:i', (int) strtotime((string) $item['criado_em']))) . '</td>'
                . '<td><button type="submit" name="redescartar" value="'
                . $this->e($item['canalId'] . '|' . $item['id']) . '" class="botao fantasma">'
                . 'Desfazer</button></td>'
                . '</tr>';
        }

        $cabecalhos = ['O quê'];

        if ($escolha === '') {
            $cabecalhos[] = 'Canal';
        }

        array_push($cabecalhos, 'Descartado', 'Quando', '');

        $html = '<section class="cartao">'
            . $this->cabecalhoCartao('Descartes', count($itens) . ' regra(s)')
            . '<p class="descricao">O que você mandou não aparecer mais. Vale só para o canal em que '
            . 'foi descartado, e some da fila na hora — desfazer traz de volta na próxima coleta.</p>'
            . $this->abasDeFila($abas, $escolha, '/descartes')
            . '<form method="post" action="' . $this->e($escolha === '' ? '/descartes' : '/descartes?canal=' . rawurlencode($escolha)) . '">'
            . '<input type="hidden" name="acao" value="redescartar">'
            . $this->tabela(
                $cabecalhos,
                $corpo,
                'Nada descartado ainda. O botão ✕ na fila descarta um anúncio, uma marca ou um vendedor.',
                count($cabecalhos),
            )
            . '</form></section>';

        return $this->layout('Descartes', '/descartes', $html, $recados);
    }

    /**
     * As abas de canal, com quanto cada fila tem.
     *
     * O total vem da fila inteira, nao do que a tela mostra: saber que um canal
     * tem 3 na fila enquanto o outro tem 200 e o motivo de existir esta aba.
     *
     * @param array<string,array{nome:string,grupos:string[],ativo:bool,total:int}> $abas
     */
    private function abasDeFila(array $abas, string $escolha, string $base = '/fila'): string
    {
        if (count($abas) < 2) {
            return '';
        }

        // o numero de "Todos" precisa bater com o que "Todos" mostra
        $total = 0;

        foreach ($abas as $id => $aba) {
            if ($id !== 'padrao') {
                $total += (int) $aba['total'];
            }
        }

        $html = '<nav class="abas-fila">'
            . '<a href="' . $base . '"' . ($escolha === '' ? ' class="atual"' : '') . '>'
            . 'Todos' . ($base === '/fila' ? ' <span class="conta">' . $total . '</span>' : '')
            . '</a>';

        foreach ($abas as $id => $aba) {
            // 'padrao' nao e canal: nao tem grupo nem liga/desliga
            $legado = $id === 'padrao';

            if ($legado) {
                $dica = 'coletado antes dos canais existirem; nao entra em nenhuma publicacao';
            } else {
                $dica = $aba['grupos'] === []
                    ? 'sem grupo configurado'
                    : count($aba['grupos']) . ' grupo(s): ' . implode(', ', $aba['grupos']);
            }

            $html .= '<a href="' . $base . '?canal=' . rawurlencode($id) . '"'
                . ($escolha === $id ? ' class="atual"' : '')
                . ' data-dica="' . $this->e($dica) . '">'
                . $this->e($aba['nome'])
                . (!$legado && !$aba['ativo'] ? ' <span class="etiqueta">pausado</span>' : '')
                . ($base === '/fila' ? ' <span class="conta">' . (int) $aba['total'] . '</span>' : '')
                . '</a>';
        }

        return $html . '</nav>';
    }

    /**
     * Os tipos mais repetidos na fila.
     *
     * A regra de diversidade segura ofertas do mesmo tipo em sequencia; quando a
     * fila e quase toda de um tipo so, o rodizio nao tem de onde tirar variedade
     * e as publicacoes espacam. Ver a concentracao aqui explica isso antes de
     * parecer defeito.
     *
     * @param array<string,int> $tipos
     */
    private function resumoDeTipos(array $tipos): string
    {
        if ($tipos === []) {
            return '';
        }

        arsort($tipos);

        $html  = '<p class="dica">Tipos na fila: ';
        $peca  = [];
        $sobra = 0;

        foreach ($tipos as $tipo => $quantos) {
            if (count($peca) < 8) {
                $peca[] = $this->e($tipo) . ' <strong>' . $quantos . '</strong>';

                continue;
            }

            $sobra++;
        }

        $html .= implode(' · ', $peca);

        return $html . ($sobra > 0 ? ' · e mais ' . $sobra . ' tipo(s)' : '') . '</p>';
    }

    public function mensagem(string $texto, array $recados): string
    {
        $html = '<section class="cartao">'
            . $this->cabecalhoCartao('Prévia da mensagem', 'Próxima da fila')
            . '<p class="descricao">Como a oferta chega no grupo. O texto vem de '
            . '<code>templates/oferta.txt</code>.</p>';

        $html .= $texto === ''
            ? '<p class="grafico-vazio">Fila vazia — nada para pré-visualizar.</p>'
            : '<div class="conversa"><div class="balao">' . nl2br($this->e($texto))
                . '<span class="hora-balao">' . date('H:i') . '</span></div></div>';

        $html .= '</section>';

        return $this->layout('Mensagem', '/mensagem', $html, $recados);
    }

    // ------------------------------------------------------------------
    // Peças
    // ------------------------------------------------------------------

    private function cabecalhoCartao(string $titulo, string $auxiliar): string
    {
        return '<header class="cartao-topo"><h2>' . $this->e($titulo) . '</h2>'
            . ($auxiliar !== '' ? '<span class="auxiliar">' . $this->e($auxiliar) . '</span>' : '')
            . '</header>';
    }

    private function tabela(array $cabecalhos, string $corpo, string $vazio, int $colunas, string $classe = ''): string
    {
        $html = '<div class="rolagem"><table class="' . $classe . '"><thead><tr>';

        foreach ($cabecalhos as $indice => $cabecalho) {
            $alinhamento = $indice > 0 && $indice < count($cabecalhos) - 1 ? '' : '';

            $html .= '<th' . $alinhamento . '>' . $this->e($cabecalho) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        $html .= $corpo !== ''
            ? $corpo
            : '<tr><td colspan="' . $colunas . '" class="vazio">' . $this->e($vazio) . '</td></tr>';

        return $html . '</tbody></table></div>';
    }

    private function selo(string $texto, bool $bom): string
    {
        return '<span class="selo ' . ($bom ? 'bom' : 'critico') . '">'
            . '<span class="ponto ' . ($bom ? 'bom' : 'critico') . '"></span>'
            . $this->e($texto) . '</span>';
    }

    /** @param array<string,mixed> $campo */
    private function campo(array $campo, mixed $valor, mixed $padrao = null): string
    {
        $chave  = (string) $campo['chave'];
        $tipo   = (string) $campo['tipo'];
        $nome   = 'campo[' . $chave . ']';
        $id     = 'c_' . md5($chave);
        $ajuda  = isset($campo['ajuda']) ? '<span class="ajuda">' . $this->e((string) $campo['ajuda']) . '</span>' : '';
        $classe = in_array($tipo, ['lista', 'dias'], true) ? ' largo' : '';

        $entrada = match ($tipo) {
            'booleano' => '<input type="hidden" name="' . $nome . '" value="0">'
                . '<label class="chave"><input type="checkbox" id="' . $id . '" name="' . $nome . '" value="1"'
                . ($valor ? ' checked' : '') . '><span class="trilho-chave"></span></label>',

            'lista' => '<textarea id="' . $id . '" name="' . $nome . '" rows="4">'
                . $this->e(implode("\n", array_map('strval', is_array($valor) ? $valor : []))) . '</textarea>',

            'escolha' => $this->selecao($nome, $campo['opcoes'] ?? [], (string) $valor, $id),

            'dias' => $this->dias(is_array($valor) ? array_map('intval', $valor) : []),

            'hora' => '<input type="time" id="' . $id . '" name="' . $nome . '" value="' . $this->e((string) $valor) . '">',

            'inteiro' => '<input type="number" step="1" id="' . $id . '" name="' . $nome . '" value="' . $this->e((string) $valor) . '">',

            'dinheiro', 'numero', 'percentual' => '<input type="number" step="0.01" id="' . $id . '" name="' . $nome . '" value="' . $this->e((string) $valor) . '">',

            default => '<input type="text" id="' . $id . '" name="' . $nome . '" value="' . $this->e((string) $valor) . '">',
        };

        $unidade = match ($tipo) {
            'percentual' => '<span class="unidade">%</span>',
            'dinheiro'   => '<span class="unidade">R$</span>',
            default      => '',
        };

        $alterado = ($campo['origem'] ?? 'config') !== 'env'
            && $this->paraComparar($valor) !== $this->paraComparar($padrao);

        // guarda o padrao no proprio elemento: o desfazer roda no navegador
        $desfazer = $alterado
            ? '<button type="button" class="desfazer" data-padrao="'
                . $this->e($this->paraFormulario($tipo, $padrao))
                . '" title="Voltar ao padrão do arquivo">alterado ↺</button>'
            : '';

        // texto invisivel que a busca varre junto com o rotulo
        $procuravel = $this->e(mb_strtolower(
            $campo['rotulo'] . ' ' . $chave . ' ' . ($campo['ajuda'] ?? '')
        ));

        return '<div class="campo' . $classe . ($tipo === 'booleano' ? ' interruptor' : '')
            . ($alterado ? ' alterado' : '') . '" data-busca="' . $procuravel . '">'
            . '<label for="' . $id . '">' . $this->e((string) $campo['rotulo']) . $desfazer . '</label>'
            . '<div class="entrada">' . $unidade . $entrada . '</div>'
            . $ajuda
            . '</div>';
    }

    /** O valor do padrao no formato que o campo do formulario espera. */
    private function paraFormulario(string $tipo, mixed $padrao): string
    {
        if ($tipo === 'lista') {
            return implode("
", array_map('strval', is_array($padrao) ? $padrao : []));
        }

        if ($tipo === 'booleano') {
            return $padrao ? '1' : '0';
        }

        if ($tipo === 'dias') {
            return implode(',', array_map('strval', is_array($padrao) ? $padrao : []));
        }

        return is_scalar($padrao) ? (string) $padrao : '';
    }

    private function selecao(
        string $nome,
        array $opcoes,
        string $atual,
        string $id = '',
        bool $travado = false,
    ): string {
        $html = '<select name="' . $nome . '"' . ($id !== '' ? ' id="' . $id . '"' : '')
            . ($travado ? ' disabled' : '') . '>';

        foreach ($opcoes as $valor => $rotulo) {
            $html .= '<option value="' . $this->e((string) $valor) . '"'
                . ((string) $valor === $atual ? ' selected' : '') . '>'
                . $this->e((string) $rotulo) . '</option>';
        }

        return $html . '</select>';
    }

    /** @param int[] $marcados */
    private function dias(array $marcados): string
    {
        $html = '<div class="dias">';

        foreach (Campos::diasDaSemana() as $numero => $nome) {
            $html .= '<label class="dia"><input type="checkbox" name="dias[]" value="' . $numero . '"'
                . (in_array($numero, $marcados, true) ? ' checked' : '') . '>'
                . '<span>' . $this->e($nome) . '</span></label>';
        }

        return $html . '</div>';
    }

    private function layout(string $titulo, string $rotaAtual, string $conteudo, array $recados): string
    {
        /*
         * Separado em "o dia a dia" e "ajustar".
         *
         * Oito itens numa lista lisa exigem ler todos para achar um. A divisao
         * segue a frequencia de uso: Fila e Descartes se visitam toda hora,
         * Buscas e Configuracao se mexem uma vez e se esquecem.
         */
        $menu = [
            'Acompanhar' => [
                '/'          => ['Visão geral', 'M3 10.5 12 4l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'],
                '/fila'      => ['Fila', 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01'],
                '/descartes' => ['Descartes', 'M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6'],
                '/mensagem'  => ['Mensagem', 'M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.4 8.4 0 0 1 8.4-9 8.4 8.4 0 0 1 8.6 9z'],
            ],
            'Ajustar' => [
                '/canais'   => ['Canais', 'M4 6h16M4 12h16M4 18h16M8 3v3M16 3v3'],
                '/nicho'    => ['Nicho', 'M12 2 4 6v6c0 5 3.4 9.1 8 10 4.6-.9 8-5 8-10V6z'],
                '/grupos'   => ['Grupos', 'M17 20v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 20v-2a4 4 0 0 0-3-3.9M16 2.1a4 4 0 0 1 0 7.8'],
                '/buscas'   => ['Buscas', 'M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16zM21 21l-4.35-4.35'],
                '/config'   => ['Configuração', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 15H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 7a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 9 3.6V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z'],
            ],
        ];

        $nav = '';

        foreach ($menu as $grupo => $itens) {
            $nav .= '<span class="grupo-menu">' . $this->e($grupo) . '</span>';

            foreach ($itens as $rota => [$rotulo, $traco]) {
                $nav .= '<a href="' . $rota . '"' . ($rota === $rotaAtual ? ' class="atual"' : '') . '>'
                    . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . $traco . '"/></svg>'
                    . '<span>' . $this->e($rotulo) . '</span></a>';
            }
        }

        $avisos = '';

        foreach ($recados as $recado) {
            $avisos .= '<div class="recado ' . $this->e($recado['tipo']) . '">'
                . '<span>' . $this->e($recado['texto']) . '</span>'
                . '<button type="button" class="fechar-recado" aria-label="Dispensar">&#10005;</button>'
                . '</div>';
        }

        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->e($titulo) . ' · ml-group</title>'
            . '<link rel="icon" type="image/png" href="/logo.png">'
            /*
             * O tema e aplicado antes do CSS, no <head>. Fazendo isso depois, a
             * pagina pinta clara e escurece um quadro adiante - o "flash branco"
             * que incomoda justamente quem escolheu o tema escuro.
             */
            . '<script>(function(){try{var t=localStorage.getItem("mlgroup:tema");'
            . 'if(t==="claro"||t==="escuro"){document.documentElement.setAttribute("data-theme",'
            . 't==="claro"?"light":"dark");}}catch(e){}})();</script>'
            . '<style>' . $this->estilo() . '</style></head><body>'
            . '<aside class="lateral">'
            . '<div class="marca"><img class="logo" src="/logo.png" alt="" width="96" height="96">'
            . '<div><strong>ml-group</strong>'
            . '<small>ofertas para oficina</small></div></div>'
            . '<nav>' . $nav . '</nav>'
            . '<div class="rodape-lateral">Painel local · 127.0.0.1</div>'
            . '</aside>'
            . '<div class="area">'
            . '<header class="topo"><h1>' . $this->e($titulo) . '</h1>'
            . '<button type="button" id="tema" class="botao fantasma" data-dica="Tema: sistema, claro ou escuro">'
            . '<span id="tema-rotulo">Tema</span></button>'
            . '</header>'
            . '<main>' . $avisos . $conteudo . '</main>'
            . '</div>'
            . '<div id="dica" class="dica-flutuante" hidden></div>'
            . '<script>' . $this->script() . '</script>'
            . '</body></html>';
    }

    private function script(): string
    {
        return <<<'JS'
        (function () {
            var dica = document.getElementById('dica');

            document.addEventListener('mouseover', function (evento) {
                var alvo = evento.target.closest('[data-dica]');

                if (!alvo) { return; }

                dica.textContent = alvo.getAttribute('data-dica');
                dica.hidden = false;
            });

            document.addEventListener('mousemove', function (evento) {
                if (dica.hidden) { return; }

                var x = Math.min(evento.clientX + 14, window.innerWidth - dica.offsetWidth - 10);

                dica.style.left = x + 'px';
                dica.style.top = (evento.clientY - dica.offsetHeight - 12) + 'px';
            });

            document.addEventListener('mouseout', function (evento) {
                if (evento.target.closest('[data-dica]')) { dica.hidden = true; }
            });

            // ---------- tema ----------

            /*
             * Tres estados, nao dois: "sistema" e o padrao e precisa continuar
             * existindo. Quem usa o computador no claro de dia e no escuro de
             * noite quer seguir o sistema operacional - um alternador so
             * claro/escuro tira essa opcao de quem ja a tinha.
             */
            var botaoTema = document.getElementById('tema');
            var rotuloTema = document.getElementById('tema-rotulo');
            var temas = ['sistema', 'claro', 'escuro'];
            var nomes = { sistema: 'Tema do sistema', claro: 'Tema claro', escuro: 'Tema escuro' };

            function lerTema() {
                try {
                    var salvo = localStorage.getItem('mlgroup:tema');

                    return temas.indexOf(salvo) !== -1 ? salvo : 'sistema';
                } catch (erro) { return 'sistema'; }
            }

            function aplicarTema(tema) {
                if (tema === 'sistema') {
                    document.documentElement.removeAttribute('data-theme');
                } else {
                    document.documentElement.setAttribute('data-theme', tema === 'claro' ? 'light' : 'dark');
                }

                if (rotuloTema) { rotuloTema.textContent = nomes[tema]; }

                try { localStorage.setItem('mlgroup:tema', tema); } catch (erro) {}
            }

            if (botaoTema) {
                aplicarTema(lerTema());

                botaoTema.addEventListener('click', function () {
                    aplicarTema(temas[(temas.indexOf(lerTema()) + 1) % temas.length]);
                });
            }

            // ---------- recados ----------
            document.addEventListener('click', function (evento) {
                var fechar = evento.target.closest('.fechar-recado');

                if (fechar) { fechar.parentNode.remove(); }
            });

            /*
             * So o recado de sucesso some sozinho. Aviso e erro ficam ate alguem
             * fechar: sumir com "furou a fila, mas nao vai ser publicado" depois
             * de seis segundos e o mesmo que nao ter avisado.
             */
            [].slice.call(document.querySelectorAll('.recado.ok')).forEach(function (recado) {
                setTimeout(function () {
                    recado.classList.add('saindo');
                    setTimeout(function () { recado.remove(); }, 400);
                }, 6000);
            });

            // ---------- filtro de tabela (grupos e fila) ----------

            /*
             * Filtra as linhas pelo data-busca que o PHP ja montou, sem ir ao
             * servidor: a tabela inteira ja esta na pagina, e recarregar so para
             * esconder linha custa uma consulta ao WhatsApp ou ao banco.
             */
            function filtrarTabela(idBusca, idConta, rotulo, aoContar) {
                var campo = document.getElementById(idBusca);

                if (!campo) { return null; }

                var conta  = document.getElementById(idConta);
                var linhas = [].slice.call(document.querySelectorAll('tbody tr[data-busca]'));

                function aplicar() {
                    var termo = campo.value.trim().toLowerCase();
                    var vistos = 0;

                    linhas.forEach(function (linha) {
                        var casa = termo === '' || linha.getAttribute('data-busca').indexOf(termo) !== -1;

                        linha.hidden = !casa;

                        if (casa) { vistos++; }
                    });

                    if (conta) {
                        conta.textContent = aoContar ? aoContar(linhas, termo, vistos) : (vistos + ' ' + rotulo);
                    }
                }

                campo.addEventListener('input', aplicar);

                // "/" foca a busca, como na tela de configuracao
                document.addEventListener('keydown', function (evento) {
                    if (evento.key === '/' && document.activeElement.tagName !== 'INPUT'
                        && document.activeElement.tagName !== 'SELECT'
                        && document.activeElement.tagName !== 'TEXTAREA') {
                        evento.preventDefault();
                        campo.focus();
                    }
                });

                return { linhas: linhas, aplicar: aplicar };
            }

            var fila = filtrarTabela('busca-fila', 'conta-fila', 'na lista');

            var grupos = filtrarTabela('busca-grupos', 'conta-grupos', 'com destino',
                function (linhas, termo, vistos) {
                    /*
                     * Aqui a conta util nao e "quantas linhas aparecem", e sim
                     * quantos grupos tem destino - enquanto o usuario nao busca
                     * nada. Buscando, volta a contar o que esta na tela.
                     */
                    if (termo !== '') { return vistos + ' na busca'; }

                    var comDestino = 0;

                    linhas.forEach(function (linha) {
                        var seletor = linha.querySelector('select');
                        var tem = !!seletor && seletor.value !== '';

                        if (tem) { comDestino++; }

                        linha.classList.toggle('usado', tem);
                    });

                    return comDestino + ' com destino';
                });

            if (grupos) {
                document.addEventListener('change', function (evento) {
                    if (evento.target.closest('tbody tr[data-busca]')) { grupos.aplicar(); }
                });
            }

            document.addEventListener('click', function (evento) {
                var botao = evento.target.closest('.id-grupo');

                if (!botao) { return; }

                evento.preventDefault();

                var id = botao.getAttribute('data-id');
                var original = botao.textContent;

                function avisar() {
                    botao.textContent = 'copiado';
                    botao.classList.add('copiado');
                    setTimeout(function () {
                        botao.textContent = original;
                        botao.classList.remove('copiado');
                    }, 1200);
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(id).then(avisar, function () {});

                    return;
                }

                // navegador antigo ou contexto sem clipboard
                var campo = document.createElement('textarea');

                campo.value = id;
                document.body.appendChild(campo);
                campo.select();

                try { document.execCommand('copy'); avisar(); } catch (erro) {}

                document.body.removeChild(campo);
            });

            // ---------- tela de configuracao ----------
            var form = document.getElementById('form-config');

            if (!form) { return; }

            var abas    = [].slice.call(document.querySelectorAll('.aba'));
            var paineis = [].slice.call(document.querySelectorAll('.painel-aba'));
            var busca   = document.getElementById('busca-campo');
            var vazio   = document.querySelector('.nada-encontrado');
            var chaveAba = 'mlgroup:aba-config';

            function mostrarAba(id) {
                abas.forEach(function (a) { a.classList.toggle('atual', a.dataset.aba === id); });
                paineis.forEach(function (p) { p.classList.toggle('oculto', p.dataset.painel !== id); });

                try { localStorage.setItem(chaveAba, id); } catch (e) {}
            }

            abas.forEach(function (aba) {
                aba.addEventListener('click', function () {
                    if (busca.value) { busca.value = ''; filtrar(); }
                    mostrarAba(aba.dataset.aba);
                });
            });

            // volta na aba onde a pessoa estava
            try {
                var salva = localStorage.getItem(chaveAba);
                if (salva && document.querySelector('[data-painel="' + salva + '"]')) { mostrarAba(salva); }
            } catch (e) {}

            // busca atravessa as abas: procurar um ajuste nao deveria exigir
            // saber em qual secao ele mora
            function filtrar() {
                var termo = busca.value.trim().toLowerCase();

                if (!termo) {
                    document.querySelectorAll('.campo').forEach(function (c) { c.classList.remove('escondido'); });
                    vazio.classList.add('oculto');
                    mostrarAba(document.querySelector('.aba.atual').dataset.aba);

                    return;
                }

                var achou = 0;

                paineis.forEach(function (painel) {
                    var visiveis = 0;

                    painel.querySelectorAll('.campo').forEach(function (campo) {
                        var bate = (campo.dataset.busca || '').indexOf(termo) !== -1;

                        campo.classList.toggle('escondido', !bate);

                        if (bate) { visiveis++; }
                    });

                    painel.classList.toggle('oculto', visiveis === 0);
                    achou += visiveis;
                });

                vazio.classList.toggle('oculto', achou > 0);
            }

            busca.addEventListener('input', filtrar);

            // desfazer campo a campo, sem precisar restaurar tudo
            form.addEventListener('click', function (evento) {
                var botao = evento.target.closest('.desfazer');

                if (!botao) { return; }

                var campo = botao.closest('.campo');
                var alvo  = campo.querySelector('input:not([type=hidden]), select, textarea');
                var valor = botao.dataset.padrao;

                if (alvo.type === 'checkbox') {
                    alvo.checked = valor === '1';
                } else if (campo.querySelector('.dias')) {
                    var marcados = valor.split(',');
                    campo.querySelectorAll('.dias input').forEach(function (d) {
                        d.checked = marcados.indexOf(d.value) !== -1;
                    });
                } else {
                    alvo.value = valor;
                }

                campo.classList.remove('alterado');
                botao.remove();
                marcarPendentes();
            });

            // conta o que mudou desde que a pagina abriu
            var inicial = new FormData(form);
            var aviso   = document.querySelector('.pendentes');

            function marcarPendentes() {
                var agora = new FormData(form);
                var mudou = 0;

                var chaves = new Set();
                for (var par of inicial.keys()) { chaves.add(par); }
                for (var par2 of agora.keys()) { chaves.add(par2); }

                chaves.forEach(function (chave) {
                    if (String(inicial.getAll(chave)) !== String(agora.getAll(chave))) { mudou++; }
                });

                aviso.textContent = mudou === 1 ? '1 alteração não salva' : mudou + ' alterações não salvas';
                aviso.classList.toggle('oculto', mudou === 0);
            }

            form.addEventListener('input', marcarPendentes);
            form.addEventListener('change', marcarPendentes);

            // Ctrl+S salva, como em qualquer editor
            document.addEventListener('keydown', function (evento) {
                if ((evento.ctrlKey || evento.metaKey) && evento.key === 's') {
                    evento.preventDefault();
                    form.submit();
                }
            });
        })();
        JS;
    }

    private function e(mixed $texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    private function estilo(): string
    {
        return <<<'CSS'
        *, *::before, *::after { box-sizing: border-box; }

        :root {
            color-scheme: light;
            --fundo: #f3f4f6;
            --papel: #ffffff;
            --papel-2: #f9fafb;
            --borda: #e4e7ec;
            --borda-forte: #d0d5dd;
            --tinta-1: #101828;
            --tinta-2: #475467;
            --tinta-3: #98a2b3;
            --acento: #2a78d6;
            --serie-1: #2a78d6;
            --ord-1: #86b6ef;
            --ord-2: #2a78d6;
            --ord-3: #184f95;
            --bom: #0ca30c;
            --aviso: #fab219;
            --critico: #d03b3b;
            --sombra: 0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.04);
        }

        /*
         | O escuro precisa das DUAS regras.
         |
         | So a media query atende quem segue o sistema, e era o unico caso que
         | existia. O botao de tema criou o outro: escolher "escuro" numa maquina
         | configurada no claro nao mudava nada, porque nenhuma regra respondia a
         | [data-theme="dark"] - o atributo era escrito no HTML e o CSS o
         | ignorava.
         */
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                color-scheme: dark;
                --fundo: #0e0f11;
                --papel: #1a1a19;
                --papel-2: #202022;
                --borda: #2e2e2c;
                --borda-forte: #3d3d3a;
                --tinta-1: #ffffff;
                --tinta-2: #c3c2b7;
                --tinta-3: #8b8a82;
                --acento: #3987e5;
                --serie-1: #3987e5;
                --ord-1: #9ec5f4;
                --ord-2: #3987e5;
                --ord-3: #184f95;
                --sombra: none;
            }
        }

        :root[data-theme="dark"] {
                color-scheme: dark;
                --fundo: #0e0f11;
                --papel: #1a1a19;
                --papel-2: #202022;
                --borda: #2e2e2c;
                --borda-forte: #3d3d3a;
                --tinta-1: #ffffff;
                --tinta-2: #c3c2b7;
                --tinta-3: #8b8a82;
                --acento: #3987e5;
                --serie-1: #3987e5;
                --ord-1: #9ec5f4;
                --ord-2: #3987e5;
                --ord-3: #184f95;
                --sombra: none;
        }

        body {
            margin: 0;
            display: grid;
            grid-template-columns: 236px 1fr;
            min-height: 100vh;
            background: var(--fundo);
            color: var(--tinta-1);
            font: 14px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        /* ---------- barra lateral ---------- */
        .lateral {
            background: var(--papel);
            border-right: 1px solid var(--borda);
            padding: 20px 14px;
            display: flex; flex-direction: column; gap: 26px;
            position: sticky; top: 0; height: 100vh;
        }
        /* logo em cima, nome embaixo */
        .marca {
            display: flex; flex-direction: column; align-items: center; gap: 9px;
            padding: 2px 8px 4px; text-align: center;
        }
        .logo {
            width: 96px; height: 96px; border-radius: 20px; flex: none;
            /*
             * object-fit para o logo nao deformar: o arquivo e quadrado hoje,
             * mas trocar por um retangular nao deveria esticar a imagem.
             */
            object-fit: contain; background: var(--papel-2);
        }
        .marca strong { display: block; font-size: 14px; letter-spacing: -.01em; }
        .marca small { display: block; font-size: 11.5px; color: var(--tinta-3); }

        .lateral nav { display: flex; flex-direction: column; gap: 2px; }
        .lateral nav a {
            display: flex; align-items: center; gap: 11px;
            padding: 9px 11px; border-radius: 8px;
            color: var(--tinta-2); text-decoration: none;
            font-size: 13.5px; font-weight: 500;
        }
        .lateral nav a svg { width: 17px; height: 17px; flex: none; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
        .lateral nav a:hover { background: var(--papel-2); color: var(--tinta-1); }
        .lateral nav a.atual { background: color-mix(in srgb, var(--acento) 11%, transparent); color: var(--acento); }
        .rodape-lateral { margin-top: auto; padding: 0 10px; font-size: 11.5px; color: var(--tinta-3); }

        /* ---------- área ---------- */
        .area { min-width: 0; display: flex; flex-direction: column; }
        .topo {
            background: var(--papel); border-bottom: 1px solid var(--borda);
            padding: 17px 28px; position: sticky; top: 0; z-index: 5;
        }
        .topo h1 { margin: 0; font-size: 19px; font-weight: 600; letter-spacing: -.02em; }
        /*
         * Sem teto de largura: o painel ocupa a tela inteira.
         *
         * O 1240px vinha da regra de nao esticar texto ate ficar ilegivel - mas
         * aplicado ao <main> inteiro ele deixava um terco de monitor largo em
         * branco, com tabela e grafico espremidos ao lado. O limite passa a
         * valer so onde o problema existe de verdade: nos paragrafos.
         */
        main { padding: 22px 28px 90px; width: 100%; }

        /* linha longa demais cansa de ler; o resto pode ocupar o que tiver */
        .descricao, .dica, .faixa-saude li { max-width: 96ch; }

        /* ---------- indicadores ---------- */
        .indicadores { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); margin-bottom: 16px; }
        .indicador {
            background: var(--papel); border: 1px solid var(--borda); border-radius: 11px;
            padding: 15px 16px 12px; box-shadow: var(--sombra);
            display: flex; flex-direction: column; gap: 3px; position: relative; overflow: hidden;
        }
        .indicador-rotulo { font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--tinta-3); }
        .indicador-valor { font-size: 27px; font-weight: 600; letter-spacing: -.03em; line-height: 1.15; display: flex; align-items: center; gap: 8px; }
        .indicador-valor.bom { color: var(--bom); } .indicador-valor.critico { color: var(--critico); }
        .indicador-nota { font-size: 12px; color: var(--tinta-3); }
        .indicador .faisca { margin-top: 8px; width: 100%; height: 30px; display: block; }
        .faisca-linha { fill: none; stroke: var(--serie-1); stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; vector-effect: non-scaling-stroke; }
        .faisca-area { fill: color-mix(in srgb, var(--serie-1) 13%, transparent); stroke: none; }

        .ponto { width: 8px; height: 8px; border-radius: 50%; flex: none; display: inline-block; }
        .ponto.bom { background: var(--bom); } .ponto.critico { background: var(--critico); }

        /* ---------- cartões ---------- */
        .cartao {
            background: var(--papel); border: 1px solid var(--borda); border-radius: 11px;
            padding: 18px 20px 20px; margin-bottom: 16px; box-shadow: var(--sombra);
            scroll-margin-top: 76px; min-width: 0;
        }
        .cartao-topo { display: flex; align-items: baseline; justify-content: space-between; gap: 14px; margin-bottom: 14px; }
        .cartao-topo h2 { margin: 0; font-size: 15px; font-weight: 600; letter-spacing: -.01em; }
        .auxiliar { font-size: 12px; color: var(--tinta-3); }
        .descricao { margin: -6px 0 16px; color: var(--tinta-2); font-size: 13px; max-width: 74ch; }
        .grade { display: grid; gap: 16px; align-items: start; }
        .grade.principal { grid-template-columns: 1.9fr 1fr; }
        .grade.secundaria { grid-template-columns: 1fr 1.9fr; }

        /* ---------- gráficos ---------- */
        .area-grafico { position: relative; }
        .grafico { width: 100%; height: 180px; display: block; overflow: visible; }
        .grade { stroke: var(--borda); stroke-width: 1; vector-effect: non-scaling-stroke; }
        .eixo { stroke: var(--borda-forte); stroke-width: 1; vector-effect: non-scaling-stroke; }
        .barra { fill: var(--serie-1); transition: fill .12s; }
        .barra:hover { fill: color-mix(in srgb, var(--serie-1) 78%, var(--tinta-1)); }
        .barra.vazia { fill: var(--borda-forte); }
        .eixo-x { display: flex; margin-top: 6px; }
        .eixo-x span { flex: 1; text-align: center; font-size: 11px; color: var(--tinta-3); white-space: nowrap; }
        .grafico-vazio { color: var(--tinta-3); font-size: 13px; text-align: center; padding: 30px 0; margin: 0; }

        .funil { display: flex; flex-direction: column; gap: 15px; }
        .etapa-topo { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin-bottom: 5px; }
        .etapa-nome { font-size: 13px; font-weight: 550; }
        .etapa-valor { font-size: 15px; font-weight: 600; font-variant-numeric: tabular-nums; letter-spacing: -.02em; }
        .trilho { height: 9px; background: var(--papel-2); border-radius: 5px; overflow: hidden; }
        .preenchimento { height: 100%; border-radius: 5px; }
        .preenchimento.nivel-1 { background: var(--ord-1); }
        .preenchimento.nivel-2 { background: var(--ord-2); }
        .preenchimento.nivel-3 { background: var(--ord-3); }
        .etapa-nota { display: block; margin-top: 5px; font-size: 11.5px; color: var(--tinta-3); }

        .dica-flutuante {
            position: fixed; z-index: 50; pointer-events: none;
            background: var(--tinta-1); color: var(--papel);
            padding: 5px 9px; border-radius: 6px; font-size: 12px; font-weight: 500;
            white-space: nowrap; box-shadow: 0 4px 12px rgba(0,0,0,.18);
        }

        /* ---------- listas ---------- */
        .propriedades { margin: 0; display: flex; flex-direction: column; gap: 11px; }
        .propriedades div { display: flex; justify-content: space-between; gap: 16px; padding-bottom: 11px; border-bottom: 1px solid var(--borda); }
        .propriedades div:last-child { border-bottom: 0; padding-bottom: 0; }
        .propriedades dt { color: var(--tinta-2); font-size: 13px; }
        .propriedades dd { margin: 0; font-weight: 550; font-size: 13px; text-align: right; }

        .rolagem { overflow-x: auto; margin: 0 -4px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th {
            text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
            color: var(--tinta-3); font-weight: 600; padding: 0 10px 8px;
            border-bottom: 1px solid var(--borda); white-space: nowrap;
        }
        td { padding: 10px; border-bottom: 1px solid var(--borda); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: 0; }
        tbody tr:hover { background: var(--papel-2); }
        td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        td.mono { font-variant-numeric: tabular-nums; color: var(--tinta-2); white-space: nowrap; }
        td.riscado { color: var(--tinta-3); text-decoration: line-through; }
        td.meio { text-align: center; }
        td a { color: var(--acento); text-decoration: none; }
        td a:hover { text-decoration: underline; }
        .vazio { color: var(--tinta-3); text-align: center; padding: 30px; }
        .nota-produto {
            display: inline-grid; place-items: center; min-width: 30px; height: 24px; padding: 0 7px;
            border-radius: 6px; font-size: 12.5px; font-weight: 600; font-variant-numeric: tabular-nums;
            background: color-mix(in srgb, var(--acento) 12%, transparent); color: var(--acento);
        }
        .editavel td { padding: 5px 6px; }
        .editavel input, .editavel select { font-size: 12.5px; padding: 6px 8px; }

        .selo { display: inline-flex; align-items: center; gap: 6px; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 550; border: 1px solid var(--borda); }
        .selo.bom { color: var(--bom); border-color: color-mix(in srgb, var(--bom) 32%, transparent); }
        .selo.critico { color: var(--critico); border-color: color-mix(in srgb, var(--critico) 32%, transparent); }

        /* ---------- formulário ---------- */
        /* ---------- abas da configuracao ---------- */
        .barra-abas {
            display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
            margin-bottom: 18px; padding-bottom: 2px;
            border-bottom: 1px solid var(--borda);
        }
        .abas { display: flex; gap: 2px; flex-wrap: wrap; flex: 1; min-width: 0; }
        .aba {
            display: flex; align-items: center; gap: 7px;
            padding: 9px 14px; border: 0; border-bottom: 2px solid transparent;
            background: none; cursor: pointer; font: inherit; font-size: 13.5px;
            font-weight: 500; color: var(--tinta-2); border-radius: 7px 7px 0 0;
        }
        .aba:hover { color: var(--tinta-1); background: var(--papel-2); }
        .aba.atual { color: var(--acento); border-bottom-color: var(--acento); font-weight: 600; }
        .aba .conta {
            display: inline-grid; place-items: center; min-width: 18px; height: 18px;
            padding: 0 5px; border-radius: 999px; font-size: 11px; font-weight: 600;
            background: color-mix(in srgb, var(--acento) 15%, transparent); color: var(--acento);
        }

        .busca-campos { position: relative; display: flex; align-items: center; }
        .busca-campos svg {
            position: absolute; left: 10px; width: 15px; height: 15px;
            fill: none; stroke: var(--tinta-3); stroke-width: 2; stroke-linecap: round;
            pointer-events: none;
        }
        .busca-campos input {
            width: 230px; padding: 7px 11px 7px 32px; font: inherit; font-size: 13px;
            color: var(--tinta-1); background: var(--papel);
            border: 1px solid var(--borda-forte); border-radius: 8px;
        }
        .oculto { display: none !important; }
        .campo.escondido { display: none; }
        .nada-encontrado { color: var(--tinta-3); font-size: 13.5px; padding: 34px; text-align: center; }

        .campo.alterado > label { color: var(--acento); }
        .desfazer {
            margin-left: 7px; padding: 1px 7px; border-radius: 999px; cursor: pointer;
            border: 1px solid color-mix(in srgb, var(--acento) 35%, transparent);
            background: color-mix(in srgb, var(--acento) 10%, transparent);
            color: var(--acento); font: inherit; font-size: 10.5px; font-weight: 600;
        }
        .desfazer:hover { background: color-mix(in srgb, var(--acento) 20%, transparent); }

        .pendentes {
            margin-right: auto; align-self: center;
            font-size: 13px; font-weight: 550; color: var(--aviso);
        }

        .campos { display: grid; gap: 17px; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }
        .campo { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
        .campo.largo { grid-column: span 2; }
        @media (max-width: 700px) { .campo.largo { grid-column: 1 / -1; } }
        .campo > label { font-size: 13px; font-weight: 550; }
        .entrada { display: flex; align-items: center; gap: 8px; }
        .unidade { font-size: 12px; color: var(--tinta-3); min-width: 16px; }
        .ajuda { font-size: 11.5px; color: var(--tinta-3); line-height: 1.45; }

        input[type=text], input[type=number], input[type=time], select, textarea {
            width: 100%; padding: 8px 11px; font: inherit; font-size: 13.5px;
            color: var(--tinta-1); background: var(--papel);
            border: 1px solid var(--borda-forte); border-radius: 7px;
        }
        input:focus, select:focus, textarea:focus {
            outline: none; border-color: var(--acento);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--acento) 16%, transparent);
        }
        textarea {
            resize: vertical; font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
            font-size: 12.5px; line-height: 1.55; min-height: 96px;
        }
        input.curto { max-width: 88px; }

        .chave { display: inline-block; cursor: pointer; }
        .chave input { position: absolute; opacity: 0; width: 0; height: 0; }
        .trilho-chave {
            display: block; width: 38px; height: 22px; border-radius: 999px;
            background: var(--borda-forte); position: relative; transition: background .15s;
        }
        .trilho-chave::after {
            content: ''; position: absolute; top: 3px; left: 3px;
            width: 16px; height: 16px; border-radius: 50%; background: #fff;
            transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2);
        }
        .chave input:checked + .trilho-chave { background: var(--acento); }
        .chave input:checked + .trilho-chave::after { transform: translateX(16px); }

        .dias { display: flex; gap: 6px; flex-wrap: wrap; }
        .dia { cursor: pointer; }
        .dia input { position: absolute; opacity: 0; width: 0; height: 0; }
        .dia span {
            display: grid; place-items: center; min-width: 44px; padding: 6px 10px;
            border: 1px solid var(--borda-forte); border-radius: 7px;
            font-size: 12.5px; font-weight: 500; color: var(--tinta-2);
        }
        .dia input:checked + span { background: color-mix(in srgb, var(--acento) 12%, transparent); border-color: var(--acento); color: var(--acento); }

        .barra-acoes {
            position: sticky; bottom: 0; display: flex; gap: 10px; align-items: center;
            justify-content: flex-end; padding: 16px 0;
            background: linear-gradient(transparent, var(--fundo) 45%);
        }
        .botao {
            font: inherit; font-size: 13.5px; font-weight: 550; padding: 9px 18px;
            border-radius: 8px; border: 1px solid var(--borda-forte); cursor: pointer;
            background: var(--papel); color: var(--tinta-1);
        }
        .botao.primario { background: var(--acento); border-color: var(--acento); color: #fff; }
        .botao.perigo { color: var(--critico); border-color: color-mix(in srgb, var(--critico) 40%, var(--borda)); }
        .botao.fantasma { padding: 5px 12px; font-size: 12.5px; }
        .botao:hover { filter: brightness(1.05); }

        .recado { padding: 11px 15px; border-radius: 9px; margin-bottom: 15px; font-size: 13.5px; border: 1px solid; display: flex; gap: 9px; }
        .recado.ok { color: var(--bom); border-color: color-mix(in srgb, var(--bom) 32%, transparent); background: color-mix(in srgb, var(--bom) 8%, transparent); }
        .recado.ruim { color: var(--critico); border-color: color-mix(in srgb, var(--critico) 32%, transparent); background: color-mix(in srgb, var(--critico) 8%, transparent); }
        .recado.atencao { color: #8a5a00; border-color: color-mix(in srgb, var(--aviso) 42%, transparent); background: color-mix(in srgb, var(--aviso) 12%, transparent); }
        @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .recado.atencao { color: var(--aviso); } }
        :root[data-theme="dark"] .recado.atencao { color: var(--aviso); }

        .conversa { background: #0b141a; border-radius: 11px; padding: 24px; }
        .balao {
            background: #005c4b; color: #e9edef; max-width: 470px; margin-left: auto;
            padding: 9px 12px 6px; border-radius: 10px 10px 3px 10px;
            font-size: 13.5px; line-height: 1.5; word-break: break-word;
        }
        .hora-balao { display: block; text-align: right; font-size: 10.5px; color: #a8bfb5; margin-top: 3px; }

        .dica { font-size: 12.5px; color: var(--tinta-3); margin: 15px 0 0; }
        code {
            font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: 12px;
            background: var(--papel-2); border: 1px solid var(--borda); border-radius: 5px; padding: 1px 6px;
        }

        /* ---------- tela de grupos ---------- */
        .filtro-grupos { display: flex; align-items: center; gap: 12px; margin: 0 0 14px; }
        .filtro-grupos input {
            flex: 1; max-width: 340px; padding: 8px 12px; border-radius: 8px;
            border: 1px solid var(--borda); background: var(--papel); color: var(--tinta-1); font-size: 13.5px;
        }
        .filtro-grupos input:focus { outline: none; border-color: var(--acento); }
        table.grupos tr.usado td:first-child { box-shadow: inset 3px 0 0 var(--acento); }
        .grupo-nome { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-weight: 520; }
        .grupo-aviso { font-size: 12px; color: var(--critico); margin-top: 4px; max-width: 46ch; line-height: 1.45; }
        .etiqueta {
            font-size: 10.5px; font-weight: 600; letter-spacing: .3px; text-transform: uppercase;
            padding: 2px 7px; border-radius: 999px; border: 1px solid var(--borda); color: var(--tinta-3);
        }
        .etiqueta.atencao { color: var(--aviso); border-color: color-mix(in srgb, var(--aviso) 34%, transparent); }
        .etiqueta.ruim { color: var(--critico); border-color: color-mix(in srgb, var(--critico) 34%, transparent); }
        .id-grupo {
            font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: 11.5px;
            background: var(--papel-2); border: 1px solid var(--borda); border-radius: 5px;
            padding: 3px 8px; color: var(--tinta-2); cursor: pointer;
        }
        .id-grupo:hover { border-color: var(--acento); color: var(--acento); }
        .id-grupo.copiado { color: var(--bom); border-color: color-mix(in srgb, var(--bom) 40%, transparent); }

        /* ---------- fila por canal ---------- */
        .abas-fila { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 16px; }
        .abas-fila a {
            display: inline-flex; align-items: center; gap: 7px; padding: 6px 12px;
            border-radius: 999px; border: 1px solid var(--borda); font-size: 13px;
            color: var(--tinta-2); text-decoration: none; background: var(--papel);
        }
        .abas-fila a:hover { border-color: var(--acento); color: var(--acento); }
        .abas-fila a.atual {
            background: color-mix(in srgb, var(--acento) 12%, transparent);
            border-color: color-mix(in srgb, var(--acento) 40%, transparent); color: var(--acento);
        }
        .abas-fila .conta {
            font-size: 11.5px; font-weight: 600; padding: 1px 7px; border-radius: 999px;
            background: var(--papel-2); color: var(--tinta-3);
        }
        .abas-fila a.atual .conta { background: color-mix(in srgb, var(--acento) 18%, transparent); color: inherit; }
        .tipo-produto { font-size: 12.5px; color: var(--tinta-2); }

        .furar {
            display: inline-flex; align-items: center; justify-content: center;
            width: 26px; height: 26px; padding: 0; border-radius: 7px; cursor: pointer;
            border: 1px solid var(--borda); background: var(--papel);
            color: var(--tinta-3); font-size: 11px; line-height: 1;
        }
        .furar:hover { border-color: var(--acento); color: var(--acento); }
        .furar.ativo {
            background: color-mix(in srgb, var(--acento) 14%, transparent);
            border-color: color-mix(in srgb, var(--acento) 45%, transparent); color: var(--acento);
        }
        tr.furado td { background: color-mix(in srgb, var(--acento) 5%, transparent); }
        tr.furado td:first-child { box-shadow: inset 3px 0 0 var(--acento); }

        /* ---------- descartar ---------- */
        .acoes-linha { display: flex; align-items: center; gap: 4px; }
        details.descartar { position: relative; }
        details.descartar > summary {
            display: inline-flex; align-items: center; justify-content: center;
            width: 26px; height: 26px; border-radius: 7px; cursor: pointer; list-style: none;
            border: 1px solid var(--borda); background: var(--papel);
            color: var(--tinta-3); font-size: 11px; line-height: 1;
        }
        details.descartar > summary::-webkit-details-marker { display: none; }
        details.descartar > summary:hover { border-color: var(--critico); color: var(--critico); }
        details[open].descartar > summary { border-color: var(--critico); color: var(--critico); }
        .menu-descarte {
            position: absolute; z-index: 30; top: calc(100% + 5px); left: 0; min-width: 232px;
            display: flex; flex-direction: column; gap: 2px; padding: 5px;
            background: var(--papel); border: 1px solid var(--borda); border-radius: 10px;
            box-shadow: 0 12px 30px rgb(0 0 0 / 16%);
        }
        .menu-descarte button {
            text-align: left; padding: 7px 10px; border-radius: 7px; cursor: pointer;
            border: 0; background: transparent; color: var(--tinta-2); font-size: 12.5px;
        }
        .menu-descarte button:hover { background: var(--papel-2); color: var(--critico); }
        .menu-descarte strong { color: inherit; font-weight: 620; }

        /* ---------- faixa de diagnostico ---------- */
        .faixa-saude {
            display: flex; gap: 12px; align-items: flex-start; margin: 0 0 18px;
            padding: 14px 16px; border-radius: 12px;
            border: 1px solid color-mix(in srgb, var(--critico) 32%, var(--borda));
            background: color-mix(in srgb, var(--critico) 6%, transparent);
        }
        .faixa-saude > .ponto { margin-top: 6px; flex: none; }
        .faixa-saude h3 { margin: 0 0 6px; font-size: 13.5px; color: var(--critico); font-weight: 600; }
        .faixa-saude ul { margin: 0; padding-left: 16px; }
        .faixa-saude li { font-size: 13px; color: var(--tinta-2); line-height: 1.65; }
        .faixa-saude strong { color: var(--tinta-1); font-weight: 570; }

        /* ---------- miniaturas ---------- */
        .com-foto { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .com-foto > a, .com-foto > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        .foto {
            width: 34px; height: 34px; flex: none; border-radius: 7px; object-fit: cover;
            background: var(--papel-2); border: 1px solid var(--borda);
        }
        .foto.vazia { display: inline-block; }
        .com-foto.grande .foto { width: 60px; height: 60px; border-radius: 9px; }

        /* ---------- proxima publicacao ---------- */
        .proximas { display: grid; grid-template-columns: repeat(auto-fit, minmax(310px, 1fr)); gap: 12px; }
        .proxima {
            display: flex; flex-direction: column; gap: 9px; padding: 13px 14px;
            border: 1px solid var(--borda); border-radius: 11px; background: var(--papel-2);
        }
        .proxima header { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
        .proxima-canal { font-size: 12px; font-weight: 620; color: var(--acento); }
        .proxima a { font-size: 13.5px; line-height: 1.45; display: block; margin-bottom: 5px; }
        .proxima-preco { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; font-size: 13px; }
        .proxima-preco strong { font-size: 15px; }
        .proxima-vazia { font-size: 13px; color: var(--tinta-3); padding: 12px 0; }
        .proxima footer { font-size: 12px; }

        /* ---------- menu em grupos ---------- */
        .grupo-menu {
            font-size: 10.5px; font-weight: 640; letter-spacing: .6px; text-transform: uppercase;
            color: var(--tinta-3); padding: 14px 10px 5px;
        }
        .lateral nav .grupo-menu:first-child { padding-top: 4px; }

        /* ---------- tema e recados ---------- */
        .topo { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
        #tema { flex: none; }
        .recado { display: flex; align-items: flex-start; gap: 10px; transition: opacity .35s, transform .35s; }
        .recado > span { flex: 1; }
        .recado.saindo { opacity: 0; transform: translateY(-6px); }
        .fechar-recado {
            flex: none; border: 0; background: transparent; cursor: pointer;
            color: inherit; opacity: .5; font-size: 11px; padding: 2px 4px; line-height: 1;
        }
        .fechar-recado:hover { opacity: 1; }

        /* ---------- canais ---------- */
        .novo-canal { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--borda); }
        .novo-canal h3 { margin: 0 0 12px; font-size: 14px; font-weight: 600; }
        .campos-linha { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
        .campos-linha label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; color: var(--tinta-2); }
        .campos-linha input {
            padding: 8px 11px; border-radius: 8px; border: 1px solid var(--borda);
            background: var(--papel); color: var(--tinta-1); font-size: 13.5px; min-width: 230px;
        }
        .campos-linha input:focus { outline: none; border-color: var(--acento); }
        .furar.remover:hover { border-color: var(--critico); color: var(--critico); }

        .campos.travados { opacity: .72; pointer-events: none; }

        @media (max-width: 960px) { .grade.principal, .grade.secundaria { grid-template-columns: 1fr; } }
        @media (max-width: 760px) {
            body { grid-template-columns: 1fr; }
            .barra-abas { gap: 10px; }
            .busca-campos input { width: 100%; }
            .busca-campos { flex: 1; }
            .lateral { position: static; height: auto; flex-direction: row; align-items: center; gap: 14px; padding: 12px 16px; overflow-x: auto; }
            .lateral nav { flex-direction: row; }
            .lateral nav a span { display: none; }
            .lateral nav .grupo-menu { display: none; }
            .rodape-lateral { display: none; }
            .marca { flex-direction: row; gap: 10px; padding: 0 8px; text-align: left; }
            .marca small { display: none; }
            .logo { width: 34px; height: 34px; border-radius: 9px; }
            .topo { padding: 14px 16px; }
            main { padding: 16px 16px 60px; }
            .campos { grid-template-columns: 1fr; }
        }
        CSS;
    }
}
