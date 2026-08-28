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
        $coletor = $this->coletor();

        Logger::i()->info('Iniciando caca de ofertas', ['coletor' => $coletor->nome()]);

        $buscas   = Config::lista('buscas.buscas');
        $porBusca = Config::inteiro('config.coleta.itens_por_busca', 50);

        $this->coletados = 0;

        $aprovados = [];
        $vistos    = [];

        foreach ($buscas as $busca) {
            if (!is_array($busca) || ($busca['ativo'] ?? true) === false) {
                continue;
            }

            $rotulo = (string) ($busca['nome'] ?? $busca['termo'] ?? 'busca');

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
     * Escolhe o coletor. Se a config pedir 'auto', tenta a API e cai para o
     * navegador quando ela exige autenticacao ou esta bloqueando.
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
                vendidos, avaliacao, total_avaliacoes, pontuacao, origem, criado_em, atualizado_em
             ) VALUES (
                :canal, :ml_id, :assinatura, :titulo, :permalink, :thumb, :categoria_id, :categoria_nome, :marca, :vendedor,
                :preco, :preco_original, :desconto, :comissao, :ganho_estimado, :frete_gratis, :full,
                :vendidos, :avaliacao, :total_avaliacoes, :pontuacao, :origem, :agora, :agora
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
                atualizado_em    = excluded.atualizado_em',
            $dados,
        );
    }
}
