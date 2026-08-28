<?php

declare(strict_types=1);

namespace MlGroup\Painel;

/**
 * Gráficos em SVG gerado no servidor.
 *
 * Sem biblioteca de terceiros: o painel roda offline, e um SVG de barras não
 * justifica uma dependência. As cores vêm por variável CSS, então o tema claro
 * e o escuro usam a mesma marcação.
 *
 * As formas seguem o mesmo critério em todas as telas: marca fina, ponta
 * arredondada em 4px ancorada na linha de base, 2px de respiro entre barras
 * vizinhas, eixo e grade recessivos, e rótulo direto só onde ajuda a ler - nunca
 * um número sobre cada barra.
 */
final class Grafico
{
    /**
     * Barras verticais para série única ao longo do tempo.
     *
     * @param array<int,array{rotulo:string,valor:float,detalhe?:string}> $pontos
     */
    public function barras(array $pontos, int $altura = 180): string
    {
        if ($pontos === []) {
            return $this->vazio('Sem dados no período.');
        }

        $largura   = 100.0;
        $topo      = 8.0;
        $base      = $altura - 26.0;
        $maximo    = max(1.0, max(array_map(static fn (array $p): float => (float) $p['valor'], $pontos)));
        $quantidade = count($pontos);
        $passo     = $largura / $quantidade;
        $espaco    = min(1.6, $passo * 0.28);
        $larguraBarra = max(0.6, $passo - $espaco);

        $svg = '<svg class="grafico" viewBox="0 0 100 ' . $altura . '" preserveAspectRatio="none" role="img" aria-label="Envios por dia">';

        // grade: três linhas discretas, sem números repetidos
        foreach ([0.0, 0.5, 1.0] as $fracao) {
            $y = $base - ($base - $topo) * $fracao;
            $svg .= '<line class="grade" x1="0" y1="' . $this->n($y) . '" x2="100" y2="' . $this->n($y) . '" />';
        }

        foreach ($pontos as $indice => $ponto) {
            $valor  = (float) $ponto['valor'];
            $altBar = $valor <= 0 ? 0.0 : max(1.2, (($base - $topo) * $valor) / $maximo);
            $x      = ($indice * $passo) + ($espaco / 2);
            $y      = $base - $altBar;

            $titulo = $this->e($ponto['rotulo']) . ' · ' . $this->e($ponto['detalhe'] ?? ((string) (int) $valor));

            if ($valor > 0) {
                $svg .= '<rect class="barra" x="' . $this->n($x) . '" y="' . $this->n($y) . '"'
                    . ' width="' . $this->n($larguraBarra) . '" height="' . $this->n($altBar) . '"'
                    . ' rx="1.1" data-dica="' . $titulo . '">'
                    . '<title>' . $titulo . '</title></rect>';
            } else {
                // dia sem envio ainda ocupa lugar: some da série seria pior que um traço
                $svg .= '<rect class="barra vazia" x="' . $this->n($x) . '" y="' . $this->n($base - 0.8) . '"'
                    . ' width="' . $this->n($larguraBarra) . '" height="0.8" rx="0.4" data-dica="' . $titulo . '">'
                    . '<title>' . $titulo . '</title></rect>';
            }
        }

        $svg .= '<line class="eixo" x1="0" y1="' . $this->n($base) . '" x2="100" y2="' . $this->n($base) . '" />';
        $svg .= '</svg>';

        // rótulos em HTML, não em SVG: o viewBox é esticado e deformaria o texto
        $rotulos = '<div class="eixo-x">';

        foreach ($pontos as $indice => $ponto) {
            // só as pontas e o meio recebem rótulo; o resto vira ruído
            $mostra = $indice === 0 || $indice === $quantidade - 1 || $indice === intdiv($quantidade, 2);

            $rotulos .= '<span>' . ($mostra ? $this->e($ponto['rotulo']) : '') . '</span>';
        }

        $rotulos .= '</div>';

        return '<div class="area-grafico">' . $svg . $rotulos . '</div>';
    }

    /**
     * Funil horizontal: cada etapa é uma fatia do que entrou na anterior.
     *
     * @param array<int,array{rotulo:string,valor:int,nota:string}> $etapas
     */
    public function funil(array $etapas): string
    {
        if ($etapas === []) {
            return $this->vazio('Nenhum ciclo no período.');
        }

        $maximo = max(1, max(array_map(static fn (array $e): int => $e['valor'], $etapas)));
        $html   = '<div class="funil">';

        foreach ($etapas as $indice => $etapa) {
            $largura = max(1.5, ($etapa['valor'] / $maximo) * 100);

            $html .= '<div class="etapa">'
                . '<div class="etapa-topo">'
                . '<span class="etapa-nome">' . $this->e($etapa['rotulo']) . '</span>'
                . '<span class="etapa-valor">' . number_format($etapa['valor'], 0, ',', '.') . '</span>'
                . '</div>'
                . '<div class="trilho"><div class="preenchimento nivel-' . ($indice + 1) . '"'
                . ' style="width:' . $this->n($largura) . '%"></div></div>'
                . '<span class="etapa-nota">' . $this->e($etapa['nota']) . '</span>'
                . '</div>';
        }

        return $html . '</div>';
    }

    /** Linha miúda para dentro de um cartão de indicador. */
    public function faisca(array $valores, int $largura = 120, int $altura = 30): string
    {
        $valores = array_values(array_map('floatval', $valores));

        if (count($valores) < 2) {
            return '';
        }

        $maximo = max($valores);
        $minimo = min($valores);
        $faixa  = max(0.001, $maximo - $minimo);
        $passo  = $largura / (count($valores) - 1);

        $pontos = [];

        foreach ($valores as $indice => $valor) {
            $x = $indice * $passo;
            $y = $altura - 3 - ((($valor - $minimo) / $faixa) * ($altura - 6));

            $pontos[] = $this->n($x) . ',' . $this->n($y);
        }

        $linha = implode(' ', $pontos);
        $area  = $linha . ' ' . $this->n($largura) . ',' . $altura . ' 0,' . $altura;

        return '<svg class="faisca" viewBox="0 0 ' . $largura . ' ' . $altura . '" aria-hidden="true">'
            . '<polygon class="faisca-area" points="' . $area . '" />'
            . '<polyline class="faisca-linha" points="' . $linha . '" />'
            . '</svg>';
    }

    private function vazio(string $texto): string
    {
        return '<p class="grafico-vazio">' . $this->e($texto) . '</p>';
    }

    private function n(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.');
    }

    private function e(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    }
}
