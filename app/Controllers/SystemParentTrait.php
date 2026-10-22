<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\BackupService;

trait SystemParentTrait
{
    public static function parentPortal(): void
    {
        require_feature('parent_portal');
        Auth::requireAuth();
        $u = Auth::user();
        if (!in_array('parent', $u['roles'], true)) {
            http_response_code(403);
            render_page('Access denied', '<div class="card">Parent role required.</div>');
            return;
        }
        $pdo = Database::connection();
        $st = $pdo->prepare('SELECT u.id,u.name,u.email FROM parent_student_links p JOIN users u ON u.id=p.student_user_id WHERE p.parent_user_id=? ORDER BY u.name');
        $st->execute([(int)$u['id']]);
        $students = $st->fetchAll();

        ob_start(); ?>
        <div class="page-head"><div><h1>Parent / guardian portal</h1><p class="muted">Read-only progress, attendance and grade summaries for explicitly linked students.</p></div></div>
        <?php foreach($students as $s):
            $sid=(int)$s['id'];
            $g=$pdo->prepare('SELECT c.title course_title,g.marks,g.max_marks,g.item_type,g.graded_at FROM grades g JOIN courses c ON c.id=g.course_id WHERE g.user_id=? ORDER BY g.graded_at DESC LIMIT 20');$g->execute([$sid]);$grades=$g->fetchAll();
            $a=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN ar.status='present' THEN 1 ELSE 0 END) present,SUM(CASE WHEN ar.status='late' THEN 1 ELSE 0 END) late,SUM(CASE WHEN ar.status='excused' THEN 1 ELSE 0 END) excused FROM attendance_records ar WHERE ar.student_id=?");$a->execute([$sid]);$attendance=$a->fetch()?:['total'=>0,'present'=>0,'late'=>0,'excused'=>0];
            $p=$pdo->prepare("SELECT c.id,c.title,(SELECT COUNT(*) FROM lessons l JOIN course_sections cs2 ON cs2.id=l.course_section_id WHERE cs2.course_id=c.id AND l.status='published') lesson_count,(SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l2 ON l2.id=lp.lesson_id JOIN course_sections cs3 ON cs3.id=l2.course_section_id WHERE cs3.course_id=c.id AND lp.user_id=? AND lp.completed_at IS NOT NULL) completed_lessons,EXISTS(SELECT 1 FROM course_completions cc WHERE cc.course_id=c.id AND cc.user_id=?) completed FROM courses c JOIN class_subjects css ON css.subject_id=c.subject_id JOIN class_members cm ON cm.class_id=css.class_id WHERE c.status='published' AND cm.user_id=? ORDER BY c.title");$p->execute([$sid,$sid,$sid]);$progress=$p->fetchAll();
            $total=(int)($attendance['total']??0);$present=(int)($attendance['present']??0);$attPct=$total>0?round($present*100/$total,1):null;
        ?>
        <section class="card"><div class="page-head"><div><h2><?=e($s['name'])?></h2><p class="muted"><?=e($s['email'])?></p></div><span class="badge"><?= $attPct===null ? 'No attendance yet' : e((string)$attPct).'% present' ?></span></div>
            <div class="grid-2"><div><h3>Course progress</h3><div class="compact-list"><?php foreach($progress as $r):$count=(int)$r['lesson_count'];$done=(int)$r['completed_lessons'];$pct=$count?round($done*100/$count):0;?><div><span><strong><?=e($r['title'])?></strong><small><?=$done?> / <?=$count?> lessons<?= $r['completed'] ? ' · Course completed' : '' ?></small></span><span class="badge"><?=$pct?>%</span></div><?php endforeach;?><?php if(!$progress):?><p class="muted">No accessible courses yet.</p><?php endif;?></div></div>
            <div><h3>Attendance</h3><div class="kv"><span>Sessions</span><strong><?=$total?></strong><span>Present</span><strong><?=$present?></strong><span>Late</span><strong><?=(int)($attendance['late']??0)?></strong><span>Excused</span><strong><?=(int)($attendance['excused']??0)?></strong></div></div></div>
            <h3>Recent grades</h3><div class="compact-list"><?php foreach($grades as $r):?><div><span><strong><?=e($r['course_title'])?></strong><small><?=e($r['item_type'])?> · <?=e($r['graded_at']??'')?></small></span><span class="badge"><?=e($r['marks'].' / '.$r['max_marks'])?></span></div><?php endforeach;?><?php if(!$grades):?><p class="muted">No grades published yet.</p><?php endif;?></div>
        </section>
        <?php endforeach;?>
        <?php if(!$students):?><div class="card empty-state">No student is linked to this parent account.</div><?php endif;?>
        <?php render_page('Parent portal',ob_get_clean());
    }
    private static function course(int $id):?array{$st=Database::connection()->prepare("SELECT c.*,s.status subject_status FROM courses c JOIN subjects s ON s.id=c.subject_id WHERE c.id=?");$st->execute([$id]);return $st->fetch()?:null;}
    private static function manageableCourses(array $u):array{$pdo=Database::connection();if(Auth::hasAnyRole(['super_admin','administrator','hod']))return $pdo->query("SELECT c.*,s.status subject_status FROM courses c JOIN subjects s ON s.id=c.subject_id WHERE c.status<>'archived' ORDER BY c.title")->fetchAll();$st=$pdo->prepare("SELECT c.*,s.status subject_status FROM courses c JOIN subjects s ON s.id=c.subject_id WHERE c.teacher_id=? AND c.status<>'archived' ORDER BY c.title");$st->execute([(int)$u['id']]);return $st->fetchAll();}
}
