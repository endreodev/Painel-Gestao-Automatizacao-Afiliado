<?php

declare(strict_types=1);

namespace MlGroup\Support;

/**
 * Cliente HTTP em cURL puro, com retry/backoff e rotacao de User-Agent.
 */
final class Http
{
    /** @var string[] */
    private const AGENTES = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0',
    ];

    public function __construct(
        private readonly int $timeout = 25,
        private readonly int $tentativas = 3,
        private readonly int $esperaBaseMs = 800,
    ) {
    }

    /**
     * @param array<string,string> $cabecalhos
     */
    public function get(string $url, array $cabecalhos = []): RespostaHttp
    {
        return $this->requisitar('GET', $url, null, $cabecalhos);
    }

    /**
     * @param array<string,mixed>  $corpo
     * @param array<string,string> $cabecalhos
     */
    public function postJson(string $url, array $corpo, array $cabecalhos = []): RespostaHttp
    {
        return $this->postJsonBruto($url, self::json($corpo), $cabecalhos);
    }

    /**
     * POST de um JSON ja serializado.
     *
     * Existe para quem assina o corpo: a API de afiliados da Shopee calcula a
     * assinatura sobre o texto exato que trafega. Reserializar aqui dentro
     * arriscaria uma virgula ou uma barra a mais e a resposta seria
     * "Invalid Signature", sem dizer por que.
     *
     * @param array<string,string> $cabecalhos
     */
    public function postJsonBruto(string $url, string $json, array $cabecalhos = []): RespostaHttp
    {
        $cabecalhos['Content-Type'] = 'application/json';

        return $this->requisitar('POST', $url, $json, $cabecalhos);
    }

    /**
     * Serializa do jeito que o projeto inteiro serializa.
     *
     * @param array<string,mixed> $corpo
     */
    public static function json(array $corpo): string
    {
        $json = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }

    /**
     * @param array<string,string> $cabecalhos
     */
    private function requisitar(string $metodo, string $url, ?string $corpo, array $cabecalhos): RespostaHttp
    {
        $ultimaFalha = 'erro desconhecido';

        for ($tentativa = 1; $tentativa <= $this->tentativas; $tentativa++) {
            $ch = curl_init();

            $cabecalhosFinais = array_merge([
                'Accept'          => 'application/json, text/html;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
                'Cache-Control'   => 'no-cache',
            ], $cabecalhos);

            $listaCabecalhos = [];

            foreach ($cabecalhosFinais as $nome => $valor) {
                $listaCabecalhos[] = $nome . ': ' . $valor;
            }

            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_CUSTOMREQUEST  => $metodo,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => $listaCabecalhos,
                CURLOPT_USERAGENT      => self::AGENTES[array_rand(self::AGENTES)],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $caBundle = CertificadoRaiz::caminho();

            if ($caBundle !== null) {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            }

            if ($corpo !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
            }

            $retorno = curl_exec($ch);
            $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $falha   = curl_error($ch);

            curl_close($ch);

            if ($retorno !== false && $falha === '') {
                // 429/5xx merecem nova tentativa; o resto devolve como esta.
                if ($status !== 429 && $status < 500) {
                    return new RespostaHttp($status, (string) $retorno);
                }

                $ultimaFalha = 'HTTP ' . $status;
            } else {
                $ultimaFalha = $falha !== '' ? $falha : 'resposta vazia';
            }

            if ($tentativa < $this->tentativas) {
                // backoff exponencial com jitter
                $espera = $this->esperaBaseMs * (2 ** ($tentativa - 1)) + random_int(0, 400);
                usleep($espera * 1000);
            }
        }

        Logger::i()->aviso('Falha HTTP apos todas as tentativas', ['url' => $url, 'motivo' => $ultimaFalha]);

        return new RespostaHttp(0, '', $ultimaFalha);
    }
}
