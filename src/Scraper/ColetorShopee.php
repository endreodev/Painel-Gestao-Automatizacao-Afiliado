<?php

declare(strict_types=1);

namespace MlGroup\Scraper;

use MlGroup\Model\Produto;
use MlGroup\Support\Config;
use MlGroup\Support\Env;
use MlGroup\Support\Http;
use MlGroup\Support\Logger;
use MlGroup\Support\RespostaHttp;

/**
 * Coletor pela API oficial do Programa de Afiliados da Shopee.
 *
 * Diferente do Mercado Livre, aqui nao ha navegador nem raspagem: a Shopee
 * publica um endpoint GraphQL para afiliados, e ele ja devolve o que no ML
 * precisa ser adivinhado - a comissao real do anuncio e o link de afiliado
 * pronto, com o rastreio da sua conta.
 *
 * Exige credenciais (AppId e Secret) da Central de Afiliados, em SHOPEE_APP_ID
 * e SHOPEE_SECRET no .env. Sem elas o coletor se declara indisponivel e o ciclo
 * segue sem a Shopee, em vez de quebrar.
 *
 * Endpoint: https://open-api.affiliate.shopee.com.br/graphql
 * Playground e documentacao: https://www.affiliateshopee.com.br/documentacao
 */
final class ColetorShopee implements ColetorInterface
{
    private const ENDPOINT = 'https://open-api.affiliate.shopee.com.br/graphql';

    /** Teto por pagina aceito pela API. */
    private const POR_PAGINA = 50;

    /** Campos do productOfferV2 que o Produto aproveita. */
    private const CAMPOS = 'itemId shopId productName productLink offerLink imageUrl '
        . 'priceMin priceMax priceDiscountRate sales ratingStar commissionRate shopName';

    public function __construct(private readonly Http $http = new Http(timeout: 20))
    {
    }

    public function nome(): string
    {
        return 'shopee';
    }

    public function disponivel(): bool
    {
        return $this->appId() !== '' && $this->secret() !== '';
    }

    /**
     * Confere as credenciais contra a API de verdade.
     *
     * Serve ao comando `php bin/mlgroup shopee`: dizer "faltou o AppId" e
     * barato, mas o erro que aparece na pratica e a assinatura recusada - e so
     * uma chamada real revela isso.
     *
     * @return array{ok:bool,mensagem:string,itens:int}
     */
    public function testar(): array
    {
        if (!$this->disponivel()) {
            return [
                'ok'       => false,
                'mensagem' => 'SHOPEE_APP_ID e SHOPEE_SECRET nao estao no .env',
                'itens'    => 0,
            ];
        }

        $resposta = $this->chamar($this->consultaProdutos(['listType' => 0], 1, 1));
        $erro     = $this->erroDe($resposta);

        if ($erro !== null) {
            return ['ok' => false, 'mensagem' => $erro, 'itens' => 0];
        }

        $nos = $resposta->json()['data']['productOfferV2']['nodes'] ?? [];

        return [
            'ok'       => true,
            'mensagem' => 'credenciais aceitas',
            'itens'    => is_array($nos) ? count($nos) : 0,
        ];
    }

