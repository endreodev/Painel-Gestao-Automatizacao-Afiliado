<?php

declare(strict_types=1);

namespace MlGroup\Cli;

use MlGroup\App\Agendador;
use MlGroup\App\Cacador;
use MlGroup\App\Ciclo;
use MlGroup\App\TarefaAgendada;
use MlGroup\App\Publicador;
use MlGroup\Database\Db;
use MlGroup\Mensagem\Montador;
use MlGroup\Support\Config;
use MlGroup\Support\Http;
use MlGroup\Support\Str;
use Throwable;

/**
 * Ponto de entrada da linha de comando.
 */
final class Console
{
    private readonly Saida $saida;

    /** @var array<string,mixed> */
    private array $opcoes = [];

    /** @var string[] */
    private array $argumentos = [];

    public function __construct(?Saida $saida = null)
    {
        $this->saida = $saida ?? new Saida();
    }

    /** @param string[] $argv */
    public function executar(array $argv): int
    {
        $comando = $argv[1] ?? 'ajuda';

        $this->interpretarArgumentos(array_slice($argv, 2));

        if (isset($this->opcoes['verbose'])) {
            Config::definir('config.log.nivel', 'debug');
        }

        try {
            return match ($comando) {
                'painel'     => (new ComandoPainel($this->saida))->executar(
                    (int) ($this->opcoes['porta'] ?? 0),
                    !isset($this->opcoes['sem-navegador']),
                ),
                'status'     => $this->status(),
                'monitor'    => $this->monitor(),
                'agendar'    => $this->agendar(),
                'rodar'      => $this->rodar(),
                'ciclo'      => $this->ciclo(),
                'analisar'   => $this->analisar(),
                'previa'     => $this->previa(),
                'conectar'   => $this->whatsapp()->conectar(),
                'desconectar' => $this->whatsapp()->desconectar(),
                'instalar-ponte' => $this->whatsapp()->instalar(),
                'ml-login'   => $this->afiliado()->login(),
                'link'       => $this->afiliado()->link($this->argumentos[0] ?? ''),
                'afiliado'   => $this->afiliado()->situacao(),
                'whatsapp'   => $this->testarWhatsapp(),
                'grupos'     => $this->whatsapp()->escolherGrupo($this->argumentos[0] ?? ''),
                'categorias' => $this->categorias(),
                'relatorio'  => $this->relatorio(),
                'limpar'     => $this->limpar(),
                'ajuda', '-h', '--help' => $this->ajuda(),
                default      => $this->comandoDesconhecido($comando),
            };
        } catch (Throwable $erro) {
            $this->linha('');
            $this->linha('  ERRO: ' . $erro->getMessage(), '31');
            $this->linha('  ' . basename($erro->getFile()) . ':' . $erro->getLine(), '90');
            $this->linha('');

            return 1;
        }
    }

    private function rodar(): int
    {
        $intervalo = isset($this->opcoes['intervalo']) ? (int) $this->opcoes['intervalo'] : null;
        $maximo    = isset($this->opcoes['ciclos']) ? (int) $this->opcoes['ciclos'] : null;

        (new Agendador())->rodar($intervalo, $maximo);

        return 0;
    }

    /** Mostra o que esta de pe e o que nao esta. */
    private function status(): int
    {
        $this->linha('');
        $this->linha('  Situacao do sistema', '1');
        $this->linha('');

        foreach ((new \MlGroup\App\Sentinela())->estado() as $item) {
            $this->linha(sprintf(
                '  %-22s %-14s %s',
                $item['nome'],
                $item['ativo'] ? 'no ar' : 'PARADO',
                $item['detalhe'],
            ), $item['ativo'] ? '32' : '31');
        }

        $this->linha('');
        $this->linha('  Na fila: ' . (new \MlGroup\App\Fila())->tamanho() . ' oferta(s)', '90');
        $this->linha('');

        return 0;
    }

