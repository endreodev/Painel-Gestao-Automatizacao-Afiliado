<?php

declare(strict_types=1);

namespace MlGroup\Scraper\Navegador;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use MlGroup\Model\Produto;
use MlGroup\Support\Logger;
use MlGroup\Support\Str;

/**
 * Traduz o HTML renderizado das paginas de lista/ofertas do ML em Produto[].
 *
 * O ML troca de marcacao com frequencia (ui-search-* virou poly-*), entao cada
 * campo tem varias estrategias de extracao e o card so e descartado quando nao
 * sobra nem titulo nem preco.
 */
final class ParserMercadoLivre
{
    /** Seletores de card, do mais especifico para o mais generico. */
    private const XPATH_CARDS = [
        "//li[contains(@class,'ui-search-layout__item')]",
        "//div[contains(@class,'poly-card')]",
        "//div[contains(@class,'promotion-item')]",
        "//li[contains(@class,'promotion-item')]",
        "//div[contains(@class,'andes-card')][.//*[contains(@class,'andes-money-amount__fraction')]]",
    ];

    /**
     * Marcadores da pagina de verificacao que o ML serve quando classifica o
     * acesso como automatizado. Reconhecer isso importa: o sintoma (zero
     * produtos) e igual ao de uma mudanca de marcacao, mas a causa e o
     * tratamento sao completamente diferentes.
     */
    private const MARCAS_BLOQUEIO = [
        'suspicious-traffic',
        'gz-account-verification',
        'captcha',
        'Verifique que voc',
    ];

    public static function pareceBloqueio(string $html): bool
    {
        foreach (self::MARCAS_BLOQUEIO as $marca) {
            if (str_contains($html, $marca)) {
                return true;
            }
        }

        return false;
    }

    /** @return Produto[] */
    public function extrair(string $html, string $origem = 'navegador'): array
    {
        if (trim($html) === '') {
            return [];
        }

        $doc = new DOMDocument();

        $anterior = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        $xpath = new DOMXPath($doc);
        $cards = $this->localizarCards($xpath);

        if ($cards === []) {
            Logger::i()->aviso(
                self::pareceBloqueio($html)
                    ? 'O ML devolveu pagina de verificacao de trafego'
                    : 'Nenhum card reconhecido no HTML - marcacao do ML pode ter mudado'
            );

            return [];
        }

        $produtos = [];
        $vistos   = [];

        foreach ($cards as $card) {
            $produto = $this->montarProduto($xpath, $card, $origem);

            if ($produto === null || isset($vistos[$produto->mlId])) {
                continue;
            }

            $vistos[$produto->mlId] = true;
            $produtos[]             = $produto;
        }

        return $produtos;
    }

    /** @return DOMElement[] */
    private function localizarCards(DOMXPath $xpath): array
    {
        foreach (self::XPATH_CARDS as $consulta) {
            $nos = $xpath->query($consulta);

            if ($nos === false || $nos->length === 0) {
                continue;
            }

            $cards = [];

            foreach ($nos as $no) {
                if ($no instanceof DOMElement) {
                    $cards[] = $no;
                }
            }

            if ($cards !== []) {
                return $cards;
            }
        }

        return [];
    }

