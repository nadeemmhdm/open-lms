<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Config;

final class BackupService
{
    public static function sqliteBackup(): ?string
    {
        if (Config::get('database.driver') !== 'sqlite') return null;
        $source = (string) Config::get('database.sqlite_path');
        if (!is_file($source)) return null;
        $dir = dirname(__DIR__, 2) . '/storage/backups';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $target = $dir . '/open-lms-' . gmdate('Ymd-His') . '.sqlite';
        return copy($source, $target) ? $target : null;
    }
}
