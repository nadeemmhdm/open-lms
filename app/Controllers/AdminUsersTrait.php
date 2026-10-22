<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Services\InviteService;
use OpenLMS\Services\TotpService;

trait AdminUsersTrait
{
    public static function users(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $message = $error = null;
        $resetLink = null;
        $inviteLink = null;
        $inviteSummary = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Invalid security token.';
            } else {
                $action = (string)($_POST['action'] ?? '');
                if ($action === 'create_student_invite') {
                    $count = (int)($_POST['student_count'] ?? 0);
                    $hours = (int)($_POST['expires_hours'] ?? 24);
                    $academicYearId = (int)($_POST['academic_year_id'] ?? 0);
                    $batchId = (int)($_POST['batch_id'] ?? 0);
                    $courseIds = is_array($_POST['course_ids'] ?? null) ? $_POST['course_ids'] : [];
                    if ($count < 1 || $count > InviteService::MAX_BULK_USES) {
                        $error = 'Student count must be between 1 and ' . InviteService::MAX_BULK_USES . '.';
                    } elseif ($hours < 1 || $hours > InviteService::MAX_EXPIRY_HOURS) {
                        $error = 'Link lifetime must be between 1 and ' . InviteService::MAX_EXPIRY_HOURS . ' hours.';
                    } else {
                        try {
                            $created = InviteService::createStudent($count, $hours, (int)(Auth::user()['id'] ?? 0), $academicYearId, $batchId, $courseIds);
                            $inviteLink = (string)$created['url'];
                            $inviteSummary = 'Student link ready for ' . $count . ' account' . ($count === 1 ? '' : 's') . '. ' . $created['academic_year_name'] . ' / ' . $created['batch_name'] . ' / ' . $created['course_count'] . ' course(s). Link ID: ' . $created['public_id'] . '.';
                            Auth::audit('users.invite_created', 'Created student onboarding link ' . $created['public_id'] . ' for batch ' . $created['batch_name'] . ' with ' . $created['course_count'] . ' course(s)');
                        } catch (\InvalidArgumentException $e) {
                            $error = $e->getMessage();
                        } catch (\Throwable $e) {
                            error_log('[OpenLMS invite create] ' . $e->getMessage());
                            $error = 'Unable to create the student onboarding link.';
                        }
                    }
                } elseif ($action === 'create_role_invite') {
                    $role = (string)($_POST['invite_role'] ?? 'teacher');
                    $hours = (int)($_POST['expires_hours'] ?? 24);
                    if ($role === 'super_admin' || $role === '') {
                        $error = 'Choose a valid non-Super-Admin role.';
                    } elseif ($hours < 1 || $hours > InviteService::MAX_EXPIRY_HOURS) {
                        $error = 'Link lifetime must be between 1 and ' . InviteService::MAX_EXPIRY_HOURS . ' hours.';
                    } else {
                        try {
                            $created = InviteService::create($role, 1, $hours, (int)(Auth::user()['id'] ?? 0));
                            $inviteLink = (string)$created['url'];
                            $inviteSummary = 'Private ' . $created['role_label'] . ' setup link created. It can create one account only. Link ID: ' . $created['public_id'] . '. Expires ' . $created['expires_at'] . ' UTC.';
                            Auth::audit('users.invite_created', 'Created single-use ' . $role . ' registration link ' . $created['public_id']);
                        } catch (\InvalidArgumentException $e) {
                            $error = $e->getMessage();
                        } catch (\Throwable $e) {
                            error_log('[OpenLMS invite create] ' . $e->getMessage());
                            $error = 'Unable to create the account setup link.';
                        }
                    }
                } elseif ($action === 'revoke_invite') {
                    $publicId = (int)($_POST['invite_public_id'] ?? 0);
                    try {
                        if (!InviteService::validPublicId($publicId)) {
                            $error = 'Invalid registration link ID.';
                        } elseif (InviteService::revoke($publicId, (int)(Auth::user()['id'] ?? 0))) {
                            Auth::audit('users.invite_revoked', 'Revoked registration link ' . $publicId);
                            $message = 'Registration link revoked.';
                        } else {
                            $error = 'Registration link was not found or is already inactive.';
                        }
                    } catch (\Throwable $e) {
                        error_log('[OpenLMS invite revoke] ' . $e->getMessage());
                        $error = 'Unable to revoke the registration link.';
                    }
                } elseif ($action === 'create') {
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
                        Auth::audit('users.status', 'Changed account status to ' . $status, null, 'user', $uid);
                        $message = 'Account status updated.';
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
        $academicYears = $pdo->query("SELECT id,name,is_active FROM academic_years ORDER BY is_active DESC,starts_on DESC,id DESC")->fetchAll();
        $batches = $pdo->query("SELECT b.id,b.name,b.academic_year_id,ay.name academic_year_name FROM batches b LEFT JOIN academic_years ay ON ay.id=b.academic_year_id ORDER BY ay.is_active DESC,ay.starts_on DESC,b.name")->fetchAll();
        $onboardingCourses = $pdo->query("SELECT c.id,c.title,s.code subject_code,u.name teacher_name FROM courses c JOIN subjects s ON s.id=c.subject_id LEFT JOIN users u ON u.id=c.teacher_id WHERE c.status='published' ORDER BY c.title")->fetchAll();
        $parents = $pdo->query("SELECT DISTINCT u.id,u.name,u.email FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='parent' AND u.status='active' ORDER BY u.name")->fetchAll();
        $students = $pdo->query("SELECT DISTINCT u.id,u.name,u.email FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='student' AND u.status='active' ORDER BY u.name")->fetchAll();
        $parentLinks = $pdo->query("SELECT p.parent_user_id,p.student_user_id,pa.name parent_name,st.name student_name FROM parent_student_links p JOIN users pa ON pa.id=p.parent_user_id JOIN users st ON st.id=p.student_user_id ORDER BY pa.name,st.name")->fetchAll();
        try { $activeInvites = InviteService::activeList(); } catch (\Throwable $e) { $activeInvites = []; error_log('[OpenLMS invite list] ' . $e->getMessage()); }

        ob_start(); ?>
        <div class="page-head"><div><span class="eyebrow">Accounts</span><h1>User management</h1><p class="muted">Use secure setup links for faster onboarding, or create an account manually when needed.</p></div></div>
        <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if ($inviteLink): ?><section class="card generated-link-card"><div class="section-title"><div><h2>Registration link ready</h2><p class="muted"><?= e((string)$inviteSummary) ?></p></div></div><div class="copy-field"><input readonly value="<?= e($inviteLink) ?>" data-copy-source><button class="btn" type="button" data-copy-button>Copy link</button></div><p class="hint">Share this link only with the intended students or staff. The secret key is part of the URL and is not stored in plain text.</p></section><?php endif; ?>
        <?php if ($resetLink): ?><section class="card generated-link-card"><h2>One-time password reset link</h2><p class="hint">Share this link with the intended user through a trusted private channel. It is not emailed automatically.</p><div class="copy-field"><input readonly value="<?= e($resetLink) ?>" data-copy-source><button class="btn" type="button" data-copy-button>Copy link</button></div></section><?php endif; ?>

        <section class="card student-onboarding-card">
          <div class="section-title"><div><span class="eyebrow">Quick student onboarding</span><h2>Create students and enroll them automatically</h2><p class="muted">Select the academic year, batch and courses once. Every student who uses the generated link is added to that batch and enrolled in those courses automatically.</p></div></div>
          <?php if($academicYears && $batches && $onboardingCourses): ?>
          <form method="post" class="student-onboarding-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_student_invite">
            <div class="onboarding-step-grid">
              <label><span class="step-number">1</span>Academic year<select name="academic_year_id" data-onboarding-year required><option value="">Select year</option><?php foreach($academicYears as $year): ?><option value="<?= (int)$year['id'] ?>" <?= (int)$year['is_active']===1?'selected':'' ?>><?= e((string)$year['name']) ?><?= (int)$year['is_active']===1?' (Active)':'' ?></option><?php endforeach; ?></select></label>
              <label><span class="step-number">2</span>Batch<select name="batch_id" data-onboarding-batch required><option value="">Select batch</option><?php foreach($batches as $batch): ?><option value="<?= (int)$batch['id'] ?>" data-year="<?= (int)$batch['academic_year_id'] ?>"><?= e((string)$batch['name']) ?></option><?php endforeach; ?></select></label>
              <label><span class="step-number">3</span>Student count<input name="student_count" type="number" min="1" max="<?= InviteService::MAX_BULK_USES ?>" value="20" required></label>
              <label><span class="step-number">4</span>Link lifetime<input name="expires_hours" type="number" min="1" max="<?= InviteService::MAX_EXPIRY_HOURS ?>" value="24" required><small>Hours</small></label>
            </div>
            <div class="course-picker"><div class="course-picker-head"><div><strong>5. Select courses</strong><small>Students are enrolled immediately after account setup.</small></div><label class="check-inline"><input type="checkbox" data-course-select-all> Select all</label></div><div class="course-check-grid"><?php foreach($onboardingCourses as $course): ?><label class="course-check"><input type="checkbox" name="course_ids[]" value="<?= (int)$course['id'] ?>"><span><strong><?= e((string)$course['title']) ?></strong><small><?= e((string)($course['subject_code'] ?: 'Course')) ?><?= $course['teacher_name'] ? ' · ' . e((string)$course['teacher_name']) : '' ?></small></span></label><?php endforeach; ?></div></div>
            <button class="btn btn-primary" type="submit">Generate student onboarding link</button>
          </form>
          <?php else: ?><div class="empty-state"><h3>Academic setup required</h3><p class="muted">Create an academic year, batch and at least one published course before generating a student onboarding link.</p><a class="btn" href="<?= e(app_url('?r=academics')) ?>">Open academics</a></div><?php endif; ?>
        </section>

        <section class="card staff-invite-card"><details><summary><strong>Teacher or staff setup link</strong><span>Single-account link</span></summary><div class="details-body"><p class="muted">Use this when a teacher or staff member should create their own name, email and password.</p><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_role_invite"><div class="form-row"><label>Account role<select name="invite_role" required><?php foreach($roles as $role): ?><option value="<?= e($role['name']) ?>" <?= $role['name']==='teacher'?'selected':'' ?>><?= e($role['label']) ?></option><?php endforeach; ?></select></label><label>Link lifetime (hours)<input name="expires_hours" type="number" min="1" max="<?= InviteService::MAX_EXPIRY_HOURS ?>" value="24" required></label></div><button class="btn" type="submit">Generate staff link</button></form></div></details></section>

        <section class="card table-card invite-list-card"><div class="section-title"><div><h2>Active setup links</h2><p class="muted">Used, expired or revoked links disappear automatically.</p></div></div><div class="table-scroll"><table><thead><tr><th>Link</th><th>Account</th><th>Assignment</th><th>Usage</th><th>Expires</th><th></th></tr></thead><tbody><?php foreach($activeInvites as $invite): $remaining=max(0,(int)$invite['max_uses']-(int)$invite['used_count']); ?><tr><td><strong><?= e((string)$invite['public_id']) ?></strong></td><td><?= e((string)$invite['role_label']) ?></td><td><?php if($invite['role_name']==='student' && $invite['batch_name']): ?><strong><?= e((string)$invite['batch_name']) ?></strong><small><?= e((string)$invite['academic_year_name']) ?> · <?= (int)$invite['course_count'] ?> course(s)</small><?php else: ?><span class="muted">Single account</span><?php endif; ?></td><td><strong><?= (int)$invite['used_count'] ?> / <?= (int)$invite['max_uses'] ?></strong><small><?= $remaining ?> remaining</small></td><td><?= e((string)$invite['expires_at']) ?><small>UTC</small></td><td><form method="post" class="inline-form" onsubmit="return confirm('Revoke this registration link?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="revoke_invite"><input type="hidden" name="invite_public_id" value="<?= e((string)$invite['public_id']) ?>"><button class="btn btn-small" type="submit">Revoke</button></form></td></tr><?php endforeach; ?><?php if(!$activeInvites): ?><tr><td colspan="6"><p class="muted">No active setup links.</p></td></tr><?php endif; ?></tbody></table></div></section>

        <section class="card table-card accounts-card"><div class="section-title"><div><h2>Accounts</h2><p class="muted">Manage existing users after onboarding.</p></div></div><div class="table-scroll"><table><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($users as $u): ?><tr><td><strong><?= e($u['name']) ?></strong><small><?= e($u['email']) ?></small></td><td><?= e($u['roles'] ?: 'Not assigned') ?></td><td><span class="badge <?= $u['status']==='active'?'badge-ok':'badge-warn' ?>"><?= e($u['status']) ?></span></td><td><?php if (!str_contains((string)$u['roles'],'super_admin')): ?><div class="action-row"><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="status" value="<?= $u['status']==='active'?'suspended':'active' ?>"><button class="btn btn-small"><?= $u['status']==='active'?'Suspend':'Activate' ?></button></form><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="reset_link"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn btn-small">Reset link</button></form><?php if(Auth::hasAnyRole(['super_admin']) && (int)$u['id']!==(int)(Auth::user()['id']??0)):?><form method="post" class="inline-form" onsubmit="return confirm('Reset this user MFA enrollment?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="reset_mfa"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="password" name="password" placeholder="Current password" required><button class="btn btn-small">Reset MFA</button></form><?php endif;?></div><?php else: ?>Protected<?php if(Auth::hasAnyRole(['super_admin']) && (int)$u['id']!==(int)(Auth::user()['id']??0)):?><form method="post" class="inline-form" onsubmit="return confirm('Reset this Super Admin MFA enrollment?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="reset_mfa"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input type="password" name="password" placeholder="Current password" required><button class="btn btn-small">Reset MFA</button></form><?php endif;?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>

        <section class="card advanced-account-tools"><details><summary><strong>Advanced account tools</strong><span>Manual creation and parent links</span></summary><div class="details-body"><div class="grid-2 admin-users-grid"><section><h3>Create account manually</h3><p class="hint">Use this only when the administrator needs to set the initial password directly.</p><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Temporary password<input type="password" name="password" minlength="12" required></label><label>Role<select name="role"><?php foreach($roles as $role): ?><option value="<?= e($role['name']) ?>"><?= e($role['label']) ?></option><?php endforeach; ?></select></label><button class="btn">Create user</button></form></section>
        <?php if(feature_enabled('parent_portal')): ?><section><h3>Parent / guardian links</h3><p class="hint">Link a Parent-role account to a Student-role account.</p><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="link_parent"><label>Parent / guardian<select name="parent_id" required><option value="">Select</option><?php foreach($parents as $p1): ?><option value="<?= (int)$p1['id'] ?>"><?= e($p1['name'].' · '.$p1['email']) ?></option><?php endforeach; ?></select></label><label>Student<select name="student_id" required><option value="">Select</option><?php foreach($students as $s1): ?><option value="<?= (int)$s1['id'] ?>"><?= e($s1['name'].' · '.$s1['email']) ?></option><?php endforeach; ?></select></label><button class="btn">Link accounts</button></form><div class="compact-list"><?php foreach($parentLinks as $link): ?><div><span><strong><?= e($link['parent_name']) ?></strong><small>Student: <?= e($link['student_name']) ?></small></span><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="unlink_parent"><input type="hidden" name="parent_id" value="<?= (int)$link['parent_user_id'] ?>"><input type="hidden" name="student_id" value="<?= (int)$link['student_user_id'] ?>"><button class="btn btn-small">Unlink</button></form></div><?php endforeach; ?><?php if(!$parentLinks): ?><p class="muted">No parent links yet.</p><?php endif; ?></div></section><?php else: ?><section><h3>Parent portal</h3><p class="muted">Enable the Parent portal feature to manage parent-student links.</p></section><?php endif; ?></div></div></details></section>
        <?php render_page('Users', ob_get_clean());
    }
}
