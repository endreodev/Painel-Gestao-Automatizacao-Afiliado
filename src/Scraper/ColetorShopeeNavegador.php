<?php

declare(strict_types=1);

namespace MlGroup\Scraper;

use MlGroup\Model\Produto;
use MlGroup\Scraper\Navegador\ChromeHeadless;
use MlGroup\Support\Config;
use MlGroup\Support\Logger;
use Throwable;
use function count;

/**
 * Coleta da Shopee pelo navegador, com a sessao do usuario.
 *
 * A outra via - a API de afiliados em open-api.affiliate.shopee.com.br - pede
 * APP_ID e SECRET, que so saem para conta ja aprovada no programa. Conta nova
 * nao tem, e ficar esperando aprovacao deixaria a Shopee de fora sem prazo.
 *
 * Aqui e o mesmo desenho do Link Builder do Mercado Livre: um perfil de Chrome
 * que fica logado em storage/navegador-shopee/, o login feito uma vez pelo
 * usuario (php bin/mlgroup shopee-login), e a coleta reusando aquela sessao.
 *
 * Por que precisa de sessao: sem login a Shopee nao entrega nada. A busca
 * responde "Pagina indisponivel. Faca login e tente novamente", e o endpoint
 * interno devolve {"is_login":false,"error":90309999,"redirect_to_error_page":
 * true}. Nao e limite de raspagem - e a politica deles.
 */
final class ColetorShopeeNavegador implements ColetorInterface
{
    /** O que o proprio site chama quando voce digita na busca. */
    private const BUSCA = 'https://shopee.com.br/api/v4/search/search_items'
        . '?by=%s&keyword=%s&limit=%d&newest=%d&order=desc&page_type=search'
        . '&scenario=PAGE_GLOBAL_SEARCH&version=2';

    private const POR_PAGINA = 60;

    private ?ChromeHeadless $navegador = null;

    public function nome(): string
    {
        return 'shopee-navegador';
    }

    /** Onde fica o Chrome logado na Shopee. */
    public function perfil(): string
    {
        $configurado = trim(Config::texto('config.shopee.perfil_navegador', ''));

        return $configurado !== '' ? $configurado : MLG_ROOT . '/storage/navegador-shopee';
    }

    /** Abre a janela para o usuario entrar na conta. */
    public function abrirLogin(): void
    {
        $this->navegador()->abrirJanelaDeLogin('https://shopee.com.br/buyer/login');
    }

    /**
     * A sessao salva ainda vale?
     *
     * Pergunta ao endpoint de busca, nao a uma pagina: ele responde em JSON e
     * diz is_login sem ambiguidade. Uma pagina exigiria adivinhar pelo HTML se
     * aquilo e a lista ou a tela de login.
     */
    public function logado(): bool
    {
        $resposta = $this->consultar('teste', 1, false);

        return ($resposta['is_login'] ?? false) === true;
    }

    /**
     * Ha perfil com sessao para coletar?
     *
     * Vem DESLIGADO por padrao, e a razao nao e tecnica.
     *
     * Medido nesta instalacao, com a conta logada de verdade: a Shopee recusa
     * acesso automatizado mesmo reconhecendo a sessao. O endpoint responde
     * is_login=true e, junto, error=90309999; a pagina de busca renderiza
     * "Pagina indisponivel" e zero produtos. Acontece igual em headless e em
     * janela real - ou seja, nao e disfarce que falta, e bloqueio deliberado.
     *
     * O codigo fica pronto porque a decisao pode mudar de lado (eles afrouxam,
     * ou aparece uma rota publica), mas ligado por padrao ele gastaria uma
     * subida de navegador por ciclo para colher nada. Quem quiser tentar de
     * novo liga em config/config.php > shopee.usar_navegador.
     *
     * O caminho que funciona e a API de afiliados: SHOPEE_APP_ID e
     * SHOPEE_SECRET, que saem quando a conta e aprovada no programa.
     */
    public function disponivel(): bool
    {
        if (!Config::booleano('config.shopee.usar_navegador', false)) {
            return false;
        }

        return is_dir($this->perfil()) && is_dir($this->perfil() . '/Default');
    }

    /**
     * Diagnostico para o comando de teste.
     *
     * @return array{ok:bool,detalhe:string}
     */
    public function testar(): array
    {
        $temPerfil = is_dir($this->perfil()) && is_dir($this->perfil() . '/Default');

        if (!$temPerfil) {
            return [
                'ok'      => false,
                'detalhe' => 'Sem perfil de navegador. Rode: php bin/mlgroup shopee-login',
            ];
        }

        try {
            $logado = $this->logado();
        } catch (Throwable $erro) {
            return ['ok' => false, 'detalhe' => 'Falha ao consultar: ' . $erro->getMessage()];
        } finally {
            $this->fechar();
        }

        if (!$logado) {
            return [
                'ok'      => false,
                'detalhe' => 'A Shopee recusou a consulta. Ou a sessao expirou (php bin/mlgroup '
                    . 'shopee-login), ou e o bloqueio dela a acesso automatizado (erro 90309999).',
            ];
        }

        return [
            'ok'      => true,
            'detalhe' => 'Sessao valida em ' . $this->perfil(),
        ];
    }

