<?php

declare(strict_types=1);

namespace MlGroup\Whatsapp;

use InvalidArgumentException;
use MlGroup\Support\Config;
use MlGroup\Support\Env;

final class Fabrica
{
    public static function criar(?string $driver = null): WhatsappInterface
    {
        $driver = strtolower($driver ?? Env::texto('WHATSAPP_DRIVER', Config::texto('config.whatsapp.driver', 'ponte')));

        return match ($driver) {
            'ponte'      => new Ponte(),
            'evolution'  => new EvolutionApi(),
            'zapi'       => new ZApi(),
            'wppconnect' => new WppConnect(),
            'simulado'   => new Simulado(),
            default      => throw new InvalidArgumentException(
                'Driver de WhatsApp desconhecido: ' . $driver
                . ' (use ponte, evolution, zapi, wppconnect ou simulado)'
            ),
        };
    }
}
