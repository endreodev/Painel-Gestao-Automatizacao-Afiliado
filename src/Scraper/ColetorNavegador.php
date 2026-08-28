<?php

declare(strict_types=1);

namespace MlGroup\Scraper;

use MlGroup\Model\Produto;
use MlGroup\Scraper\Navegador\ChromeHeadless;
use MlGroup\Scraper\Navegador\ParserMercadoLivre;
use MlGroup\Support\Config;
use MlGroup\Support\Logger;
use MlGroup\Support\Str;
use Throwable;

/**
 * Coletor que abre o Mercado Livre no navegador (Chrome headless) e le a pagina
 * renderizada. E o modo principal: enxerga exatamente o que o usuario ve,
 * inclusive a pagina /ofertas, que a API nao expoe.
 *
 * Cada busca de config/buscas.php pode ser de tres tipos:
 *   - 'termo'   -> monta a URL de lista a partir da palavra-chave + filtros
 *   - 'ofertas' -> varre /ofertas (opcionalmente por categoria)
 *   - 'url'     -> usa uma URL colada do navegador, com os filtros ja aplicados
 */
final class ColetorNavegador implements ColetorInterface
{
    private const ITENS_POR_PAGINA = 50;

    /** Uma unica tentativa de contorno por busca. */
    private bool $tentouContornar = false;

    public function __construct(
        private readonly ChromeHeadless $navegador = new ChromeHeadless(),
        private readonly ParserMercadoLivre $parser = new ParserMercadoLivre(),
    ) {
    }

    public function nome(): string
    {
        return 'navegador';
    }

    public function disponivel(): bool
    {
        return $this->navegador->disponivel();
    }

    /**
     * @param  array<string,mixed> $busca
     * @return Produto[]
     */
    public function coletar(array $busca, int $limite): array
    {
        $produtos = [];
        $pagina   = 1;
        $maximo   = Config::inteiro('config.coleta.max_paginas', 3);

        $this->tentouContornar = false;

        while (count($produtos) < $limite && $pagina <= $maximo) {
            $url = $this->montarUrl($busca, $pagina);

            if ($url === '') {
                break;
            }

            try {
                $html = $this->navegador->html($url);
            } catch (Throwable $erro) {
                Logger::i()->erro('Falha ao abrir pagina no navegador', [
                    'url'    => $url,
                    'motivo' => $erro->getMessage(),
                ]);

                break;
            }

            if (ParserMercadoLivre::pareceBloqueio($html)) {
                if ($this->contornarBloqueio($busca, $url)) {
                    continue;
                }

                break;
            }

            $lote = $this->parser->extrair($html, 'navegador');

            Logger::i()->debug('Pagina lida', [
                'url'    => $url,
                'pagina' => $pagina,
                'itens'  => count($lote),
            ]);

            if ($lote === []) {
                break;
            }

            $produtos = array_merge($produtos, $lote);
            $pagina++;

            usleep(Config::inteiro('config.coleta.intervalo_requisicao_ms', 400) * 1000);
        }

        return array_slice($this->semDuplicados($produtos), 0, $limite);
    }

    /**
     * O ML barrou o acesso: pausa, refaz a sessao pela home e tenta de novo.
     *
     * Vale uma tentativa por busca. Insistir alem disso so piora a reputacao do
     * IP - e as paginas /ofertas, que raramente sao barradas, continuam
     * alimentando o ciclo.
     *
     * @param array<string,mixed> $busca
     */
    private function contornarBloqueio(array $busca, string $url): bool
    {
        $rotulo = (string) ($busca['nome'] ?? $busca['termo'] ?? $url);

        if ($this->tentouContornar) {
            Logger::i()->aviso('Busca abandonada: o ML manteve a verificacao de trafego', ['busca' => $rotulo]);

            return false;
        }

        $this->tentouContornar = true;

        $pausa = Config::inteiro('config.coleta.pausa_bloqueio_s', 45);

        Logger::i()->aviso('Verificacao de trafego detectada, pausando antes de tentar de novo', [
            'busca'   => $rotulo,
            'pausa_s' => $pausa,
        ]);

        sleep($pausa);

        $this->navegador->reiniciar();

        return true;
    }

    /**
     * @param  array<string,mixed> $busca
     */
    private function montarUrl(array $busca, int $pagina): string
    {
        $tipo = (string) ($busca['tipo'] ?? 'termo');

        return match ($tipo) {
            'url'     => $this->urlDireta($busca, $pagina),
            'ofertas' => $this->urlOfertas($busca, $pagina),
            default   => $this->urlLista($busca, $pagina),
        };
    }

