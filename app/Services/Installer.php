<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Database;
use PDO;

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
            ['OpenSSL', function_exists('openssl_encrypt'), function_exists('openssl_encrypt') ? 'Available' : 'Missing'],
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

        try {
            if (!class_exists('finfo')) {
                return [false, 'PHP Fileinfo is required for secure learning-material uploads.'];
            }
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

            $pdo = Database::connection($db);
            self::migrate($pdo, $driver);
            self::seed($pdo, $driver, $name, $email, $password, $institution);

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
                    'require_mfa_for_super_admin' => false,
                ],
                'uploads' => [
                    'max_bytes' => 10 * 1024 * 1024,
                ],
            ], true) . ";\n";

            if (file_put_contents($root . '/config/config.php', $config, LOCK_EX) === false) {
                return [false, 'Database was initialized, but config/config.php could not be written. Check permissions.'];
            }
            @chmod($root . '/config/config.php', 0600);
            file_put_contents($root . '/storage/install.lock', gmdate('c') . "\n", LOCK_EX);
            return [true, null];
        } catch (\Throwable $e) {
            return [false, 'Installation failed: ' . self::safeError($e)];
        }
    }

    private static function safeError(\Throwable $e): string
    {
        $message = $e->getMessage();
        $message = preg_replace('/password=[^;\s]+/i', 'password=[redacted]', $message);
        $message = preg_replace('/\/[^\s]+\/storage\/[^\s]+/i', '[private path]', $message);
        return mb_substr((string) $message, 0, 400);
    }

    public static function migrate(PDO $pdo, string $driver): void
    {
        Migrator::applyAll($pdo, $driver);
    }

    private static function seed(PDO $pdo, string $driver, string $name, string $email, string $password, string $institution): void
    {
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
            $stmt = $pdo->prepare('INSERT INTO roles (name, label, is_system) VALUES (?, ?, 1)');
            $stmt->execute([$slug, $label]);
        }

        $permissions = ['users.manage','roles.manage','settings.manage','features.manage','academics.manage','courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view','audit.view'];
        foreach ($permissions as $permission) {
            $stmt = $pdo->prepare('INSERT INTO permissions (name, label) VALUES (?, ?)');
            $stmt->execute([$permission, ucwords(str_replace(['.','_'], ' ', $permission))]);
        }

        $superId = (int) $pdo->query("SELECT id FROM roles WHERE name = 'super_admin' LIMIT 1")->fetchColumn();
        $permissionIds = $pdo->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($permissionIds as $pid) {
            $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$superId, $pid]);
        }

        $rolePermissionMap = [
            'administrator' => ['users.manage','settings.manage','features.manage','academics.manage','courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view','audit.view'],
            'teacher' => ['courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view'],
            'hod' => ['academics.manage','courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view'],
            'examiner' => ['questions.manage','exams.manage','reports.view'],
        ];
        foreach ($rolePermissionMap as $roleName => $names) {
            $r = $pdo->prepare('SELECT id FROM roles WHERE name = ?');
            $r->execute([$roleName]);
            $roleId = (int) $r->fetchColumn();
            foreach ($names as $permissionName) {
                $p = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
                $p->execute([$permissionName]);
                $permissionId = (int) $p->fetchColumn();
                if ($roleId && $permissionId) {
                    $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$roleId, $permissionId]);
                }
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'active', $now, $now]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $superId]);

        $stmt = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, ?, ?)');
        $stmt->execute(['institution_name', $institution, 1, $now]);
        $stmt->execute(['installed_at', gmdate('c'), 0, $now]);

        $features = ['attendance','question_bank','quizzes','exams','projects','gradebook','notifications','reports','certificates','timetable','parent_portal','live_classes','fee_management'];
        foreach ($features as $feature) {
            $enabled = in_array($feature, ['attendance','question_bank','quizzes','exams','projects','gradebook','notifications','reports'], true) ? 1 : 0;
            $pdo->prepare('INSERT INTO feature_settings (feature_key, enabled, updated_at) VALUES (?, ?, ?)')->execute([$feature, $enabled, $now]);
        }

        $pdo->prepare('INSERT INTO audit_logs (actor_user_id, action, description, entity_type, entity_id, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, 'install.complete', 'Open LMS installed and first Super Admin created', 'user', $userId, null, $now]);
    }
}
