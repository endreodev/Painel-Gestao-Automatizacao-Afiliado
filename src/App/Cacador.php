<?php

declare(strict_types=1);

namespace MlGroup\App;

use MlGroup\Afiliado\TabelaComissao;
use MlGroup\App\Canal;
use MlGroup\Analise\Filtro;
use MlGroup\Analise\HistoricoPreco;
use MlGroup\Analise\Pontuador;
use MlGroup\Database\Db;
use MlGroup\Model\Produto;
use MlGroup\Scraper\ColetorApi;
use MlGroup\Scraper\ColetorInterface;
use MlGroup\Scraper\ColetorNavegador;
use MlGroup\Scraper\ColetorShopee;
use MlGroup\Support\Config;
use MlGroup\Support\Logger;
use Throwable;

/**
 * Percorre as buscas configuradas, coleta, enriquece, filtra, pontua e grava.
 *
 * Devolve so o que passou por tudo, ja ordenado pela pontuacao - quem publica e
 * o Publicador, que nao precisa saber nada de coleta.
 */
final class Cacador
{
    private ?ColetorInterface $coletor = null;

    /** Coletores ja instanciados, por loja. @var array<string,ColetorInterface> */
    private array $porLoja = [];

    /** Quantidade bruta trazida da ultima caca, antes dos filtros. */
    private int $coletados = 0;

    public function __construct(
        private readonly TabelaComissao $comissao = new TabelaComissao(),
        private readonly HistoricoPreco $historico = new HistoricoPreco(),
        private readonly Filtro $filtro = new Filtro(),
        private readonly Pontuador $pontuador = new Pontuador(),
    ) {
    }

    /**
     * @return Produto[] Aprovados, do melhor para o pior.
     */
    public function cacar(): array
    {
        $buscas   = Config::lista('buscas.buscas');
        $porBusca = Config::inteiro('config.coleta.itens_por_busca', 50);

        /*
         * Sem citar o coletor aqui de proposito: escolher o do Mercado Livre
         * custa uma chamada de teste a API deles, e quem so tem busca da Shopee
         * pagaria essa ida a toa. Cada busca ja registra a loja que usou.
         */
        Logger::i()->info('Iniciando caca de ofertas', ['buscas' => count($buscas)]);

        $this->coletados = 0;

        $aprovados = [];
        $vistos    = [];

        foreach ($buscas as $busca) {
            if (!is_array($busca) || ($busca['ativo'] ?? true) === false) {
                continue;
            }

            $rotulo  = (string) ($busca['nome'] ?? $busca['termo'] ?? 'busca');
            $coletor = $this->coletorDaBusca($busca);

            if (!$coletor instanceof ColetorInterface) {
                continue;
            }

            try {
                $itens = $coletor->coletar($busca, $porBusca);
            } catch (Throwable $erro) {
                Logger::i()->erro('Busca falhou', ['busca' => $rotulo, 'motivo' => $erro->getMessage()]);

                continue;
            }

            $novos = [];

            foreach ($itens as $item) {
                // o mesmo anuncio pode aparecer em duas buscas; a primeira vale
                if (!isset($vistos[$item->mlId])) {
                    $vistos[$item->mlId] = true;
                    $novos[]             = $item;
                }
            }

            $this->coletados += count($novos);

            /*
             * Avalia e grava a cada busca, e nao so no fim.
             *
             * Uma rodada com varias buscas por termo leva dez minutos, porque
             * cada bloqueio do ML custa ~55s de pausa. Guardando tudo para o
             * final, a fila ficava vazia o ciclo inteiro e uma interrupcao no
             * meio jogava fora tudo que ja tinha sido coletado. Assim a primeira
             * busca ja deixa oferta pronta para publicar.
             */
            $doLote = $this->avaliar($novos);

            $aprovados = array_merge($aprovados, $doLote);

            Logger::i()->info('Busca concluida', [
                'busca'     => $rotulo,
                'loja'      => $coletor->nome(),
                'itens'     => count($itens),
                'aprovados' => count($doLote),
            ]);
        }

        usort(
            $aprovados,
            static fn (Produto $a, Produto $b): int => $b->pontuacao <=> $a->pontuacao,
        );

        return $aprovados;
    }

    public function coletados(): int
    {
        return $this->coletados;
    }

    /**
     * @param  Produto[] $produtos
     * @return Produto[]
     */
    public function avaliar(array $produtos): array
    {
        $aprovados  = [];
        $reprovados = [];

        foreach ($this->semVariacoesRepetidas($produtos) as $produto) {
            $this->historico->registrar($produto);
            $this->historico->enriquecer($produto);
            $this->comissao->aplicar($produto);

            $motivo = $this->filtro->reprovar($produto);

            if ($motivo !== null) {
                $reprovados[$motivo] = ($reprovados[$motivo] ?? 0) + 1;

                Logger::i()->debug('Produto reprovado', [
                    'id'     => $produto->mlId,
                    'titulo' => mb_substr($produto->titulo, 0, 50),
                    'motivo' => $motivo,
                ]);

                continue;
            }

            $this->pontuador->pontuar($produto);
            $this->salvar($produto);

            $aprovados[] = $produto;
        }

        usort(
            $aprovados,
            static fn (Produto $a, Produto $b): int => $b->pontuacao <=> $a->pontuacao,
        );

        Logger::i()->info('Avaliacao concluida', [
            'coletados' => count($produtos),
            'aprovados' => count($aprovados),
        ]);

        if ($reprovados !== []) {
            arsort($reprovados);
            Logger::i()->debug('Resumo das reprovacoes', array_slice($reprovados, 0, 8, true));
        }

        return $aprovados;
    }