    /**
     * @param  array<string,mixed> $busca
     * @return Produto[]
     */
    public function coletar(array $busca, int $limite): array
    {
        if (!$this->disponivel()) {
            Logger::i()->aviso('Busca da Shopee ignorada: credenciais ausentes', [
                'busca' => (string) ($busca['nome'] ?? ''),
            ]);

            return [];
        }

        $produtos   = [];
        $pagina     = 1;
        $porPagina  = min(self::POR_PAGINA, max(1, $limite));
        $maxPaginas = max(1, Config::inteiro('config.shopee.max_paginas', 3));

        while (count($produtos) < $limite && $pagina <= $maxPaginas) {
            $resposta = $this->chamar($this->consultaProdutos($busca, $pagina, $porPagina));
            $erro     = $this->erroDe($resposta);

            if ($erro !== null) {
                Logger::i()->aviso('Busca na Shopee falhou', [
                    'busca'  => (string) ($busca['nome'] ?? $busca['termo'] ?? ''),
                    'motivo' => $erro,
                ]);

                break;
            }

            $bloco = $resposta->json()['data']['productOfferV2'] ?? [];
            $nos   = is_array($bloco['nodes'] ?? null) ? $bloco['nodes'] : [];

            if ($nos === []) {
                break;
            }

            foreach ($nos as $no) {
                if (!is_array($no)) {
                    continue;
                }

                $produto = $this->montarProduto($no);

                if ($produto !== null) {
                    $produtos[] = $produto;
                }
            }

            if (($bloco['pageInfo']['hasNextPage'] ?? false) !== true) {
                break;
            }

            $pagina++;

            // a API limita chamadas por minuto; o espacamento fica na config
            // para poder ser afrouxado sem mexer em codigo
            usleep(Config::inteiro('config.shopee.intervalo_requisicao_ms', 400) * 1000);
        }

        Logger::i()->debug('Coleta na Shopee concluida', [
            'busca' => (string) ($busca['nome'] ?? $busca['termo'] ?? ''),
            'itens' => count($produtos),
        ]);

        return array_slice($produtos, 0, $limite);
    }

    // ------------------------------------------------------------------
    // GraphQL
    // ------------------------------------------------------------------

    /**
     * Monta a consulta a partir da definicao da busca.
     *
     * Os argumentos sao os da propria Shopee, repassados como estao:
     *   listType  0 = tudo · 2 = mais vendidos · 3 e 4 = categoria · 5 = loja
     *   matchId   id da categoria (listType 3 e 4) ou da loja (listType 5)
     *   sortType  codigo de ordenacao da Shopee (a lista esta na documentacao
     *             deles; deixe de fora para usar a ordem padrao)
     *
     * @param array<string,mixed> $busca
     */
    private function consultaProdutos(array $busca, int $pagina, int $porPagina): string
    {
        $argumentos = [
            'page'  => $pagina,
            'limit' => $porPagina,
        ];

        $termo = trim((string) ($busca['termo'] ?? ''));

        if ($termo !== '') {
            $argumentos['keyword'] = $termo;
        }

        foreach (['listType', 'matchId', 'sortType'] as $opcional) {
            // aceita tambem em minusculo, que e como o resto do config escreve
            $valor = $busca[$opcional] ?? $busca[strtolower($opcional)] ?? null;

            if ($valor !== null && $valor !== '') {
                $argumentos[$opcional] = (int) $valor;
            }
        }

        // sem nenhum criterio a API recusa; "tudo" e o equivalente as ofertas
        if (!isset($argumentos['keyword']) && !isset($argumentos['listType'])) {
            $argumentos['listType'] = 0;
        }

        $partes = [];

        foreach ($argumentos as $nome => $valor) {
            $partes[] = $nome . ': ' . (is_int($valor)
                ? (string) $valor
                : (string) json_encode((string) $valor, JSON_UNESCAPED_UNICODE));
        }

        return sprintf(
            '{ productOfferV2(%s) { nodes { %s } pageInfo { page limit hasNextPage } } }',
            implode(', ', $partes),
            self::CAMPOS,
        );
    }

    private function chamar(string $consulta): RespostaHttp
    {
        // o corpo assinado precisa ser o mesmo que trafega, byte a byte
        $corpo = Http::json(['query' => $consulta]);

        $appId     = $this->appId();
        $timestamp = time();

        $assinatura = hash('sha256', $appId . $timestamp . $corpo . $this->secret());

        return $this->http->postJsonBruto(self::ENDPOINT, $corpo, [
            'Authorization' => sprintf(
                'SHA256 Credential=%s, Timestamp=%d, Signature=%s',
                $appId,
                $timestamp,
                $assinatura,
            ),
        ]);
    }