    private function montarProduto(DOMXPath $xpath, DOMElement $card, string $origem): ?Produto
    {
        $href = $this->primeiroTexto($xpath, $card, [
            ".//a[contains(@class,'poly-component__title')]/@href",
            ".//a[contains(@class,'ui-search-link')]/@href",
            ".//a[contains(@class,'promotion-item__link')]/@href",
            './/a/@href',
        ]);

        $mlId = $this->extrairId($href);

        if ($mlId === '') {
            return null;
        }

        $titulo = $this->primeiroTexto($xpath, $card, [
            ".//a[contains(@class,'poly-component__title')]",
            ".//h2[contains(@class,'ui-search-item__title')]",
            ".//p[contains(@class,'promotion-item__title')]",
            './/h2',
            './/h3',
            ".//a[contains(@class,'ui-search-link')]/@title",
        ]);

        if ($titulo === '') {
            return null;
        }

        $preco = $this->precoAtual($xpath, $card);

        if ($preco <= 0) {
            return null;
        }

        $precoOriginal = $this->precoOriginal($xpath, $card);

        // quando o card so mostra "x% OFF" sem riscar o valor antigo, reconstroi o original
        if ($precoOriginal <= $preco) {
            $desconto = $this->descontoDeclarado($xpath, $card);

            if ($desconto > 0 && $desconto < 100) {
                $precoOriginal = round($preco / (1 - ($desconto / 100)), 2);
            }
        }

        $textoCard = Str::normalizar($card->textContent);

        [$avaliacao, $totalAvaliacoes] = $this->avaliacoes($xpath, $card);

        return new Produto(
            mlId:            $mlId,
            titulo:          $titulo,
            permalink:       $this->limparUrl($href),
            preco:           $preco,
            precoOriginal:   $precoOriginal,
            thumb:           $this->imagem($xpath, $card),
            categoriaId:     '',
            categoriaNome:   '',
            marca:           $this->primeiroTexto($xpath, $card, [
                ".//span[contains(@class,'poly-component__brand')]",
            ]),
            vendedor:        $this->primeiroTexto($xpath, $card, [
                ".//span[contains(@class,'poly-component__seller')]",
                ".//p[contains(@class,'ui-search-official-store-label')]",
            ]),
            freteGratis:     str_contains($textoCard, 'frete gratis') || str_contains($textoCard, 'envio gratis'),
            full:            str_contains($textoCard, 'full'),
            vendidos:        $this->vendidos($textoCard),
            avaliacao:       $avaliacao,
            totalAvaliacoes: $totalAvaliacoes,
            origem:          $origem,
        );
    }

    private function precoAtual(DOMXPath $xpath, DOMElement $card): float
    {
        $consultas = [
            ".//div[contains(@class,'poly-price__current')]//span[contains(@class,'andes-money-amount')][1]",
            ".//span[contains(@class,'poly-price__amount')][1]",
            ".//span[contains(@class,'andes-money-amount--cents-superscript')][1]",
            ".//span[contains(@class,'andes-money-amount')][not(ancestor::s)][not(contains(@class,'previous'))][1]",
        ];

        foreach ($consultas as $consulta) {
            $nos = $xpath->query($consulta, $card);

            if ($nos === false || $nos->length === 0) {
                continue;
            }

            $primeiro = $nos->item(0);

            if (!$primeiro instanceof DOMNode) {
                continue;
            }

            $valor = $this->valorMonetario($xpath, $primeiro);

            if ($valor > 0) {
                return $valor;
            }
        }

        return 0.0;
    }

    private function precoOriginal(DOMXPath $xpath, DOMElement $card): float
    {
        $consultas = [
            ".//s[contains(@class,'andes-money-amount')][1]",
            ".//*[contains(@class,'andes-money-amount--previous')][1]",
            ".//*[contains(@class,'ui-search-price__original-value')][1]",
        ];

        foreach ($consultas as $consulta) {
            $nos = $xpath->query($consulta, $card);

            if ($nos === false || $nos->length === 0) {
                continue;
            }

            $primeiro = $nos->item(0);

            if (!$primeiro instanceof DOMNode) {
                continue;
            }

            $valor = $this->valorMonetario($xpath, $primeiro);

            if ($valor > 0) {
                return $valor;
            }
        }

        return 0.0;
    }