    /**
     * Confere e religa o que estiver parado.
     *
     * Feito para o Agendador de Tarefas do Windows chamar de tempos em tempos.
     */
    /**
     * Cadastra (ou remove) a tarefa que chama o monitor de tempos em tempos.
     *
     *   php bin/mlgroup agendar
     *   php bin/mlgroup agendar --minutos=10
     *   php bin/mlgroup agendar --remover
     */
    private function agendar(): int
    {
        $this->linha('');
        $this->linha('  Agendamento do monitor', '1');
        $this->linha('');

        if (isset($this->opcoes['remover'])) {
            if (!TarefaAgendada::existe()) {
                $this->linha('  Nao havia agendamento.', '90');
                $this->linha('');

                return 0;
            }

            if (!TarefaAgendada::remover()) {
                $this->linha('  Nao foi possivel remover a tarefa.', '31');
                $this->linha('');

                return 1;
            }

            $this->linha('  Agendamento removido.', '32');
            $this->linha('  O sistema volta a depender de voce rodar o monitor a mao.', '33');
            $this->linha('');

            return 0;
        }

        $minutos = (int) ($this->opcoes['minutos'] ?? 5);
        $criado  = TarefaAgendada::criar($minutos);

        if (!$criado['ok']) {
            $this->linha('  ' . $criado['mensagem'], '31');
            $this->linha('');

            return 1;
        }

        $this->linha('  ' . $criado['mensagem'], '32');
        $this->linha('');
        $this->linha('  A tarefa roda escondida, sem abrir janela.', '90');
        $this->linha('  Saida:   storage/monitor.log', '90');
        $this->linha('  A mao:   ' . TarefaAgendada::lancador(), '90');
        $this->linha('');
        $this->linha('  Para desfazer:  php bin/mlgroup agendar --remover', '90');
        $this->linha('');

        return 0;
    }

    private function monitor(): int
    {
        $sentinela = new \MlGroup\App\Sentinela();
        $acoes     = $sentinela->garantir();

        $this->linha('');

        foreach ($sentinela->estado() as $item) {
            $this->linha(sprintf(
                '  %-22s %-14s %s',
                $item['nome'],
                $item['ativo'] ? 'no ar' : 'PARADO',
                $item['detalhe'],
            ), $item['ativo'] ? '32' : '31');
        }

        /*
         * Quem roda o monitor a mao costuma nao saber que existe agendamento -
         * e um monitor que so roda quando chamado a mao resolve metade do
         * problema: ele religa o laco agora, mas nao na madrugada em que a
         * maquina reiniciou.
         */
        if (!TarefaAgendada::existe()) {
            $this->linha('  Nao esta agendado: rode  php bin/mlgroup agendar', '33');
        }

        $this->linha('');

        if ($acoes === []) {
            $this->linha('  Nada a fazer.', '90');
            $this->linha('');

            return 0;
        }

        foreach ($acoes as $acao) {
            $this->linha('  ' . $acao, '33');
        }

        $this->linha('');

        return 0;
    }

    private function ciclo(): int
    {
        $resumo = (new Ciclo())->executar(isset($this->opcoes['analise']));

        $this->linha('');
        $this->linha(sprintf(
            '  aprovados: %d   enviados: %d',
            $resumo['aprovados'],
            $resumo['enviados'],
        ), '32');
        $this->linha('');

        return 0;
    }

