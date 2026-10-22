<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\BackupService;

trait SystemTimetableTrait
{
    public static function timetable(): void
    {
        require_feature('timetable');
        Auth::requireAuth();
        $u = Auth::user();
        $pdo = Database::connection();
        $manage = Auth::can('academics.manage') || Auth::can('courses.manage');
        $global = Auth::hasAnyRole(['super_admin','administrator','hod']);
        $uid = (int)$u['id'];
        $error = $message = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $manage) {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Security token expired.';
            } else {
                try {
                    $action = (string)($_POST['action'] ?? '');
                    $cid = (int)($_POST['course_id'] ?? 0);
                    $course = self::course($cid);
                    if (!$course || !CoursePolicy::canManage($course, $u)) throw new \RuntimeException('Course access denied.');

                    if ($action === 'timetable') {
                        $batchId = (int)($_POST['batch_id'] ?? 0) ?: null;
                        if ($batchId !== null) {
                            $st = $pdo->prepare('SELECT COUNT(*) FROM courses c JOIN class_subjects cs ON cs.subject_id=c.subject_id JOIN batches b ON b.class_id=cs.class_id WHERE c.id=? AND b.id=?');
                            $st->execute([$cid,$batchId]);
                            if (!(int)$st->fetchColumn()) throw new \RuntimeException('The selected batch class does not have this course subject.');
                        }
                        $weekday = max(1,min(7,(int)($_POST['weekday'] ?? 1)));
                        $start = (string)($_POST['starts_at'] ?? '');
                        $end = (string)($_POST['ends_at'] ?? '');
                        if (!preg_match('/^\d{2}:\d{2}$/',$start) || !preg_match('/^\d{2}:\d{2}$/',$end) || $end <= $start) throw new \RuntimeException('Valid start/end times are required.');
                        $room = mb_substr(trim((string)($_POST['room_label'] ?? '')),0,120);
                        $pdo->prepare('INSERT INTO timetable_entries (course_id,batch_id,weekday,starts_at,ends_at,room_label,created_by) VALUES (?,?,?,?,?,?,?)')->execute([$cid,$batchId,$weekday,$start,$end,$room,(int)$u['id']]);
                        $message = 'Timetable entry added.';
                    } elseif ($action === 'live') {
                        if (!feature_enabled('live_classes')) throw new \RuntimeException('Live classes module is disabled.');
                        $url = safe_external_url((string)($_POST['join_url'] ?? ''));
                        if (!$url) throw new \RuntimeException('Valid HTTPS/HTTP class link required.');
                        $start = utc_datetime_from_input($_POST['live_starts_at'] ?? null);
                        $end = utc_datetime_from_input($_POST['live_ends_at'] ?? null);
                        if (!$start) throw new \RuntimeException('Live class start time required.');
                        if ($end && $end <= $start) throw new \RuntimeException('Live class end time must be after its start time.');
                        $title = mb_substr(trim((string)($_POST['live_title'] ?? 'Live class')),0,180);
                        if ($title === '') $title = 'Live class';
                        $pdo->prepare('INSERT INTO live_classes (course_id,title,join_url,starts_at,ends_at,created_by) VALUES (?,?,?,?,?,?)')->execute([$cid,$title,$url,$start,$end,(int)$u['id']]);
                        notify_course_students($cid,'Live class scheduled',$title.' · '.format_app_datetime($start).' '.app_timezone(),'live_class',app_url('?r=timetable'));
                        $message = 'Live class scheduled.';
                    }
                } catch (\Throwable $e) {
                    $error = $e instanceof \RuntimeException ? $e->getMessage() : 'Unable to update timetable.';
                }
            }
        }

        $courses = $manage ? self::manageableCourses($u) : [];
        if ($manage) {
            if ($global) {
                $batches = $pdo->query('SELECT DISTINCT b.id,b.name FROM batches b JOIN class_subjects cs ON cs.class_id=b.class_id ORDER BY b.name')->fetchAll();
            } else {
                $st = $pdo->prepare('SELECT DISTINCT b.id,b.name FROM batches b JOIN class_subjects cs ON cs.class_id=b.class_id JOIN courses c ON c.subject_id=cs.subject_id WHERE c.teacher_id=? ORDER BY b.name');$st->execute([$uid]);$batches=$st->fetchAll();
            }
        } else $batches = [];

        if ($global) {
            $entryWhere = '1=1';$entryArgs=[];$liveWhere='1=1';$liveArgs=[];
        } elseif (in_array('student',$u['roles'],true)) {
            $courseAccess = "EXISTS(SELECT 1 FROM class_subjects cs JOIN class_members cm ON cm.class_id=cs.class_id WHERE cs.subject_id=c.subject_id AND cm.user_id=?)";
            $entryWhere = $courseAccess." AND (t.batch_id IS NULL OR EXISTS(SELECT 1 FROM batch_members bm2 WHERE bm2.batch_id=t.batch_id AND bm2.user_id=?))";
            $entryArgs = [$uid,$uid];$liveWhere=$courseAccess;$liveArgs=[$uid];
        } elseif (in_array('teacher',$u['roles'],true)) {
            $entryWhere='c.teacher_id=?';$entryArgs=[$uid];$liveWhere='c.teacher_id=?';$liveArgs=[$uid];
        } else {
            $entryWhere='1=0';$entryArgs=[];$liveWhere='1=0';$liveArgs=[];
        }

        $st=$pdo->prepare("SELECT t.*,c.title course_title,b.name batch_name FROM timetable_entries t JOIN courses c ON c.id=t.course_id LEFT JOIN batches b ON b.id=t.batch_id WHERE $entryWhere ORDER BY t.weekday,t.starts_at");$st->execute($entryArgs);$entries=$st->fetchAll();
        $live=[];if(feature_enabled('live_classes')){$st=$pdo->prepare("SELECT l.*,c.title course_title FROM live_classes l JOIN courses c ON c.id=l.course_id WHERE $liveWhere ORDER BY l.starts_at");$st->execute($liveArgs);$live=$st->fetchAll();}

        ob_start(); ?>
        <div class="page-head"><div><h1>Timetable & live classes</h1><p class="muted">Weekly schedule and optional external live class links.</p></div></div>
        <?php if($message):?><div class="alert alert-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
        <?php if($manage):?><div class="grid-2"><section class="card"><h2>Add timetable entry</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="timetable"><label>Course<select name="course_id" required><?php foreach($courses as $c):?><option value="<?=(int)$c['id']?>"><?=e($c['title'])?></option><?php endforeach;?></select></label><label>Batch<select name="batch_id"><option value="">All enrolled learners</option><?php foreach($batches as $b):?><option value="<?=(int)$b['id']?>"><?=e($b['name'])?></option><?php endforeach;?></select><small>The server verifies that the batch class has the selected course subject.</small></label><div class="form-row"><label>Weekday<select name="weekday"><?php foreach(['Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6,'Sun'=>7] as $d=>$v):?><option value="<?=$v?>"><?=$d?></option><?php endforeach;?></select></label><label>Room<input name="room_label" maxlength="120"></label></div><div class="form-row"><label>Starts<input type="time" name="starts_at" required></label><label>Ends<input type="time" name="ends_at" required></label></div><button class="btn">Add</button></form></section>
        <?php if(feature_enabled('live_classes')):?><section class="card"><h2>Schedule live class</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="live"><label>Course<select name="course_id" required><?php foreach($courses as $c):?><option value="<?=(int)$c['id']?>"><?=e($c['title'])?></option><?php endforeach;?></select></label><label>Title<input name="live_title" maxlength="180" required></label><label>Join URL<input type="url" name="join_url" required></label><div class="form-row"><label>Starts (<?=e(app_timezone())?>)<input type="datetime-local" name="live_starts_at" required></label><label>Ends (<?=e(app_timezone())?>)<input type="datetime-local" name="live_ends_at"></label></div><button class="btn">Schedule</button></form></section><?php endif;?></div><?php endif;?>
        <section class="card"><h2>Weekly timetable</h2><div class="compact-list"><?php foreach($entries as $e1):?><div><span><strong><?=e($e1['course_title'])?></strong><small><?=e('Day '.$e1['weekday'].' · '.$e1['starts_at'].'–'.$e1['ends_at'].' · '.($e1['room_label']?:'No room'))?></small></span><span class="badge"><?=e($e1['batch_name']??'All')?></span></div><?php endforeach;?><?php if(!$entries):?><p class="muted">No timetable entries available.</p><?php endif;?></div></section>
        <?php if($live):?><section class="card"><h2>Upcoming live classes</h2><div class="compact-list"><?php foreach($live as $l):?><a href="<?=e($l['join_url'])?>" target="_blank" rel="noopener noreferrer"><span><strong><?=e($l['title'])?></strong><small><?=e($l['course_title'].' · '.format_app_datetime((string)$l['starts_at']).' '.app_timezone())?></small></span><span class="badge">Join</span></a><?php endforeach;?></div></section><?php endif;?>
        <?php render_page('Timetable',ob_get_clean());
    }
}
