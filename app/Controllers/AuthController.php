<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Services\MailService;

final class AuthController
{
    public static function login(): void
    {
        if (Auth::user()) {
            header('Location: ' . app_url('?r=dashboard')); exit;
        }
        $error = null;
        $flash = flash_pull();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Your session token expired. Refresh and try again.';
            } else {
                [$ok, $state] = Auth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''), client_ip(), isset($_POST['remember_me']));
                if ($ok && $state === 'mfa') { header('Location: ' . app_url('?r=mfa-challenge')); exit; }
                if ($ok) { header('Location: ' . app_url('?r=dashboard')); exit; }
                $error = $state;
            }
        }
        ob_start(); ?>
        <div class="auth-wrap">
            <section class="auth-card card">
                <div class="brand-mark"><?= e(app_brand_initials()) ?></div>
                <p class="auth-brand-name"><?= e(app_name()) ?></p>
                <h1>Welcome back</h1>
                <p class="muted">Sign in to your learning workspace.</p>
                <?php if ($flash): ?><div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-danger' ?>"><?= e((string)($flash['message'] ?? '')) ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
                <form method="post" autocomplete="on">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                    <label>Email<input type="email" name="email" required autocomplete="username"></label>
                    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
                    <label class="check-inline"><input type="checkbox" name="remember_me" value="1"> Remember me for 1 day</label><p class="hint">Without this option, the sign-in session expires after 12 hours.</p>
                    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
                </form>
                <p class="auth-links"><a href="<?= e(app_url('?r=forgot-password')) ?>">Forgot password?</a></p>
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


    public static function forgotPassword(): void
    {
        if (Auth::user()) {
            header('Location: ' . app_url('?r=dashboard')); exit;
        }

        $sent = false;
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Your session token expired. Refresh and try again.';
            } else {
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $pdo = Database::connection();
                $ip = client_ip();
                $nowTs = time();
                $now = gmdate('Y-m-d H:i:s', $nowTs);
                $emailHash = hash('sha256', $email);
                try {
                    $pdo->prepare('DELETE FROM password_reset_requests WHERE requested_at<?')->execute([gmdate('Y-m-d H:i:s', $nowTs - 172800)]);

                    // Request ceilings are intentionally stricter than sign-in throttles because each allowed request can send mail.
                    $ip15 = $pdo->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE ip_address=? AND requested_at>=?');
                    $ip15->execute([$ip, gmdate('Y-m-d H:i:s', $nowTs - 900)]);
                    $ipHour = $pdo->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE ip_address=? AND requested_at>=?');
                    $ipHour->execute([$ip, gmdate('Y-m-d H:i:s', $nowTs - 3600)]);
                    $mail5 = $pdo->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE email_hash=? AND mail_sent=1 AND requested_at>=?');
                    $mail5->execute([$emailHash, gmdate('Y-m-d H:i:s', $nowTs - 300)]);
                    $mailHour = $pdo->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE email_hash=? AND mail_sent=1 AND requested_at>=?');
                    $mailHour->execute([$emailHash, gmdate('Y-m-d H:i:s', $nowTs - 3600)]);
                    $mailDay = $pdo->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE email_hash=? AND mail_sent=1 AND requested_at>=?');
                    $mailDay->execute([$emailHash, gmdate('Y-m-d H:i:s', $nowTs - 86400)]);

                    $allowed = (int)$ip15->fetchColumn() < 5
                        && (int)$ipHour->fetchColumn() < 10
                        && (int)$mail5->fetchColumn() < 1
                        && (int)$mailHour->fetchColumn() < 3
                        && (int)$mailDay->fetchColumn() < 5;

                    $pdo->prepare('INSERT INTO password_reset_requests (email_hash,ip_address,mail_sent,requested_at) VALUES (?,?,0,?)')
                        ->execute([$emailHash,$ip,$now]);
                    $requestId = (int)$pdo->lastInsertId();

                    if ($allowed && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $userStmt = $pdo->prepare("SELECT id,name,email,status FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1");
                        $userStmt->execute([$email]);
                        $user = $userStmt->fetch();
                        if ($user && (string)$user['status'] === 'active' && MailService::enabled()) {
                            $token = bin2hex(random_bytes(32));
                            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=? AND used_at IS NULL')->execute([(int)$user['id']]);
                            $pdo->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at,created_at) VALUES (?,?,?,?)')
                                ->execute([(int)$user['id'], hash('sha256', $token), gmdate('Y-m-d H:i:s', $nowTs + 1800), $now]);
                            try {
                                MailService::sendPasswordReset($user, absolute_app_url('?r=reset-password&token=' . rawurlencode($token)));
                                $pdo->prepare('UPDATE password_reset_requests SET mail_sent=1 WHERE id=?')->execute([$requestId]);
                                Auth::audit('auth.password_reset_requested', 'Password reset email requested', null, 'user', (int)$user['id']);
                            } catch (\Throwable $mailError) {
                                error_log('[OpenLMS password reset mail] ' . $mailError->getMessage());
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[OpenLMS forgot password] ' . $e->getMessage());
                }
                $sent = true;
            }
        }

        ob_start(); ?>
        <div class="auth-wrap"><section class="auth-card card">
          <div class="brand-mark"><?= e(app_brand_initials()) ?></div>
          <p class="auth-brand-name"><?= e(app_name()) ?></p>
          <h1>Forgot password</h1>
          <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
          <?php if($sent): ?>
            <div class="alert alert-success">If an active account matches that email and mail delivery is available, a one-time reset link has been sent.</div>
            <p class="hint">The link expires in 30 minutes. For security, repeated requests are delayed and mail delivery is limited. Check spam/junk mail if it does not appear.</p>
          <?php else: ?>
            <p class="muted">Enter the email address used as your LMS username.</p>
            <form method="post" autocomplete="off">
              <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
              <label>Email / username<input type="email" name="email" required autocomplete="email"></label>
              <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
            </form>
          <?php endif; ?>
          <p class="auth-links"><a href="<?= e(app_url('?r=login')) ?>">Back to sign in</a></p>
        </section></div>
        <?php render_page('Forgot password', ob_get_clean(), false);
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
                        Auth::revokeAllSessions((int)$row['user_id']);
                        Auth::audit('auth.password_reset', 'Password reset token used', (int)$row['user_id'], 'user', (int)$row['user_id']);
                        ob_start(); ?><div class="auth-wrap"><section class="auth-card card"><div class="brand-mark"><?= e(app_brand_initials()) ?></div><p class="auth-brand-name"><?= e(app_name()) ?></p><h1>Password updated</h1><p>Your one-time reset link is now invalid.</p><a class="btn btn-primary btn-block" href="<?= e(app_url('?r=login')) ?>">Sign in</a></section></div><?php render_page('Password updated', ob_get_clean(), false); return;
                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $error = 'Unable to reset password.';
                    }
                }
            }
        }
        ob_start(); ?>
        <div class="auth-wrap"><section class="auth-card card"><div class="brand-mark"><?= e(app_brand_initials()) ?></div><p class="auth-brand-name"><?= e(app_name()) ?></p><h1>Reset password</h1>
        <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if(!$valid): ?><p>This reset link is invalid, expired, or already used. Request a new one-time reset link from the sign-in page or contact an administrator.</p><a class="btn" href="<?= e(app_url('?r=login')) ?>">Back to sign in</a>
        <?php else: ?><p class="muted">Reset password for <?= e((string)$row['email']) ?>.</p><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><label>New password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><label>Confirm password<input type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></label><button class="btn btn-primary btn-block">Set new password</button></form><?php endif; ?></section></div>
        <?php render_page('Reset password', ob_get_clean(), false);
    }

}
