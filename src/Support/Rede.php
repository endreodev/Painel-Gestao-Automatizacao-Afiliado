<?php

declare(strict_types=1);

namespace MlGroup\Support;

/**
 * Quem pode falar com o painel.
 *
 * O painel edita configuracao e dispara envio, entao por padrao so responde a
 * quem chama de dentro da propria maquina. A lista de excecoes existe por causa
 * do container: com o painel dentro do Docker, a requisicao do navegador chega
 * pelo gateway da rede do Docker (172.x) e nao mais por 127.0.0.1 - o que faria
 * o painel responder 403 para o dono da maquina.
 */
final class Rede
{
    /** O endereco pode abrir o painel? */
    public static function liberado(string $ip): bool
    {
        $ip = self::normalizar($ip);

        // vazio = servidor embutido do PHP servindo o console, sem socket
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        foreach (self::origens() as $faixa) {
            if (self::pertence($ip, $faixa)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Faixas liberadas alem do proprio computador.
     *
     * PAINEL_ORIGENS no .env (ou no ambiente do container) tem prioridade sobre
     * config/config.php > painel.origens. Aceita IP exato ou faixa CIDR,
     * separados por virgula: "172.16.0.0/12, 192.168.0.10".
     *
     * @return string[]
     */
    private static function origens(): array
    {
        $bruto = Env::texto('PAINEL_ORIGENS', Config::texto('config.painel.origens'));

        if (trim($bruto) === '') {
            return [];
        }

        $faixas = array_map('trim', explode(',', $bruto));

        return array_values(array_filter($faixas, static fn (string $faixa): bool => $faixa !== ''));
    }

    /** O PHP entrega ::ffff:172.18.0.1 quando o socket e IPv6 e o cliente, IPv4. */
    private static function normalizar(string $ip): string
    {
        $ip = trim($ip);

        return str_starts_with($ip, '::ffff:') ? substr($ip, 7) : $ip;
    }

    private static function pertence(string $ip, string $faixa): bool
    {
        if (!str_contains($faixa, '/')) {
            return $ip === $faixa;
        }

        [$rede, $mascara] = explode('/', $faixa, 2);

        $alvo  = ip2long($ip);
        $base  = ip2long($rede);
        $bits  = (int) $mascara;

        if ($alvo === false || $base === false) {
            return false;
        }

        // /0 liberaria a internet inteira por causa de um erro de digitacao
        if ($bits < 1 || $bits > 32) {
            return false;
        }

        $corte = -1 << (32 - $bits);

        return ($alvo & $corte) === ($base & $corte);
    }
}
