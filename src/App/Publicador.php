<?php

declare(strict_types=1);

namespace MlGroup\App;

use MlGroup\Afiliado\LinkAfiliado;
use MlGroup\Database\Db;
use MlGroup\Mensagem\Montador;
use MlGroup\Model\Produto;
use MlGroup\Support\Config;
use MlGroup\Support\Env;
use MlGroup\Support\Logger;
use MlGroup\Whatsapp\Fabrica;
use MlGroup\Whatsapp\WhatsappInterface;

/**
 * Publica as ofertas aprovadas no(s) grupo(s).
 *
 * Dois modos, em config/config.php > envio.modo:
 *   'individual' -> uma mensagem por oferta (melhor preview de link)
 *   'lote'       -> uma mensagem com as N melhores (menos ruido no grupo)
 *
 * Todo envio e registrado, inclusive falha - e o historico de envios que
 * alimenta a regra de nao repetir oferta.
 */
final class Publicador
{
    private readonly WhatsappInterface $whatsapp;

    public function __construct(
        ?WhatsappInterface $whatsapp = null,
        private readonly Montador $montador = new Montador(),
        private readonly LinkAfiliado $link = new LinkAfiliado(),
    ) {
        $this->whatsapp = $whatsapp ?? Fabrica::criar();
    }

    /**
     * @param  Produto[] $produtos Ja aprovados e ordenados por pontuacao.
     * @return int Quantidade efetivamente enviada.
     */
    public function publicar(array $produtos): int
    {
        if ($produtos === []) {
            Logger::i()->info('Nada aprovado para publicar');

            return 0;
        }

        $grupos = $this->grupos();

        if ($grupos === []) {
            Logger::i()->erro('Nenhum grupo configurado (defina WHATSAPP_GRUPOS no .env)');

            return 0;
        }

        if (!$this->whatsapp->conectado()) {
            Logger::i()->erro('Gateway de WhatsApp desconectado', ['driver' => $this->whatsapp->nome()]);

            return 0;
        }

        $selecionados = array_slice($produtos, 0, Config::inteiro('config.envio.max_por_execucao', 5));

        /*
         * O link de afiliado e montado AQUI, e nao na coleta.
         *
         * Motivo forte: o produto que sai da fila e reconstruido do banco, e o
         * link nao e uma coluna - aplicado na coleta, ele se perdia no caminho e
         * a mensagem ia com o permalink cru, sem rastreio nenhum. Foi o que
         * aconteceu.
         *
         * Motivo pratico: no modo 'linkbuilder' cada link custa uma ida ao
         * navegador. Gerar so para o que vai ser publicado e uma chamada por
         * ciclo, em vez de uma para cada produto aprovado.
         */
        foreach ($selecionados as $produto) {
            $this->link->aplicar($produto);
        }

        return Config::texto('config.envio.modo', 'individual') === 'lote'
            ? $this->publicarLote($selecionados, $grupos)
            : $this->publicarIndividual($selecionados, $grupos);
    }

    /**
     * @param  Produto[] $produtos
     * @param  string[]  $grupos
     */
    private function publicarIndividual(array $produtos, array $grupos): int
    {
        $enviados = 0;
        $intervalo = Config::inteiro('config.envio.intervalo_mensagens_s', 8);

        foreach ($produtos as $produto) {
            $mensagem = $this->montador->oferta($produto);

            foreach ($grupos as $grupo) {
                $resultado = $this->enviar($grupo, $produto, $mensagem);

                if ($resultado) {
                    $enviados++;
                }

                // espacar mensagens reduz risco de bloqueio por flood
                if ($intervalo > 0) {
                    sleep($intervalo);
                }
            }
        }

        return $enviados;
    }

