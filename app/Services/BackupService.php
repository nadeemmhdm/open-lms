<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Config;
use OpenLMS\Core\Database;
use PDO;

final class BackupService
{
    private const FORMAT = 'open-lms-backup-v3';
    private const AAD = 'OPENLMS-BACKUP-V3';
    private const HEADER = "OLMSBK3\n";

    /**
     * Application data only. Runtime sessions, password-reset tokens/requests,
     * login throttles, backup history and schema_migrations are deliberately
     * excluded so a restore cannot roll back the installed schema or revive
     * security credentials.
     */
    private const TABLES = [
        'roles','permissions','users','user_roles','role_permissions','user_login_history',
        'academic_years','departments','classes','sections','batches','semesters','batch_members','class_members','class_subjects',
        'subjects','courses','course_batches','enrollments','course_sections','lessons','lesson_links','lesson_prerequisites','learning_materials','lesson_progress',
        'attendance_sessions','attendance_records','attendance_corrections',
        'question_banks','questions','question_options','quizzes','quiz_questions','exam_attempts','attempt_questions','exam_answers','exam_events',
        'projects','project_groups','project_group_members','project_submissions','submission_files','project_submission_events','grades',
        'feature_settings','app_settings','notifications','announcements','announcement_media','certificates','timetable_entries','live_classes','parent_student_links',
        'fee_invoices','fee_payments','payment_transactions','support_tickets','support_replies',
        'registration_invites','registration_invite_contexts','registration_invite_courses','registration_invite_history',
        'doubts','doubt_replies','doubt_reply_reactions','course_completions','mfa_credentials','audit_logs'
    ];

    public static function createEncrypted(int $actorUserId): array
    {
        self::requireCrypto();
        $pdo = Database::connection();
        $payload = [
            'format' => self::FORMAT,
            'created_at' => gmdate('c'),
            'driver' => (string) Config::get('database.driver', 'mysql'),
            'migrations' => self::migrationVersions($pdo),
            'tables' => [],
            'uploads' => [],
        ];

        foreach (self::TABLES as $table) {
            if (!self::tableExists($pdo, $table)) continue;
            $payload['tables'][$table] = $pdo->query('SELECT * FROM ' . self::quoteIdentifier($table))->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach (self::referencedUploads($pdo) as $relative) {
            $path = UploadService::resolveStoredPath($relative);
            if ($path === null || !is_file($path) || !is_readable($path)) {
                throw new \RuntimeException('A referenced private upload is missing or unreadable. Remove the broken file reference or restore the file before creating a backup.');
            }
            $bytes = file_get_contents($path);
            if ($bytes === false) throw new \RuntimeException('Unable to read a private upload for backup.');
            $payload['uploads'][$relative] = [
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'data' => base64_encode($bytes),
            ];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) throw new \RuntimeException('Unable to encode backup data.');

        $plain = function_exists('gzencode') ? "GZ\n" . gzencode($json, 6) : "JS\n" . $json;
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, self::AAD, 16);
        if ($cipher === false || strlen($tag) !== 16) throw new \RuntimeException('Unable to encrypt backup.');

        $dir = app_root() . '/storage/backups';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Backup directory is not writable. Check storage/backups permissions.');
        }
        if (!is_writable($dir)) throw new \RuntimeException('Backup directory is not writable. Check storage/backups permissions.');

        $name = 'open-lms-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.olmsbak';
        $path = $dir . '/' . $name;
        $body = self::HEADER . base64_encode($iv . $tag . $cipher);
        $written = file_put_contents($path, $body, LOCK_EX);
        if ($written === false || $written !== strlen($body)) {
            @unlink($path);
            throw new \RuntimeException('Unable to write the complete encrypted backup file.');
        }
        @chmod($path, 0640);
        $size = (int) filesize($path);

