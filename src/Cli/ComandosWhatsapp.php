<?php

declare(strict_types=1);

namespace MlGroup\Cli;

use MlGroup\App\Canal;
use MlGroup\App\DestinosDeGrupo;
use MlGroup\Support\Config;
use MlGroup\Support\Str;
use MlGroup\Support\ConfigLocal;
use MlGroup\Support\Env;
use MlGroup\Whatsapp\CatalogoDeGrupos;
use MlGroup\Whatsapp\GerenciadorPonte;
use Throwable;

/**
 * Comandos de conexao com o WhatsApp: QR code, escolha de grupo e logout.
 *
 * Ficam separados do Console porque sao os unicos comandos interativos - eles
 * esperam o usuario escanear o QR e digitar a escolha.
 */
final class ComandosWhatsapp
{
    public function __construct(
        private readonly Saida $saida,
        private readonly GerenciadorPonte $gerenciador = new GerenciadorPonte(),
    ) {
    }

    /**
     * Sobe a ponte, mostra o QR code e espera a leitura.
     * Ao conectar, ja oferece a escolha do grupo.
     */
    public function conectar(): int
    {
        $this->saida->linha('');
        $this->saida->titulo('  Conectar ao WhatsApp');
        $this->saida->linha('');

        if (!$this->gerenciador->dependenciasInstaladas() && $this->instalar() !== 0) {
            return 1;
        }

        try {
            $this->saida->linha('  Iniciando a ponte...', '90');
            $this->gerenciador->garantir();
        } catch (Throwable $erro) {
            $this->saida->linha('  ' . $erro->getMessage(), '31');
            $this->saida->linha('');

            return 1;
        }

        if ($this->gerenciador->conectado()) {
            $numero = (string) ($this->gerenciador->status()['numero'] ?? '');

            $this->saida->linha('  Ja conectado' . ($numero !== '' ? ' como ' . $numero : '') . '.', '32');
            $this->saida->linha('');

            return $this->escolherGrupo();
        }

        return $this->esperarLeituraDoQr();
    }

    private function esperarLeituraDoQr(): int
    {
        $limite      = time() + 180;
        $qrMostrado  = '';

        $this->saida->linha('  Aguardando o QR code...', '90');

        while (time() < $limite) {
            $status = $this->gerenciador->status();

            if (($status['conectado'] ?? false) === true) {
                $this->saida->linha('');
                $this->saida->linha('  Conectado como ' . ($status['numero'] ?? ''), '32');
                $this->saida->linha('');

                return $this->escolherGrupo();
            }

            $arte = (string) ($status['qr_ascii'] ?? '');

            // o WhatsApp troca o QR a cada ~20s; so redesenha quando muda
            if ($arte !== '' && $arte !== $qrMostrado) {
                $qrMostrado = $arte;

                $this->saida->linha('');
                $this->saida->linha('  No celular: WhatsApp > Aparelhos conectados > Conectar um aparelho', '1');
                $this->saida->linha('');
                $this->saida->cru($arte);
                $this->saida->linha('  Aponte a camera para o codigo acima.', '90');
            }

            sleep(2);
        }

        $this->saida->linha('');
        $this->saida->linha('  Tempo esgotado sem leitura do QR. Rode o comando de novo.', '33');
        $this->saida->linha('');

        return 1;
    }

