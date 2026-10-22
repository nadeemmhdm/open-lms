<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;

final class ProfileController
{
    public static function show(): void
    {
        Auth::requireAuth();
        $pdo = Database::connection();
        $current = Auth::user();
        $userId = (int)$current['id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                flash_set('danger', 'Security token expired. Refresh the page and try again.');
                header('Location: ' . app_url('?r=profile')); exit;
            }

            $action = (string)($_POST['profile_action'] ?? 'details');
            if ($action === 'details') {
                $name = trim((string)($_POST['name'] ?? ''));
                if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                    flash_set('danger', 'Display name must contain between 2 and 120 characters.');
                } else {
                    $pdo->prepare('UPDATE users SET name=?, updated_at=? WHERE id=?')->execute([$name, gmdate('Y-m-d H:i:s'), $userId]);
                    Auth::audit('profile.updated', 'Updated own profile details', $userId, 'user', $userId);
                    flash_set('success', 'Profile details updated.');
                }
                header('Location: ' . app_url('?r=profile')); exit;
            }

            if ($action === 'password') {
                $currentPassword = (string)($_POST['current_password'] ?? '');
                $newPassword = (string)($_POST['new_password'] ?? '');
                $confirmPassword = (string)($_POST['confirm_password'] ?? '');
                if (!Auth::verifyCurrentPassword($currentPassword)) {
                    flash_set('danger', 'Current password is incorrect.');
                } elseif (strlen($newPassword) < 12) {
                    flash_set('danger', 'New password must contain at least 12 characters.');
                } elseif ($newPassword !== $confirmPassword) {
                    flash_set('danger', 'New password and confirmation do not match.');
                } elseif (password_verify($newPassword, self::passwordHash($pdo, $userId))) {
                    flash_set('danger', 'Choose a new password that is different from the current password.');
                } else {
                    $pdo->prepare('UPDATE users SET password_hash=?, updated_at=? WHERE id=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s'), $userId]);
                    Auth::audit('profile.password_changed', 'Changed own account password', $userId, 'user', $userId);
                    session_regenerate_id(true);
                    Csrf::rotate();
                    flash_set('success', 'Password changed successfully.');
                }
                header('Location: ' . app_url('?r=profile')); exit;
            }
        }

        $stmt = $pdo->prepare('SELECT id,name,email,status,last_login_at,created_at,updated_at FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$userId]);
        $account = $stmt->fetch() ?: $current;
        $roles = Auth::roles($userId);
        $flash = flash_pull();
        $mfaEnabled = Auth::mfaEnabled($userId);
        $initials = self::initials((string)$account['name']);

        ob_start(); ?>
        <div class="page-head profile-page-head"><div><span class="eyebrow">Account</span><h1>My profile</h1><p class="muted">Manage your personal details and account security.</p></div><span class="badge <?= $mfaEnabled ? 'badge-ok' : 'badge-warn' ?>"><?= $mfaEnabled ? 'MFA enabled' : 'MFA not enabled' ?></span></div>
        <?php if($flash): ?><div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-danger' ?>"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
        <div class="profile-grid">
          <aside class="card profile-summary">
            <div class="profile-avatar-large"><?= e($initials) ?></div>
            <div><h2><?= e($account['name']) ?></h2><p class="muted"><?= e($account['email']) ?></p></div>
            <div class="profile-role-list"><?php foreach($roles as $role): ?><span class="badge"><?= e(ucwords(str_replace('_',' ',$role))) ?></span><?php endforeach; ?></div>
            <dl class="profile-meta">
              <div><dt>Status</dt><dd><?= e(ucfirst((string)$account['status'])) ?></dd></div>
              <div><dt>Member since</dt><dd><?= e(substr((string)$account['created_at'],0,10)) ?></dd></div>
              <div><dt>Last sign in</dt><dd><?= e((string)($account['last_login_at'] ?: 'Not recorded')) ?></dd></div>
            </dl>
            <a class="btn btn-block" href="<?= e(app_url('?r=mfa-setup')) ?>">Manage MFA security</a>
          </aside>
          <div class="profile-forms">
            <section class="card"><div class="section-title"><div><h2>Personal details</h2><p class="muted">Your email is managed by the LMS administrator.</p></div></div><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="profile_action" value="details"><label>Display name<input name="name" maxlength="120" value="<?= e($account['name']) ?>" required></label><label>Email address<input value="<?= e($account['email']) ?>" disabled><small>Contact an administrator if your email needs to change.</small></label><div class="form-actions"><button class="btn btn-primary" type="submit">Save profile</button></div></form></section>
            <section class="card"><div class="section-title"><div><h2>Change password</h2><p class="muted">Use at least 12 characters and avoid reusing your current password.</p></div></div><form method="post" autocomplete="off"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="profile_action" value="password"><label>Current password<input name="current_password" type="password" autocomplete="current-password" required></label><div class="form-row"><label>New password<input name="new_password" type="password" minlength="12" autocomplete="new-password" required></label><label>Confirm new password<input name="confirm_password" type="password" minlength="12" autocomplete="new-password" required></label></div><div class="form-actions"><button class="btn btn-primary" type="submit">Update password</button></div></form></section>
          </div>
        </div>
        <?php render_page('My profile', ob_get_clean());
    }

    private static function passwordHash(\PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$userId]);
        return (string)$stmt->fetchColumn();
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr((string)($parts[0] ?? 'U'), 0, 1);
        $last = count($parts) > 1 ? mb_substr((string)$parts[count($parts)-1], 0, 1) : '';
        return strtoupper($first . $last);
    }
}