    /** @param array<string,mixed> $busca */
    private function urlDireta(array $busca, int $pagina): string
    {
        $url = (string) ($busca['url'] ?? '');

        if ($url === '' || $pagina === 1) {
            return $url;
        }

        $desde = (($pagina - 1) * self::ITENS_POR_PAGINA) + 1;

        // paginacao das listas do ML vem no proprio caminho: .../_Desde_51
        if (str_contains($url, 'lista.mercadolivre.com.br') || str_contains($url, '/_Desde_')) {
            return preg_replace('/_Desde_\d+/', '_Desde_' . $desde, $url) === $url
                ? rtrim($url, '/') . '/_Desde_' . $desde
                : (string) preg_replace('/_Desde_\d+/', '_Desde_' . $desde, $url);
        }

        return $this->comPagina($url, $pagina);
    }

    /**
     * Acrescenta (ou troca) o page= na query, respeitando o fragmento.
     *
     * A URL de ofertas filtrada que se copia do navegador termina com um
     * fragmento - ...&domain_id=MLB-TOOLS#filter_applied=domain_id. Grudar
     * "&page=2" no fim jogaria o parametro para dentro do fragmento, que o
     * servidor nem recebe: a pagina 2 voltaria identica a 1 e o coletor leria
     * duas vezes a mesma coisa.
     */
    private function comPagina(string $url, int $pagina): string
    {
        $partes    = explode('#', $url, 2);
        $semAncora = $partes[0];
        $ancora    = isset($partes[1]) ? '#' . $partes[1] : '';

        $pedacos = explode('?', $semAncora, 2);
        $base    = $pedacos[0];

        parse_str($pedacos[1] ?? '', $parametros);

        $parametros['page'] = $pagina;

        // sem codificar o $ dos domain_id, que o ML usa como separador
        $query = str_replace('%24', '$', http_build_query($parametros));

        return $base . '?' . $query . $ancora;
    }

    /** @param array<string,mixed> $busca */
    private function urlOfertas(array $busca, int $pagina): string
    {
        $parametros = ['page' => $pagina];

        if (!empty($busca['categoria'])) {
            $parametros['category'] = (string) $busca['categoria'];
        }

        if (!empty($busca['container'])) {
            $parametros['container_id'] = (string) $busca['container'];
        }

        if (!empty($busca['dominios'])) {
            $parametros['domain_id'] = (string) $busca['dominios'];
        }

        /*
         * Filtro de preco na propria fonte.
         *
         * Medido: a pagina de ofertas de Ferramentas sem filtro traz 19% de
         * itens entre R$ 10 e 100; com price=10-100, traz 100%. Filtrar aqui
         * vale muito mais que filtrar depois - o que nao interessa nem chega a
         * ocupar lugar na pagina.
         */
        if (!empty($busca['preco_min']) || !empty($busca['preco_max'])) {
            $parametros['price'] = ((int) ($busca['preco_min'] ?? 0))
                . '-' . ((int) ($busca['preco_max'] ?? 0));
        }

        // o $ separa os dominios e nao pode ser escapado
        $query = str_replace('%24', '$', http_build_query($parametros));

        return 'https://www.mercadolivre.com.br/ofertas?' . $query;
    }

    /**
     * Monta a URL de lista com os filtros no formato de caminho do ML.
     * Ex.: https://lista.mercadolivre.com.br/furadeira-de-impacto_PriceRange_100-500_Discount_20-100_Desde_51
     *
     * @param array<string,mixed> $busca
     */
    private function urlLista(array $busca, int $pagina): string
    {
        $termo = (string) ($busca['termo'] ?? '');

        if ($termo === '') {
            return '';
        }

        $url = 'https://lista.mercadolivre.com.br/' . Str::slug($termo);

        if (!empty($busca['preco_min']) || !empty($busca['preco_max'])) {
            $url .= '_PriceRange_' . ((int) ($busca['preco_min'] ?? 0)) . '-' . ((int) ($busca['preco_max'] ?? 0) ?: '0');
        }

        if (!empty($busca['desconto_min'])) {
            $url .= '_Discount_' . ((int) $busca['desconto_min']) . '-100';
        }

        if (!empty($busca['apenas_frete_gratis'])) {
            $url .= '_ShippingCost_Free';
        }

        if (!empty($busca['apenas_full'])) {
            $url .= '_SHIPPING*ORIGIN_10215068';
        }

        if (!empty($busca['ordem'])) {
            $url .= '_OrderId_' . strtoupper((string) $busca['ordem']);
        }

        if ($pagina > 1) {
            $url .= '_Desde_' . ((($pagina - 1) * self::ITENS_POR_PAGINA) + 1);
        }

        return $url;
    }

    /**
     * @param  Produto[] $produtos
     * @return Produto[]
     */
    private function semDuplicados(array $produtos): array
    {
        $unicos = [];

        foreach ($produtos as $produto) {
            $unicos[$produto->mlId] = $produto;
        }

        return array_values($unicos);
    }
}