    /**
     * Lista os grupos e aponta cada um para um canal.
     *
     * Antes gravava direto em WHATSAPP_GRUPOS no .env. Com canais, isso virou
     * uma armadilha: o .env so vale como padrao antigo, entao quem escolhia um
     * grupo aqui via a escolha ser ignorada por qualquer canal que ja tivesse
     * grupo proprio. Agora escreve no mesmo lugar que o painel.
     *
     * Aceita um termo para filtrar: com dezenas de grupos, listar todos e pior
     * do que digitar parte do nome.
     *
     *   php bin/mlgroup grupos
     *   php bin/mlgroup grupos ferramenta
     */
    public function escolherGrupo(string $filtro = ''): int
    {
        $catalogo = CatalogoDeGrupos::atual(true);
        $todos    = $catalogo['grupos'];

        if ($todos === []) {
            $this->saida->linha('  Nenhum grupo encontrado.', '33');
            $this->saida->linha('  Voce precisa participar de pelo menos um grupo com este numero.', '90');
            $this->saida->linha('');

            return 1;
        }

        $grupos = $this->filtrar($todos, $filtro);

        if ($grupos === []) {
            $this->saida->linha('  Nenhum grupo com "' . $filtro . '" no nome.', '33');
            $this->saida->linha('  Sem o termo, lista os ' . count($todos) . ' grupos.', '90');
            $this->saida->linha('');

            return 1;
        }

        // id do grupo -> nome do canal que o usa
        $dono = [];

        foreach (Canal::todos() as $canal) {
            foreach ($canal->grupos() as $id) {
                $dono[$id] = $canal->nome();
            }
        }

        $this->saida->linha('');
        $this->saida->titulo('  Seus grupos');
        $this->saida->linha('');

        $rotulo = count($grupos) === count($todos)
            ? count($todos) . ' grupos'
            : count($grupos) . ' de ' . count($todos) . ' grupos, filtrando por "' . $filtro . '"';

        $this->saida->linha('  ' . $rotulo, '90');
        $this->saida->linha('');
        $this->saida->linha('   #   ' . Str::preencher('GRUPO', 38) . sprintf(' %8s  %s', 'MEMBROS', 'CANAL'), '90');

        foreach ($grupos as $indice => $grupo) {
            $canal = $dono[$grupo['id']] ?? '';
            $marca = $this->marcaDe($grupo);

            $this->saida->linha(
                sprintf('  %s%2d) ', $marca === '' ? ' ' : $marca, $indice + 1)
                . Str::preencher($grupo['nome'], 38)
                . sprintf(' %8s  %s', $grupo['participantes'] > 0 ? (string) $grupo['participantes'] : '-', $canal !== '' ? $canal : '-'),
                $canal !== '' ? '32' : '',
            );
        }

        $this->saida->linha('');
        $this->legenda($grupos);

        if (!$this->interativo()) {
            $this->saida->linha('  Rode em um terminal para escolher, ou use o painel em Grupos.', '90');
            $this->saida->linha('');

            foreach ($grupos as $indice => $grupo) {
                $this->saida->linha(sprintf('  %2d) %s', $indice + 1, $grupo['id']), '90');
            }

            $this->saida->linha('');

            return 0;
        }

        return $this->atribuir($grupos);
    }

    /**
     * Pergunta os numeros e o canal, e grava.
     *
     * @param array<int,array<string,mixed>> $grupos
     */
    private function atribuir(array $grupos): int
    {
        $canais = Canal::todos();

        if ($canais === []) {
            $this->saida->linha('  Nenhum canal cadastrado em config/canais.php.', '33');

            return 1;
        }

        $this->saida->linha('  Numero do grupo (varios com virgula, Enter para sair):', '1');
        $this->saida->escrever('  > ');

        $resposta = trim((string) fgets(STDIN));

        if ($resposta === '') {
            $this->saida->linha('  Nada alterado.', '90');
            $this->saida->linha('');

            return 0;
        }

        $escolhidos = [];

        foreach (explode(',', $resposta) as $pedaco) {
            $numero = (int) trim($pedaco);

            if ($numero >= 1 && isset($grupos[$numero - 1])) {
                $escolhidos[] = $grupos[$numero - 1];
            }
        }

        if ($escolhidos === []) {
            $this->saida->linha('  Nenhum numero valido. Nada alterado.', '33');
            $this->saida->linha('');

            return 1;
        }

        $canal = $this->escolherCanal($canais);

        if ($canal === null) {
            $this->saida->linha('  Nada alterado.', '90');
            $this->saida->linha('');

            return 0;
        }

        $ids = array_map(static fn (array $grupo): string => (string) $grupo['id'], $escolhidos);

        $this->gravarNoCanal($canal, $ids);

        $this->saida->linha('');
        $this->saida->linha('  Gravado no canal "' . $canal->nome() . '":', '32');

        foreach ($escolhidos as $grupo) {
            $this->saida->linha('    ' . $grupo['nome'], '32');

            if (($grupo['somente_admin'] ?? false) && ($grupo['sou_admin'] ?? null) === false) {
                $this->saida->linha(
                    '    Atencao: so admins publicam neste grupo e este numero nao e admin.',
                    '31',
                );
                $this->saida->linha('    O envio volta como aceito e a mensagem nao chega.', '31');
            }
        }

        if (!$canal->ligado()) {
            $this->saida->linha('');
            $this->saida->linha('  O canal esta pausado. Ligue em Canais, no painel.', '33');
        }

        /*
         * Antes este comando forcava WHATSAPP_DRIVER=ponte ao gravar. Mudar o
         * driver por baixo de quem so queria escolher um grupo e surpresa demais
         * - quem esta em 'simulado' costuma estar calibrando filtro de proposito.
         * Avisar resolve o mesmo problema sem decidir pelo usuario.
         */
        $driver = strtolower(Env::texto('WHATSAPP_DRIVER', 'ponte'));

        if ($driver !== '' && $driver !== 'ponte') {
            $this->saida->linha('');
            $this->saida->linha('  O driver atual e "' . $driver . '", nao a ponte do QR code.', '33');

            if ($driver === 'simulado') {
                $this->saida->linha('  Em simulado nada e enviado de verdade.', '33');
            }

            $this->saida->linha('  Troque em Configuracao > WhatsApp, no painel.', '90');
        }

        $this->saida->linha('');
        $this->saida->linha('  Teste agora com:  php bin/mlgroup ciclo --analise', '90');
        $this->saida->linha('  Ou envie de fato:  php bin/mlgroup ciclo', '90');
        $this->saida->linha('');

        return 0;
    }

