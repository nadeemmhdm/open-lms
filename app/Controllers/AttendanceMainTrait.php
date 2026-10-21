<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;

trait AttendanceMainTrait
{
    public static function index(): void
    {
        require_feature('attendance');
        Auth::requireAuth();
        $user = Auth::user();
        if (in_array('student', $user['roles'], true) && !Auth::can('attendance.manage')) { self::studentView($user); return; }
        Auth::requirePermission('attendance.manage');
        $pdo = Database::connection();
        $years = $pdo->query('SELECT id,name,is_active FROM academic_years ORDER BY is_active DESC,starts_on DESC,id DESC')->fetchAll();
        $semesters = $pdo->query('SELECT id,name,academic_year_id FROM semesters ORDER BY id DESC')->fetchAll();
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) $error = 'Security token expired.';
            else {
                try {
                    $courseId = (int)($_POST['course_id'] ?? 0);
                    $course = self::course($courseId);
                    if (!$course || !CoursePolicy::canManage($course, $user)) throw new \RuntimeException('Course access denied.');
                    $batchId = (int)($_POST['batch_id'] ?? 0) ?: null;
                    if ($batchId && !self::batchAllowed($courseId, $batchId)) throw new \RuntimeException('That batch is not assigned to this course.');
                    $date = trim((string)($_POST['session_date'] ?? ''));
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new \RuntimeException('Choose a valid attendance date.');
                    $period = trim((string)($_POST['period_label'] ?? '')) ?: 'Daily';
                    $dup = $pdo->prepare('SELECT id FROM attendance_sessions WHERE course_id=? AND COALESCE(batch_id,0)=? AND session_date=? AND period_label=? LIMIT 1');
                    $dup->execute([$courseId, $batchId ?? 0, $date, $period]);
                    if ($dup->fetchColumn()) throw new \RuntimeException('An attendance session already exists for this course, batch, date and period.');
                    $yearId = (int)($_POST['academic_year_id'] ?? 0) ?: null;
                    $semesterId = (int)($_POST['semester_id'] ?? 0) ?: null;
                    if ($yearId && !self::idExists('academic_years', $yearId)) throw new \RuntimeException('Academic year is invalid.');
                    if ($semesterId && !self::idExists('semesters', $semesterId)) throw new \RuntimeException('Semester is invalid.');
                    $pdo->prepare('INSERT INTO attendance_sessions (course_id,batch_id,session_date,period_label,marked_by,created_at,academic_year_id,semester_id) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$courseId,$batchId,$date,mb_substr($period,0,80),(int)$user['id'],gmdate('Y-m-d H:i:s'),$yearId,$semesterId]);
                    $id = (int)$pdo->lastInsertId();
                    Auth::audit('attendance.session_created', 'Attendance session created', (int)$user['id'], 'attendance_session', $id);
                    header('Location: ' . app_url('?r=attendance&session_id='.$id)); exit;
                } catch (\Throwable $e) { $error = self::friendly($e); }
            }
        }

