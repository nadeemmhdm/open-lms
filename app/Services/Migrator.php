<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Config;
use OpenLMS\Core\Database;
use PDO;
use PDOException;

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
                if ($driver === 'mysql') {
                    foreach (self::splitStatements($sql) as $index => $statement) {
                        try {
                            $pdo->exec($statement);
                        } catch (PDOException $statementError) {
                            if (!self::isRetrySafeDuplicateDdl($statementError, $statement)) {
                                throw new \RuntimeException(
                                    'Migration ' . basename($file) . ' statement ' . ($index + 1) . ' failed: ' . self::compactDbMessage($statementError),
                                    0,
                                    $statementError
                                );
                            }
                        }
                    }
                } else {
                    try {
                        $pdo->exec($sql);
                    } catch (PDOException $statementError) {
                        throw new \RuntimeException(
                            'Migration ' . basename($file) . ' failed: ' . self::compactDbMessage($statementError),
                            0,
                            $statementError
                        );
                    }
                }
                try {
                    $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
                    $stmt->execute([$version, gmdate('Y-m-d H:i:s')]);
                } catch (PDOException $versionError) {
                    throw new \RuntimeException(
                        'Migration ' . basename($file) . ' could not record completion: ' . self::compactDbMessage($versionError),
                        0,
                        $versionError
                    );
                }
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
    /**
     * Migrations intentionally contain only ordinary DDL/DML (no stored routines).
     * Execute MySQL statements individually so shared hosts do not need multi-statement PDO support.
     */
    private static function splitStatements(string $sql): array
    {
        $parts = preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $statement): bool => $statement !== ''));
    }


    /**
     * MySQL/MariaDB DDL auto-commits on shared hosts. If a previous request died
     * after a DDL statement but before schema_migrations was recorded, repeating
     * that exact migration can legitimately hit an "already exists" error.
     */
    private static function isRetrySafeDuplicateDdl(PDOException $e, string $statement): bool
    {
        $info = $e->errorInfo;
        $code = is_array($info) && isset($info[1]) && is_numeric($info[1]) ? (int)$info[1] : null;
        $normalized = strtoupper(ltrim($statement));
        if ($code === 1060 && str_starts_with($normalized, 'ALTER TABLE') && str_contains($normalized, 'ADD COLUMN')) return true;
        if ($code === 1061 && str_starts_with($normalized, 'CREATE INDEX')) return true;
        if ($code === 1050 && str_starts_with($normalized, 'CREATE TABLE')) return true;
        return false;
    }

    private static function compactDbMessage(PDOException $e): string
    {
        $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?: 'database error';
        if (strlen($message) > 220) $message = substr($message, 0, 217) . '...';
        return $message;
    }

}
