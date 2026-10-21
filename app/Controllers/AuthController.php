<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;

final class AuthController
{
    public static function login(): void
    {
        if (Auth::user()) { header('Location: ' . app_url('?r=dashboard')); exit; }
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) $error = 'Your session token expired. Refresh and try again.';
            else {
                [$ok, $error] = Auth::attempt((string)($_POST['email'] ?? ''),(string)($_POST['password'] ?? ''),client_ip());
                if ($ok) { header('Location: ' . app_url('?r=dashboard')); exit; }
            }
        }
        ob_start(); ?>
        <div class="auth-wrap"><section class="auth-card card"><div class="brand-mark">OL</div><h1>Welcome back</h1><p class="muted">Sign in to your learning workspace.</p><?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?><form method="post" autocomplete="on"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><label>Email<input type="email" name="email" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn btn-primary btn-block" type="submit">Sign in</button></form></section></div>
        <?php render_page('Sign in', ob_get_clean(), false);
    }
    public static function logout(): void { Auth::logout(); header('Location: ' . app_url('?r=login')); exit; }
}