    /** Caca e mostra o ranking sem enviar nada. */
    private function analisar(): int
    {
        $produtos = (new Cacador())->cacar();

        if ($produtos === []) {
            $this->linha('');
            $this->linha('  Nenhuma oferta passou nos filtros.', '33');
            $this->linha('  Rode com --verbose para ver o motivo de cada reprovacao.', '90');
            $this->linha('');

            return 0;
        }

        $limite = (int) ($this->opcoes['limite'] ?? 15);

        $this->linha('');
        $this->linha(sprintf(
            '  %-4s %-52s %11s %7s %7s %8s',
            'NOTA',
            'PRODUTO',
            'PRECO',
            'DESC',
            'COMIS',
            'GANHO',
        ), '1');
        $this->linha('  ' . str_repeat('-', 94), '90');

        foreach (array_slice($produtos, 0, $limite) as $produto) {
            $this->linha(sprintf(
                '  %-4s %-52s %11s %7s %7s %8s',
                number_format($produto->pontuacao, 0),
                Str::limitar($produto->titulo, 52),
                Str::dinheiro($produto->preco),
                Str::percentual($produto->desconto()),
                Str::percentual($produto->comissao, 1),
                Str::dinheiro($produto->ganhoEstimado),
            ));
        }

        $this->linha('');
        $this->linha('  ' . count($produtos) . ' ofertas aprovadas no total.', '32');
        $this->linha('');

        return 0;
    }

    /** Renderiza a mensagem da melhor oferta, para conferir o template. */
    private function previa(): int
    {
        $produtos = (new Cacador())->cacar();

        if ($produtos === []) {
            $this->linha('  Nenhuma oferta aprovada para pre-visualizar.', '33');

            return 0;
        }

        $montador = new Montador();

        $this->linha('');
        $this->linha(str_repeat('=', 60), '90');

        if (Config::texto('config.envio.modo', 'individual') === 'lote') {
            $this->linha($montador->lote(array_slice($produtos, 0, Config::inteiro('config.envio.max_por_execucao', 5))));
        } else {
            $this->linha($montador->oferta($produtos[0]));
        }

        $this->linha(str_repeat('=', 60), '90');
        $this->linha('');

        return 0;
    }

    private function testarWhatsapp(): int
    {
        $publicador = new Publicador();
        $driver     = $publicador->driver();

        $this->linha('');
        $this->linha('  Driver: ' . $driver->nome());

        if (!$driver->conectado()) {
            $this->linha('  Status: DESCONECTADO', '31');

            $this->linha(
                $driver->nome() === 'ponte'
                    ? '  Rode: php bin/mlgroup conectar'
                    : '  Confira as credenciais do gateway no .env',
                '90',
            );

            $this->linha('');

            return 1;
        }

        $this->linha('  Status: conectado', '32');

        $destino = (string) ($this->opcoes['grupo'] ?? '');

        if ($destino === '') {
            $this->linha('  Passe --grupo=<id> para disparar uma mensagem de teste.', '90');
            $this->linha('');

            return 0;
        }

        $resultado = $driver->enviarTexto($destino, 'Teste do ml-group em ' . date('d/m/Y H:i') . '.');

        $resultado->sucesso
            ? $this->linha('  Mensagem enviada.', '32')
            : $this->linha('  Falha: ' . $resultado->erro, '31');

        $this->linha('');

        return $resultado->sucesso ? 0 : 1;
    }

    /** Consulta a arvore real de categorias do ML para preencher config/buscas.php. */
    private function categorias(): int
    {
        $termo = $this->argumentos[0] ?? '';
        $http  = new Http();

        $resposta = $http->get('https://api.mercadolibre.com/sites/MLB/categories');

        if (!$resposta->ok()) {
            $this->linha('  Nao foi possivel consultar as categorias (HTTP ' . $resposta->status . ').', '31');

            return 1;
        }

        $this->linha('');

        foreach ($resposta->json() as $categoria) {
            if (!is_array($categoria)) {
                continue;
            }

            $nome = (string) ($categoria['name'] ?? '');

            if ($termo !== '' && !str_contains(Str::normalizar($nome), Str::normalizar($termo))) {
                continue;
            }

            $this->linha(sprintf('  %-14s %s', (string) ($categoria['id'] ?? ''), $nome));
        }

        $this->linha('');

        return 0;
    }

