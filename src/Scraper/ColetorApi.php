<?php

declare(strict_types=1);

namespace MlGroup\Scraper;

use MlGroup\Model\Produto;
use MlGroup\Support\Config;
use MlGroup\Support\Env;
use MlGroup\Support\Http;
use MlGroup\Support\Logger;

/**
 * Coletor pela API publica do Mercado Livre.
 *
 * Rapido e estruturado, porem a busca anonima vem sendo restringida pelo ML.
 * Se responder 401/403 o Caçador cai automaticamente para o ColetorNavegador.
 */
final class ColetorApi implements ColetorInterface
{
    private const BASE = 'https://api.mercadolibre.com';

    private const POR_PAGINA = 50;

    public function __construct(private readonly Http $http = new Http())
    {
    }

    public function nome(): string
    {
        return 'api';
    }

    public function disponivel(): bool
    {
        $resposta = $this->http->get(self::BASE . '/sites/MLB/search?q=furadeira&limit=1', $this->cabecalhos());

        if ($resposta->status === 401 || $resposta->status === 403) {
            Logger::i()->aviso('API do ML exigiu autenticacao', ['status' => $resposta->status]);

            return false;
        }

        return $resposta->ok();
    }

    /**
     * @param  array<string,mixed> $busca
     * @return Produto[]
     */
    public function coletar(array $busca, int $limite): array
    {
        $produtos = [];
        $offset   = 0;

        while (count($produtos) < $limite && $offset < 1000) {
            $url      = $this->montarUrl($busca, $offset);
            $resposta = $this->http->get($url, $this->cabecalhos());

            if (!$resposta->ok()) {
                Logger::i()->aviso('Busca via API falhou', [
                    'termo'  => $busca['termo'] ?? '',
                    'status' => $resposta->status,
                ]);

                break;
            }

            $dados     = $resposta->json();
            $resultados = $dados['results'] ?? [];

            if (!is_array($resultados) || $resultados === []) {
                break;
            }

            foreach ($resultados as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $produto = $this->montarProduto($item);

                if ($produto !== null) {
                    $produtos[] = $produto;
                }
            }

            $offset += self::POR_PAGINA;

            usleep(Config::inteiro('config.coleta.intervalo_requisicao_ms', 400) * 1000);
        }

        Logger::i()->debug('Coleta via API concluida', [
            'termo' => $busca['termo'] ?? '',
            'itens' => count($produtos),
        ]);

        return array_slice($produtos, 0, $limite);
    }

    /** @param array<string,mixed> $busca */
    private function montarUrl(array $busca, int $offset): string
    {
        $parametros = [
            'limit'  => self::POR_PAGINA,
            'offset' => $offset,
        ];

        if (!empty($busca['termo'])) {
            $parametros['q'] = (string) $busca['termo'];
        }

        if (!empty($busca['categoria'])) {
            $parametros['category'] = (string) $busca['categoria'];
        }

        if (!empty($busca['preco_min']) || !empty($busca['preco_max'])) {
            $parametros['price'] = ($busca['preco_min'] ?? '*') . '-' . ($busca['preco_max'] ?? '*');
        }

        if (!empty($busca['apenas_frete_gratis'])) {
            $parametros['shipping'] = 'free';
        }

        // ordena por maior desconto quando o ML aceita; senao cai no relevance
        $parametros['sort'] = (string) ($busca['ordem'] ?? 'relevance');

        return self::BASE . '/sites/MLB/search?' . http_build_query($parametros);
    }

    /** @return array<string,string> */
    private function cabecalhos(): array
    {
        $token = Env::texto('ML_ACCESS_TOKEN');

        return $token !== ''
            ? ['Authorization' => 'Bearer ' . $token]
            : [];
    }

    /** @param array<string,mixed> $item */
    private function montarProduto(array $item): ?Produto
    {
        $id    = (string) ($item['id'] ?? '');
        $titulo = (string) ($item['title'] ?? '');

        if ($id === '' || $titulo === '') {
            return null;
        }

        $preco = (float) ($item['price'] ?? 0);

        if ($preco <= 0) {
            return null;
        }

        $precoOriginal = (float) ($item['original_price'] ?? 0);

        $envio      = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];
        $vendedor   = is_array($item['seller'] ?? null) ? $item['seller'] : [];
        $avaliacoes = is_array($item['reviews'] ?? null) ? $item['reviews'] : [];

        return new Produto(
            mlId:            $id,
            titulo:          $titulo,
            permalink:       (string) ($item['permalink'] ?? ''),
            preco:           $preco,
            precoOriginal:   $precoOriginal,
            thumb:           (string) ($item['thumbnail'] ?? ''),
            categoriaId:     (string) ($item['category_id'] ?? ''),
            categoriaNome:   (string) ($item['domain_id'] ?? ''),
            marca:           $this->atributo($item, 'BRAND'),
            vendedor:        (string) ($vendedor['nickname'] ?? ''),
            freteGratis:     (bool) ($envio['free_shipping'] ?? false),
            full:            ($envio['logistic_type'] ?? '') === 'fulfillment',
            vendidos:        (int) ($item['sold_quantity'] ?? 0),
            avaliacao:       (float) ($avaliacoes['rating_average'] ?? 0),
            totalAvaliacoes: (int) ($avaliacoes['total'] ?? 0),
            origem:          'api',
        );
    }

    /** @param array<string,mixed> $item */
    private function atributo(array $item, string $chave): string
    {
        $atributos = $item['attributes'] ?? [];

        if (!is_array($atributos)) {
            return '';
        }

        foreach ($atributos as $atributo) {
            if (is_array($atributo) && ($atributo['id'] ?? '') === $chave) {
                return (string) ($atributo['value_name'] ?? '');
            }
        }

        return '';
    }
}
