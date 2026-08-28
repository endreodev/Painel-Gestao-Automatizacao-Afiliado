<?php

declare(strict_types=1);

namespace MlGroup\Database;

use PDO;
use PDOStatement;

/**
 * Conexao SQLite unica do processo.
 */
final class Db
{
    private static ?PDO $pdo = null;

    /** Quando definido, substitui o banco padrao. Ver usarArquivo(). */
    private static ?string $arquivo = null;

    /**
     * Aponta a conexao para outro banco.
     *
     * Existe para o autoteste rodar num banco proprio. Ate aqui ele inseria
     * produto ficticio, marcava envio e limpava tudo no fim - no banco de
     * producao. Funcionava, mas uma interrupcao no meio deixava lixo, e as
     * contagens dos testes dependiam de quanto o sistema real ja tinha coletado.
     *
     * Fecha a conexao aberta: continuar na antiga deixaria o teste escrevendo no
     * banco que ele acabou de dizer para nao usar.
     */
    public static function usarArquivo(?string $caminho): void
    {
        self::$pdo     = null;
        self::$arquivo = $caminho;
    }

    public static function conexao(): PDO
    {
        if (self::$pdo === null) {
            $caminho = self::$arquivo ?? MLG_ROOT . '/storage/mlgroup.sqlite';
            $pasta   = dirname($caminho);

            if (!is_dir($pasta)) {
                mkdir($pasta, 0775, true);
            }

            self::$pdo = new PDO('sqlite:' . $caminho, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');

            Migracoes::executar(self::$pdo);
        }

        return self::$pdo;
    }

    /** @param array<string,mixed> $parametros */
    public static function executar(string $sql, array $parametros = []): PDOStatement
    {
        $stmt = self::conexao()->prepare($sql);
        $stmt->execute($parametros);

        return $stmt;
    }

    /**
     * @param array<string,mixed> $parametros
     * @return array<int,array<string,mixed>>
     */
    public static function todos(string $sql, array $parametros = []): array
    {
        return self::executar($sql, $parametros)->fetchAll();
    }

    /**
     * @param array<string,mixed> $parametros
     * @return array<string,mixed>|null
     */
    public static function primeiro(string $sql, array $parametros = []): ?array
    {
        $linha = self::executar($sql, $parametros)->fetch();

        return $linha === false ? null : $linha;
    }

    /** @param array<string,mixed> $parametros */
    public static function valor(string $sql, array $parametros = []): mixed
    {
        $valor = self::executar($sql, $parametros)->fetchColumn();

        return $valor === false ? null : $valor;
    }
}
