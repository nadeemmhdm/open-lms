<?php
namespace OpenLMS\Core;

use PDO;

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, name, email, status, created_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active') {
            self::logout();
            return null;
        }
        $roles = self::roles((int) $user['id']);
        $user['roles'] = $roles;
        return $user;
    }

    public static function roles(int $userId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT r.name FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? ORDER BY r.id');
        $stmt->execute([$userId]);
        return array_column($stmt->fetchAll(), 'name');
    }

    public static function hasRole(string $role): bool
    {
        $user = self::user();
        return $user && in_array($role, $user['roles'], true);
    }


    private static array $permissionCache = [];

    public static function hasAnyRole(array $roles): bool
    {
        $user = self::user();
        return $user && (bool) array_intersect($roles, $user['roles']);
    }

    public static function permissions(?int $userId = null): array
    {
        $userId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
        if ($userId < 1) return [];
        if (isset(self::$permissionCache[$userId])) return self::$permissionCache[$userId];
        $roles = self::roles($userId);
        if (in_array('super_admin', $roles, true)) {
            $all = Database::connection()->query('SELECT name FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
            return self::$permissionCache[$userId] = array_values($all);
        }
        $stmt = Database::connection()->prepare('SELECT DISTINCT p.name FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id=p.id INNER JOIN user_roles ur ON ur.role_id=rp.role_id WHERE ur.user_id=?');
        $stmt->execute([$userId]);
        return self::$permissionCache[$userId] = array_column($stmt->fetchAll(), 'name');
    }

    public static function can(string $permission): bool
    {
        return in_array($permission, self::permissions(), true);
    }

    public static function requirePermission(string $permission): void
    {
        self::requireAuth();
        if (!self::can($permission)) {
            render_status_page(403, 'Access denied', 'You do not have permission to access this page.', 'Back to dashboard', app_url('?r=dashboard'));
            exit;
        }
    }

    public static function isPrivileged(): bool
    {
        $user = self::user();
        if (!$user) return false;
        return (bool) array_intersect(['super_admin', 'administrator'], $user['roles']);
    }

    public static function attempt(string $email, string $password, string $ip): array
    {
        $pdo = Database::connection();
        $email = mb_strtolower(trim($email));
        $window = max(1, (int) Config::get('security.login_window_minutes', 15));
        $max = max(3, (int) Config::get('security.login_max_attempts', 5));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($window * 60));

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip_address = ? AND successful = 0 AND attempted_at >= ?');
        $stmt->execute([$email, $ip, $cutoff]);
        if ((int) $stmt->fetchColumn() >= $max) {
            return [false, 'Too many failed login attempts. Try again later.'];
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $ok = $user && $user['status'] === 'active' && password_verify($password, $user['password_hash']);

        $log = $pdo->prepare('INSERT INTO login_attempts (email, ip_address, successful, attempted_at) VALUES (?, ?, ?, ?)');
        $log->execute([$email, $ip, $ok ? 1 : 0, gmdate('Y-m-d H:i:s')]);

        if (!$ok) {
            return [false, 'Invalid email or password.'];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);
        }

        $mfa = $pdo->prepare('SELECT COUNT(*) FROM mfa_credentials WHERE user_id=? AND enabled_at IS NOT NULL');
        $mfa->execute([(int)$user['id']]);
        if ((int)$mfa->fetchColumn() > 0) {
            session_regenerate_id(true);
            $_SESSION['mfa_pending_user_id'] = (int)$user['id'];
            $_SESSION['mfa_pending_started_at'] = time();
            $_SESSION['mfa_pending_attempts'] = 0;
            $_SESSION['mfa_pending_locked_until'] = 0;
            unset($_SESSION['user_id']);
            Csrf::rotate();
            return [true, 'mfa'];
        }

        self::finishLogin((int)$user['id']);
        return [true, null];
    }


    public static function finishLogin(int $userId): void
    {
        session_regenerate_id(true);
        unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_started_at'], $_SESSION['mfa_pending_attempts'], $_SESSION['mfa_pending_locked_until']);
        $_SESSION['user_id'] = $userId;
        $_SESSION['authenticated_at'] = time();
        self::$permissionCache = [];
        Csrf::rotate();
        try {
            Database::connection()->prepare('UPDATE users SET last_login_at=?,updated_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),$userId]);
        } catch (\Throwable) {}
        self::audit('auth.login', 'User signed in', $userId);
    }

    public static function verifyCurrentPassword(string $password): bool
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) return false;
        $stmt = Database::connection()->prepare('SELECT password_hash FROM users WHERE id=? AND status=\'active\'');
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        return is_string($hash) && password_verify($password, $hash);
    }

    public static function mfaEnabled(?int $userId = null): bool
    {
        $userId = $userId ?? (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) return false;
        try {
            $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM mfa_credentials WHERE user_id=? AND enabled_at IS NOT NULL');
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable) { return false; }
    }

    public static function logout(): void
    {
        if (!empty($_SESSION['user_id'])) {
            try { self::audit('auth.logout', 'User signed out', (int) $_SESSION['user_id']); } catch (\Throwable) {}
        }
        self::$permissionCache = [];
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function requireAuth(): void
    {
        if (!self::user()) {
            header('Location: ' . app_url('?r=login'));
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireAuth();
        if (!self::isPrivileged()) {
            render_status_page(403, 'Access denied', 'You do not have permission to access this page.', 'Back to dashboard', app_url('?r=dashboard'));
            exit;
        }
    }

    public static function audit(string $action, string $description, ?int $actorUserId = null, ?string $entityType = null, ?int $entityId = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO audit_logs (actor_user_id, action, description, entity_type, entity_id, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $actorUserId ?? ($_SESSION['user_id'] ?? null), $action, $description, $entityType, $entityId,
            client_ip(), gmdate('Y-m-d H:i:s')
        ]);
    }
}
