<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Services\TotpService;

trait AdminUsersTrait
{
    public static function users(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $message = $error = null;
        $resetLink = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Invalid security token.';
            } else {
                $action = (string)($_POST['action'] ?? '');
                if ($action === 'create') {
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
                    $password = (string) ($_POST['password'] ?? '');
                    $role = (string) ($_POST['role'] ?? 'student');
                    if ($role === 'super_admin') {
                        $error = 'Create the account with a non-Super-Admin role, then use Roles & permissions with current-password reauthentication to grant Super Admin.';
                    } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
                        $error = 'Use a valid name/email and a password of at least 12 characters.';
                    } else {
                        try {
                            $pdo->beginTransaction();
                            $now = gmdate('Y-m-d H:i:s');
                            $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,status,created_at,updated_at) VALUES (?,?,?,?,?,?)');
                            $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),'active',$now,$now]);
                            $uid = (int) $pdo->lastInsertId();
                            $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
                            $stmt->execute([$role]);
                            $rid = (int) $stmt->fetchColumn();
                            if (!$rid) throw new \RuntimeException('Invalid role.');
                            $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')->execute([$uid,$rid]);
                            $pdo->commit();
                            Auth::audit('users.create', 'Created user ' . $email, null, 'user', $uid);
                            $message = 'User created.';
                        } catch (\Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $error = str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate') ? 'That email already exists.' : 'Unable to create user.';
                        }
                    }
                } elseif ($action === 'link_parent') {
                    $parentId = (int)($_POST['parent_id'] ?? 0);
                    $studentId = (int)($_POST['student_id'] ?? 0);
                    if ($parentId < 1 || $studentId < 1 || !in_array('parent', Auth::roles($parentId), true) || !in_array('student', Auth::roles($studentId), true)) {
                        $error = 'Choose a valid Parent and Student account.';
                    } else {
                        $stmt = $pdo->prepare('SELECT COUNT(*) FROM parent_student_links WHERE parent_user_id=? AND student_user_id=?');
                        $stmt->execute([$parentId,$studentId]);
                        if (!(int)$stmt->fetchColumn()) $pdo->prepare('INSERT INTO parent_student_links (parent_user_id,student_user_id,created_at) VALUES (?,?,?)')->execute([$parentId,$studentId,gmdate('Y-m-d H:i:s')]);
                        Auth::audit('users.parent_link', 'Linked parent/guardian to student', null, 'user', $studentId);
                        $message = 'Parent / guardian link saved.';
                    }
                } elseif ($action === 'unlink_parent') {
                    $parentId = (int)($_POST['parent_id'] ?? 0);
                    $studentId = (int)($_POST['student_id'] ?? 0);
                    $pdo->prepare('DELETE FROM parent_student_links WHERE parent_user_id=? AND student_user_id=?')->execute([$parentId,$studentId]);
                    Auth::audit('users.parent_unlink', 'Removed parent/guardian link', null, 'user', $studentId);
                    $message = 'Parent / guardian link removed.';
                } elseif ($action === 'reset_link') {
                    $uid = (int) ($_POST['user_id'] ?? 0);
                    $targetRoles = Auth::roles($uid);
                    if ($uid < 1 || in_array('super_admin', $targetRoles, true)) {
                        $error = 'Password reset links for Super Admin accounts are not issued from this screen.';
                    } else {
                        $token = bin2hex(random_bytes(32));
                        $hash = hash('sha256', $token);
                        $now = gmdate('Y-m-d H:i:s');
                        $expires = gmdate('Y-m-d H:i:s', time() + 1800);
                        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=? AND used_at IS NULL')->execute([$uid]);
                        $pdo->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at,created_at) VALUES (?,?,?,?)')->execute([$uid,$hash,$expires,$now]);
                        $resetLink = absolute_app_url('?r=reset-password&token=' . rawurlencode($token));
                        Auth::audit('users.password_reset_link', 'Issued a one-time password reset link', null, 'user', $uid);
                        $message = 'One-time reset link created. It expires in 30 minutes and is shown only on this page.';
                    }
                } elseif ($action === 'status') {
                    $uid = (int) ($_POST['user_id'] ?? 0);
                    $status = ($_POST['status'] ?? '') === 'suspended' ? 'suspended' : 'active';
                    $targetRoles = Auth::roles($uid);
                    if (in_array('super_admin', $targetRoles, true)) {
                        $error = 'Super Admin suspension is blocked from this screen.';
                    } else {
                        $pdo->prepare('UPDATE users SET status = ?, updated_at = ? WHERE id = ?')->execute([$status,gmdate('Y-m-d H:i:s'),$uid]);
                        if ($status === 'suspended') Auth::revokeAllSessions($uid);
                        Auth::audit('users.status', 'Changed account status to ' . $status, null, 'user', $uid);
                        $message = $status === 'suspended' ? 'Account suspended and all active sign-ins revoked.' : 'Account activated.';
                    }
                } elseif ($action === 'delete_user') {
                    $uid = (int)($_POST['user_id'] ?? 0);
                    $actor = Auth::user();
                    $targetRoles = $uid > 0 ? Auth::roles($uid) : [];
                    if ($uid < 1 || $uid === (int)($actor['id'] ?? 0)) {
                        $error = 'You cannot delete your own account from this screen.';
                    } elseif (in_array('super_admin', $targetRoles, true)) {
                        $error = 'Super Admin accounts are protected from deletion.';
                    } elseif (!Auth::verifyCurrentPassword((string)($_POST['password'] ?? ''))) {
                        $error = 'Your current administrator password is required to delete an account.';
                    } else {
                        $st=$pdo->prepare('SELECT email FROM users WHERE id=?');$st->execute([$uid]);$targetEmail=(string)$st->fetchColumn();
                        if ($targetEmail === '') $error='User not found.';
                        else {
                            try {
                                Auth::revokeAllSessions($uid);
                                $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
                                Auth::audit('users.delete', 'Deleted user ' . $targetEmail);
                                $message = 'User account deleted.';
                            } catch (\Throwable $e) {
                                $error = 'This account is linked to protected financial or audit history and cannot be deleted safely. Suspend the account instead.';
                            }
                        }
                    }
                } elseif ($action === 'reset_mfa') {
                    $uid = (int)($_POST['user_id'] ?? 0);
                    $actor = Auth::user();
                    if (!Auth::hasAnyRole(['super_admin'])) {
                        $error = 'Only a Super Admin can reset MFA enrollment.';
                    } elseif ($uid < 1 || $uid === (int)($actor['id'] ?? 0)) {
                        $error = 'Choose another valid account. Use Security / MFA to change your own MFA.';
                    } elseif (!Auth::verifyCurrentPassword((string)($_POST['password'] ?? ''))) {
                        $error = 'Current Super Admin password is required to reset MFA.';
                    } else {
                        $st = $pdo->prepare('SELECT email FROM users WHERE id=?');$st->execute([$uid]);$targetEmail = (string)$st->fetchColumn();
                        if ($targetEmail === '') $error = 'User not found.';
                        else {
                            TotpService::disableForUser($uid);
                            Auth::audit('mfa.admin_reset', 'Super Admin reset MFA enrollment for ' . $targetEmail, null, 'user', $uid);
                            $message = 'MFA enrollment reset. If the account role requires MFA, the user must enroll again after sign-in.';
                        }
                    }
                }
            }
        }

        $groupConcat = \OpenLMS\Core\Config::get('database.driver') === 'sqlite' ? "GROUP_CONCAT(r.name, ', ')" : "GROUP_CONCAT(r.name SEPARATOR ', ')";
        $users = $pdo->query("SELECT u.id,u.name,u.email,u.status,u.created_at,{$groupConcat} AS roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id GROUP BY u.id ORDER BY u.id DESC")->fetchAll();
        $roles = $pdo->query("SELECT name,label FROM roles WHERE name <> 'super_admin' ORDER BY id")->fetchAll();
        $parents = $pdo->query("SELECT DISTINCT u.id,u.name,u.email FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='parent' AND u.status='active' ORDER BY u.name")->fetchAll();
        $students = $pdo->query("SELECT DISTINCT u.id,u.name,u.email FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='student' AND u.status='active' ORDER BY u.name")->fetchAll();
        $parentLinks = $pdo->query("SELECT p.parent_user_id,p.student_user_id,pa.name parent_name,st.name student_name FROM parent_student_links p JOIN users pa ON pa.id=p.parent_user_id JOIN users st ON st.id=p.student_user_id ORDER BY pa.name,st.name")->fetchAll();

        ob_start(); ?>
        <div class="page-head"><div><span class="eyebrow">Accounts</span><h1>User management</h1><p class="muted">Use secure setup links for faster onboarding, or create an account manually when needed.</p></div></div>
        <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if ($resetLink): ?><section class="card generated-link-card"><h2>One-time password reset link</h2><p class="hint">Share this link with the intended user through a trusted private channel. It is not emailed automatically.</p><div class="copy-field"><input readonly value="<?= e($resetLink) ?>" data-copy-source><button class="btn" type="button" data-copy-button>Copy link</button></div></section><?php endif; ?>

        <section class="card"><div class="section-title"><div><h2>New student or staff accounts</h2><p class="muted">Use the dedicated Onboarding page to generate secure student batch/course links or one-use staff setup links.</p></div><a class="btn btn-primary" href="<?= e(app_url('?r=onboarding')) ?>">Open onboarding</a></div></section>

        <div class="grid-2 admin-users-grid">
            <section class="card"><h2>Create account manually</h2><p class="hint">Use this only when the administrator needs to set the initial password directly.</p><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Temporary password<input type="password" name="password" minlength="12" required></label><label>Role<select name="role"><?php foreach($roles as $role): ?><option value="<?= e($role['name']) ?>"><?= e($role['label']) ?></option><?php endforeach; ?></select></label><button class="btn">Create user</button></form></section>
            <section class="card table-card"><h2>Accounts</h2><div class="table-scroll"><table><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($users as $u): ?><tr><td><strong><?= e($u['name']) ?></strong><small><?= e($u['email']) ?></small></td><td><?= e($u['roles'] ?: 'Not assigned') ?></td><td><span class="badge <?= $u['status']==='active'?'badge-ok':'badge-warn' ?>"><?= e($u['status']) ?></span></td><td><?php if (!str_contains((string)$u['roles'],'super_admin')): ?><div class="action-row"><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="status" value="<?= $u['status']==='active'?'suspended':'active' ?>"><button class="btn btn-small"><?= $u['status']==='active'?'Suspend':'Activate' ?></button></form><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="reset_link"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn btn-small">Reset link</button></form><form method="post" class="inline-form" onsubmit="return confirm('Permanently delete this user account and its linked records?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="password" name="password" placeholder="Admin password" required><button class="btn btn-small" type="submit">Delete</button></form><?php if(Auth::hasAnyRole(['super_admin']) && (int)$u['id']!==(int)(Auth::user()['id']??0)):?><form method="post" class="inline-form" onsubmit="return confirm('Reset this user MFA enrollment?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="reset_mfa"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="password" name="password" placeholder="Current password" required><button class="btn btn-small">Reset MFA</button></form><?php endif;?></div><?php else: ?>Protected<?php if(Auth::hasAnyRole(['super_admin']) && (int)$u['id']!==(int)(Auth::user()['id']??0)):?><form method="post" class="inline-form" onsubmit="return confirm('Reset this Super Admin MFA enrollment?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="reset_mfa"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="password" name="password" placeholder="Current password" required><button class="btn btn-small">Reset MFA</button></form><?php endif;?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
        </div>
        <?php if(feature_enabled('parent_portal')): ?><section class="card"><h2>Parent / guardian links</h2><p class="hint">Link Parent-role accounts to Student-role accounts. The parent portal only exposes records for explicitly linked students.</p><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="link_parent"><div class="form-row"><label>Parent / guardian<select name="parent_id" required><option value="">Select</option><?php foreach($parents as $p1): ?><option value="<?= (int)$p1['id'] ?>"><?= e($p1['name'].' · '.$p1['email']) ?></option><?php endforeach; ?></select></label><label>Student<select name="student_id" required><option value="">Select</option><?php foreach($students as $s1): ?><option value="<?= (int)$s1['id'] ?>"><?= e($s1['name'].' · '.$s1['email']) ?></option><?php endforeach; ?></select></label></div><button class="btn">Link accounts</button></form><div class="compact-list"><?php foreach($parentLinks as $link): ?><div><span><strong><?= e($link['parent_name']) ?></strong><small>Student: <?= e($link['student_name']) ?></small></span><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="unlink_parent"><input type="hidden" name="parent_id" value="<?= (int)$link['parent_user_id'] ?>"><input type="hidden" name="student_id" value="<?= (int)$link['student_user_id'] ?>"><button class="btn btn-small">Unlink</button></form></div><?php endforeach; ?><?php if(!$parentLinks): ?><p class="muted">No parent links yet.</p><?php endif; ?></div></section><?php endif; ?>
        <?php render_page('Users', ob_get_clean());
    }
}
