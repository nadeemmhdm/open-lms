<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Database;
use PDO;
use PDOException;

final class Installer
{
    public static function diagnostics(): array
    {
        $root = dirname(__DIR__, 2);
        $pdoDrivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
        return [
            ['PHP 8.1+', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION],
            ['PDO extension', class_exists(PDO::class), class_exists(PDO::class) ? 'Available' : 'Missing'],
            ['PDO MySQL', in_array('mysql', $pdoDrivers, true), in_array('mysql', $pdoDrivers, true) ? 'Available' : 'Not available'],
            ['PDO SQLite', in_array('sqlite', $pdoDrivers, true), in_array('sqlite', $pdoDrivers, true) ? 'Available' : 'Optional / unavailable'],
            ['Sessions', function_exists('session_start'), function_exists('session_start') ? 'Available' : 'Missing'],
            ['OpenSSL', function_exists('openssl_encrypt') && function_exists('openssl_decrypt'), (function_exists('openssl_encrypt') && function_exists('openssl_decrypt')) ? 'Available' : 'Missing (required for MFA secret encryption and backups)'],
            ['mbstring', function_exists('mb_strlen') && function_exists('mb_substr') && function_exists('mb_strtolower'), function_exists('mb_strlen') ? 'Available' : 'Missing (required)'],
            ['Fileinfo', class_exists('finfo'), class_exists('finfo') ? 'Available' : 'Missing (required for secure uploads)'],
            ['Config directory writable', is_writable($root . '/config'), is_writable($root . '/config') ? 'Writable' : 'Not writable'],
            ['Storage directory writable', is_writable($root . '/storage'), is_writable($root . '/storage') ? 'Writable' : 'Not writable'],
            ['File uploads', filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN), 'upload_max_filesize=' . ini_get('upload_max_filesize')],
        ];
    }

    public static function install(array $input): array
    {
        $driver = $input['db_driver'] ?? 'mysql';
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            return [false, 'Unsupported database driver.'];
        }

        $email = mb_strtolower(trim((string) ($input['admin_email'] ?? '')));
        $name = trim((string) ($input['admin_name'] ?? ''));
        $password = (string) ($input['admin_password'] ?? '');
        $institution = trim((string) ($input['institution_name'] ?? 'Open LMS'));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, 'Enter a valid administrator name and email address.'];
        }
        if (strlen($password) < 12) {
            return [false, 'Administrator password must contain at least 12 characters.'];
        }

        $root = dirname(__DIR__, 2);
        $db = [
            'driver' => $driver,
            'host' => trim((string) ($input['db_host'] ?? 'localhost')),
            'port' => (int) ($input['db_port'] ?? 3306),
            'name' => trim((string) ($input['db_name'] ?? '')),
            'user' => trim((string) ($input['db_user'] ?? '')),
            'pass' => (string) ($input['db_pass'] ?? ''),
            'charset' => 'utf8mb4',
            'sqlite_path' => $root . '/storage/open-lms.sqlite',
        ];

        if ($driver === 'mysql' && ($db['host'] === '' || $db['name'] === '' || $db['user'] === '')) {
            return [false, 'MySQL host, database name and username are required.'];
        }

        $stage = 'server preflight';
        try {
            if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
                return [false, 'PHP OpenSSL is required for MFA secret encryption and authenticated backups.'];
            }
            if (!function_exists('mb_strlen') || !function_exists('mb_substr') || !function_exists('mb_strtolower')) {
                return [false, 'PHP mbstring is required by Open LMS.'];
            }
            if (!class_exists('finfo')) {
                return [false, 'PHP Fileinfo is required for secure learning-material uploads.'];
            }

            self::assertWritableDirectory($root . '/config', 'config');
            self::assertWritableDirectory($root . '/storage', 'storage');

            if ($driver === 'sqlite') {
                if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                    return [false, 'PDO_SQLITE is not available on this server. Use MySQL/MariaDB.'];
                }
                if (!is_writable($root . '/storage')) {
                    return [false, 'The storage directory is not writable.'];
                }
            } elseif (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
                return [false, 'PDO_MYSQL is not available on this server.'];
            }

            $stage = 'database connection';
            $pdo = Database::connection($db);
            $pdo->query('SELECT 1');

            $stage = 'database migration';
            self::migrate($pdo, $driver);

            $stage = 'initial data seed';
            $pdo->beginTransaction();
            try {
                self::seed($pdo, $driver, $name, $email, $password, $institution);
                $pdo->commit();
            } catch (\Throwable $seedError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $seedError;
            }

            $stage = 'configuration write';
            $appKey = bin2hex(random_bytes(32));
            $config = "<?php\nreturn " . var_export([
                'app' => [
                    'name' => $institution,
                    'env' => 'production',
                    'debug' => false,
                    'timezone' => 'UTC',
                    'base_url' => '',
                    'key' => $appKey,
                ],
                'database' => $db,
                'security' => [
                    'session_name' => 'open_lms_session',
                    'login_max_attempts' => 5,
                    'login_window_minutes' => 15,
                    'require_mfa_for_super_admin' => true,
                ],
                'uploads' => [
                    'max_bytes' => 10 * 1024 * 1024,
                ],
            ], true) . ";\n";

            if (file_put_contents($root . '/config/config.php', $config, LOCK_EX) === false) {
                return [false, 'Database was initialized, but config/config.php could not be written. Check permissions.'];
            }
            @chmod($root . '/config/config.php', 0600);
            $stage = 'install lock write';
            if (file_put_contents($root . '/storage/install.lock', gmdate('c') . "\n", LOCK_EX) === false) {
                @unlink($root . '/config/config.php');
                return [false, 'Database was initialized, but storage/install.lock could not be written. Check storage/ permissions and retry.'];
            }
            return [true, null];
        } catch (\Throwable $e) {
            error_log('[OpenLMS installer][' . $stage . '] ' . $e->getMessage());
            return [false, 'Installation failed at ' . $stage . ': ' . self::safeError($e, $stage)];
        }
    }

    private static function safeError(\Throwable $e, string $stage): string
    {
        $message = strtolower($e->getMessage());
        $code = $e instanceof PDOException ? self::pdoErrorCode($e) : null;

        if ($stage === 'database connection') {
            return match ($code) {
                1044 => 'MySQL rejected access to this database. Verify the exact InfinityFree database name and database user.',
                1045 => 'MySQL rejected the username/password. Use the hosting-account database password and exact database username.',
                1049 => 'MySQL says the database does not exist. Copy the full database name from InfinityFree MySQL Databases.',
                2002, 2003, 2005 => 'Cannot reach the database host. On InfinityFree use the exact sqlXXX.infinityfree.com hostname shown in MySQL Databases, not localhost.',
                default => str_contains($message, 'unable to open database file')
                    ? 'SQLite could not open storage/open-lms.sqlite. Check that storage/ is writable and SQLite is permitted by the host.'
                    : 'Database connection failed' . ($code ? ' (DB error ' . $code . ')' : '') . '. Verify the driver and connection details.',
            };
        }

        if ($stage === 'database migration') {
            $detail = preg_replace('/\s+/', ' ', $e->getMessage()) ?: 'unknown migration error';
            $detail = preg_replace('/(?:password|passwd|pwd)\s*[=:]\s*[^\s;]+/i', 'password=[hidden]', $detail) ?: $detail;
            if (strlen($detail) > 260) $detail = substr($detail, 0, 257) . '...';
            return 'Schema migration failed' . ($code ? ' (DB error ' . $code . ')' : '') . ': ' . $detail;
        }

        if ($stage === 'initial data seed') {
            if ($code === 1062 || str_contains($message, 'unique constraint failed')) {
                return 'The database contains partial data from an earlier setup attempt. The installer can repair known Open LMS partial states, but this database has an unexpected duplicate. Use the same admin email and retry; if it persists, use an empty database.';
            }
            return 'Initial LMS data could not be saved' . ($code ? ' (DB error ' . $code . ')' : '') . '. The database may contain data from another installation.';
        }

        if (str_contains($message, 'permission') || str_contains($message, 'writable') || str_contains($message, 'write')) {
            return 'A required application directory could not be written. Check config/ and storage/ permissions.';
        }
        return 'A server-side setup step failed. Review the private PHP error log and installer diagnostics.';
    }

    private static function pdoErrorCode(PDOException $e): ?int
    {
        $info = $e->errorInfo;
        if (is_array($info) && isset($info[1]) && is_numeric($info[1])) return (int)$info[1];
        return is_numeric($e->getCode()) ? (int)$e->getCode() : null;
    }

    private static function assertWritableDirectory(string $directory, string $label): void
    {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException($label . ' directory is not writable.');
        }
        $probe = $directory . '/.openlms-write-' . bin2hex(random_bytes(6));
        if (file_put_contents($probe, 'ok', LOCK_EX) === false) {
            throw new \RuntimeException($label . ' directory write probe failed.');
        }
        @unlink($probe);
    }

    public static function migrate(PDO $pdo, string $driver): void
    {
        Migrator::applyAll($pdo, $driver);
    }

    private static function seed(PDO $pdo, string $driver, string $name, string $email, string $password, string $institution): void
    {
        $now = gmdate('Y-m-d H:i:s');

        // Retry safety: never silently attach a new Super Admin to an unrelated/non-empty database.
        $existingUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($existingUsers > 0) {
            $check = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1');
            $check->execute([$email]);
            if (!$check->fetchColumn()) {
                throw new \RuntimeException('Open LMS users already exist in this database with a different administrator email.');
            }
        }

        $roles = [
            'super_admin' => 'Super Admin',
            'administrator' => 'Administrator',
            'teacher' => 'Teacher / Trainer',
            'student' => 'Student',
            'hod' => 'HOD / Academic Coordinator',
            'examiner' => 'Examiner',
            'parent' => 'Parent / Guardian',
        ];
        foreach ($roles as $slug => $label) {
            self::insertIfMissing($pdo, 'roles', 'name', $slug, ['label' => $label, 'is_system' => 1]);
        }

        $permissions = ['users.manage','roles.manage','settings.manage','features.manage','academics.manage','courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view','audit.view'];
        foreach ($permissions as $permission) {
            self::insertIfMissing($pdo, 'permissions', 'name', $permission, ['label' => ucwords(str_replace(['.','_'], ' ', $permission))]);
        }

        $superId = self::idBy($pdo, 'roles', 'name', 'super_admin');
        $permissionIds = $pdo->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($permissionIds as $pid) self::insertPairIfMissing($pdo, 'role_permissions', 'role_id', $superId, 'permission_id', (int)$pid);

        $rolePermissionMap = [
            'administrator' => ['users.manage','settings.manage','features.manage','academics.manage','courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view','audit.view'],
            'teacher' => ['courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view'],
            'hod' => ['academics.manage','courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view'],
            'examiner' => ['questions.manage','exams.manage','reports.view'],
        ];
        foreach ($rolePermissionMap as $roleName => $names) {
            $roleId = self::idBy($pdo, 'roles', 'name', $roleName);
            foreach ($names as $permissionName) {
                $permissionId = self::idBy($pdo, 'permissions', 'name', $permissionName);
                if ($roleId && $permissionId) self::insertPairIfMissing($pdo, 'role_permissions', 'role_id', $roleId, 'permission_id', $permissionId);
            }
        }

        $findUser = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1');
        $findUser->execute([$email]);
        $userId = (int)($findUser->fetchColumn() ?: 0);
        if ($userId > 0) {
            $pdo->prepare('UPDATE users SET name=?,password_hash=?,status=?,updated_at=? WHERE id=?')
                ->execute([$name, password_hash($password, PASSWORD_DEFAULT), 'active', $now, $userId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,status,created_at,updated_at) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'active', $now, $now]);
            $userId = (int)$pdo->lastInsertId();
        }
        self::insertPairIfMissing($pdo, 'user_roles', 'user_id', $userId, 'role_id', $superId);

        self::upsertSetting($pdo, 'institution_name', $institution, 1, $now);
        self::upsertSetting($pdo, 'installed_at', gmdate('c'), 0, $now);
        self::upsertSetting($pdo, 'mfa_required_roles', json_encode(['super_admin']), 0, $now);

        $features = ['attendance','question_bank','quizzes','exams','projects','gradebook','notifications','announcements','reports','certificates','timetable','parent_portal','live_classes','fee_management','search','backup_restore','support'];
        foreach ($features as $feature) {
            $enabled = in_array($feature, ['attendance','question_bank','quizzes','exams','projects','gradebook','notifications','announcements','reports','search','backup_restore','support'], true) ? 1 : 0;
            $find = $pdo->prepare('SELECT id FROM feature_settings WHERE feature_key=? LIMIT 1');
            $find->execute([$feature]);
            if ($find->fetchColumn()) {
                $pdo->prepare('UPDATE feature_settings SET enabled=?,updated_at=? WHERE feature_key=?')->execute([$enabled, $now, $feature]);
            } else {
                $pdo->prepare('INSERT INTO feature_settings (feature_key,enabled,updated_at) VALUES (?,?,?)')->execute([$feature, $enabled, $now]);
            }
        }

        $audit = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id=? AND action='install.complete'");
        $audit->execute([$userId]);
        if ((int)$audit->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO audit_logs (actor_user_id,action,description,entity_type,entity_id,ip_address,created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$userId, 'install.complete', 'Open LMS installed and first Super Admin created', 'user', $userId, null, $now]);
        }
    }

    private static function idBy(PDO $pdo, string $table, string $column, string $value): int
    {
        $allowed = ['roles' => ['name'], 'permissions' => ['name']];
        if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) throw new \LogicException('Invalid installer lookup.');
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE {$column}=? LIMIT 1");
        $stmt->execute([$value]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private static function insertIfMissing(PDO $pdo, string $table, string $keyColumn, string $keyValue, array $values): void
    {
        $allowed = ['roles' => ['name','label','is_system'], 'permissions' => ['name','label']];
        if (!isset($allowed[$table]) || !in_array($keyColumn, $allowed[$table], true)) throw new \LogicException('Invalid installer insert.');
        $check = $pdo->prepare("SELECT id FROM {$table} WHERE {$keyColumn}=? LIMIT 1");
        $check->execute([$keyValue]);
        if ($check->fetchColumn()) return;
        $columns = array_merge([$keyColumn], array_keys($values));
        foreach ($columns as $column) if (!in_array($column, $allowed[$table], true)) throw new \LogicException('Invalid installer insert column.');
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')')
            ->execute(array_merge([$keyValue], array_values($values)));
    }

    private static function insertPairIfMissing(PDO $pdo, string $table, string $aCol, int $a, string $bCol, int $b): void
    {
        $allowed = [
            'role_permissions' => ['role_id','permission_id'],
            'user_roles' => ['user_id','role_id'],
        ];
        if (!isset($allowed[$table]) || !in_array($aCol, $allowed[$table], true) || !in_array($bCol, $allowed[$table], true)) throw new \LogicException('Invalid installer relation.');
        $check = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$aCol}=? AND {$bCol}=?");
        $check->execute([$a,$b]);
        if ((int)$check->fetchColumn() === 0) $pdo->prepare("INSERT INTO {$table} ({$aCol},{$bCol}) VALUES (?,?)")->execute([$a,$b]);
    }

    private static function upsertSetting(PDO $pdo, string $key, ?string $value, int $isPublic, string $now): void
    {
        $find = $pdo->prepare('SELECT id FROM app_settings WHERE setting_key=? LIMIT 1');
        $find->execute([$key]);
        if ($find->fetchColumn()) {
            $pdo->prepare('UPDATE app_settings SET setting_value=?,is_public=?,updated_at=? WHERE setting_key=?')->execute([$value,$isPublic,$now,$key]);
        } else {
            $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value,is_public,updated_at) VALUES (?,?,?,?)')->execute([$key,$value,$isPublic,$now]);
        }
    }
}
