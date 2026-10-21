<?php
namespace OpenLMS\Core;

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, name, email, status, created_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active') { self::logout(); return null; }
        $user['roles'] = self::roles((int) $user['id']);
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

    public static function isPrivileged(): bool
    {
        $user = self::user();
        return $user && (bool) array_intersect(['super_admin','administrator'], $user['roles']);
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
        if ((int) $stmt->fetchColumn() >= $max) return [false, 'Too many failed login attempts. Try again later.'];

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $ok = $user && $user['status'] === 'active' && password_verify($password, $user['password_hash']);
        $pdo->prepare('INSERT INTO login_attempts (email, ip_address, successful, attempted_at) VALUES (?, ?, ?, ?)')->execute([$email, $ip, $ok ? 1 : 0, gmdate('Y-m-d H:i:s')]);
        if (!$ok) return [false, 'Invalid email or password.'];

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['authenticated_at'] = time();
        Csrf::rotate();
        self::audit('auth.login', 'User signed in', (int) $user['id']);
        return [true, null];
    }

    public static function logout(): void
    {
        if (!empty($_SESSION['user_id'])) { try { self::audit('auth.logout', 'User signed out', (int) $_SESSION['user_id']); } catch (\Throwable) {} }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function requireAuth(): void
    {
        if (!self::user()) { header('Location: ' . app_url('?r=login')); exit; }
    }

    public static function requireAdmin(): void
    {
        self::requireAuth();
        if (!self::isPrivileged()) {
            http_response_code(403);
            render_page('Access denied', '<div class="card"><h2>403</h2><p>You do not have permission to access this page.</p></div>');
            exit;
        }
    }

    public static function audit(string $action, string $description, ?int $actorUserId = null, ?string $entityType = null, ?int $entityId = null): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO audit_logs (actor_user_id, action, description, entity_type, entity_id, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$actorUserId ?? ($_SESSION['user_id'] ?? null), $action, $description, $entityType, $entityId, client_ip(), gmdate('Y-m-d H:i:s')]);
    }
}