    /** Le "1.234" + "56" das spans de fracao/centavos, com fallback no texto puro. */
    private function valorMonetario(DOMXPath $xpath, DOMNode $no): float
    {
        $fracao = $xpath->query(".//span[contains(@class,'andes-money-amount__fraction')]", $no);

        if ($fracao !== false && $fracao->length > 0) {
            $inteiro = preg_replace('/\D/', '', $fracao->item(0)?->textContent ?? '') ?? '';

            $centavosNos = $xpath->query(".//span[contains(@class,'andes-money-amount__cents')]", $no);
            $centavos    = '00';

            if ($centavosNos !== false && $centavosNos->length > 0) {
                $centavos = preg_replace('/\D/', '', $centavosNos->item(0)?->textContent ?? '') ?? '00';
            }

            if ($inteiro !== '') {
                return (float) ($inteiro . '.' . str_pad(substr($centavos . '00', 0, 2), 2, '0'));
            }
        }

        return Str::paraDecimal($no->textContent);
    }

    private function descontoDeclarado(DOMXPath $xpath, DOMElement $card): float
    {
        $texto = $this->primeiroTexto($xpath, $card, [
            ".//span[contains(@class,'andes-money-amount__discount')]",
            ".//span[contains(@class,'ui-search-price__discount')]",
            ".//p[contains(@class,'promotion-item__discount-text')]",
        ]);

        if ($texto !== '' && preg_match('/(\d+)\s*%/', $texto, $achado) === 1) {
            return (float) $achado[1];
        }

        return 0.0;
    }

    /** @return array{0:float,1:int} */
    private function avaliacoes(DOMXPath $xpath, DOMElement $card): array
    {
        $nota = (float) str_replace(',', '.', $this->primeiroTexto($xpath, $card, [
            ".//span[contains(@class,'poly-reviews__rating')]",
            ".//span[contains(@class,'ui-search-reviews__rating-number')]",
        ]));

        $totalTexto = $this->primeiroTexto($xpath, $card, [
            ".//span[contains(@class,'poly-reviews__total')]",
            ".//span[contains(@class,'ui-search-reviews__amount')]",
        ]);

        $total = 0;

        if ($totalTexto !== '' && preg_match('/(\d+)/', str_replace('.', '', $totalTexto), $achado) === 1) {
            $total = (int) $achado[1];
        }

        return [$nota > 5 ? 0.0 : $nota, $total];
    }

    private function vendidos(string $textoNormalizado): int
    {
        if (preg_match('/(\d[\d.]*)\s*(mil)?\s*vendid/', $textoNormalizado, $achado) !== 1) {
            return 0;
        }

        $quantidade = (int) str_replace('.', '', $achado[1]);

        return ($achado[2] ?? '') === 'mil' ? $quantidade * 1000 : $quantidade;
    }

    private function imagem(DOMXPath $xpath, DOMElement $card): string
    {
        foreach (['.//img/@data-src', './/img/@src'] as $consulta) {
            $valor = $this->primeiroTexto($xpath, $card, [$consulta]);

            if ($valor !== '' && !str_starts_with($valor, 'data:image')) {
                return $valor;
            }
        }

        return '';
    }

    /** @param string[] $consultas */
    private function primeiroTexto(DOMXPath $xpath, DOMElement $card, array $consultas): string
    {
        foreach ($consultas as $consulta) {
            $nos = $xpath->query($consulta, $card);

            if ($nos === false || $nos->length === 0) {
                continue;
            }

            $texto = trim(preg_replace('/\s+/u', ' ', $nos->item(0)?->textContent ?? '') ?? '');

            if ($texto !== '') {
                return $texto;
            }
        }

        return '';
    }

    /** Extrai MLB1234567890 / MLB-1234567890 da URL do anuncio. */
    private function extrairId(string $url): string
    {
        if (preg_match('/(ML[A-Z]-?\d{6,})/i', $url, $achado) === 1) {
            return strtoupper(str_replace('-', '', $achado[1]));
        }

        return '';
    }

    /** Remove tracking do permalink para nao poluir o link de afiliado. */
    private function limparUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $partes = parse_url($url);

        if ($partes === false || !isset($partes['host'])) {
            return $url;
        }

        return ($partes['scheme'] ?? 'https') . '://' . $partes['host'] . ($partes['path'] ?? '');
    }
}
