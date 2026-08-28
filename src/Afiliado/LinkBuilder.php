<?php

declare(strict_types=1);

namespace MlGroup\Afiliado;

use MlGroup\Database\Db;
use MlGroup\Scraper\Navegador\ChromeHeadless;
use MlGroup\Support\Config;
use MlGroup\Support\Logger;
use Throwable;

/**
 * Gera o link de afiliado pela tela oficial do Mercado Livre.
 *
 * Por que nao montar o link por template: o link real carrega um parametro
 * "ref" opaco, gerado do lado do ML a cada link. Nao da para reproduzir por
 * concatenacao de string - so a propria tela devolve o link completo.
 *
 * A tela exige login (com reCAPTCHA), entao o navegador usa um perfil fixo em
 * storage/navegador-ml/. O login e feito uma vez, pelo usuario, no comando
 * `ml-login`; daqui em diante a sessao e reaproveitada.
 *
 * Cada link gerado fica em cache no banco: a tela e lenta e nao ha motivo para
 * pedir duas vezes o link do mesmo anuncio.
 */
final class LinkBuilder
{
    private const URL = 'https://www.mercadolivre.com.br/afiliados/linkbuilder#hub';

    private ?ChromeHeadless $navegador = null;

    public function perfil(): string
    {
        // Config::texto devolve a string vazia quando a chave existe vazia,
        // e nao o padrao - por isso o fallback fica aqui
        $configurado = Config::texto('config.afiliado.perfil_navegador');

        return $configurado !== '' ? $configurado : MLG_ROOT . '/storage/navegador-ml';
    }

    public function navegador(): ChromeHeadless
    {
        return $this->navegador ??= new ChromeHeadless($this->perfil());
    }

    /** Abre a janela de login do Mercado Livre para o usuario se autenticar. */
    public function abrirLogin(): void
    {
        $this->navegador()->abrirJanelaDeLogin('https://www.mercadolivre.com.br/afiliados/linkbuilder');
    }

    /** A sessao salva ainda esta valida? */
    public function logado(): bool
    {
        $titulo = $this->executar(self::URL, 'document.title');

        if (!is_string($titulo)) {
            return false;
        }

        return !$this->pareceLogin($titulo);
    }

    /**
     * Devolve o link de afiliado do produto, ou null quando nao consegue.
     *
     * Nunca lanca: link ruim nao pode impedir o envio de uma oferta. Quem chama
     * decide o que fazer com o null (o LinkAfiliado cai para o modelo).
     */
    public function gerar(string $urlProduto, string $mlId = ''): ?string
    {
        $cache = $this->doCache($mlId, $urlProduto);

        if ($cache !== null) {
            return $cache;
        }

        $resultado = $this->tentar($urlProduto);

        if (!is_array($resultado)) {
            return null;
        }

        $link = $resultado['link'] ?? null;

        if (!is_string($link) || $link === '') {
            Logger::i()->aviso('Link Builder nao devolveu link', [
                'motivo' => $resultado['erro'] ?? 'desconhecido',
            ]);

            return null;
        }

        $this->guardar($mlId, $urlProduto, $link);

        return $link;
    }

    /**
     * Roda a automacao e devolve o diagnostico completo da pagina.
     *
     * Vale tanto para gerar quanto para depurar: quando o link nao sai, a mesma
     * chamada ja traz os campos e botoes que existiam na tela.
     *
     * @return array<string,mixed>|null
     */
    public function tentar(string $urlProduto): ?array
    {
        try {
            $bruto = $this->executar(self::URL, $this->script($urlProduto), true);
        } catch (Throwable $erro) {
            Logger::i()->erro('Falha ao abrir o Link Builder', ['motivo' => $erro->getMessage()]);

            return null;
        } finally {
            $this->fechar();
        }

        if (!is_array($bruto)) {
            return null;
        }

        if (($bruto['precisa_login'] ?? false) === true) {
            Logger::i()->erro('Link Builder pediu login - rode: php bin/mlgroup ml-login');
        }

        return $bruto;
    }

    private function executar(string $url, string $script, bool $promessa = false): mixed
    {
        return $this->navegador()->avaliar($url, $script, $promessa);
    }

    /**
     * Encerra o navegador e libera o perfil.
     *
     * O Chrome nao aceita duas instancias no mesmo user-data-dir. Como o laco de
     * publicacao gera link a cada ciclo com este mesmo perfil, deixar o
     * navegador residente travava o perfil para todo o resto - `ml-login` e
     * `link` passavam a falhar com "a porta de depuracao nao respondeu". Um link
     * a cada dez minutos nao justifica manter o Chrome de pe.
     */
    private function fechar(): void
    {
        $this->navegador?->encerrar();
        $this->navegador = null;
    }