    /**
     * Com um canal so, nao pergunta - nao ha escolha a fazer.
     *
     * @param Canal[] $canais
     */
    private function escolherCanal(array $canais): ?Canal
    {
        if (count($canais) === 1) {
            return $canais[0];
        }

        $this->saida->linha('');
        $this->saida->linha('  Para qual canal?', '1');

        foreach ($canais as $indice => $canal) {
            $this->saida->linha(sprintf(
                '   %d) %-24s %s',
                $indice + 1,
                $canal->nome(),
                $canal->ligado() ? '' : '(pausado)',
            ));
        }

        $this->saida->escrever('  > ');

        $numero = (int) trim((string) fgets(STDIN));

        return $canais[$numero - 1] ?? null;
    }

    /**
     * Acrescenta os grupos ao canal, tirando-os de qualquer outro.
     *
     * Grupo em dois canais receberia duas ofertas diferentes do mesmo sistema,
     * o que parece defeito para quem le o grupo.
     *
     * @param string[] $ids
     */
    private function gravarNoCanal(Canal $canal, array $ids): void
    {
        $porCanal = DestinosDeGrupo::mover(DestinosDeGrupo::atual(), $ids, $canal->id());
        $canais   = [];

        foreach (Canal::todos() as $outro) {
            $dados           = $outro->paraArray();
            $dados['grupos'] = array_values($porCanal[$outro->id()] ?? []);
            $canais[]        = $dados;
        }

        ConfigLocal::definir('canais.canais', $canais);
        ConfigLocal::gravar();
        Config::recarregar();
    }

    /**
     * @param  array<int,array<string,mixed>> $grupos
     * @return array<int,array<string,mixed>>
     */
    private function filtrar(array $grupos, string $filtro): array
    {
        $filtro = trim($filtro);

        if ($filtro === '') {
            return $grupos;
        }

        $alvo = mb_strtolower($filtro);

        return array_values(array_filter($grupos, static function (array $grupo) use ($alvo): bool {
            return str_contains(mb_strtolower((string) $grupo['nome']), $alvo)
                || str_contains(mb_strtolower((string) $grupo['id']), $alvo);
        }));
    }

    /** @param array<string,mixed> $grupo */
    private function marcaDe(array $grupo): string
    {
        if (!($grupo['somente_admin'] ?? false)) {
            return '';
        }

        return ($grupo['sou_admin'] ?? null) === true ? '' : '!';
    }

    /** @param array<int,array<string,mixed>> $grupos */
    private function legenda(array $grupos): void
    {
        foreach ($grupos as $grupo) {
            if ($this->marcaDe($grupo) === '!') {
                $this->saida->linha('  ! = so admins publicam e este numero nao e admin', '33');
                $this->saida->linha('');

                return;
            }
        }
    }


    /** Encerra a sessao; o proximo `conectar` pede QR de novo. */
    public function desconectar(): int
    {
        $this->saida->linha('');

        if (!$this->gerenciador->noAr()) {
            $this->saida->linha('  A ponte nao esta rodando - nada a desconectar.', '33');
            $this->saida->linha('');

            return 0;
        }

        $this->gerenciador->sair();

        $this->saida->linha('  Sessao encerrada. Rode `conectar` para ler um novo QR.', '32');
        $this->saida->linha('');

        return 0;
    }

    /** Instala as dependencias Node da ponte. */
    public function instalar(): int
    {
        $this->saida->linha('  Instalando as dependencias da ponte (uma vez so)...', '90');

        $node = $this->gerenciador->node();

        if ($node === null) {
            $this->saida->linha('');
            $this->saida->linha('  Node.js 20 ou superior nao encontrado.', '31');
            $this->saida->linha('  Instale em https://nodejs.org e rode o comando de novo.', '90');
            $this->saida->linha('');

            return 1;
        }

        [$ok, $saida] = $this->gerenciador->instalarDependencias();

        if (!$ok) {
            $this->saida->linha('');
            $this->saida->linha('  Falha ao instalar:', '31');
            $this->saida->linha('  ' . mb_substr($saida, 0, 600), '90');
            $this->saida->linha('');

            return 1;
        }

        $this->saida->linha('  Dependencias instaladas.', '32');

        return 0;
    }

    /** Da para perguntar algo ao usuario? */
    public function interativo(): bool
    {
        return stream_isatty(STDIN);
    }
}
