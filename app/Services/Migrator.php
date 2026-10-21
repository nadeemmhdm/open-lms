<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Config;
use OpenLMS\Core\Database;
use PDO;

final class Migrator
{
    public static function migrateConfigured(): void
    {
        $driver = (string) Config::get('database.driver', 'mysql');
        self::applyAll(Database::connection(), $driver);
    }

    public static function applyAll(PDO $pdo, string $driver): void
    {
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new \RuntimeException('Unsupported migration driver.');
        }

        $root = dirname(__DIR__, 2);
        $files = glob($root . '/database/migrations/*_' . $driver . '.sql') ?: [];
        sort($files, SORT_NATURAL);

        $applied = [];
        try {
            $applied = array_fill_keys($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN), true);
        } catch (\Throwable) {
            // Fresh installs do not have schema_migrations until migration 001 is applied.
        }

        foreach ($files as $file) {
            $base = basename($file, '.sql');
            $version = preg_replace('/_(mysql|sqlite)$/', '', $base) ?: $base;
            if (isset($applied[$version])) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException('Unable to read migration: ' . basename($file));
            }

            $useTransaction = $driver === 'sqlite' && !$pdo->inTransaction();
            if ($useTransaction) {
                $pdo->beginTransaction();
            }

            try {
                $pdo->exec($sql);
                $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
                $stmt->execute([$version, gmdate('Y-m-d H:i:s')]);
                if ($useTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                $applied[$version] = true;
            } catch (\Throwable $e) {
                if ($useTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    }
}
