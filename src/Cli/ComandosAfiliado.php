<?php

declare(strict_types=1);

namespace MlGroup\Cli;

use MlGroup\Afiliado\LinkBuilder;
use MlGroup\Support\Config;
use MlGroup\Support\Env;

/**
 * Comandos ligados ao Programa de Afiliados: login no Mercado Livre e geracao
 * de link pela tela oficial.
 */
final class ComandosAfiliado
{
    public function __construct(
        private readonly Saida $saida,
        private readonly LinkBuilder $construtor = new LinkBuilder(),
    ) {
    }

    /**
     * Abre o navegador para o usuario entrar na conta do Mercado Livre.
     *
     * O login precisa ser feito por ele: a tela do ML usa reCAPTCHA, que existe
     * exatamente para nao ser resolvido por automacao. Depois disso a sessao
     * fica salva no perfil e o modo headless a reaproveita.
     */
    public function login(): int
    {
        $this->saida->linha('');
        $this->saida->titulo('  Entrar no Mercado Livre');
        $this->saida->linha('');
        $this->saida->linha('  Vou abrir uma janela do navegador. Nela:', '90');
        $this->saida->linha('');
        $this->saida->linha('    1. Entre na sua conta do Mercado Livre');
        $this->saida->linha('    2. Confirme que a tela do Link Builder abriu');
        $this->saida->linha('    3. Feche a janela');
        $this->saida->linha('');

        $this->construtor->abrirLogin();

        $this->saida->linha('  Perfil: ' . $this->construtor->perfil(), '90');
        $this->saida->linha('');

        if (!$this->saida->interativo()) {
            $this->saida->linha('  Depois de fechar a janela, confira com: php bin/mlgroup link <url-do-produto>', '90');
            $this->saida->linha('');

            return 0;
        }

        $this->saida->escrever('  Terminou o login e fechou a janela? Aperte Enter para eu conferir... ');
        fgets(STDIN);

        $this->saida->linha('');
        $this->saida->linha('  Conferindo a sessao...', '90');

        if (!$this->construtor->logado()) {
            $this->saida->linha('  Ainda aparece a tela de login. Rode o comando de novo.', '31');
            $this->saida->linha('');

            return 1;
        }

        $this->saida->linha('  Sessao valida.', '32');

        Config::definir('config.afiliado.modo', 'linkbuilder');

        $this->saida->linha('');
        $this->saida->linha('  Para os links passarem a sair da tela oficial, deixe em', '90');
        $this->saida->linha('  config/config.php:  afiliado.modo => \'linkbuilder\'', '90');
        $this->saida->linha('');
        $this->saida->linha('  Teste com: php bin/mlgroup link <url-de-um-produto>', '90');
        $this->saida->linha('');

        return 0;
    }

    /**
     * Gera (ou diagnostica) o link de um produto pela tela oficial.
     *
     * Quando o link nao sai, imprime os campos e botoes que a tela tinha - e
     * com isso da para preencher afiliado.seletor_campo / seletor_botao sem
     * precisar adivinhar.
     */
    public function link(string $urlProduto): int
    {
        $this->saida->linha('');

        if ($urlProduto === '') {
            $this->saida->linha('  Informe a URL do produto:', '33');
            $this->saida->linha('  php bin/mlgroup link https://produto.mercadolivre.com.br/MLB-...', '90');
            $this->saida->linha('');

            return 1;
        }

        $this->saida->linha('  Produto: ' . $urlProduto, '90');
        $this->saida->linha('  Abrindo o Link Builder...', '90');

        $resultado = $this->construtor->tentar($urlProduto);

        if ($resultado === null) {
            $this->saida->linha('  Nao consegui abrir a tela. Veja storage/logs.', '31');
            $this->saida->linha('');

            return 1;
        }

        if (($resultado['precisa_login'] ?? false) === true) {
            $this->saida->linha('  A tela pediu login. Rode: php bin/mlgroup ml-login', '31');
            $this->saida->linha('');

            return 1;
        }

        $link = $resultado['link'] ?? null;

        if (is_string($link) && $link !== '') {
            $this->saida->linha('');
            $this->saida->linha('  Link gerado:', '32');
            $this->saida->linha('  ' . $link);
            $this->saida->linha('');

            return 0;
        }

        $this->saida->linha('');
        $this->saida->linha('  O link nao saiu: ' . (string) ($resultado['erro'] ?? 'motivo desconhecido'), '33');
        $this->saida->linha('');
        $this->saida->linha('  O que a tela mostrava:', '1');

        $this->listar('campos', $resultado['campos'] ?? []);
        $this->listar('botoes', $resultado['botoes'] ?? []);

        if (isset($resultado['depois']) && is_array($resultado['depois'])) {
            $this->saida->linha('');
            $this->saida->linha('  o que ficou na tela depois do clique:', '90');

            foreach ($resultado['depois'] as $chave => $valor) {
                $texto = is_array($valor)
                    ? ($valor === [] ? '(nenhum)' : implode(' | ', array_map('strval', $valor)))
                    : var_export($valor, true);

                $this->saida->linha('    ' . $chave . ': ' . mb_substr($texto, 0, 400));
            }
        }

        $this->saida->linha('');
        $this->saida->linha('  Use isso para preencher, em config/config.php:', '90');
        $this->saida->linha('    afiliado.seletor_campo  e  afiliado.seletor_botao', '90');
        $this->saida->linha('');

        return 1;
    }

    /** @param mixed $itens */
    private function listar(string $titulo, mixed $itens): void
    {
        $this->saida->linha('');
        $this->saida->linha('  ' . $titulo . ':', '90');

        if (!is_array($itens) || $itens === []) {
            $this->saida->linha('    (nenhum)', '90');

            return;
        }

        foreach ($itens as $item) {
            if (!is_array($item)) {
                continue;
            }

            $partes = [];

            foreach ($item as $chave => $valor) {
                if (is_scalar($valor) && (string) $valor !== '') {
                    $partes[] = $chave . '=' . $valor;
                }
            }

            if ($partes !== []) {
                $this->saida->linha('    ' . implode('  ', $partes));
            }
        }
    }

    /** Mostra como o link esta sendo montado hoje. */
    public function situacao(): int
    {
        $modo = Config::texto('config.afiliado.modo', 'modelo');

        $this->saida->linha('');
        $this->saida->titulo('  Afiliado');
        $this->saida->linha('');
        $this->saida->linha('  modo       : ' . $modo);
        $this->saida->linha('  tag        : ' . (Env::texto('ML_AFILIADO_TAG') ?: '(vazia)'));
        $this->saida->linha('  ferramenta : ' . (Env::texto('ML_AFILIADO_FERRAMENTA') ?: '(vazia)'));

        if ($modo === 'linkbuilder') {
            $this->saida->linha('  perfil     : ' . $this->construtor->perfil(), '90');
        } else {
            $this->saida->linha('  modelo     : ' . Config::texto('config.afiliado.modelo'), '90');
        }

        $this->saida->linha('');

        return 0;
    }
}
