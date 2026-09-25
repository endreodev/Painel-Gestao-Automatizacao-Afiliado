<?php

declare(strict_types=1);

namespace MlGroup\App;

use MlGroup\Database\Db;
use MlGroup\Model\Produto;
use MlGroup\Support\Str;

/**
 * Busca manual no que ja foi coletado, para publicar na hora.
 *
 * Existe para o caso em que a fila nao serve: alguem pede uma lavadora a
 * bateria no grupo, ou aparece uma data comemorativa, e esperar o rodizio
 * chegar la nao faz sentido. Aqui a escolha e sua - o sistema so encontra e
 * envia.
 *
 * Procura no BANCO, e nao no Mercado Livre ao vivo. Nao e limitacao de projeto:
 * o ML bloqueia lista.mercadolivre.com.br para acesso automatizado e a busca por
 * termo volta sempre vazia. O que temos sao os produtos que a coleta ja trouxe
 * das paginas de ofertas - alguns milhares, renovados a cada ciclo.
 */
final class BuscaDireta
{
    /**
     * Procura por descricao e faixa de preco.
     *
     * @return array<int,array{produto:Produto,ja_enviado:bool,quando:?string}>
     */
    public function procurar(
        string $descricao,
        float $precoMinimo = 0.0,
        float $precoMaximo = 0.0,
        int $limite = 30,
    ): array {
        $palavras = $this->palavrasDe($descricao);

        if ($palavras === []) {
            return [];
        }

        $canal = Canal::ativo()?->id() ?? 'padrao';

        $onde = ['p.canal = :canal'];
        $args = ['canal' => $canal];

        if ($precoMinimo > 0) {
            $onde[]            = 'p.preco >= :preco_min';
            $args['preco_min'] = $precoMinimo;
        }

        if ($precoMaximo > 0) {
            $onde[]            = 'p.preco <= :preco_max';
            $args['preco_max'] = $precoMaximo;
        }

        /*
         * O preco filtra no banco; a descricao, em PHP.
         *
         * Com LIKE, "pressao" nao encontra "Pressão" - o SQLite compara byte a
         * byte e nao sabe tirar acento, e titulo de anuncio e cheio deles. Dava
         * para guardar uma coluna sem acento so para isso, mas sao poucos
         * milhares de linhas: normalizar os dois lados em PHP custa
         * milissegundos e nao cria estado novo para manter em sincronia.
         */
        $linhas = Db::todos(
            'SELECT p.* FROM produtos p WHERE ' . implode(' AND ', $onde)
            . ' ORDER BY p.pontuacao DESC, p.desconto DESC',
            $args,
        );

        $achados = [];

        foreach ($linhas as $linha) {
            if (!$this->casaTodas($palavras, (string) $linha['titulo'])) {
                continue;
            }

            $produto = Produto::doBanco($linha);
            $envio   = $this->ultimoEnvio($canal, $produto);

            $achados[] = [
                'produto'    => $produto,
                'ja_enviado' => $envio !== null,
                'quando'     => $envio,
            ];

            if (count($achados) >= max(1, $limite)) {
                break;
            }
        }

        return $achados;
    }

    /**
     * Publica um produto agora, sem passar pela fila.
     *
     * O produto e lido do banco na hora do envio, e nao recebido pronto: o botao
     * manda so o id, e aceitar preco e titulo vindos do formulario deixaria a
     * mensagem do grupo a merce do que fosse postado.
     *
     * @return array{ok:bool,motivo:string,produto:?Produto}
     */
    public function enviar(string $mlId, Publicador $publicador): array
    {
        $canal = Canal::ativo()?->id() ?? 'padrao';

        $linha = Db::primeiro(
            'SELECT * FROM produtos WHERE canal = :canal AND ml_id = :ml_id',
            ['canal' => $canal, 'ml_id' => $mlId],
        );

        if ($linha === null) {
            return ['ok' => false, 'motivo' => 'Produto não encontrado neste canal.', 'produto' => null];
        }

        $produto = Produto::doBanco($linha);
        $quantos = $publicador->publicar([$produto]);

        return $quantos > 0
            ? ['ok' => true, 'motivo' => '', 'produto' => $produto]
            : ['ok' => false, 'motivo' => 'O envio falhou. Veja a conexão em WhatsApp.', 'produto' => $produto];
    }

    /**
     * Todas as palavras aparecem no titulo, em qualquer ordem e sem acento?
     *
     * Casa por trecho, e nao por palavra inteira: quem digita "bateria" espera
     * achar "2 Baterias", e quem digita "parafusadeira" espera achar
     * "Parafusadeiras". Aqui a busca e manual e o usuario ve o que veio - errar
     * para o lado de mostrar demais e melhor do que esconder o que ele procura.
     *
     * @param string[] $palavras ja normalizadas
     */
    private function casaTodas(array $palavras, string $titulo): bool
    {
        $alvo = Str::normalizar($titulo);

        foreach ($palavras as $palavra) {
            if (!str_contains($alvo, $palavra)) {
                return false;
            }
        }

        return true;
    }

    /** Quando este produto (ou a mesma variacao) ja foi publicado neste canal. */
    private function ultimoEnvio(string $canal, Produto $produto): ?string
    {
        $quando = Db::valor(
            "SELECT MAX(enviado_em) FROM envios
              WHERE status = 'enviado' AND canal = :canal
                AND (ml_id = :ml_id OR (assinatura <> '' AND assinatura = :assinatura))",
            [
                'canal'      => $canal,
                'ml_id'      => $produto->mlId,
                'assinatura' => $produto->assinatura(),
            ],
        );

        return $quando !== null && $quando !== false ? (string) $quando : null;
    }

    /**
     * Palavras que valem para a busca.
     *
     * Sem acento e em minuscula, porque o titulo do anuncio nao segue padrao
     * nenhum. Palavra de uma letra so e descartada: nao filtra nada e faz o
     * LIKE varrer a tabela a toa.
     *
     * @return string[]
     */
    private function palavrasDe(string $descricao): array
    {
        $limpo = preg_replace('/[^a-z0-9]+/', ' ', Str::normalizar($descricao)) ?? '';

        $palavras = array_values(array_filter(
            explode(' ', trim($limpo)),
            static fn (string $p): bool => mb_strlen($p) > 1,
        ));

        return array_slice($palavras, 0, 8);
    }
}