    private function relatorio(): int
    {
        $dias  = (int) ($this->opcoes['dias'] ?? 7);
        $corte = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days') ?: time());

        $envios = Db::primeiro(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'enviado' THEN 1 ELSE 0 END) AS ok,
                    SUM(CASE WHEN status = 'falha'   THEN 1 ELSE 0 END) AS falhas
               FROM envios
              WHERE enviado_em >= :corte",
            ['corte' => $corte],
        ) ?? [];

        $this->linha('');
        $this->linha('  Ultimos ' . $dias . ' dias', '1');
        $this->linha('  ' . str_repeat('-', 50), '90');
        $this->linha(sprintf('  Mensagens enviadas : %d', (int) ($envios['ok'] ?? 0)));
        $this->linha(sprintf('  Falhas de envio    : %d', (int) ($envios['falhas'] ?? 0)));

        $ganho = Db::valor(
            "SELECT SUM(p.ganho_estimado)
               FROM envios e
               JOIN produtos p ON p.ml_id = e.ml_id
              WHERE e.status = 'enviado' AND e.enviado_em >= :corte",
            ['corte' => $corte],
        );

        $this->linha(sprintf('  Comissao potencial : %s', Str::dinheiro((float) ($ganho ?? 0))));
        $this->linha('  (potencial = soma da comissao se cada oferta gerar 1 venda)', '90');

        $topo = Db::todos(
            "SELECT p.titulo, p.preco, p.desconto, p.pontuacao
               FROM envios e
               JOIN produtos p ON p.ml_id = e.ml_id
              WHERE e.status = 'enviado' AND e.enviado_em >= :corte
              GROUP BY p.ml_id
              ORDER BY p.pontuacao DESC
              LIMIT 10",
            ['corte' => $corte],
        );

        if ($topo !== []) {
            $this->linha('');
            $this->linha('  Melhores ofertas publicadas', '1');
            $this->linha('  ' . str_repeat('-', 50), '90');

            foreach ($topo as $linha) {
                $this->linha(sprintf(
                    '  %-3d %-46s %10s %6s',
                    (int) $linha['pontuacao'],
                    Str::limitar((string) $linha['titulo'], 46),
                    Str::dinheiro((float) $linha['preco']),
                    Str::percentual((float) $linha['desconto']),
                ));
            }
        }

        $this->linha('');