        if (self::tableExists($pdo, 'backup_history')) {
            $pdo->prepare('INSERT INTO backup_history (file_name,driver,size_bytes,created_by,created_at) VALUES (?,?,?,?,?)')
                ->execute([$name, (string) Config::get('database.driver', 'mysql'), $size, $actorUserId, gmdate('Y-m-d H:i:s')]);
        }
        return [$name, $size];
    }

    public static function restoreEncrypted(string $name, int $actorUserId): void
    {
        self::requireCrypto();
        if (!preg_match('/^open-lms-[A-Za-z0-9._-]+\.olmsbak$/', $name)) throw new \RuntimeException('Invalid backup name.');

        $backupDir = app_root() . '/storage/backups';
        $path = $backupDir . '/' . $name;
        $real = realpath($path);
        $base = realpath($backupDir);
        if (!$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) throw new \RuntimeException('Backup not found.');
        if (!is_readable($real)) throw new \RuntimeException('Backup file is not readable.');

        $body = file_get_contents($real);
        if ($body === false) throw new \RuntimeException('Unable to read backup file.');
        [$version, $payload] = self::decodeBackup($body);

        $stage = null;
        if ($version >= 2) {
            if (!is_array($payload['uploads'] ?? null)) throw new \RuntimeException('Backup upload payload is invalid.');
            $stage = self::stageUploads($payload['uploads']);
        }

        $pdo = Database::connection();
        $driver = (string) Config::get('database.driver', 'mysql');
        $available = array_values(array_filter(self::TABLES, fn(string $table): bool => self::tableExists($pdo, $table)));
        $present = array_values(array_filter($available, fn(string $table): bool => array_key_exists($table, $payload['tables'])));
        if (!$present || !in_array('users', $present, true) || !in_array('roles', $present, true)) {
            if ($stage && is_dir($stage)) self::removeTree($stage);
            throw new \RuntimeException('Backup is missing required account data and cannot be restored safely.');
        }

        // V3 backups are complete application-data snapshots and clear all managed
        // data tables. Legacy V1/V2 backups clear only tables they actually contain.
        $deleteTables = $version >= 3 ? $available : $present;
        $uploads = app_root() . '/storage/uploads';
        $oldUploads = null;

        self::setForeignKeys($pdo, $driver, false);
        $pdo->beginTransaction();
        try {
            foreach (array_reverse($deleteTables) as $table) {
                $pdo->exec('DELETE FROM ' . self::quoteIdentifier($table));
            }

            foreach ($present as $table) {
                $rows = $payload['tables'][$table];
                if (!is_array($rows)) throw new \RuntimeException('Backup table data is invalid for ' . $table . '.');
                $currentColumns = self::columns($pdo, $table, $driver);
                foreach ($rows as $row) {
                    if (!is_array($row) || !$row) continue;
                    // Older backups may not know newer nullable/default columns. Only
                    // restore columns that exist in the current schema.
                    $row = array_intersect_key($row, array_flip($currentColumns));
                    if (!$row) continue;
                    $cols = array_keys($row);
                    $quoted = array_map([self::class, 'quoteIdentifier'], $cols);
                    $sql = 'INSERT INTO ' . self::quoteIdentifier($table)
                        . ' (' . implode(',', $quoted) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
                    $pdo->prepare($sql)->execute(array_values($row));
                }
            }

            if ($version >= 2 && $stage !== null) {
                if (!is_dir(dirname($uploads))) throw new \RuntimeException('Storage directory is unavailable.');
                $oldUploads = app_root() . '/storage/uploads.pre-restore-' . bin2hex(random_bytes(4));
                if (is_dir($uploads) && !rename($uploads, $oldUploads)) throw new \RuntimeException('Unable to stage current private uploads for restore.');
                if (!rename($stage, $uploads)) {
                    if (is_dir($oldUploads)) @rename($oldUploads, $uploads);
                    throw new \RuntimeException('Unable to activate restored private uploads.');
                }
                $stage = null;
            }

            // Never revive browser sessions or password-reset credentials from a backup.
            foreach (['auth_sessions','password_reset_tokens','password_reset_requests','login_attempts'] as $ephemeral) {
                if (self::tableExists($pdo, $ephemeral)) $pdo->exec('DELETE FROM ' . self::quoteIdentifier($ephemeral));
            }

            if (self::tableExists($pdo, 'backup_history')) {
                $pdo->prepare('UPDATE backup_history SET restored_at=? WHERE file_name=?')->execute([gmdate('Y-m-d H:i:s'), $name]);
            }
            $pdo->commit();
            if ($oldUploads && is_dir($oldUploads)) self::removeTree($oldUploads);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($version >= 2) {
                if (is_dir($uploads)) self::removeTree($uploads);
                if ($oldUploads && is_dir($oldUploads)) @rename($oldUploads, $uploads);
                if ($stage && is_dir($stage)) self::removeTree($stage);
            }
            throw $e;
        } finally {
            self::setForeignKeys($pdo, $driver, true);
        }
    }

    public static function pathForDownload(string $name): string
    {
        if (!preg_match('/^open-lms-[A-Za-z0-9._-]+\.olmsbak$/', $name)) throw new \RuntimeException('Invalid backup name.');
        $dir = app_root() . '/storage/backups';
        $base = realpath($dir);
        $real = realpath($dir . '/' . $name);
        if (!$base || !$real || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real) || !is_readable($real)) {
            throw new \RuntimeException('Backup not found.');
        }
        return $real;
    }

    public static function listBackups(): array
    {
        $dir = app_root() . '/storage/backups';
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/open-lms-*.olmsbak') ?: [];
        $out = [];
        foreach ($files as $f) {
            if (!is_file($f)) continue;
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'modified' => (int) filemtime($f)];
        }
        usort($out, fn($a, $b) => $b['modified'] <=> $a['modified']);
        return $out;
    }

    private static function decodeBackup(string $body): array
    {
        $version = 0;
        $format = '';
        $aad = '';
        if (str_starts_with($body, self::HEADER)) { $version = 3; $format = self::FORMAT; $aad = self::AAD; }
        elseif (str_starts_with($body, "OLMSBK2\n")) { $version = 2; $format = 'open-lms-backup-v2'; $aad = 'OPENLMS-BACKUP-V2'; }
        elseif (str_starts_with($body, "OLMSBK1\n")) { $version = 1; $format = 'open-lms-backup-v1'; $aad = 'OPENLMS'; }
        else throw new \RuntimeException('Unsupported backup format.');

        $raw = base64_decode(substr($body, 8), true);
        if ($raw === false || strlen($raw) < 29) throw new \RuntimeException('Corrupted backup.');
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($plain === false) throw new \RuntimeException('Backup authentication failed. The file may be corrupted or was created with a different LMS application key.');

        if ($version >= 3) {
            if (str_starts_with($plain, "GZ\n")) {
                if (!function_exists('gzdecode')) throw new \RuntimeException('This server cannot decompress the backup. Enable PHP zlib support.');
                $json = gzdecode(substr($plain, 3));
                if ($json === false) throw new \RuntimeException('Compressed backup data is corrupted.');
            } elseif (str_starts_with($plain, "JS\n")) {
                $json = substr($plain, 3);
            } else throw new \RuntimeException('Backup payload encoding is invalid.');
        } else {
            $json = $plain;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || ($payload['format'] ?? '') !== $format || !is_array($payload['tables'] ?? null)) {
            throw new \RuntimeException('Invalid backup payload.');
        }
        return [$version, $payload];
    }

    private static function referencedUploads(PDO $pdo): array
    {
        $paths = [];
        foreach (['learning_materials', 'submission_files', 'announcement_media'] as $table) {
            if (!self::tableExists($pdo, $table)) continue;
            foreach ($pdo->query('SELECT stored_name FROM ' . self::quoteIdentifier($table))->fetchAll(PDO::FETCH_COLUMN) as $relative) {
                $relative = (string) $relative;
                if (!self::validRelativeUpload($relative)) throw new \RuntimeException('Unsafe private upload path found in database.');
                $paths[$relative] = true;
            }
        }
        return array_keys($paths);
    }

    private static function stageUploads(array $files): string
    {
        $stage = app_root() . '/storage/uploads.restore-' . bin2hex(random_bytes(6));
        if (!mkdir($stage, 0750, true) && !is_dir($stage)) throw new \RuntimeException('Unable to create restore staging directory.');
        try {
            foreach ($files as $relative => $meta) {
                $relative = (string) $relative;
                if (!self::validRelativeUpload($relative) || !is_array($meta)) throw new \RuntimeException('Unsafe upload entry in backup.');
                $bytes = base64_decode((string)($meta['data'] ?? ''), true);
                if ($bytes === false || strlen($bytes) !== (int)($meta['size'] ?? -1) || !hash_equals((string)($meta['sha256'] ?? ''), hash('sha256', $bytes))) {
                    throw new \RuntimeException('Private upload integrity check failed during restore.');
                }
                $target = $stage . '/' . $relative;
                $dir = dirname($target);
                if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('Unable to create restore upload directory.');
                if (file_put_contents($target, $bytes, LOCK_EX) === false) throw new \RuntimeException('Unable to stage a restored private upload.');
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
            && (str_starts_with($relative, 'materials/') || str_starts_with($relative, 'projects/') || str_starts_with($relative, 'announcements/'))
            && preg_match('#^[A-Za-z0-9/_-]+\.dat$#', $relative) === 1;
    }

    private static function columns(PDO $pdo, string $table, string $driver): array
    {
        if ($driver === 'sqlite') {
            $rows = $pdo->query("PRAGMA table_info('" . str_replace("'", "''", $table) . "')")->fetchAll(PDO::FETCH_ASSOC);
            return array_column($rows, 'name');
        }
        $rows = $pdo->query('SHOW COLUMNS FROM ' . self::quoteIdentifier($table))->fetchAll(PDO::FETCH_ASSOC);
        return array_column($rows, 'Field');
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            if ((string)Config::get('database.driver', 'mysql') === 'sqlite') {
                $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
                $st->execute([$table]);
                return (bool)$st->fetchColumn();
            }
            $st = $pdo->prepare('SHOW TABLES LIKE ?');
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable) { return false; }
    }

    private static function migrationVersions(PDO $pdo): array
    {
        try { return $pdo->query('SELECT version FROM schema_migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN); }
        catch (\Throwable) { return []; }
    }

    private static function setForeignKeys(PDO $pdo, string $driver, bool $enabled): void
    {
        if ($driver === 'sqlite') $pdo->exec('PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF'));
        else $pdo->exec('SET FOREIGN_KEY_CHECKS=' . ($enabled ? '1' : '0'));
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) throw new \LogicException('Unsafe SQL identifier.');
        return '`' . $identifier . '`';
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
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) throw new \RuntimeException('PHP OpenSSL is required for encrypted backups.');
    }

    private static function key(): string
    {
        $v = trim((string) Config::get('app.key', ''));
        if ($v === '' || $v === 'generate-a-random-64-hex-character-key') throw new \RuntimeException('The LMS application encryption key is not configured.');
        return preg_match('/^[a-f0-9]{64}$/i', $v) ? (hex2bin($v) ?: hash('sha256', $v, true)) : hash('sha256', $v, true);
    }
}