    /**
     * Coleta uma busca.
     *
     * @param  array<string,mixed> $busca
     * @return Produto[]
     */
    public function coletar(array $busca, int $limite): array
    {
        $termo = trim((string) ($busca['termo'] ?? $busca['nome'] ?? ''));

        if ($termo === '') {
            Logger::i()->aviso('Busca da Shopee sem termo', ['busca' => $busca['nome'] ?? '?']);

            return [];
        }

        $produtos = [];

        try {
            $porPagina = min(self::POR_PAGINA, max(1, $limite));
            $resposta  = $this->consultar($termo, $porPagina, (bool) ($busca['novidades'] ?? false));

            if (($resposta['is_login'] ?? false) !== true) {
                Logger::i()->aviso('Shopee sem sessao valida - rode: php bin/mlgroup shopee-login', [
                    'erro' => $resposta['error'] ?? null,
                ]);

                return [];
            }

            foreach ($resposta['items'] ?? [] as $item) {
                $produto = $this->montarProduto(is_array($item) ? $item : []);

                if ($produto !== null) {
                    $produtos[] = $produto;
                }

                if (count($produtos) >= $limite) {
                    break;
                }
            }
        } catch (Throwable $erro) {
            Logger::i()->erro('Falha ao coletar na Shopee', ['motivo' => $erro->getMessage()]);
        } finally {
            /*
             * Fecha sempre. O perfil e uma pasta unica e o Chrome a tranca
             * enquanto roda: deixar aberto faz a proxima coleta (ou o login)
             * falhar com o perfil em uso - o mesmo problema que ja mordeu no
             * Link Builder do Mercado Livre.
             */
            $this->fechar();
        }

        return $produtos;
    }

    /**
     * Chama o endpoint de busca e devolve o JSON.
     *
     * @return array<string,mixed>
     */
    private function consultar(string $termo, int $porPagina, bool $novidades): array
    {
        $url = sprintf(
            self::BUSCA,
            $novidades ? 'ctime' : 'relevancy',
            rawurlencode($termo),
            $porPagina,
            $novidades ? 1 : 0,
        );

        /*
         * fetch de dentro da pagina, e nao navegacao ate a URL.
         *
         * Indo direto, o Chrome trata o JSON como documento e a Shopee recusa
         * por falta dos cabecalhos que o site envia. Pedindo de dentro de
         * shopee.com.br, a requisicao sai com origem, referer e cookies iguais
         * aos de um clique de verdade.
         */
        $js = 'fetch(' . json_encode($url) . ', {credentials: "include", headers: {'
            . '"x-api-source": "pc", "x-requested-with": "XMLHttpRequest",'
            . '"af-ac-enc-dat": "null"'
            . '}}).then(r => r.text())';

        $bruto = $this->navegador()->avaliar('https://shopee.com.br/', $js, true);

        if (!is_string($bruto) || $bruto === '') {
            return [];
        }

        $dados = json_decode($bruto, true);

        return is_array($dados) ? $dados : [];
    }

    /**
     * Converte um item da Shopee em Produto.
     *
     * @param array<string,mixed> $item
     */
    private function montarProduto(array $item): ?Produto
    {
        $base = $item['item_basic'] ?? $item;

        if (!is_array($base)) {
            return null;
        }

        $itemId = (int) ($base['itemid'] ?? 0);
        $shopId = (int) ($base['shopid'] ?? 0);
        $nome   = trim((string) ($base['name'] ?? ''));

        if ($itemId === 0 || $shopId === 0 || $nome === '') {
            return null;
        }

        /*
         * A Shopee guarda dinheiro em centavos de milhar (100000 = R$ 1,00).
         * Dividir por 100 daria R$ 1.000,00 num produto de um real - e o filtro
         * de preco reprovaria tudo sem que o motivo aparecesse em lugar nenhum.
         */
        $preco = ((float) ($base['price'] ?? 0)) / 100000;

        if ($preco <= 0) {
            return null;
        }

        $antes    = ((float) ($base['price_before_discount'] ?? 0)) / 100000;
        $desconto = (int) ($base['raw_discount'] ?? 0);

        if ($antes <= $preco && $desconto > 0 && $desconto < 100) {
            $antes = round($preco / (1 - $desconto / 100), 2);
        }

        $imagem = (string) ($base['image'] ?? '');

        return new Produto(
            mlId:            'SHP' . $shopId . '-' . $itemId,
            titulo:          $nome,
            permalink:       $this->linkDoProduto($nome, $shopId, $itemId),
            preco:           $preco,
            precoOriginal:   $antes > $preco ? $antes : 0.0,
            thumb:           $imagem !== '' ? 'https://down-br.img.susercontent.com/file/' . $imagem : '',
            vendedor:        trim((string) ($base['shop_name'] ?? '')),
            freteGratis:     ($base['show_free_shipping'] ?? false) === true,
            vendidos:        (int) ($base['historical_sold'] ?? 0),
            avaliacao:       (float) ($base['item_rating']['rating_star'] ?? 0),
            totalAvaliacoes: (int) ($base['item_rating']['rating_count'][0] ?? 0),
            origem:          'shopee-navegador',
            loja:            'shopee',
        );
    }

    /** URL publica do anuncio, no formato que a Shopee usa. */
    private function linkDoProduto(string $nome, int $shopId, int $itemId): string
    {
        $apelido = preg_replace('/[^A-Za-z0-9]+/', '-', $nome) ?? '';
        $apelido = trim(mb_substr($apelido, 0, 60), '-');

        return 'https://shopee.com.br/' . ($apelido !== '' ? $apelido : 'produto')
            . '-i.' . $shopId . '.' . $itemId;
    }

    private function navegador(): ChromeHeadless
    {
        // com janela: o headless e recusado pela Shopee mesmo com a sessao valida
        return $this->navegador ??= new ChromeHeadless($this->perfil(), comJanela: true);
    }

    private function fechar(): void
    {
        if ($this->navegador !== null) {
            $this->navegador->encerrar();
            $this->navegador = null;
        }
    }
}
