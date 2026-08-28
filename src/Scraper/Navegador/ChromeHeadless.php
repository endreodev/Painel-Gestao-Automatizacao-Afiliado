<?php

declare(strict_types=1);

namespace MlGroup\Scraper\Navegador;

use MlGroup\Support\Config;
use MlGroup\Support\Logger;
use RuntimeException;

/**
 * Controla o Chrome/Edge em modo headless pelo DevTools Protocol.
 *
 * Por que nao --dump-dom: no Windows o chrome.exe e compilado como aplicativo
 * de interface grafica e nao escreve nada no stdout do console, entao a saida
 * volta sempre vazia. Abrir a porta de depuracao e falar DevTools funciona nos
 * tres sistemas e ainda permite esperar a pagina de fato terminar de renderizar.
 *
 * O navegador e iniciado uma vez e reaproveitado entre as paginas do ciclo.
 */
final class ChromeHeadless
{
    /** Caminhos testados quando config/.env nao informam o executavel. */
    private const CAMINHOS_PADRAO = [
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ];

    private ?string $executavel = null;

    /** @var resource|null */
    private $processo = null;

    private int $porta = 0;

    private string $perfil = '';

    /**
     * Perfil fixo, quando a sessao precisa sobreviver entre execucoes.
     *
     * A coleta usa perfil descartavel (nada a preservar). Ja o Link Builder de
     * afiliados exige estar logado no Mercado Livre, e o login so vale a pena
     * fazer uma vez - por isso ele aponta para uma pasta fixa.
     */
    public function __construct(private readonly ?string $perfilFixo = null)
    {
    }

    private int $sequencia = 0;

    /** User-Agent real do navegador, lido do proprio DevTools. */
    private string $userAgent = '';

    private bool $aquecido = false;

    public function __destruct()
    {
        $this->encerrar();
    }

    public function executavel(): ?string
    {
        if ($this->executavel !== null) {
            return $this->executavel;
        }

        $configurado = Config::texto('config.navegador.executavel');

        if ($configurado !== '' && is_file($configurado)) {
            return $this->executavel = $configurado;
        }

        foreach (self::CAMINHOS_PADRAO as $caminho) {
            if (is_file($caminho)) {
                return $this->executavel = $caminho;
            }
        }

        return null;
    }

    public function disponivel(): bool
    {
        return $this->executavel() !== null;
    }

    /**
     * Abre a URL e devolve o HTML ja renderizado.
     *
     * @throws RuntimeException quando o navegador nao esta instalado ou nao sobe
     */
    public function html(string $url): string
    {
        $this->garantirNavegador();
        $this->aquecer();

        return $this->abrirEler($url);
    }

    /**
     * Primeira visita da sessao vai para a home do ML.
     *
     * O Mercado Livre devolve pagina de "trafego suspeito" para quem cai direto
     * numa URL de lista sem nunca ter passado pela home. Uma visita previa
     * resolve os cookies de sessao e as listas passam a responder normalmente.
     */
    private function aquecer(): void
    {
        if ($this->aquecido || !Config::booleano('config.navegador.aquecer', true)) {
            return;
        }

        // marca antes de navegar para nao entrar em recursao pelo html()
        $this->aquecido = true;

        $url = Config::texto('config.navegador.url_aquecimento', 'https://www.mercadolivre.com.br');

        Logger::i()->debug('Aquecendo a sessao do navegador', ['url' => $url]);

        $this->abrirEler($url);
    }

    private function abrirEler(string $url): string
    {
        $valor = $this->abrirEavaliar($url, 'document.documentElement.outerHTML');

        return is_string($valor) ? $valor : '';
    }