    /**
     * Colapsa as variacoes de catalogo do mesmo produto.
     *
     * O ML publica cor/voltagem como anuncios distintos, com ml_id proprio e
     * titulo identico - foi o caso das serras MLB15462505 e MLB15462506, que
     * apareceram lado a lado no ranking. Fica a mais barata.
     *
     * @param  Produto[] $produtos
     * @return Produto[]
     */
    private function semVariacoesRepetidas(array $produtos): array
    {
        $unicos = [];

        foreach ($produtos as $produto) {
            $chave    = $produto->assinatura();
            $anterior = $unicos[$chave] ?? null;

            if ($anterior === null || $produto->preco < $anterior->preco) {
                $unicos[$chave] = $produto;
            }
        }

        return array_values($unicos);
    }

    /**
     * O coletor que atende esta busca.
     *
     * A loja vem da propria busca ('loja' => 'shopee'), e nao de uma opcao
     * global: as duas lojas convivem no mesmo ciclo e no mesmo grupo, cada
     * busca sabendo onde procurar. Sem a chave, e Mercado Livre - foi assim
     * durante toda a vida do projeto e nenhuma busca antiga precisa mudar.
     *
     * Devolve null quando a loja existe mas nao pode operar agora (a Shopee sem
     * credenciais, por exemplo): a busca e pulada e o ciclo segue com o resto.
     *
     * @param array<string,mixed> $busca
     */
    private function coletorDaBusca(array $busca): ?ColetorInterface
    {
        $loja = strtolower(trim((string) ($busca['loja'] ?? 'ml')));

        if ($loja === '' || $loja === 'ml' || $loja === 'mercadolivre') {
            return $this->coletor();
        }

        if ($loja !== 'shopee') {
            Logger::i()->aviso('Busca com loja desconhecida', [
                'busca' => (string) ($busca['nome'] ?? ''),
                'loja'  => $loja,
            ]);

            return null;
        }

        $shopee = $this->porLoja['shopee'] ??= new ColetorShopee();

        if (!$shopee->disponivel()) {
            Logger::i()->aviso('Busca da Shopee pulada: SHOPEE_APP_ID/SHOPEE_SECRET ausentes', [
                'busca' => (string) ($busca['nome'] ?? ''),
            ]);

            return null;
        }

        return $shopee;
    }

    /**
     * Escolhe o coletor do Mercado Livre. Se a config pedir 'auto', tenta a API
     * e cai para o navegador quando ela exige autenticacao ou esta bloqueando.
     */
    public function coletor(): ColetorInterface
    {
        if ($this->coletor !== null) {
            return $this->coletor;
        }

        $preferido = Config::texto('config.coleta.modo', 'auto');

        if ($preferido === 'api') {
            return $this->coletor = new ColetorApi();
        }

        if ($preferido === 'navegador') {
            return $this->coletor = new ColetorNavegador();
        }

        $api = new ColetorApi();

        if ($api->disponivel()) {
            return $this->coletor = $api;
        }

        Logger::i()->info('API indisponivel, usando o navegador');

        return $this->coletor = new ColetorNavegador();
    }

    private function salvar(Produto $produto): void
    {
        $dados          = $produto->paraArray();
        $dados['agora'] = date('Y-m-d H:i:s');
        $dados['canal'] = Canal::ativo()?->id() ?? 'padrao';

        Db::executar(
            'INSERT INTO produtos (
                canal, ml_id, assinatura, titulo, permalink, thumb, categoria_id, categoria_nome, marca, vendedor,
                preco, preco_original, desconto, comissao, ganho_estimado, frete_gratis, full,
                vendidos, avaliacao, total_avaliacoes, pontuacao, origem, loja, link_afiliado, criado_em, atualizado_em
             ) VALUES (
                :canal, :ml_id, :assinatura, :titulo, :permalink, :thumb, :categoria_id, :categoria_nome, :marca, :vendedor,
                :preco, :preco_original, :desconto, :comissao, :ganho_estimado, :frete_gratis, :full,
                :vendidos, :avaliacao, :total_avaliacoes, :pontuacao, :origem, :loja, :link_afiliado, :agora, :agora
             )
             ON CONFLICT(canal, ml_id) DO UPDATE SET
                assinatura       = excluded.assinatura,
                titulo           = excluded.titulo,
                permalink        = excluded.permalink,
                thumb            = excluded.thumb,
                preco            = excluded.preco,
                preco_original   = excluded.preco_original,
                desconto         = excluded.desconto,
                comissao         = excluded.comissao,
                ganho_estimado   = excluded.ganho_estimado,
                frete_gratis     = excluded.frete_gratis,
                full             = excluded.full,
                vendidos         = excluded.vendidos,
                avaliacao        = excluded.avaliacao,
                total_avaliacoes = excluded.total_avaliacoes,
                pontuacao        = excluded.pontuacao,
                origem           = excluded.origem,
                loja             = excluded.loja,
                link_afiliado    = excluded.link_afiliado,
                atualizado_em    = excluded.atualizado_em',
            $dados,
        );
    }
}
