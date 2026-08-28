<?php

declare(strict_types=1);

namespace MlGroup\Analise;

use MlGroup\Database\Db;
use MlGroup\Model\Produto;
use MlGroup\Support\Config;
use MlGroup\Support\Str;

/**
 * Decide se um produto pode ir para o grupo.
 *
 * Devolve o motivo da reprovacao em vez de um booleano seco - o log de "por que
 * nao enviei" e o que permite calibrar os limites em config/config.php.
 */
final class Filtro
{
    public function __construct(
        private readonly HistoricoPreco $historico = new HistoricoPreco(),
        private readonly Nicho $nicho = new Nicho(),
        private readonly Descartes $descartes = new Descartes(),
    ) {
    }

    /** @return string|null Motivo da reprovacao, ou null quando aprovado. */
    public function reprovar(Produto $produto): ?string
    {
        /*
         * O descarte manual vem antes de tudo. E uma decisao explicita do
         * usuario sobre aquele anuncio, marca ou vendedor - nenhum limite
         * calculado deveria ter a chance de contradizer isso, e explicar a
         * recusa por "desconto baixo" quando na verdade o item foi descartado
         * a mao mandaria o usuario calibrar o filtro errado.
         */
        $descarte = $this->descartes->motivo($produto);

        if ($descarte !== null) {
            return $descarte;
        }

        // relevancia primeiro: nao adianta avaliar desconto de algo que nao
        // serve para o publico do grupo
        if ($this->nicho->reprovar($produto)) {
            return 'fora do nicho ' . Config::texto('nicho.nome', 'configurado');
        }

        $desconto = $produto->desconto();

        if ($desconto < Config::decimal('config.filtros.desconto_minimo', 15.0)) {
            return sprintf('desconto de %.1f%% abaixo do minimo', $desconto);
        }

        $descontoMaximo = Config::decimal('config.filtros.desconto_maximo', 90.0);

        if ($descontoMaximo > 0 && $desconto > $descontoMaximo) {
            return sprintf('desconto de %.1f%% alto demais para ser real', $desconto);
        }

        if ($produto->comissao < Config::decimal('config.filtros.comissao_minima', 3.0)) {
            return sprintf('comissao de %.1f%% abaixo do minimo', $produto->comissao);
        }

        if ($produto->ganhoEstimado < Config::decimal('config.filtros.ganho_minimo', 0.0)) {
            return sprintf('ganho estimado de R$ %.2f abaixo do minimo', $produto->ganhoEstimado);
        }

        $precoMinimo = Config::decimal('config.filtros.preco_minimo', 0.0);
        $precoMaximo = Config::decimal('config.filtros.preco_maximo', 0.0);

        if ($precoMinimo > 0 && $produto->preco < $precoMinimo) {
            return 'preco abaixo da faixa configurada';
        }

        if ($precoMaximo > 0 && $produto->preco > $precoMaximo) {
            return 'preco acima da faixa configurada';
        }

        $avaliacaoMinima = Config::decimal('config.filtros.avaliacao_minima', 0.0);

        // produto sem nenhuma avaliacao nao e reprovado por nota, so por volume
        if ($avaliacaoMinima > 0 && $produto->totalAvaliacoes > 0 && $produto->avaliacao < $avaliacaoMinima) {
            return sprintf('nota %.1f abaixo da minima', $produto->avaliacao);
        }

        $avaliacoesMinimas = Config::inteiro('config.filtros.avaliacoes_minimas', 0);

        if ($avaliacoesMinimas > 0 && $produto->totalAvaliacoes < $avaliacoesMinimas) {
            return sprintf('apenas %d avaliacoes', $produto->totalAvaliacoes);
        }

        if (Config::booleano('config.filtros.exigir_frete_gratis', false) && !$produto->freteGratis) {
            return 'sem frete gratis';
        }

        if (Config::booleano('config.filtros.exigir_full', false) && !$produto->full) {
            return 'sem entrega FULL';
        }

        if (Str::contemAlgum($produto->titulo, Config::lista('config.filtros.palavras_bloqueadas'))) {
            return 'titulo contem palavra bloqueada';
        }

        $palavrasObrigatorias = Config::lista('config.filtros.palavras_obrigatorias');

        if ($palavrasObrigatorias !== [] && !Str::contemAlgum($produto->titulo, $palavrasObrigatorias)) {
            return 'titulo nao bate com nenhuma palavra obrigatoria';
        }

        if ($produto->vendedor !== '' && Str::contemAlgum($produto->vendedor, Config::lista('config.filtros.vendedores_bloqueados'))) {
            return 'vendedor bloqueado';
        }

        if ($produto->marca !== '' && Str::contemAlgum($produto->marca, Config::lista('config.filtros.marcas_bloqueadas'))) {
            return 'marca bloqueada';
        }

        if ($this->historico->descontoSuspeito($produto)) {
            return 'desconto falso: preco "de" inflado em relacao ao historico';
        }

        $motivoEnvio = $this->reprovarPorEnvioRecente($produto);

        if ($motivoEnvio !== null) {
            return $motivoEnvio;
        }

        return null;
    }

    public function aprovar(Produto $produto): bool
    {
        return $this->reprovar($produto) === null;
    }

    /**
     * Evita repetir a mesma oferta no grupo. O reenvio so libera antes do prazo
     * quando o preco caiu o suficiente para ser noticia nova.
     */
    private function reprovarPorEnvioRecente(Produto $produto): ?string
    {
        $dias = Config::inteiro('config.filtros.dias_sem_repetir', 7);

        if ($dias <= 0) {
            return null;
        }

        $corte = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days') ?: time());

        // compara por assinatura tambem: as variacoes de catalogo do mesmo
        // produto tem ml_id proprio e passariam batido por uma checagem so de ID
        $ultimo = Db::primeiro(
            "SELECT preco, enviado_em
               FROM envios
              WHERE (ml_id = :id OR assinatura = :assinatura)
                AND status = 'enviado'
                AND enviado_em >= :corte
              ORDER BY enviado_em DESC
              LIMIT 1",
            [
                'id'         => $produto->mlId,
                'assinatura' => $produto->assinatura(),
                'corte'      => $corte,
            ],
        );

        if ($ultimo === null) {
            return null;
        }

        $precoAnterior = (float) $ultimo['preco'];
        $quedaMinima   = Config::decimal('config.filtros.queda_para_reenviar', 10.0);

        if ($precoAnterior > 0 && $quedaMinima > 0) {
            $queda = (($precoAnterior - $produto->preco) / $precoAnterior) * 100;

            if ($queda >= $quedaMinima) {
                return null;
            }
        }

        return 'ja enviado em ' . substr((string) $ultimo['enviado_em'], 0, 16);
    }
}