    /**
     * Abre a URL e roda uma expressao JavaScript na pagina ja carregada.
     *
     * Usado por automacoes que precisam interagir, e nao so ler - o Link Builder
     * de afiliados, por exemplo, exige preencher um campo e clicar num botao.
     *
     * @param bool $aguardarPromessa true quando a expressao devolve uma Promise
     */
    public function avaliar(string $url, string $expressao, bool $aguardarPromessa = false): mixed
    {
        $this->garantirNavegador();
        $this->aquecer();

        return $this->abrirEavaliar($url, $expressao, $aguardarPromessa);
    }

    /**
     * Salva um PNG da pagina.
     *
     * Serve para diagnostico: ver com os proprios olhos o que o navegador
     * recebeu - a pagina de verificacao do ML, uma tela do painel, um layout
     * que quebrou. Devolve false se o navegador nao produzir imagem.
     */
    public function capturarTela(string $url, string $arquivo): bool
    {
        $this->garantirNavegador();

        $aba = $this->abrirAba();

        try {
            $ws = WebSocket::conectar($aba['ws'], (float) Config::inteiro('config.navegador.timeout_s', 60));

            try {
                $this->chamar($ws, 'Page.enable');
                $this->disfarcar($ws);
                $this->chamar($ws, 'Page.navigate', ['url' => $url]);
                $this->esperarCarregar($ws);

                usleep(Config::inteiro('config.navegador.espera_render_ms', 2500) * 1000);

                $resposta = $this->chamar($ws, 'Page.captureScreenshot', [
                    'format'      => 'png',
                    'captureBeyondViewport' => true,
                ]);

                $base64 = $resposta['result']['data'] ?? '';

                if (!is_string($base64) || $base64 === '') {
                    return false;
                }

                return file_put_contents($arquivo, base64_decode($base64, true)) !== false;
            } finally {
                $ws->fechar();
            }
        } finally {
            $this->fecharAba($aba['id']);
        }
    }

    private function abrirEavaliar(string $url, string $expressao, bool $aguardarPromessa = false): mixed
    {
        Logger::i()->debug('Abrindo no navegador', ['url' => $url]);

        $aba = $this->abrirAba();

        try {
            return $this->carregar($aba, $url, $expressao, $aguardarPromessa);
        } finally {
            $this->fecharAba($aba['id']);
        }
    }

    /** Fecha o navegador e limpa o perfil temporario. */
    public function encerrar(): void
    {
        // Browser.close vem primeiro porque no Windows o processo que lancamos
        // sai logo apos delegar a um filho: matar o handle que temos deixaria o
        // navegador de verdade rodando solto.
        $this->pedirFechamento();

        if (is_resource($this->processo)) {
            $estado = proc_get_status($this->processo);

            if ($estado['running']) {
                proc_terminate($this->processo, 9);
            }

            proc_close($this->processo);
        }

        $this->processo = null;
        $this->porta    = 0;

        // perfil fixo guarda a sessao logada: apagar seria pedir login de novo
        if ($this->perfil !== '' && $this->perfilFixo === null) {
            $this->apagarPasta($this->perfil);
        }

        $this->perfil = '';
    }

    /**
     * @param  array{id:string,ws:string} $aba
     */
    private function carregar(
        array $aba,
        string $url,
        string $expressao,
        bool $aguardarPromessa = false,
    ): mixed {
        $ws = WebSocket::conectar($aba['ws'], (float) Config::inteiro('config.navegador.timeout_s', 60));

        try {
            $this->chamar($ws, 'Page.enable');
            $this->disfarcar($ws);
            $this->chamar($ws, 'Page.navigate', ['url' => $url]);

            $this->esperarCarregar($ws);

            // margem para o conteudo que so aparece depois do load (lazy render)
            usleep(Config::inteiro('config.navegador.espera_render_ms', 2500) * 1000);

            $resposta = $this->chamar($ws, 'Runtime.evaluate', [
                'expression'    => $expressao,
                'returnByValue' => true,
                'awaitPromise'  => $aguardarPromessa,
            ]);

            $erro = $resposta['result']['exceptionDetails']['exception']['description'] ?? null;

            if (is_string($erro)) {
                Logger::i()->aviso('Erro ao rodar script na pagina', ['erro' => mb_substr($erro, 0, 200)]);
            }

            return $resposta['result']['result']['value'] ?? null;
        } finally {
            $ws->fechar();
        }
    }