    /**
     * O motivo da falha, ou null quando a resposta veio boa.
     *
     * A Shopee responde 200 com o erro no corpo em boa parte dos casos - a
     * assinatura recusada e o principal deles -, entao olhar so o status HTTP
     * faria o coletor tratar erro como lista vazia.
     */
    private function erroDe(RespostaHttp $resposta): ?string
    {
        if ($resposta->corpo === '') {
            return $resposta->erro ?? 'resposta vazia';
        }

        $dados = $resposta->json();

        if (isset($dados['errors'][0]['message'])) {
            return (string) $dados['errors'][0]['message'];
        }

        // formato proprio da Shopee: {"error": 10020, "message": "..."}
        if (!empty($dados['error'])) {
            return sprintf(
                '%s (codigo %s)',
                (string) ($dados['message'] ?? 'erro na API da Shopee'),
                (string) $dados['error'],
            );
        }

        if (!$resposta->ok()) {
            return 'HTTP ' . $resposta->status;
        }

        if (!isset($dados['data']['productOfferV2'])) {
            return 'resposta sem productOfferV2';
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Conversao
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $no */
    private function montarProduto(array $no): ?Produto
    {
        $titulo = trim((string) ($no['productName'] ?? ''));

        if ((string) ($no['itemId'] ?? '') === '' || $titulo === '') {
            return null;
        }

        $preco = (float) ($no['priceMin'] ?? 0);

        if ($preco <= 0) {
            return null;
        }

        $oferta = (string) ($no['offerLink'] ?? '');
        $puro   = (string) ($no['productLink'] ?? '');

        $produto = new Produto(
            mlId:          $this->identificador($no),
            titulo:        $titulo,
            permalink:     $puro !== '' ? $puro : $oferta,
            preco:         $preco,
            precoOriginal: $this->precoOriginal($preco, (float) ($no['priceDiscountRate'] ?? 0)),
            thumb:         (string) ($no['imageUrl'] ?? ''),
            vendedor:      (string) ($no['shopName'] ?? ''),
            vendidos:      (int) ($no['sales'] ?? 0),
            avaliacao:     (float) ($no['ratingStar'] ?? 0),
            origem:        'shopee-api',
            loja:          'shopee',
        );

        /*
         * A comissao vem do proprio anuncio, e nao da tabela mantida a mao em
         * config/comissoes.php - e a vantagem principal desta fonte. A Shopee
         * manda ora fracao (0.08), ora percentual (8); acima de 1 so pode ser
         * percentual, porque comissao de 100% nao existe.
         */
        $taxa = (float) ($no['commissionRate'] ?? 0);

        $produto->comissao = $taxa > 0 && $taxa <= 1 ? round($taxa * 100, 2) : round($taxa, 2);

        // o offerLink ja e o link rastreado da sua conta: nao ha o que montar
        $produto->linkAfiliado = $oferta;

        return $produto;
    }

    /**
     * Chave unica do produto no banco.
     *
     * Leva prefixo porque a tabela e compartilhada com o Mercado Livre e o
     * itemId da Shopee e um numero solto - sem o prefixo, um dia ele esbarra
     * num id de outra loja e um produto sobrescreve o outro.
     *
     * @param array<string,mixed> $no
     */
    private function identificador(array $no): string
    {
        return 'SHP' . (string) ($no['shopId'] ?? '0') . '-' . (string) ($no['itemId'] ?? '0');
    }

    /**
     * Reconstroi o preco "de" a partir do percentual de desconto.
     *
     * A Shopee nao devolve o preco cheio, so a taxa de desconto ja aplicada. O
     * template mostra "De X por Y", e sem isto toda oferta da Shopee chegaria
     * sem o "de" e com desconto zero - o filtro de desconto minimo cortaria
     * todas elas.
     */
    private function precoOriginal(float $preco, float $descontoPercentual): float
    {
        if ($descontoPercentual <= 0 || $descontoPercentual >= 100) {
            return 0.0;
        }

        return round($preco / (1 - ($descontoPercentual / 100)), 2);
    }

    private function appId(): string
    {
        return trim(Env::texto('SHOPEE_APP_ID', Config::texto('config.shopee.app_id')));
    }

    private function secret(): string
    {
        return trim(Env::texto('SHOPEE_SECRET', Config::texto('config.shopee.secret')));
    }
}
