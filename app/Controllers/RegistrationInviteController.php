<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Services\InviteService;

final class RegistrationInviteController
{
    public static function show(): void
    {
        if (Auth::user()) {
            render_status_page(403, 'Already signed in', 'Sign out before using an account setup link. This prevents an existing session from being replaced by a new account.', 'Open dashboard', app_url('?r=dashboard'));
            return;
        }
        $idRaw = (string)($_GET['id'] ?? $_POST['invite_id'] ?? '');
        $secret = strtolower(trim((string)($_GET['key'] ?? $_POST['invite_key'] ?? '')));
        if (!preg_match('/^\d{6,8}$/', $idRaw) || !preg_match('/^[a-f0-9]{48}$/', $secret)) {
            render_status_page(404, 'Link not found', 'The registration link is invalid or does not exist.');
            return;
        }
        $publicId = (int)$idRaw;
        $inspection = InviteService::inspect($publicId, $secret);
        if ($inspection['state'] !== 'active') {
            self::renderInactive((string)$inspection['state']);
            return;
        }
        $invite = (array)$inspection['invite'];
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Security token expired. Refresh the page and try again.';
            } else {
                $password = (string)($_POST['password'] ?? '');
                $confirm = (string)($_POST['confirm_password'] ?? '');
                if ($password !== $confirm) {
                    $error = 'Password and confirmation do not match.';
                } else {
                    try {
                        [$ok,$message,$userId] = InviteService::register(
                            $publicId,
                            $secret,
                            (string)($_POST['name'] ?? ''),
                            (string)($_POST['email'] ?? ''),
                            $password
                        );
                        if ($ok && $userId) {
                            Auth::finishLogin((int)$userId);
                            header('Location: ' . app_url('?r=dashboard&welcome=1'));
                            exit;
                        }
                        $error = $message;
                        $after = InviteService::inspect($publicId, $secret);
                        if ($after['state'] !== 'active' && in_array($after['state'], ['used','expired','revoked'], true)) {
                            self::renderInactive((string)$after['state']);
                            return;
                        }
                    } catch (\Throwable $e) {
                        error_log('[OpenLMS invite registration] ' . $e->getMessage());
                        $error = 'Account setup could not be completed. Try again or contact the LMS administrator.';
                    }
                }
            }
        }

        $remaining = max(0, (int)$invite['max_uses'] - (int)$invite['used_count']);
        $isBulk = (int)$invite['max_uses'] > 1;
        ob_start(); ?>
        <div class="public-auth-wrap invite-setup-wrap">
          <section class="public-auth-card card">
            <div class="public-brand"><span class="brand-mark">OL</span><div><strong>Open LMS</strong><span>Account setup</span></div></div>
            <div class="invite-head"><span class="eyebrow"><?= $isBulk ? 'Student registration' : 'Private account invitation' ?></span><h1>Create your account</h1><p class="muted">This link creates a <?= e((string)$invite['role_label']) ?> account. Your email address is also your sign-in username.</p></div>
            <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <div class="invite-meta"><div><span>Role</span><strong><?= e((string)$invite['role_label']) ?></strong></div><?php if($isBulk): ?><div><span>Seats remaining</span><strong><?= $remaining ?></strong></div><?php endif; ?><div><span>Link ID</span><strong><?= e((string)$publicId) ?></strong></div><div><span>Expires</span><strong><?= e((string)$invite['expires_at']) ?> UTC</strong></div></div>
            <form method="post" autocomplete="on" class="invite-registration-form">
              <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
              <input type="hidden" name="invite_id" value="<?= e((string)$publicId) ?>">
              <input type="hidden" name="invite_key" value="<?= e($secret) ?>">
              <label>Full name<input name="name" maxlength="120" value="<?= e((string)($_POST['name'] ?? '')) ?>" autocomplete="name" required></label>
              <label>Email / username<input name="email" type="email" maxlength="190" value="<?= e((string)($_POST['email'] ?? '')) ?>" autocomplete="email" required></label>
              <div class="form-row"><label>Password<input name="password" type="password" minlength="12" autocomplete="new-password" required><small>Use at least 12 characters.</small></label><label>Confirm password<input name="confirm_password" type="password" minlength="12" autocomplete="new-password" required></label></div>
              <button class="btn btn-primary btn-block" type="submit">Create account and sign in</button>
            </form>
            <p class="hint">Only use this link if it was shared with you by an authorized LMS administrator.</p>
          </section>
        </div>
        <?php render_page('Create account', ob_get_clean(), false);
    }

    private static function renderInactive(string $state): void
    {
        if ($state === 'expired') {
            render_status_page(410, 'Registration link expired', 'This account setup link has passed its expiry time. Ask the LMS administrator for a new link.');
            return;
        }
        if ($state === 'used') {
            render_status_page(410, 'Registration link completed', 'This account setup link has already reached its allowed account limit and cannot be used again.');
            return;
        }
        if ($state === 'revoked') {
            render_status_page(410, 'Registration link unavailable', 'This account setup link was cancelled by an administrator. Ask for a new link if you still need access.');
            return;
        }
        render_status_page(404, 'Link not found', 'The registration link is invalid or does not exist.');
    }
}