    /**
     * Faz a aba parecer um navegador comum.
     *
     * Sem isso o Chrome headless se entrega por dois detalhes: navigator.webdriver
     * fica true e o User-Agent diz "HeadlessChrome". Sao os dois sinais que os
     * filtros de trafego automatizado olham primeiro.
     */
    private function disfarcar(WebSocket $ws): void
    {
        $this->chamar($ws, 'Emulation.setUserAgentOverride', [
            'userAgent'      => $this->userAgent(),
            'acceptLanguage' => 'pt-BR,pt;q=0.9,en;q=0.8',
            'platform'       => 'Win32',
        ]);

        $this->chamar($ws, 'Page.addScriptToEvaluateOnNewDocument', [
            'source' => <<<'JS'
                Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
                Object.defineProperty(navigator, 'languages', { get: () => ['pt-BR', 'pt', 'en'] });
                window.chrome = window.chrome || { runtime: {} };
            JS,
        ]);
    }

    /**
     * User-Agent usado nas paginas.
     *
     * Sem configuracao explicita, parte do UA real do navegador instalado e so
     * troca "HeadlessChrome" por "Chrome" - assim a versao nunca fica defasada
     * em relacao ao binario da maquina.
     */
    private function userAgent(): string
    {
        $configurado = Config::texto('config.navegador.user_agent');

        if ($configurado !== '') {
            return $configurado;
        }

        if ($this->userAgent !== '') {
            return $this->userAgent;
        }

        $versao = json_decode($this->devtools('GET', '/json/version'), true);
        $bruto  = is_array($versao) ? (string) ($versao['User-Agent'] ?? '') : '';

        if ($bruto === '') {
            return $this->userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';
        }

        return $this->userAgent = str_replace('HeadlessChrome', 'Chrome', $bruto);
    }

    /** Espera o evento de load; segue adiante no timeout em vez de abortar. */
    private function esperarCarregar(WebSocket $ws): void
    {
        $limite = microtime(true) + Config::inteiro('config.navegador.timeout_s', 60);

        while (microtime(true) < $limite) {
            $bruto = $ws->receber(max(1.0, $limite - microtime(true)));

            if ($bruto === null) {
                return;
            }

            $evento = json_decode($bruto, true);

            if (is_array($evento) && ($evento['method'] ?? '') === 'Page.loadEventFired') {
                return;
            }
        }

        Logger::i()->debug('Load da pagina nao confirmado no tempo limite, seguindo mesmo assim');
    }

