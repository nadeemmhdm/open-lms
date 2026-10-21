<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Config;
use OpenLMS\Core\Database;
use PDO;

final class BackupService
{
    private const FORMAT = 'open-lms-backup-v2';
    private const AAD = 'OPENLMS-BACKUP-V2';

    private const TABLES = [
        'roles','permissions','users','user_roles','role_permissions','academic_years','departments','classes','sections','batches','semesters','batch_members','subjects','courses','course_batches','enrollments','course_sections','lessons','lesson_links','lesson_prerequisites','learning_materials','lesson_progress','attendance_sessions','attendance_records','attendance_corrections','question_banks','questions','question_options','quizzes','quiz_questions','exam_attempts','attempt_questions','exam_answers','exam_events','projects','project_groups','project_group_members','project_submissions','submission_files','project_submission_events','grades','feature_settings','app_settings','notifications','announcements','certificates','timetable_entries','live_classes','parent_student_links','fee_invoices','fee_payments','password_reset_tokens','course_completions','mfa_credentials','login_attempts','audit_logs','schema_migrations'
    ];

    public static function createEncrypted(int $actorUserId): array
    {
        self::requireCrypto();
        $pdo = Database::connection();
        $payload = [
            'format' => self::FORMAT,
            'created_at' => gmdate('c'),
            'driver' => (string) Config::get('database.driver', 'mysql'),
            'tables' => [],
            'uploads' => [],
        ];

        foreach (self::TABLES as $table) {
            try {
                $payload['tables'][$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable) {
                $payload['tables'][$table] = [];
            }
        }

        foreach (self::referencedUploads($pdo) as $relative) {
            $path = UploadService::resolveStoredPath($relative);
            if ($path === null || !is_file($path) || !is_readable($path)) {
                throw new \RuntimeException('A referenced private upload is missing or unreadable; backup was not created.');
            }
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException('Unable to read a private upload for backup.');
            }
            $payload['uploads'][$relative] = [
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'data' => base64_encode($bytes),
            ];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode backup.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($json, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, self::AAD, 16);
        if ($cipher === false) {
            throw new \RuntimeException('Unable to encrypt backup.');
        }

        $dir = app_root() . '/storage/backups';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Backup directory is not writable.');
        }
        $name = 'open-lms-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.olmsbak';
        $path = $dir . '/' . $name;
        $body = "OLMSBK2\n" . base64_encode($iv . $tag . $cipher);
        if (file_put_contents($path, $body, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write backup file.');
        }
        @chmod($path, 0640);
        $size = (int) filesize($path);
        $pdo->prepare('INSERT INTO backup_history (file_name,driver,size_bytes,created_by,created_at) VALUES (?,?,?,?,?)')
            ->execute([$name, (string) Config::get('database.driver', 'mysql'), $size, $actorUserId, gmdate('Y-m-d H:i:s')]);
        return [$name, $size];
    }

    public static function restoreEncrypted(string $name, int $actorUserId): void
    {
        self::requireCrypto();
        if (!preg_match('/^open-lms-[A-Za-z0-9._-]+\.olmsbak$/', $name)) {
            throw new \RuntimeException('Invalid backup name.');
        }
        $backupDir = app_root() . '/storage/backups';
        $path = $backupDir . '/' . $name;
        $real = realpath($path);
        $base = realpath($backupDir);
        if (!$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Backup not found.');
        }

        $body = (string) file_get_contents($real);
        $v2 = str_starts_with($body, "OLMSBK2\n");
        $v1 = str_starts_with($body, "OLMSBK1\n");
        if (!$v2 && !$v1) {
            throw new \RuntimeException('Unsupported backup format.');
        }
        $raw = base64_decode(substr($body, 8), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Corrupted backup.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $aad = $v2 ? self::AAD : 'OPENLMS';
        $json = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($json === false) {
            throw new \RuntimeException('Backup authentication failed.');
        }
        $payload = json_decode($json, true);
        $expectedFormat = $v2 ? self::FORMAT : 'open-lms-backup-v1';
        if (!is_array($payload) || ($payload['format'] ?? '') !== $expectedFormat || !is_array($payload['tables'] ?? null)) {
            throw new \RuntimeException('Invalid backup payload.');
        }

        $stage = null;
        if ($v2) {
            if (!is_array($payload['uploads'] ?? null)) {
                throw new \RuntimeException('Backup upload payload is invalid.');
            }
            $stage = self::stageUploads($payload['uploads']);
        }

        $pdo = Database::connection();
        $driver = (string) Config::get('database.driver', 'mysql');
        $uploads = app_root() . '/storage/uploads';
        $oldUploads = null;
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = OFF');
        } else {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        }

        $pdo->beginTransaction();
        try {
            foreach (array_reverse(self::TABLES) as $table) {
                if ($table === 'backup_history') continue;
                try { $pdo->exec('DELETE FROM ' . $table); } catch (\Throwable) {}
            }
            foreach (self::TABLES as $table) {
                if ($table === 'backup_history') continue;
                $rows = $payload['tables'][$table] ?? [];
                if (!is_array($rows)) continue;
                foreach ($rows as $row) {
                    if (!is_array($row) || !$row) continue;
                    $cols = array_keys($row);
                    if (array_diff($cols, self::columns($pdo, $table, $driver))) {
                        throw new \RuntimeException('Backup schema does not match the installed application.');
                    }
                    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
                    $pdo->prepare($sql)->execute(array_values($row));
                }
            }

            if ($v2 && $stage !== null) {
                if (!is_dir(dirname($uploads))) {
                    throw new \RuntimeException('Storage directory is unavailable.');
                }
                $oldUploads = app_root() . '/storage/uploads.pre-restore-' . bin2hex(random_bytes(4));
                if (is_dir($uploads) && !rename($uploads, $oldUploads)) {
                    throw new \RuntimeException('Unable to stage current private uploads for restore.');
                }
                if (!rename($stage, $uploads)) {
                    if (is_dir($oldUploads)) @rename($oldUploads, $uploads);
                    throw new \RuntimeException('Unable to activate restored private uploads.');
                }
                $stage = null;
            }

            $pdo->prepare('UPDATE backup_history SET restored_at=? WHERE file_name=?')->execute([gmdate('Y-m-d H:i:s'), $name]);
            $pdo->commit();
            if ($oldUploads && is_dir($oldUploads)) self::removeTree($oldUploads);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($v2) {
                if (is_dir($uploads)) self::removeTree($uploads);
                if ($oldUploads && is_dir($oldUploads)) @rename($oldUploads, $uploads);
                if ($stage && is_dir($stage)) self::removeTree($stage);
            }
            throw $e;
        } finally {
            if ($driver === 'sqlite') $pdo->exec('PRAGMA foreign_keys = ON');
            else $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public static function listBackups(): array
    {
        $dir = app_root() . '/storage/backups';
        $files = glob($dir . '/open-lms-*.olmsbak') ?: [];
        $out = [];
        foreach ($files as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'modified' => (int) filemtime($f)];
        }
        usort($out, fn($a, $b) => $b['modified'] <=> $a['modified']);
        return $out;
    }

    private static function referencedUploads(PDO $pdo): array
    {
        $paths = [];
        foreach (['learning_materials', 'submission_files'] as $table) {
            try {
                foreach ($pdo->query('SELECT stored_name FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN) as $relative) {
                    $relative = (string) $relative;
                    if (!self::validRelativeUpload($relative)) {
                        throw new \RuntimeException('Unsafe private upload path found in database.');
                    }
                    $paths[$relative] = true;
                }
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Throwable) {}
        }
        return array_keys($paths);
    }

    private static function stageUploads(array $files): string
    {
        $stage = app_root() . '/storage/uploads.restore-' . bin2hex(random_bytes(6));
        if (!mkdir($stage, 0750, true) && !is_dir($stage)) {
            throw new \RuntimeException('Unable to create restore staging directory.');
        }
        try {
            foreach ($files as $relative => $meta) {
                $relative = (string) $relative;
                if (!self::validRelativeUpload($relative) || !is_array($meta)) {
                    throw new \RuntimeException('Unsafe upload entry in backup.');
                }
                $bytes = base64_decode((string)($meta['data'] ?? ''), true);
                if ($bytes === false || strlen($bytes) !== (int)($meta['size'] ?? -1) || !hash_equals((string)($meta['sha256'] ?? ''), hash('sha256', $bytes))) {
                    throw new \RuntimeException('Private upload integrity check failed during restore.');
                }
                $target = $stage . '/' . $relative;
                $dir = dirname($target);
                if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                    throw new \RuntimeException('Unable to create restore upload directory.');
                }
                if (file_put_contents($target, $bytes, LOCK_EX) === false) {
                    throw new \RuntimeException('Unable to stage a restored private upload.');
                }
                @chmod($target, 0640);
            }
            return $stage;
        } catch (\Throwable $e) {
            self::removeTree($stage);
            throw $e;
        }
    }

    private static function validRelativeUpload(string $relative): bool
    {
        return $relative !== ''
            && !str_contains($relative, '..')
            && !str_starts_with($relative, '/')
            && !str_contains($relative, '\\')
            && (str_starts_with($relative, 'materials/') || str_starts_with($relative, 'projects/'))
            && preg_match('#^[A-Za-z0-9/_-]+\.dat$#', $relative) === 1;
    }

    private static function columns(PDO $pdo, string $table, string $driver): array
    {
        if ($driver === 'sqlite') {
            $rows = $pdo->query("PRAGMA table_info('" . str_replace("'", "''", $table) . "')")->fetchAll(PDO::FETCH_ASSOC);
            return array_column($rows, 'name');
        }
        $rows = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
        return array_column($rows, 'Field');
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) @unlink($path);
            elseif (is_dir($path)) self::removeTree($path);
        }
        @rmdir($dir);
    }

    private static function requireCrypto(): void
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new \RuntimeException('OpenSSL is required for encrypted backups.');
        }
    }

    private static function key(): string
    {
        $v = (string) Config::get('app.key', '');
        return preg_match('/^[a-f0-9]{64}$/i', $v)
            ? (hex2bin($v) ?: hash('sha256', $v, true))
            : hash('sha256', $v, true);
    }
}
