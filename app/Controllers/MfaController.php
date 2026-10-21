<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Services\TotpService;

final class MfaController
{
    public static function setup(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $enabled = TotpService::isEnabled((int)$user['id']);
        $error = null;
        $recoveryCodes = $_SESSION['_mfa_recovery_codes'] ?? null;
        unset($_SESSION['_mfa_recovery_codes']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) $error = 'Security token expired.';
            else {
                $action = (string)($_POST['action'] ?? '');
                if ($action === 'begin' && !$enabled) {
                    $secret = TotpService::generateSecret();
                    $_SESSION['_mfa_setup_secret'] = $secret;
                } elseif ($action === 'verify' && !$enabled) {
                    $secret = (string)($_SESSION['_mfa_setup_secret'] ?? '');
                    if ($secret === '') $error = 'Start enrollment again.';
                    else {
                        $step = TotpService::verifyCode($secret, (string)($_POST['code'] ?? ''));
                        if ($step === null) $error = 'Invalid authenticator code.';
                        else {
                            $codes = TotpService::enableForUser((int)$user['id'], $secret);
                            unset($_SESSION['_mfa_setup_secret']);
                            $_SESSION['_mfa_recovery_codes'] = $codes;
                            Auth::audit('mfa.enabled', 'MFA was enabled', (int)$user['id'], 'user', (int)$user['id']);
                            header('Location: ' . app_url('?r=mfa-setup')); exit;
                        }
                    }
                } elseif ($action === 'disable' && $enabled) {
                    if (!Auth::verifyCurrentPassword((string)($_POST['password'] ?? ''))) $error = 'Current password is incorrect.';
                    elseif (!TotpService::verifyForUser((int)$user['id'], (string)($_POST['code'] ?? ''))) $error = 'Authenticator or recovery code is invalid.';
                    else {
                        TotpService::disableForUser((int)$user['id']);
                        Auth::audit('mfa.disabled', 'MFA was disabled', (int)$user['id'], 'user', (int)$user['id']);
                        flash_set('success', 'MFA disabled.');
                        header('Location: ' . app_url('?r=mfa-setup')); exit;
                    }
                }
            }
        }

        $secret = (string)($_SESSION['_mfa_setup_secret'] ?? '');
        $issuer = (string)(\OpenLMS\Core\Config::get('app.name', 'Open LMS'));
        $uri = $secret !== '' ? TotpService::provisioningUri($secret, (string)$user['email'], $issuer) : '';
        $flash = flash_pull();
        ob_start(); ?>
        <div class="page-head"><div><h1>Authenticator MFA</h1><p class="muted">Protect your account using an RFC 6238 compatible authenticator app.</p></div></div>
        <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if($flash): ?><div class="alert alert-success"><?= e($flash['message']) ?></div><?php endif; ?>
        <?php if(is_array($recoveryCodes)): ?><section class="card"><h2>Save recovery codes now</h2><p>Each code works once. Store them offline.</p><pre class="recovery-codes"><?= e(implode("\n", $recoveryCodes)) ?></pre></section><?php endif; ?>
        <?php if($enabled): ?>
          <section class="card"><h2>MFA is enabled</h2><p>Your account requires an authenticator or unused recovery code at sign-in.</p>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="disable"><label>Current password<input type="password" name="password" required></label><label>Authenticator / recovery code<input name="code" required autocomplete="one-time-code"></label><button class="btn">Disable MFA</button></form>
          </section>
        <?php elseif($secret === ''): ?>
          <section class="card"><h2>Enroll authenticator</h2><p>Start enrollment, then add the generated secret to Google Authenticator, Microsoft Authenticator or another compatible TOTP app.</p><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="begin"><button class="btn btn-primary">Start enrollment</button></form></section>
        <?php else: ?>
          <section class="card"><h2>Add this account to your authenticator</h2><p class="hint">Scan the QR code with a compatible authenticator. It is generated locally in your browser; the secret is not sent to a QR service.</p><div class="mfa-enroll"><div class="mfa-qr" data-mfa-qr data-uri="<?= e($uri) ?>" aria-live="polite"></div><div><div class="kv"><span>Manual secret</span><code><?= e($secret) ?></code></div><p><a class="btn" href="<?= e($uri) ?>">Open authenticator provisioning link</a></p></div></div><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="verify"><label>6-digit code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" required></label><button class="btn btn-primary">Verify and enable</button></form></section>
        <?php endif; ?>
        <?php render_page('MFA', ob_get_clean());
    }

    public static function challenge(): void
    {
        $userId = (int)($_SESSION['mfa_pending_user_id'] ?? 0);
        if ($userId < 1) { header('Location: ' . app_url('?r=login')); exit; }
        if (time() - (int)($_SESSION['mfa_pending_started_at'] ?? 0) > 600) {
            unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_started_at']);
            header('Location: ' . app_url('?r=login')); exit;
        }
        $error = null;
        $lockedUntil = (int)($_SESSION['mfa_pending_locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            $error = 'Too many MFA attempts. Try again in a few minutes.';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Security token expired.';
            } elseif (!TotpService::verifyForUser($userId, (string)($_POST['code'] ?? ''))) {
                $attempts = (int)($_SESSION['mfa_pending_attempts'] ?? 0) + 1;
                $_SESSION['mfa_pending_attempts'] = $attempts;
                if ($attempts >= 5) {
                    $_SESSION['mfa_pending_attempts'] = 0;
                    $_SESSION['mfa_pending_locked_until'] = time() + 300;
                    $error = 'Too many MFA attempts. Try again in a few minutes.';
                    Auth::audit('mfa.challenge_locked', 'MFA challenge temporarily locked after repeated failures', $userId, 'user', $userId);
                } else {
                    $error = 'Invalid or already-used code.';
                }
            } else {
                Auth::finishLogin($userId);
                header('Location: ' . app_url('?r=dashboard')); exit;
            }
        }
        ob_start(); ?>
        <div class="auth-wrap"><section class="auth-card card"><div class="brand-mark">OL</div><h1>Two-factor verification</h1><p class="muted">Enter your authenticator code or an unused recovery code.</p><?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><label>Code<input name="code" required autocomplete="one-time-code" autofocus></label><button class="btn btn-primary btn-block">Verify</button></form><form method="post" action="<?= e(app_url('?r=logout')) ?>"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><button class="btn btn-block" type="submit">Cancel sign-in</button></form></section></div>
        <?php render_page('MFA verification', ob_get_clean(), false);
    }
}