    /**
     * @param  Produto[] $produtos
     * @param  string[]  $grupos
     */
    private function publicarLote(array $produtos, array $grupos): int
    {
        $mensagem = $this->montador->lote($produtos);
        $enviados = 0;

        foreach ($grupos as $grupo) {
            $resultado = $this->whatsapp->enviarTexto($grupo, $mensagem);

            foreach ($produtos as $produto) {
                $this->registrar($grupo, $produto, $mensagem, $resultado->sucesso, $resultado->erro);
            }

            if ($resultado->sucesso) {
                $enviados += count($produtos);
                Logger::i()->info('Lote publicado', ['grupo' => $grupo, 'ofertas' => count($produtos)]);
            } else {
                Logger::i()->erro('Falha ao publicar lote', ['grupo' => $grupo, 'erro' => $resultado->erro]);
            }

            sleep(Config::inteiro('config.envio.intervalo_mensagens_s', 8));
        }

        return $enviados;
    }

    private function enviar(string $grupo, Produto $produto, string $mensagem): bool
    {
        $comImagem = Config::booleano('config.envio.enviar_imagem', true) && $produto->thumb !== '';

        $resultado = $comImagem
            ? $this->whatsapp->enviarImagem($grupo, $this->imagemGrande($produto->thumb), $mensagem)
            : $this->whatsapp->enviarTexto($grupo, $mensagem);

        $this->registrar($grupo, $produto, $mensagem, $resultado->sucesso, $resultado->erro);

        /*
         * Saida para o caso do link nao ficar tocavel dentro da legenda.
         *
         * No iPhone, link em legenda de imagem nem sempre vira link clicavel -
         * em mensagem de texto, sempre vira. Com isto ligado a oferta vai com
         * foto e o link sai logo atras, sozinho, garantidamente clicavel. Custa
         * uma mensagem a mais por oferta, entao fica desligado por padrao.
         */
        if ($resultado->sucesso && $comImagem && Config::booleano('config.envio.link_em_mensagem_separada', false)) {
            $this->whatsapp->enviarTexto($grupo, $produto->link());
        }

        if ($resultado->sucesso) {
            Logger::i()->info('Oferta publicada', [
                'grupo'     => $grupo,
                'id'        => $produto->mlId,
                'desconto'  => $produto->desconto(),
                'pontuacao' => $produto->pontuacao,
            ]);

            return true;
        }

        Logger::i()->erro('Falha ao publicar oferta', [
            'grupo' => $grupo,
            'id'    => $produto->mlId,
            'erro'  => $resultado->erro,
        ]);

        return false;
    }

    /** A thumb do ML vem em 100x100; -O devolve a imagem em resolucao cheia. */
    private function imagemGrande(string $thumb): string
    {
        return (string) preg_replace('/-[A-Z]\.(jpg|png|webp)$/i', '-O.$1', $thumb);
    }

    private function registrar(
        string $grupo,
        Produto $produto,
        string $mensagem,
        bool $sucesso,
        string $erro,
    ): void {
        Db::executar(
            'INSERT INTO envios (ml_id, assinatura, canal, grupo, preco, desconto, pontuacao, mensagem, status, erro, enviado_em)
             VALUES (:ml_id, :assinatura, :canal, :grupo, :preco, :desconto, :pontuacao, :mensagem, :status, :erro, :agora)',
            [
                'ml_id'      => $produto->mlId,
                'assinatura' => $produto->assinatura(),
                'canal'      => Canal::ativo()?->id() ?? 'padrao',
                'grupo'     => $grupo,
                'preco'     => $produto->preco,
                'desconto'  => $produto->desconto(),
                'pontuacao' => $produto->pontuacao,
                'mensagem'  => $mensagem,
                'status'    => $sucesso ? 'enviado' : 'falha',
                'erro'      => $erro === '' ? null : $erro,
                'agora'     => date('Y-m-d H:i:s'),
            ],
        );
    }

    /** @return string[] */
    private function grupos(): array
    {
        // com canais, cada um tem os seus; o .env vale como padrao antigo
        $doCanal = Canal::ativo()?->grupos() ?? [];

        if ($doCanal !== []) {
            return $doCanal;
        }

        $bruto = Env::texto('WHATSAPP_GRUPOS');

        if ($bruto === '') {
            $bruto = implode(',', array_map('strval', Config::lista('config.whatsapp.grupos')));
        }

        $grupos = array_filter(array_map('trim', explode(',', $bruto)));

        return array_values($grupos);
    }

    public function driver(): WhatsappInterface
    {
        return $this->whatsapp;
    }
}
