<?php

declare(strict_types=1);

namespace MlGroup\Afiliado;

use MlGroup\Model\Produto;
use MlGroup\Support\Config;
use MlGroup\Support\Env;
use MlGroup\Support\Http;
use MlGroup\Support\Logger;

/**
 * Monta o link de afiliado a partir do permalink do anuncio.
 *
 * O modelo do link fica em config/config.php > afiliado.modelo, com os
 * marcadores {url}, {url_encoded}, {tag} e {ferramenta}. Assim, se o ML mudar o
 * formato de rastreio, voce ajusta a config sem mexer em codigo.
 *
 * Opcionalmente encurta o link chamando um encurtador HTTP proprio.
 */
final class LinkAfiliado
{
    /** O aviso de tag ausente vale uma vez por execucao, nao por produto. */
    private static bool $avisouSemTag = false;

    public function __construct(
        private readonly Http $http = new Http(),
        private readonly LinkBuilder $linkBuilder = new LinkBuilder(),
    ) {
    }

    public function gerar(Produto $produto): string
    {
        $permalink = $produto->permalink;

        if ($permalink === '') {
            return '';
        }

        /*
         * Shopee: o link ja veio pronto da API, com o rastreio da sua conta
         * dentro. Passar o modelo do Mercado Livre por cima acrescentaria
         * parametros que a Shopee ignora e, pior, jogaria fora o unico
         * identificador que credita a comissao.
         */
        if ($produto->loja === 'shopee') {
            return $produto->linkAfiliado !== '' ? $produto->linkAfiliado : $permalink;
        }

        if (Config::texto('config.afiliado.modo', 'modelo') === 'linkbuilder') {
            $oficial = $this->linkBuilder->gerar($permalink, $produto->mlId);

            if ($oficial !== null) {
                return $oficial;
            }

            // sem link oficial nao se cancela a oferta: cai para o modelo, que
            // ao menos leva a tag, e o aviso ja foi para o log
            Logger::i()->aviso('Link Builder indisponivel, usando o modelo', ['id' => $produto->mlId]);
        }

        $tag = Env::texto('ML_AFILIADO_TAG', Config::texto('config.afiliado.tag'));

        if ($tag === '') {
            if (!self::$avisouSemTag) {
                self::$avisouSemTag = true;
                Logger::i()->aviso('Tag de afiliado nao configurada - os links vao sem rastreio (defina ML_AFILIADO_TAG no .env)');
            }

            return $permalink;
        }

        $modelo = Config::texto(
            'config.afiliado.modelo',
            '{url}?matt_word={tag}&matt_tool={ferramenta}'
        );

        $link = strtr($modelo, [
            '{url}'         => $permalink,
            '{url_encoded}' => rawurlencode($permalink),
            '{tag}'         => rawurlencode($tag),
            '{ferramenta}'  => rawurlencode(
                Env::texto('ML_AFILIADO_FERRAMENTA', Config::texto('config.afiliado.ferramenta', '82183'))
            ),
        ]);

        return $this->encurtar($link);
    }

    /** Aplica o link no produto e devolve o proprio produto. */
    public function aplicar(Produto $produto): Produto
    {
        $produto->linkAfiliado = $this->gerar($produto);

        return $produto;
    }

    /**
     * Encurta via endpoint configurado. Qualquer falha devolve o link original -
     * um link longo e melhor que oferta nao enviada.
     */
    private function encurtar(string $link): string
    {
        if (!Config::booleano('config.afiliado.encurtar', false)) {
            return $link;
        }

        $endpoint = Config::texto('config.afiliado.encurtador_url');

        if ($endpoint === '') {
            return $link;
        }

        $cabecalhos = [];
        $token      = Env::texto('ENCURTADOR_TOKEN');

        if ($token !== '') {
            $cabecalhos['Authorization'] = 'Bearer ' . $token;
        }

        $resposta = $this->http->postJson($endpoint, ['url' => $link], $cabecalhos);

        if (!$resposta->ok()) {
            Logger::i()->aviso('Encurtador falhou', ['status' => $resposta->status]);

            return $link;
        }

        $dados = $resposta->json();

        foreach (Config::lista('config.afiliado.encurtador_campos') as $campo) {
            $curto = $dados[(string) $campo] ?? null;

            if (is_string($curto) && $curto !== '') {
                return $curto;
            }
        }

        return $link;
    }
}