        $courses = self::manageableCourses($user);
        $sessions = self::manageableSessions($user);
        $selected = (int)($_GET['session_id'] ?? 0);
        if ($selected) { self::markSession($selected, $user, $error); return; }
        $report = self::reportRows($user);
        $exportQuery = http_build_query(array_filter([
            'r'=>'attendance-export','date_from'=>$_GET['date_from']??'','date_to'=>$_GET['date_to']??'',
            'academic_year_id'=>$_GET['academic_year_id']??'','semester_id'=>$_GET['semester_id']??'',
            'course_id'=>$_GET['course_id']??'','batch_id'=>$_GET['batch_id']??''
        ], fn($v)=>$v!==''));
        ob_start(); ?>
        <div class="page-head"><div><h1>Attendance</h1><p class="muted">Daily/period marking plus course, batch, month/date-range, semester and academic-year reporting.</p></div><a class="btn" href="<?= e(app_url('?'.$exportQuery)) ?>">Export filtered CSV</a></div>
        <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <div class="grid-2"><section class="card"><h2>New attendance session</h2><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><label>Course<select name="course_id" required><option value="">Select</option><?php foreach($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['title']) ?></option><?php endforeach; ?></select></label><label>Batch<select name="batch_id"><option value="">All enrolled students</option><?php foreach(self::batchesForCourses($courses) as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></label><div class="form-row"><label>Date<input type="date" name="session_date" value="<?= e(gmdate('Y-m-d')) ?>" required></label><label>Period<input name="period_label" value="Daily" maxlength="80"></label></div><div class="form-row"><label>Academic year<select name="academic_year_id"><option value="">None</option><?php foreach($years as $y):?><option value="<?=(int)$y['id']?>" <?= $y['is_active']?'selected':'' ?>><?=e($y['name'])?></option><?php endforeach;?></select></label><label>Semester<select name="semester_id"><option value="">None</option><?php foreach($semesters as $sm):?><option value="<?=(int)$sm['id']?>"><?=e($sm['name'])?></option><?php endforeach;?></select></label></div><button class="btn btn-primary">Create session</button></form></section>
        <section class="card"><h2>Recent sessions</h2><div class="compact-list"><?php foreach($sessions as $s): ?><a href="<?= e(app_url('?r=attendance&session_id='.(int)$s['id'])) ?>"><span><strong><?= e($s['course_title']) ?></strong><small><?= e($s['session_date'].' · '.$s['period_label'].' · '.($s['batch_name'] ?: 'All enrolled')) ?></small></span><span class="badge"><?= (int)$s['record_count'] ?> marked</span></a><?php endforeach; ?><?php if(!$sessions): ?><p class="muted">No attendance sessions yet.</p><?php endif; ?></div></section></div>
        <section class="card"><h2>Attendance report</h2><form method="get"><input type="hidden" name="r" value="attendance"><div class="form-row"><label>From<input type="date" name="date_from" value="<?=e($_GET['date_from']??'')?>"></label><label>To<input type="date" name="date_to" value="<?=e($_GET['date_to']??'')?>"></label></div><div class="form-row"><label>Academic year<select name="academic_year_id"><option value="">All</option><?php foreach($years as $y):?><option value="<?=(int)$y['id']?>" <?= (string)($y['id'])===(string)($_GET['academic_year_id']??'')?'selected':'' ?>><?=e($y['name'])?></option><?php endforeach;?></select></label><label>Semester<select name="semester_id"><option value="">All</option><?php foreach($semesters as $sm):?><option value="<?=(int)$sm['id']?>" <?= (string)($sm['id'])===(string)($_GET['semester_id']??'')?'selected':'' ?>><?=e($sm['name'])?></option><?php endforeach;?></select></label></div><div class="form-row"><label>Course<select name="course_id"><option value="">All accessible courses</option><?php foreach($courses as $c):?><option value="<?=(int)$c['id']?>" <?= (string)($c['id'])===(string)($_GET['course_id']??'')?'selected':'' ?>><?=e($c['title'])?></option><?php endforeach;?></select></label><label>Batch<select name="batch_id"><option value="">All</option><?php foreach(self::batchesForCourses($courses) as $b):?><option value="<?=(int)$b['id']?>" <?= (string)($b['id'])===(string)($_GET['batch_id']??'')?'selected':'' ?>><?=e($b['name'])?></option><?php endforeach;?></select></label></div><button class="btn">Apply filters</button></form><div class="table-scroll"><table><thead><tr><th>Student</th><th>Course</th><th>Total</th><th>Present</th><th>Late</th><th>Absent</th><th>Excused</th><th>Present %</th></tr></thead><tbody><?php foreach($report as $r):$total=(int)$r['total'];$pct=$total?round(((int)$r['present']+(int)$r['late'])/$total*100,1):0;?><tr><td><?=e($r['student_name'])?></td><td><?=e($r['course_title'])?></td><td><?=$total?></td><td><?=(int)$r['present']?></td><td><?=(int)$r['late']?></td><td><?=(int)$r['absent']?></td><td><?=(int)$r['excused']?></td><td><?=$pct?>%</td></tr><?php endforeach;?></tbody></table></div><?php if(!$report):?><p class="muted">No records match these filters.</p><?php endif;?></section>
        <?php render_page('Attendance', ob_get_clean());

    }
    public static function export(): void
    {
        require_feature('attendance');
        Auth::requireAuth();
        $user=Auth::user();
        $pdo=Database::connection();
        $clauses=[];$args=[];
        if(in_array('student',$user['roles'],true)&&!Auth::can('attendance.manage')){$clauses[]='r.student_id=?';$args[]=(int)$user['id'];}
        else{Auth::requirePermission('attendance.manage');if(in_array('teacher',$user['roles'],true)&&!Auth::hasAnyRole(['administrator','super_admin','hod'])){$clauses[]='c.teacher_id=?';$args[]=(int)$user['id'];}}
        self::appendReportFilters($clauses,$args);
        $where=$clauses?implode(' AND ',$clauses):'1=1';
        $sql="SELECT a.session_date,a.period_label,c.title,u.name,r.status,r.note FROM attendance_records r JOIN attendance_sessions a ON a.id=r.session_id JOIN courses c ON c.id=a.course_id JOIN users u ON u.id=r.student_id WHERE $where ORDER BY a.session_date DESC,c.title,u.name";
        $st=$pdo->prepare($sql);$st->execute($args);
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="attendance-'.gmdate('Ymd').'.csv"');$out=fopen('php://output','wb');fputcsv($out,['Date','Period','Course','Student','Status','Note']);foreach($st->fetchAll() as $row)fputcsv($out,$row);fclose($out);exit;
    }
}
