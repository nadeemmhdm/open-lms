<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Services\InviteService;

final class OnboardingController
{
    public static function index(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $message = $error = null;
        $generated = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Security token expired. Refresh and try again.';
            } else {
                $action = (string)($_POST['action'] ?? '');
                try {
                    if ($action === 'student_link') {
                        $count = (int)($_POST['student_count'] ?? 0);
                        $hours = (int)($_POST['expires_hours'] ?? 24);
                        $yearId = (int)($_POST['academic_year_id'] ?? 0);
                        $batchId = (int)($_POST['batch_id'] ?? 0);
                        $courseIds = is_array($_POST['course_ids'] ?? null) ? $_POST['course_ids'] : [];
                        if ($count < 1 || $count > InviteService::MAX_BULK_USES) throw new \RuntimeException('Student count must be between 1 and ' . InviteService::MAX_BULK_USES . '.');
                        if ($hours < 1 || $hours > InviteService::MAX_EXPIRY_HOURS) throw new \RuntimeException('Link lifetime must be between 1 and ' . InviteService::MAX_EXPIRY_HOURS . ' hours.');
                        $generated = InviteService::createStudent($count, $hours, (int)(Auth::user()['id'] ?? 0), $yearId, $batchId, $courseIds);
                        Auth::audit('users.invite_created', 'Created Student onboarding link ' . $generated['public_id'] . ' for ' . $generated['batch_name']);
                        $message = 'Student onboarding link created.';
                    } elseif ($action === 'staff_link') {
                        $role = (string)($_POST['role'] ?? 'teacher');
                        $hours = (int)($_POST['expires_hours'] ?? 24);
                        if ($hours < 1 || $hours > InviteService::MAX_EXPIRY_HOURS) throw new \RuntimeException('Link lifetime must be between 1 and ' . InviteService::MAX_EXPIRY_HOURS . ' hours.');
                        $generated = InviteService::create($role, 1, $hours, (int)(Auth::user()['id'] ?? 0));
                        Auth::audit('users.invite_created', 'Created single-use ' . $role . ' setup link ' . $generated['public_id']);
                        $message = 'Staff setup link created.';
                    } elseif ($action === 'revoke') {
                        $publicId = (int)($_POST['public_id'] ?? 0);
                        if (!InviteService::revoke($publicId, (int)(Auth::user()['id'] ?? 0))) throw new \RuntimeException('Setup link is already inactive or was not found.');
                        Auth::audit('users.invite_revoked', 'Revoked registration link ' . $publicId);
                        $message = 'Setup link revoked.';
                    }
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $error = $e->getMessage();
                } catch (\Throwable $e) {
                    error_log('[OpenLMS onboarding] ' . $e->getMessage());
                    $error = 'Unable to complete the onboarding action.';
                }
            }
        }

        $years = $pdo->query('SELECT id,name,is_active FROM academic_years ORDER BY is_active DESC,id DESC')->fetchAll();
        $batches = $pdo->query('SELECT b.id,b.name,b.academic_year_id,ay.name academic_year_name FROM batches b JOIN academic_years ay ON ay.id=b.academic_year_id ORDER BY ay.is_active DESC,ay.id DESC,b.name')->fetchAll();
        $courses = $pdo->query("SELECT c.id,c.title,s.code subject_code,u.name teacher_name FROM courses c JOIN subjects s ON s.id=c.subject_id LEFT JOIN users u ON u.id=c.teacher_id WHERE c.status='published' ORDER BY c.title")->fetchAll();
        $roles = $pdo->query("SELECT name,label FROM roles WHERE name<>'super_admin' AND name<>'student' ORDER BY id")->fetchAll();
        $activeInvites = InviteService::activeList();

        ob_start(); ?>
        <div class="page-head"><div><h1>Onboarding</h1><p class="muted">Create one link and let students or staff set up their own accounts.</p></div><a class="btn" href="<?= e(app_url('?r=users')) ?>">Manage existing users</a></div>
        <?php if($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if($generated): ?><section class="card"><h2>Setup link ready</h2><p class="hint">Copy this link now. For security, the secret part is not stored in readable form.</p><div class="form-row"><label>Link<input id="generated-onboarding-link" readonly value="<?= e((string)$generated['url']) ?>" onclick="this.select()"></label><div style="display:flex;align-items:end"><button class="btn btn-primary" type="button" onclick="navigator.clipboard?.writeText(document.getElementById('generated-onboarding-link').value)">Copy link</button></div></div><?php if(($generated['role'] ?? '')==='student'): ?><p class="muted"><strong><?= e((string)$generated['academic_year_name']) ?> / <?= e((string)$generated['batch_name']) ?></strong> · <?= (int)$generated['course_count'] ?> course(s) · <?= (int)$generated['max_uses'] ?> student seats</p><?php else: ?><p class="muted">Role: <?= e((string)$generated['role_label']) ?> · one account only</p><?php endif; ?></section><?php endif; ?>

        <div class="grid-2">
          <section class="card"><h2>Student onboarding</h2><p class="muted">Pick the academic context once. Every student using the link is added to the batch and enrolled in the selected courses automatically.</p>
          <?php if($years && $batches && $courses): ?><form method="post" id="student-onboarding-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="student_link"><label>Academic year<select name="academic_year_id" id="onboarding-year" required><option value="">Select year</option><?php foreach($years as $year): ?><option value="<?= (int)$year['id'] ?>" <?= (int)$year['is_active']===1?'selected':'' ?>><?= e((string)$year['name']) ?><?= (int)$year['is_active']===1?' (Active)':'' ?></option><?php endforeach; ?></select></label><label>Batch<select name="batch_id" id="onboarding-batch" required><option value="">Select batch</option><?php foreach($batches as $batch): ?><option value="<?= (int)$batch['id'] ?>" data-year="<?= (int)$batch['academic_year_id'] ?>"><?= e((string)$batch['name']) ?></option><?php endforeach; ?></select></label><div class="form-row"><label>Student count<input name="student_count" type="number" min="1" max="<?= InviteService::MAX_BULK_USES ?>" value="20" required></label><label>Expires after (hours)<input name="expires_hours" type="number" min="1" max="<?= InviteService::MAX_EXPIRY_HOURS ?>" value="24" required></label></div><fieldset style="border:1px solid var(--border);border-radius:12px;padding:14px"><legend style="padding:0 6px;font-weight:700">Courses</legend><label style="display:flex;grid-template-columns:auto 1fr;align-items:center"><input id="select-all-courses" type="checkbox" style="width:auto">Select all</label><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-top:10px"><?php foreach($courses as $course): ?><label style="display:flex;grid-template-columns:auto 1fr;align-items:start;gap:8px;border:1px solid var(--border);padding:10px;border-radius:10px"><input type="checkbox" name="course_ids[]" value="<?= (int)$course['id'] ?>" style="width:auto;margin-top:3px"><span><strong><?= e((string)$course['title']) ?></strong><small><?= e((string)($course['subject_code'] ?: 'Course')) ?><?= $course['teacher_name'] ? ' · ' . e((string)$course['teacher_name']) : '' ?></small></span></label><?php endforeach; ?></div></fieldset><button class="btn btn-primary" type="submit">Generate student link</button></form><?php else: ?><p class="muted">Create an academic year, batch and published course first.</p><a class="btn" href="<?= e(app_url('?r=academics')) ?>">Open academics</a><?php endif; ?>
          </section>

          <section class="card"><h2>Teacher / staff onboarding</h2><p class="muted">Generate a private one-use link. The person sets their own name, email and password.</p><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="staff_link"><label>Role<select name="role" required><?php foreach($roles as $role): ?><option value="<?= e((string)$role['name']) ?>" <?= $role['name']==='teacher'?'selected':'' ?>><?= e((string)$role['label']) ?></option><?php endforeach; ?></select></label><label>Expires after (hours)<input name="expires_hours" type="number" min="1" max="<?= InviteService::MAX_EXPIRY_HOURS ?>" value="24" required></label><button class="btn" type="submit">Generate staff link</button></form></section>
        </div>

        <section class="card table-card"><h2>Active setup links</h2><div class="table-scroll"><table><thead><tr><th>ID</th><th>Role</th><th>Assignment</th><th>Usage</th><th>Expires</th><th></th></tr></thead><tbody><?php foreach($activeInvites as $invite): ?><tr><td><strong><?= e((string)$invite['public_id']) ?></strong></td><td><?= e((string)$invite['role_label']) ?></td><td><?php if($invite['role_name']==='student' && $invite['batch_name']): ?><?= e((string)$invite['academic_year_name']) ?><small><?= e((string)$invite['batch_name']) ?> · <?= (int)$invite['course_count'] ?> course(s)</small><?php else: ?><span class="muted">Single account</span><?php endif; ?></td><td><?= (int)$invite['used_count'] ?> / <?= (int)$invite['max_uses'] ?></td><td><?= e(format_app_datetime((string)$invite['expires_at'])) ?><small><?= e(app_timezone()) ?></small></td><td><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="public_id" value="<?= e((string)$invite['public_id']) ?>"><button class="btn btn-small" type="submit">Revoke</button></form></td></tr><?php endforeach; ?><?php if(!$activeInvites): ?><tr><td colspan="6"><span class="muted">No active setup links.</span></td></tr><?php endif; ?></tbody></table></div></section>
        <script>
        (()=>{const y=document.getElementById('onboarding-year'),b=document.getElementById('onboarding-batch');if(y&&b){const s=()=>{const v=y.value;let first='';[...b.options].forEach((o,i)=>{if(!i)return;const show=!v||o.dataset.year===v;o.hidden=!show;o.disabled=!show;if(show&&!first)first=o.value});if(b.selectedOptions[0]?.disabled)b.value=first};y.addEventListener('change',s);s()}const all=document.getElementById('select-all-courses');if(all){const boxes=[...document.querySelectorAll('input[name="course_ids[]"]')];all.addEventListener('change',()=>boxes.forEach(x=>x.checked=all.checked));}})();
        </script>
        <?php render_page('Onboarding', ob_get_clean());
    }
}
