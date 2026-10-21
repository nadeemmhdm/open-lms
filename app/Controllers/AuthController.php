<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;

final class AuthController
{
    public static function login(): void
    {
        if (Auth::user()) {
            header('Location: ' . app_url('?r=dashboard')); exit;
        }
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Your session token expired. Refresh and try again.';
            } else {
                [$ok, $state] = Auth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''), client_ip());
                if ($ok && $state === 'mfa') { header('Location: ' . app_url('?r=mfa-challenge')); exit; }
                if ($ok) { header('Location: ' . app_url('?r=dashboard')); exit; }
                $error = $state;
            }
        }
        ob_start(); ?>
        <div class="auth-wrap">
            <section class="auth-card card">
                <div class="brand-mark">OL</div>
                <h1>Welcome back</h1>
                <p class="muted">Sign in to your learning workspace.</p>
                <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
                <form method="post" autocomplete="on">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                    <label>Email<input type="email" name="email" required autocomplete="username"></label>
                    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
                    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
                </form>
            </section>
        </div>
        <?php render_page('Sign in', ob_get_clean(), false);
    }

    public static function logout(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            render_page('Invalid request', '<div class="card"><h2>Invalid sign-out request</h2><p>Refresh the page and try again.</p></div>', false);
            return;
        }
        Auth::logout();
        header('Location: ' . app_url('?r=login')); exit;
    }

    public static function resetPassword(): void
    {
        $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
        $error = null;
        $valid = false;
        $row = null;
        if ($token !== '' && preg_match('/^[a-f0-9]{64}$/i', $token)) {
            $stmt = Database::connection()->prepare('SELECT prt.*,u.email,u.status FROM password_reset_tokens prt JOIN users u ON u.id=prt.user_id WHERE prt.token_hash=? AND prt.used_at IS NULL LIMIT 1');
            $stmt->execute([hash('sha256', $token)]);
            $row = $stmt->fetch();
            $valid = (bool)$row && $row['status'] === 'active' && (string)$row['expires_at'] > gmdate('Y-m-d H:i:s');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) $error = 'Security token expired.';
            elseif (!$valid || !$row) $error = 'This reset link is invalid, expired, or already used.';
            else {
                $password = (string)($_POST['password'] ?? '');
                $confirm = (string)($_POST['confirm_password'] ?? '');
                if (strlen($password) < 12) $error = 'Use a password of at least 12 characters.';
                elseif (!hash_equals($password, $confirm)) $error = 'Passwords do not match.';
                else {
                    $pdo = Database::connection();
                    $pdo->beginTransaction();
                    try {
                        $now = gmdate('Y-m-d H:i:s');
                        $consume = $pdo->prepare('UPDATE password_reset_tokens SET used_at=? WHERE id=? AND used_at IS NULL');
                        $consume->execute([$now,(int)$row['id']]);
                        if ($consume->rowCount() !== 1) throw new \RuntimeException('Reset token was already used.');
                        $pdo->prepare('UPDATE users SET password_hash=?,updated_at=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT),$now,(int)$row['user_id']]);
                        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=? AND id<>?')->execute([(int)$row['user_id'],(int)$row['id']]);
                        $pdo->commit();
                        Auth::audit('auth.password_reset', 'Password reset token used', (int)$row['user_id'], 'user', (int)$row['user_id']);
                        ob_start(); ?><div class="auth-wrap"><section class="auth-card card"><div class="brand-mark">OL</div><h1>Password updated</h1><p>Your one-time reset link is now invalid.</p><a class="btn btn-primary btn-block" href="<?= e(app_url('?r=login')) ?>">Sign in</a></section></div><?php render_page('Password updated', ob_get_clean(), false); return;
                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $error = 'Unable to reset password.';
                    }
                }
            }
        }
        ob_start(); ?>
        <div class="auth-wrap"><section class="auth-card card"><div class="brand-mark">OL</div><h1>Reset password</h1>
        <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if(!$valid): ?><p>This reset link is invalid, expired, or already used. Ask an administrator for a new one-time reset link.</p><a class="btn" href="<?= e(app_url('?r=login')) ?>">Back to sign in</a>
        <?php else: ?><p class="muted">Reset password for <?= e((string)$row['email']) ?>.</p><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><label>New password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><label>Confirm password<input type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></label><button class="btn btn-primary btn-block">Set new password</button></form><?php endif; ?></section></div>
        <?php render_page('Reset password', ob_get_clean(), false);
    }

}
