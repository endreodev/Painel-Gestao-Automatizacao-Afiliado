<?php
/**
 * Porta de entrada do painel web.
 *
 * Servido pelo servidor embutido do PHP, preso a 127.0.0.1 (no container ele
 * escuta em 0.0.0.0 e a lista de PAINEL_ORIGENS diz quem entra):
 *   php bin/mlgroup painel
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use MlGroup\Painel\Painel;

$rota   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$rota   = rtrim($rota, '/') ?: '/';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// requisicao de fora da maquina nao passa: o painel edita configuracao
$remoto = $_SERVER['REMOTE_ADDR'] ?? '';

if (!\MlGroup\Support\Rede::liberado($remoto)) {
    http_response_code(403);
    exit('O painel responde apenas localmente.');
}

/*
 * O logo, antes de qualquer cabecalho de HTML.
 *
 * O painel nao serve arquivo estatico - o servidor embutido manda tudo para
 * este script. Sem esta saida, /logo.png cairia na rota padrao e devolveria a
 * pagina inicial no lugar da imagem.
 *
 * O arquivo vai inteiro, sem redimensionar: nao ha GD nesta instalacao. Custa
 * pouco porque so trafega em 127.0.0.1 e porque o cabecalho de cache faz o
 * navegador buscar uma vez so - as visitas seguintes respondem 304, sem corpo.
 */
if (in_array($rota, ['/logo.png', '/favicon.ico'], true)) {
    $arquivo = MLG_ROOT . '/logo.png';

    if (!is_file($arquivo)) {
        http_response_code(404);
        exit;
    }

    $etiqueta = '"' . md5((string) filemtime($arquivo) . filesize($arquivo)) . '"';

    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('ETag: ' . $etiqueta);

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etiqueta) {
        http_response_code(304);
        exit;
    }

    header('Content-Length: ' . filesize($arquivo));
    readfile($arquivo);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

echo (new Painel())->responder($rota, $metodo, $_POST, $_GET);