    private function pareceLogin(string $texto): bool
    {
        $texto = mb_strtolower($texto);

        foreach (['iniciar sessão', 'iniciar sessao', 'entre na sua conta', 'login', 'e-mail ou telefone'] as $marca) {
            if (str_contains($texto, $marca)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Script que roda dentro da pagina.
     *
     * Escrito para nao depender de nome de classe do ML: procura o campo pelo
     * que ele diz de si (placeholder, label, aria-label) e o botao pelo texto.
     * Devolve sempre um diagnostico, mesmo quando falha, para permitir ajustar
     * os seletores sem uma nova rodada as cegas.
     */
    private function script(string $urlProduto): string
    {
        $alvo    = json_encode($urlProduto, JSON_UNESCAPED_SLASHES);
        $espera  = Config::inteiro('config.afiliado.espera_linkbuilder_ms', 12000);
        $seletor = json_encode(Config::texto('config.afiliado.seletor_campo'));
        $tipo    = json_encode(Config::texto('config.afiliado.tipo_link', 'curto'));
        $botao   = json_encode(Config::texto('config.afiliado.seletor_botao'));

        return <<<JS
        (async () => {
            const alvo = {$alvo};
            const seletorCampo = {$seletor};
            const seletorBotao = {$botao};
            const tipoLink = {$tipo};
            const espera = ms => new Promise(r => setTimeout(r, ms));
            const visivel = e => e.offsetParent !== null || e.getClientRects().length > 0;
            const texto = e => (e.innerText || e.textContent || '').trim();

            const diagnostico = { link: null, erro: null, precisa_login: false, campos: [], botoes: [] };

            if (/iniciar sess|entre na sua conta|e-mail ou telefone/i.test(document.title)) {
                diagnostico.precisa_login = true;
                diagnostico.erro = 'a tela pediu login';
                return diagnostico;
            }

            const campos = [...document.querySelectorAll('input, textarea')].filter(visivel);

            diagnostico.campos = campos.slice(0, 12).map(e => ({
                tag: e.tagName.toLowerCase(),
                type: e.type || '',
                id: e.id || '',
                name: e.name || '',
                placeholder: e.placeholder || '',
                aria: e.getAttribute('aria-label') || '',
            }));

            const botoes = [...document.querySelectorAll('button, a[role=button]')].filter(visivel);

            diagnostico.botoes = botoes.slice(0, 12).map(e => ({
                texto: texto(e).slice(0, 40),
                classe: (e.className || '').toString().slice(0, 50),
            }));

            // 1) campo onde entra a URL do produto
            const pista = /link|url|cole|endere|mercadolivre\.com/i;

            // a barra de busca do proprio site tambem diz "produtos" no
            // placeholder e ja foi escolhida por engano
            const buscaDoSite = e =>
                /as_word|cb1-edit|nav-search/i.test((e.name || '') + ' ' + (e.id || '') + ' ' + (e.className || ''));

            let campo = seletorCampo ? document.querySelector(seletorCampo) : null;

            if (!campo) {
                const candidatos = campos.filter(e => !buscaDoSite(e));

                campo = candidatos.find(e =>
                        e.tagName === 'TEXTAREA'
                        && pista.test((e.placeholder || '') + ' ' + (e.getAttribute('aria-label') || ''))
                    )
                    || candidatos.find(e => e.tagName === 'TEXTAREA')
                    || candidatos.find(e =>
                        pista.test((e.placeholder || '') + ' ' + (e.getAttribute('aria-label') || '') + ' ' + (e.name || ''))
                    )
                    || candidatos.find(e => ['text', 'url'].includes((e.type || '').toLowerCase()));
            }

            if (!campo) {
                diagnostico.erro = 'campo de URL nao encontrado';
                return diagnostico;
            }

            // setter nativo: componentes React ignoram atribuicao direta de value
            const proto = campo.tagName === 'TEXTAREA' ? HTMLTextAreaElement : HTMLInputElement;
            const setter = Object.getOwnPropertyDescriptor(proto.prototype, 'value').set;

            campo.focus();
            setter.call(campo, alvo);
            campo.dispatchEvent(new Event('input', { bubbles: true }));
            campo.dispatchEvent(new Event('change', { bubbles: true }));

            await espera(600);

            // 2) botao de gerar
            let botao = seletorBotao ? document.querySelector(seletorBotao) : null;

            if (!botao) {
                botao = botoes.find(e => /gerar|criar|encurtar|obter/i.test(texto(e)));
            }

            if (botao) {
                botao.click();
            } else {
                campo.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true }));
            }

            /*
             * A tela oferece "Link curto" (meli.la) e "Link completo"
             * (mercadolivre.com.br). Os dois apontam para o mesmo lugar, mas o
             * completo mostra o dominio do ML no texto da mensagem - num grupo
             * de WhatsApp isso conta, porque encurtador desconhecido gera
             * desconfianca. Por padrao o ML ja vem no curto.
             */
            if (tipoLink === 'completo') {
                await espera(2500);

                for (const rotulo of document.querySelectorAll('label.andes-radio__label')) {
                    if (!/completo/i.test(texto(rotulo))) {
                        continue;
                    }

                    const radio = rotulo.closest('.andes-radio')?.querySelector('input[type=radio]') || rotulo;

                    radio.click();
                    break;
                }
            }

            // 3) espera o link aparecer em algum lugar da tela
            // o ML devolve o link de afiliado encurtado em meli.la, e nao no
            // dominio principal - exigir "mercadolivre" descartava justamente o
            // link que interessa
            const ehLink = v => typeof v === 'string'
                && /^https?:\\/\\//.test(v)
                && /meli\\.la|mercadolivre|mercadolibre|mercadoli\\.br/.test(v)
                && v !== alvo
                && !v.includes('/afiliados/linkbuilder');

            const limite = Date.now() + {$espera};

            while (Date.now() < limite) {
                for (const e of document.querySelectorAll('input, textarea')) {
                    if (ehLink(e.value)) {
                        diagnostico.link = e.value.trim();
                        return diagnostico;
                    }
                }

                for (const e of document.querySelectorAll('a[href]')) {
                    const href = e.getAttribute('href') || '';

                    if (ehLink(href) && /meli\\.la|sec\\/|matt_word|matt_tool/.test(href)) {
                        diagnostico.link = href.trim();
                        return diagnostico;
                    }
                }

                // o botao "Copiar" guarda o link pronto no proprio atributo
                for (const e of document.querySelectorAll('[data-clipboard-text]')) {
                    const valor = e.getAttribute('data-clipboard-text') || '';

                    if (ehLink(valor)) {
                        diagnostico.link = valor.trim();
                        return diagnostico;
                    }
                }

                const achado = (document.body.innerText || '').match(/https?:\\/\\/[^\\s"']*(?:meli\\.la|sec\\/|matt_word)[^\\s"']*/);

                if (achado) {
                    diagnostico.link = achado[0].trim();
                    return diagnostico;
                }

                await espera(500);
            }

            diagnostico.erro = 'o link nao apareceu no tempo esperado';

            // o que ficou na tela depois do clique: sem isto o ajuste seria as cegas
            diagnostico.depois = {
                campo_preenchido: (campo.value || '').slice(0, 120),
                botao_encontrado: !!botao,
                botao_desabilitado: botao ? !!botao.disabled : null,
                urls_na_tela: [...document.querySelectorAll('input, textarea')]
                    .map(e => (e.value || '').trim())
                    .filter(v => /^https?:/.test(v))
                    .slice(0, 5),
                copiaveis: [...document.querySelectorAll('[data-clipboard-text], [data-clipboard], [class*=copy], [class*=copiar]')]
                    .map(e => (e.getAttribute('data-clipboard-text') || texto(e)).slice(0, 120))
                    .filter(Boolean)
                    .slice(0, 5),
                mensagens: [...document.querySelectorAll('[class*=error], [class*=message], [class*=alert], [class*=feedback], [role=alert]')]
                    .map(e => texto(e).slice(0, 120))
                    .filter(Boolean)
                    .slice(0, 6),
                trecho: (document.body.innerText || '').replace(/\s+/g, ' ').slice(0, 700),
            };

            return diagnostico;
        })()
        JS;
    }

    /**
     * Chave do cache.
     *
     * Inclui o tipo de link: trocar de 'curto' para 'completo' precisa gerar de
     * novo, senao o cache devolveria para sempre o formato antigo.
     */
    private function chaveCache(string $mlId, string $urlProduto): string
    {
        $base = $mlId !== '' ? $mlId : $urlProduto;

        return $base . '|' . Config::texto('config.afiliado.tipo_link', 'curto');
    }

    private function doCache(string $mlId, string $urlProduto): ?string
    {
        $chave = $this->chaveCache($mlId, $urlProduto);

        $valor = Db::valor(
            'SELECT link FROM links_afiliado WHERE chave = :chave',
            ['chave' => $chave],
        );

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    private function guardar(string $mlId, string $urlProduto, string $link): void
    {
        Db::executar(
            'INSERT INTO links_afiliado (chave, ml_id, url_produto, link, criado_em)
             VALUES (:chave, :ml_id, :url, :link, :agora)
             ON CONFLICT(chave) DO UPDATE SET link = excluded.link, criado_em = excluded.criado_em',
            [
                'chave' => $this->chaveCache($mlId, $urlProduto),
                'ml_id' => $mlId,
                'url'   => $urlProduto,
                'link'  => $link,
                'agora' => date('Y-m-d H:i:s'),
            ],
        );
    }
}
