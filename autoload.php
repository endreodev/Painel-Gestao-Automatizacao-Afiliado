<?php
/**
 * Autoloader PSR-4 simples (o projeto nao usa Composer de proposito:
 * roda em qualquer PHP 8.1+ com curl/pdo_sqlite, sem instalar nada).
 */

declare(strict_types=1);

define('MLG_ROOT', __DIR__);

spl_autoload_register(static function (string $classe): void {
    $prefixo = 'MlGroup\\';

    if (!str_starts_with($classe, $prefixo)) {
        return;
    }

    $relativo = substr($classe, strlen($prefixo));
    $arquivo  = MLG_ROOT . '/src/' . str_replace('\\', '/', $relativo) . '.php';

    if (is_file($arquivo)) {
        require_once $arquivo;
    }
});

require_once MLG_ROOT . '/src/Support/Env.php';

\MlGroup\Support\Env::carregar(MLG_ROOT . '/.env');

/*
 * O fuso e definido aqui, e nao no bin/mlgroup, porque todo horario gravado no
 * banco (envios, historico de preco, execucoes) e comparado como texto. Se um
 * ponto de entrada rodasse sem isto, gravaria em UTC e as janelas de "ja enviei
 * ha X dias" e "coleta ainda no prazo" passariam a mentir por 3 horas.
 */
date_default_timezone_set(
    \MlGroup\Support\Config::texto('config.fuso', 'America/Sao_Paulo')
);
