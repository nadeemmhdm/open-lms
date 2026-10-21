<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\BackupService;

trait SystemReportsCertificatesTrait
{
    public static function reports(): void
    {
        require_feature('reports');
        Auth::requirePermission('reports.view');
        $u = Auth::user();
        $pdo = Database::connection();
        $global = Auth::hasAnyRole(['super_admin','administrator','hod']);
        $uid = (int)$u['id'];

        if ($global) {
            $stats = [
                'learners' => (int)$pdo->query("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name='student'")->fetchColumn(),
                'courses' => (int)$pdo->query("SELECT COUNT(*) FROM courses WHERE status='published'")->fetchColumn(),
                'completions' => (int)$pdo->query('SELECT COUNT(*) FROM course_completions')->fetchColumn(),
                'submissions' => (int)$pdo->query("SELECT COUNT(*) FROM project_submissions WHERE status IN ('submitted','graded')")->fetchColumn(),
            ];
            $where = '1=1';
            $args = [];
        } else {
            $countLearners = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM users u WHERE EXISTS(SELECT 1 FROM enrollments e JOIN courses c ON c.id=e.course_id WHERE e.user_id=u.id AND e.status IN ('active','completed') AND c.teacher_id=?) OR EXISTS(SELECT 1 FROM batch_members bm JOIN course_batches cb ON cb.batch_id=bm.batch_id JOIN courses c ON c.id=cb.course_id WHERE bm.user_id=u.id AND c.teacher_id=?)");
            $countLearners->execute([$uid,$uid]);
            $countCourses = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE teacher_id=? AND status='published'");$countCourses->execute([$uid]);
            $countCompletions = $pdo->prepare('SELECT COUNT(*) FROM course_completions cc JOIN courses c ON c.id=cc.course_id WHERE c.teacher_id=?');$countCompletions->execute([$uid]);
            $countSubmissions = $pdo->prepare("SELECT COUNT(*) FROM project_submissions ps JOIN projects p ON p.id=ps.project_id JOIN courses c ON c.id=p.course_id WHERE c.teacher_id=? AND ps.status IN ('submitted','graded')");$countSubmissions->execute([$uid]);
            $stats = ['learners'=>(int)$countLearners->fetchColumn(),'courses'=>(int)$countCourses->fetchColumn(),'completions'=>(int)$countCompletions->fetchColumn(),'submissions'=>(int)$countSubmissions->fetchColumn()];
            $where = 'c.teacher_id=?';
            $args = [$uid];
        }

        $sql = "SELECT c.id,c.title,u.name teacher_name,
            (SELECT COUNT(DISTINCT u2.id) FROM users u2 WHERE EXISTS(SELECT 1 FROM enrollments e WHERE e.user_id=u2.id AND e.course_id=c.id AND e.status IN ('active','completed')) OR EXISTS(SELECT 1 FROM batch_members bm JOIN course_batches cb ON cb.batch_id=bm.batch_id WHERE bm.user_id=u2.id AND cb.course_id=c.id)) enrollments,
            (SELECT COUNT(*) FROM lessons l JOIN course_sections cs ON cs.id=l.course_section_id WHERE cs.course_id=c.id AND l.status='published') lessons,
            (SELECT COUNT(*) FROM course_completions cc WHERE cc.course_id=c.id) completions,
            (SELECT COUNT(*) FROM project_submissions ps JOIN projects p ON p.id=ps.project_id WHERE p.course_id=c.id AND ps.status='submitted') pending_projects
            FROM courses c LEFT JOIN users u ON u.id=c.teacher_id WHERE $where ORDER BY c.title";
        $st = $pdo->prepare($sql);$st->execute($args);$courses = $st->fetchAll();

        ob_start(); ?>
        <div class="page-head"><div><h1>Reports & analytics</h1><p class="muted"><?= $global ? 'Institution-wide' : 'Your-course' ?> operational metrics from LMS records.</p></div></div>
        <div class="stats-grid"><div class="card stat"><span>Learners</span><strong><?=$stats['learners']?></strong></div><div class="card stat"><span>Published courses</span><strong><?=$stats['courses']?></strong></div><div class="card stat"><span>Course completions</span><strong><?=$stats['completions']?></strong></div><div class="card stat"><span>Project submissions</span><strong><?=$stats['submissions']?></strong></div></div>
        <section class="card table-card"><h2>Course performance / teacher workload</h2><div class="table-scroll"><table><thead><tr><th>Course</th><th>Teacher</th><th>Learners</th><th>Lessons</th><th>Completions</th><th>Pending projects</th></tr></thead><tbody><?php foreach($courses as $c):?><tr><td><?=e($c['title'])?></td><td><?=e($c['teacher_name']??'Unassigned')?></td><td><?=(int)$c['enrollments']?></td><td><?=(int)$c['lessons']?></td><td><?=(int)$c['completions']?></td><td><?=(int)$c['pending_projects']?></td></tr><?php endforeach;?></tbody></table></div></section>
        <?php render_page('Reports',ob_get_clean());
    }
    public static function certificates(): void
    {
        require_feature('certificates');
        Auth::requireAuth();
        $u = Auth::user();
        $pdo = Database::connection();
        $can = Auth::can('reports.view');
        $error = $message = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can) {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Security token expired.';
            } else {
                try {
                    $action = (string)($_POST['action'] ?? 'issue');
                    if ($action === 'revoke') {
                        $certId = (int)($_POST['certificate_id'] ?? 0);
                        $st = $pdo->prepare('SELECT cert.*,c.teacher_id,c.status course_status,s.status subject_status FROM certificates cert JOIN courses c ON c.id=cert.course_id JOIN subjects s ON s.id=c.subject_id WHERE cert.id=?');
                        $st->execute([$certId]);
                        $cert = $st->fetch();
                        if (!$cert || !CoursePolicy::canManage($cert + ['id'=>(int)$cert['course_id']], $u)) throw new \RuntimeException('Certificate access denied.');
                        if (!$cert['revoked_at']) {
                            $pdo->prepare('UPDATE certificates SET revoked_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s'), $certId]);
                        }
                        $message = 'Certificate revoked.';
                    } else {
                        $uid = (int)($_POST['user_id'] ?? 0);
                        $cid = (int)($_POST['course_id'] ?? 0);
                        $c = self::course($cid);
                        if (!$c || !CoursePolicy::canManage($c, $u)) throw new \RuntimeException('Course access denied.');
                        $st = $pdo->prepare('SELECT COUNT(*) FROM course_completions WHERE user_id=? AND course_id=?');
                        $st->execute([$uid, $cid]);
                        if (!(int)$st->fetchColumn()) throw new \RuntimeException('Certificate can be issued only after course completion.');
                        $st = $pdo->prepare('SELECT id FROM certificates WHERE user_id=? AND course_id=? AND revoked_at IS NULL');
                        $st->execute([$uid, $cid]);
                        if ($st->fetchColumn()) throw new \RuntimeException('An active certificate already exists.');
                        $no = 'OLMS-' . gmdate('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
                        $token = bin2hex(random_bytes(24));
                        $pdo->prepare('INSERT INTO certificates (user_id,course_id,certificate_no,issued_by,issued_at,verification_token) VALUES (?,?,?,?,?,?)')
                            ->execute([$uid,$cid,$no,(int)$u['id'],gmdate('Y-m-d H:i:s'),$token]);
                        notify_user($uid, 'Certificate issued', 'Your completion certificate for '.$c['title'].' is available.', 'certificate', app_url('?r=certificates'));
                        $message = 'Certificate issued.';
                    }
                } catch (\Throwable $e) {
                    $error = $e instanceof \RuntimeException ? $e->getMessage() : 'Unable to update certificate.';
                }
            }
        }

        if ($can) {
            $where = Auth::hasAnyRole(['super_admin','administrator','hod']) ? '1=1' : 'c.teacher_id=?';
            $args = $where === '1=1' ? [] : [(int)$u['id']];
            $st = $pdo->prepare("SELECT cc.user_id,cc.course_id,u.name student_name,c.title course_title FROM course_completions cc JOIN users u ON u.id=cc.user_id JOIN courses c ON c.id=cc.course_id WHERE $where ORDER BY cc.completed_at DESC");
            $st->execute($args); $eligible = $st->fetchAll();
            $st = $pdo->prepare("SELECT cert.*,u.name student_name,c.title course_title,c.teacher_id,c.status course_status FROM certificates cert JOIN users u ON u.id=cert.user_id JOIN courses c ON c.id=cert.course_id WHERE $where ORDER BY cert.issued_at DESC");
            $st->execute($args); $certs = $st->fetchAll();
        } else {
            $eligible = [];
            $st = $pdo->prepare('SELECT cert.*,c.title course_title FROM certificates cert JOIN courses c ON c.id=cert.course_id WHERE cert.user_id=? ORDER BY cert.issued_at DESC');
            $st->execute([(int)$u['id']]); $certs = $st->fetchAll();
        }

        ob_start(); ?>
        <div class="page-head"><div><h1>Certificates</h1><p class="muted">Verifiable course completion certificates. Revoked certificates remain auditable but fail public verification.</p></div></div>
        <?php if($message):?><div class="alert alert-success"><?=e($message)?></div><?php endif;?>
        <?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
        <?php if($can):?><section class="card"><h2>Issue certificate</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="issue"><label>Completed course / student<select name="pair" onchange="const p=this.value.split(':');this.form.user_id.value=p[0]||'';this.form.course_id.value=p[1]||''"><option value="">Select</option><?php foreach($eligible as $e1):?><option value="<?=(int)$e1['user_id'].':'.(int)$e1['course_id']?>"><?=e($e1['student_name'].' · '.$e1['course_title'])?></option><?php endforeach;?></select></label><input type="hidden" name="user_id"><input type="hidden" name="course_id"><button class="btn btn-primary">Issue</button></form></section><?php endif;?>
        <div class="card-grid"><?php foreach($certs as $c):?><article class="card"><span class="badge <?= $c['revoked_at'] ? 'badge-warn' : 'badge-ok' ?>"><?=e($c['certificate_no'])?></span><h2><?=e($c['course_title'])?></h2><?php if(isset($c['student_name'])):?><p><?=e($c['student_name'])?></p><?php endif;?><p class="muted">Issued <?=e($c['issued_at'])?><?= $c['revoked_at'] ? ' · Revoked '.e($c['revoked_at']) : '' ?></p><div class="action-row"><a class="btn" target="_blank" href="<?=e(app_url('?r=certificate-verify&token='.rawurlencode($c['verification_token'])))?>">Verify / print</a><?php if($can && !$c['revoked_at']):?><form method="post" class="inline-form" onsubmit="return confirm('Revoke this certificate?')"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="certificate_id" value="<?=(int)$c['id']?>"><button class="btn btn-small">Revoke</button></form><?php endif;?></div></article><?php endforeach;?></div>
        <?php render_page('Certificates',ob_get_clean());
    }
    public static function certificateVerify(): void
    {
        require_feature('certificates');$token=(string)($_GET['token']??'');$st=Database::connection()->prepare('SELECT cert.*,u.name student_name,c.title course_title,s.name subject_name FROM certificates cert JOIN users u ON u.id=cert.user_id JOIN courses c ON c.id=cert.course_id JOIN subjects s ON s.id=c.subject_id WHERE cert.verification_token=? LIMIT 1');$st->execute([$token]);$c=$st->fetch();ob_start();?><div class="auth-wrap"><section class="card certificate-sheet"><?php if(!$c):?><h1>Certificate not found</h1><p>The verification token is invalid.</p><?php elseif($c['revoked_at']):?><h1>Certificate revoked</h1><p>This certificate is no longer valid.</p><?php else:?><div class="brand-mark">OL</div><p class="muted">Certificate of Completion</p><h1><?=e($c['student_name'])?></h1><p>has successfully completed</p><h2><?=e($c['course_title'])?></h2><p><?=e($c['subject_name'])?></p><div class="kv"><span>Certificate no.</span><strong><?=e($c['certificate_no'])?></strong><span>Issued</span><strong><?=e($c['issued_at'])?></strong></div><button class="btn" onclick="window.print()">Print</button><?php endif;?></section></div><?php render_page('Certificate verification',ob_get_clean(),false);
    }
}
