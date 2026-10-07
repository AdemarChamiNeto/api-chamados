<?php

declare(strict_types=1);

namespace Chamados;

use PDO;

final class Database
{
    public static function connect(string $dsn, ?string $user = null, ?string $password = null): PDO
    {
        $pdo = new PDO($dsn, $user ?: null, $password ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        if (self::driver($pdo) === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $pdo->exec("SET time_zone = '+00:00'");
        }
        return $pdo;
    }

    public static function driver(PDO $pdo): string
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** Aplica o schema do driver (idempotente: CREATE ... IF NOT EXISTS). */
    public static function migrate(PDO $pdo): void
    {
        $file = __DIR__ . '/../database/schema.' . (self::driver($pdo) === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
        }
    }
}
