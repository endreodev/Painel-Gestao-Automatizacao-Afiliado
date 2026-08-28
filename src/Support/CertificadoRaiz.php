<?php

declare(strict_types=1);

namespace MlGroup\Support;

/**
 * Descobre o pacote de certificados raiz (CA bundle) para o cURL validar HTTPS.
 *
 * O PHP para Windows nao vem com CA bundle e, sem curl.cainfo no php.ini, toda
 * chamada HTTPS falha com "unable to get local issuer certificate". Em vez de
 * desligar a verificacao - o que abriria a porta para interceptacao no meio do
 * caminho - o sistema procura um bundle ja instalado na maquina.
 */
final class CertificadoRaiz
{
    /** Locais mais comuns em instalacoes Windows. */
    private const CANDIDATOS = [
        'C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt',
        'C:\Program Files\Git\mingw64\ssl\certs\ca-bundle.crt',
        'C:\Program Files (x86)\Git\mingw64\etc\ssl\certs\ca-bundle.crt',
        'C:\php\extras\ssl\cacert.pem',
        'C:\xampp\apache\bin\curl-ca-bundle.crt',
        'C:\laragon\bin\php\cacert.pem',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/usr/local/etc/openssl/cert.pem',
    ];

    private static ?string $caminho = null;

    private static bool $procurado = false;

    public static function caminho(): ?string
    {
        if (self::$procurado) {
            return self::$caminho;
        }

        self::$procurado = true;

        foreach (self::origens() as $origem) {
            if ($origem !== '' && is_file($origem)) {
                return self::$caminho = $origem;
            }
        }

        Logger::i()->aviso(
            'CA bundle nao encontrado - chamadas HTTPS vao falhar. '
            . 'Baixe https://curl.se/ca/cacert.pem e aponte em config/config.php > http.ca_bundle'
        );

        return self::$caminho = null;
    }

    /** @return string[] */
    private static function origens(): array
    {
        return [
            Config::texto('config.http.ca_bundle'),
            Env::texto('CURL_CA_BUNDLE'),
            (string) ini_get('curl.cainfo'),
            (string) ini_get('openssl.cafile'),
            MLG_ROOT . '/storage/cacert.pem',
            ...self::CANDIDATOS,
        ];
    }
}
