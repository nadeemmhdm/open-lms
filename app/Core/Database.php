<?php
namespace OpenLMS\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(?array $override = null): PDO
    {
        if ($override === null && self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = $override ?? (array) Config::get('database', []);
        $driver = $db['driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            $path = $db['sqlite_path'] ?? dirname(__DIR__, 2) . '/storage/open-lms.sqlite';
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $host = $db['host'] ?? 'localhost';
            $port = (int) ($db['port'] ?? 3306);
            $name = $db['name'] ?? '';
            $charset = $db['charset'] ?? 'utf8mb4';
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
            $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        if ($override === null) {
            self::$pdo = $pdo;
        }
        return $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