        return 0;
    }

    private function limpar(): int
    {
        $dias  = (int) ($this->opcoes['dias'] ?? 60);
        $corte = date('Y-m-d H:i:s', strtotime('-' . $dias . ' days') ?: time());

        $envios = Db::executar('DELETE FROM envios WHERE enviado_em < :corte', ['corte' => $corte])->rowCount();
        $exec   = Db::executar('DELETE FROM execucoes WHERE iniciado_em < :corte', ['corte' => $corte])->rowCount();
        $prod   = Db::executar(
            'DELETE FROM produtos
              WHERE atualizado_em < :corte
                AND ml_id NOT IN (SELECT ml_id FROM envios)',
            ['corte' => $corte],
        )->rowCount();

        Db::conexao()->exec('VACUUM');

        $this->linha('');
        $this->linha(sprintf('  Removidos: %d envios, %d execucoes, %d produtos.', $envios, $exec, $prod), '32');
        $this->linha('');

        return 0;
    }

    private function whatsapp(): ComandosWhatsapp
    {
        return new ComandosWhatsapp($this->saida);
    }

    private function afiliado(): ComandosAfiliado
    {
        return new ComandosAfiliado($this->saida);
    }

    private function comandoDesconhecido(string $comando): int
    {
        $this->linha('');
        $this->linha('  Comando desconhecido: ' . $comando, '31');
        $this->ajuda();

        return 1;
    }

    private function ajuda(): int
    {
        $this->linha('');
        $this->linha('  ml-group - cacador de ofertas de ferramentas para grupo de WhatsApp', '1');
        $this->linha('');
        $this->linha('  USO', '1');
        $this->linha('    php bin/mlgroup <comando> [opcoes]');
        $this->linha('');
        $this->linha('  PAINEL', '1');
        $this->linha('    painel       Abre a interface web de configuracao no navegador');
        $this->linha('');
        $this->linha('  PRIMEIROS PASSOS', '1');
        $this->linha('    conectar     Conecta ao WhatsApp lendo o QR code e escolhe o grupo');
        $this->linha('    grupos [termo]  Lista seus grupos e aponta cada um para um canal');
        $this->linha('    agendar      Faz o Windows chamar o monitor sozinho (--minutos=5, --remover)');
        $this->linha('    desconectar  Encerra a sessao do WhatsApp');
        $this->linha('    ml-login     Entra no Mercado Livre para gerar link pela tela oficial');
        $this->linha('');
        $this->linha('  COMANDOS', '1');
        $this->linha('    status       Mostra o que esta de pe e o que nao esta');
        $this->linha('    monitor      Confere e religa o que estiver parado (use no Agendador)');
        $this->linha('    rodar        Laco continuo: caca e publica no intervalo configurado');
        $this->linha('    ciclo        Executa uma unica rodada (use no Agendador de Tarefas/cron)');
        $this->linha('    analisar     Mostra o ranking de ofertas sem enviar nada');
        $this->linha('    previa       Renderiza a mensagem da melhor oferta, para conferir o template');
        $this->linha('    whatsapp     Testa a conexao com o gateway');
        $this->linha('    categorias   Lista as categorias do Mercado Livre (aceita filtro)');
        $this->linha('    afiliado     Mostra como o link de afiliado esta sendo montado');
        $this->linha('    link <url>   Gera o link de um produto pela tela oficial do ML');
        $this->linha('    relatorio    Resumo de envios e comissao potencial');
        $this->linha('    limpar       Remove dados antigos do banco');
        $this->linha('');
        $this->linha('  OPCOES', '1');
        $this->linha('    --intervalo=60   Minutos entre ciclos (comando rodar)');
        $this->linha('    --ciclos=3       Encerra apos N ciclos (comando rodar)');
        $this->linha('    --analise        Nao envia, so avalia (comando ciclo)');
        $this->linha('    --grupo=<id>     Destino da mensagem de teste (comando whatsapp)');
        $this->linha('    --dias=7         Janela dos comandos relatorio/limpar');
        $this->linha('    --limite=15      Linhas exibidas pelo comando analisar');
        $this->linha('    --porta=8321     Porta do painel');
        $this->linha('    --sem-navegador  Nao abre o navegador sozinho');
        $this->linha('    --verbose        Log em nivel debug');
        $this->linha('');
        $this->linha('  EXEMPLOS', '1');
        $this->linha('    php bin/mlgroup painel', '90');
        $this->linha('    php bin/mlgroup conectar', '90');
        $this->linha('    php bin/mlgroup analisar --verbose', '90');
        $this->linha('    php bin/mlgroup ciclo --analise', '90');
        $this->linha('    php bin/mlgroup rodar --intervalo=90', '90');
        $this->linha('');

        return 0;
    }

    /** @param string[] $argumentos */
    private function interpretarArgumentos(array $argumentos): void
    {
        foreach ($argumentos as $argumento) {
            if (!str_starts_with($argumento, '--')) {
                $this->argumentos[] = $argumento;

                continue;
            }

            $sem = substr($argumento, 2);
            $pos = strpos($sem, '=');

            if ($pos === false) {
                $this->opcoes[$sem] = true;

                continue;
            }

            $this->opcoes[substr($sem, 0, $pos)] = substr($sem, $pos + 1);
        }
    }

    private function linha(string $texto, string $cor = '0'): void
    {
        fwrite(STDOUT, "\033[" . $cor . 'm' . $texto . "\033[0m" . PHP_EOL);
    }
}