    /**
     * Envia um comando e devolve a resposta correspondente, ignorando os
     * eventos que chegam no meio.
     *
     * @param  array<string,mixed> $parametros
     * @return array<string,mixed>
     */
    private function chamar(WebSocket $ws, string $metodo, array $parametros = []): array
    {
        $id = ++$this->sequencia;

        $comando = ['id' => $id, 'method' => $metodo];

        if ($parametros !== []) {
            $comando['params'] = $parametros;
        }

        $ws->enviar((string) json_encode($comando, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $limite = microtime(true) + Config::inteiro('config.navegador.timeout_s', 60);

        while (microtime(true) < $limite) {
            $bruto = $ws->receber(max(1.0, $limite - microtime(true)));

            if ($bruto === null) {
                break;
            }

            $resposta = json_decode($bruto, true);

            if (is_array($resposta) && ($resposta['id'] ?? null) === $id) {
                return $resposta;
            }
        }

        throw new RuntimeException('Sem resposta do navegador para ' . $metodo);
    }

    /** @return array{id:string,ws:string} */
    private function abrirAba(): array
    {
        // /json/new exige PUT desde o Chrome 111
        $bruto = $this->devtools('PUT', '/json/new?about:blank');
        $dados = json_decode($bruto, true);

        $id = is_array($dados) ? (string) ($dados['id'] ?? '') : '';
        $ws = is_array($dados) ? (string) ($dados['webSocketDebuggerUrl'] ?? '') : '';

        if ($id === '' || $ws === '') {
            throw new RuntimeException('Nao foi possivel abrir uma aba no navegador: ' . substr($bruto, 0, 200));
        }

        return ['id' => $id, 'ws' => $ws];
    }

    private function fecharAba(string $id): void
    {
        $this->devtools('GET', '/json/close/' . rawurlencode($id));
    }

    private function devtools(string $metodo, string $rota): string
    {
        $ch = curl_init('http://127.0.0.1:' . $this->porta . $rota);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $retorno = curl_exec($ch);
        curl_close($ch);

        return $retorno === false ? '' : (string) $retorno;
    }

    /** Manda o navegador se fechar pelo canal do DevTools. */
    private function pedirFechamento(): void
    {
        if ($this->porta <= 0) {
            return;
        }

        $versao = json_decode($this->devtools('GET', '/json/version'), true);
        $ws     = is_array($versao) ? (string) ($versao['webSocketDebuggerUrl'] ?? '') : '';

        if ($ws === '') {
            return;
        }

        try {
            $canal = WebSocket::conectar($ws, 5.0);
            $canal->enviar((string) json_encode(['id' => ++$this->sequencia, 'method' => 'Browser.close']));
            $canal->receber(5.0);
            $canal->fechar();

            // da tempo de os processos filhos saírem antes de apagar o perfil
            usleep(500000);
        } catch (RuntimeException $erro) {
            Logger::i()->debug('Fechamento limpo do navegador falhou', ['motivo' => $erro->getMessage()]);
        }
    }

    /** Derruba o navegador atual e comeca sessao limpa (cookies novos). */
    public function reiniciar(): void
    {
        Logger::i()->debug('Reiniciando o navegador');

        $this->encerrar();

        $this->aquecido  = false;
        $this->userAgent = '';
    }

    private function garantirNavegador(): void
    {
        // a saude e medida pela porta de depuracao, nao por proc_get_status:
        // no Windows o processo lancado repassa o trabalho a um filho e sai,
        // o que faria o status dizer "caiu" com o navegador rodando.
        if ($this->processo !== null) {
            if ($this->porta > 0 && $this->devtools('GET', '/json/version') !== '') {
                return;
            }

            Logger::i()->aviso('Navegador nao responde, reiniciando');
            $this->encerrar();
        }

        $executavel = $this->executavel();

        if ($executavel === null) {
            throw new RuntimeException(
                'Chrome/Edge nao encontrado. Informe o caminho em config/config.php > navegador.executavel'
            );
        }

        $this->porta  = $this->portaLivre();
        $this->perfil = $this->perfilTemporario();

        $comando = [
            $executavel,
            '--headless=new',
            '--disable-gpu',
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-extensions',
            '--disable-background-networking',
            '--disable-dev-shm-usage',
            '--disable-blink-features=AutomationControlled',
            '--hide-scrollbars',
            '--mute-audio',
            '--lang=pt-BR',
            '--window-size=1920,1080',
            '--remote-debugging-port=' . $this->porta,
            '--user-data-dir=' . $this->perfil,
            'about:blank',
        ];

        // o UA definitivo e aplicado por aba em disfarcar(); aqui so vale o
        // que o usuario fixou na config
        $userAgent = Config::texto('config.navegador.user_agent');

        if ($userAgent !== '') {
            array_splice($comando, -1, 0, ['--user-agent=' . $userAgent]);
        }

        $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processo    = proc_open($comando, $descritores, $canos);

        if (!is_resource($processo)) {
            throw new RuntimeException('Nao foi possivel iniciar o navegador');
        }

        // pipes ficam abertos e sem bloqueio so para o Chrome nao travar escrevendo
        foreach ($canos as $cano) {
            if (is_resource($cano)) {
                stream_set_blocking($cano, false);
            }
        }

        $this->processo = $processo;

        $this->esperarDevtools();

        Logger::i()->debug('Navegador pronto', ['porta' => $this->porta]);
    }

    private function esperarDevtools(): void
    {
        $limite = microtime(true) + 30;

        while (microtime(true) < $limite) {
            if ($this->devtools('GET', '/json/version') !== '') {
                return;
            }

            usleep(250000);
        }

        $this->encerrar();

        throw new RuntimeException('O navegador subiu mas a porta de depuracao nao respondeu');
    }

    private function portaLivre(): int
    {
        $configurada = Config::inteiro('config.navegador.porta_devtools', 0);

        if ($configurada > 0) {
            return $configurada;
        }

        $socket = @stream_socket_server('tcp://127.0.0.1:0', $codigo, $mensagem);

        if ($socket === false) {
            return random_int(9300, 9799);
        }

        $nome = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $porta = (int) substr($nome, (int) strrpos($nome, ':') + 1);

        return $porta > 0 ? $porta : random_int(9300, 9799);
    }

    private function perfilTemporario(): string
    {
        if ($this->perfilFixo !== null) {
            if (!is_dir($this->perfilFixo)) {
                mkdir($this->perfilFixo, 0775, true);
            }

            return $this->perfilFixo;
        }

        $this->limparPerfisAbandonados();

        $pasta = MLG_ROOT . '/storage/cache/chrome-' . bin2hex(random_bytes(6));

        if (!is_dir($pasta)) {
            mkdir($pasta, 0775, true);
        }

        return $pasta;
    }

    /**
     * Abre uma janela de verdade (sem headless) no perfil fixo e devolve o
     * controle na hora.
     *
     * E o unico jeito de o usuario fazer um login que passa por reCAPTCHA: quem
     * digita e resolve o desafio e ele, no navegador dele. Depois disso a sessao
     * fica no perfil e o modo headless a reaproveita.
     */
    public function abrirJanelaDeLogin(string $url): void
    {
        $executavel = $this->executavel();

        if ($executavel === null) {
            throw new RuntimeException('Chrome/Edge nao encontrado');
        }

        // o headless nao pode estar segurando o mesmo user-data-dir
        $this->encerrar();

        $perfil = $this->perfilFixo ?? $this->perfilTemporario();

        if (!is_dir($perfil)) {
            mkdir($perfil, 0775, true);
        }

        $comando = sprintf(
            'powershell -NoProfile -NonInteractive -Command "Start-Process -FilePath \'%s\''
            . ' -ArgumentList \'--user-data-dir=%s\',\'--no-first-run\',\'--no-default-browser-check\',\'%s\'"',
            str_replace('/', '\\', $executavel),
            str_replace('/', '\\', $perfil),
            $url,
        );

        $handle = popen($comando, 'r');

        if ($handle !== false) {
            pclose($handle);
        }
    }

    /**
     * Remove perfis de execucoes que nao chegaram a encerrar direito (Ctrl+C,
     * queda de energia). Sem isso storage/cache cresce sem parar.
     */
    private function limparPerfisAbandonados(): void
    {
        $corte = time() - (Config::inteiro('config.navegador.perfil_vencido_min', 60) * 60);

        foreach (glob(MLG_ROOT . '/storage/cache/chrome-*') ?: [] as $pasta) {
            if (is_dir($pasta) && filemtime($pasta) < $corte) {
                $this->apagarPasta($pasta);
            }
        }
    }

    private function apagarPasta(string $pasta): void
    {
        if (!is_dir($pasta)) {
            return;
        }

        $itens = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($itens as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($pasta);
    }
}
