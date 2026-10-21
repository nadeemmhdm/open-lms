<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;

trait AttendanceWriteTrait
{
    private static function markSession(int $sessionId, array $user, ?string $incomingError): void
    {
        $pdo = Database::connection();
        $st = $pdo->prepare('SELECT a.*,c.title course_title,c.teacher_id,b.name batch_name FROM attendance_sessions a INNER JOIN courses c ON c.id=a.course_id LEFT JOIN batches b ON b.id=a.batch_id WHERE a.id=?');
        $st->execute([$sessionId]); $session=$st->fetch();
        if (!$session || !CoursePolicy::canManage($session + ['id'=>$session['course_id'],'status'=>'published','subject_status'=>'active'], $user)) { http_response_code(404); render_page('Not found','<div class="card">Attendance session unavailable.</div>'); return; }
        $error=$incomingError; $message=null;
        $students=self::studentsForSession($session);
        if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='save_marks') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) $error='Security token expired.';
            elseif (!empty($session['locked_at'])) $error='This attendance session is locked.';
            else {
                try {
                    $statuses=(array)($_POST['status'] ?? []); $notes=(array)($_POST['note'] ?? []); $reason=trim((string)($_POST['correction_reason'] ?? ''));
                    $pdo->beginTransaction(); $changed=false; $notifyAbsent=[];
                    foreach($students as $student){$sid=(int)$student['id'];$status=(string)($statuses[$sid]??'');if(!in_array($status,['present','absent','late','excused'],true))continue;$q=$pdo->prepare('SELECT status FROM attendance_records WHERE session_id=? AND student_id=?');$q->execute([$sessionId,$sid]);$old=$q->fetchColumn();$note=mb_substr(trim((string)($notes[$sid]??'')),0,250);
                        if($old!==false){if($old!==$status){if($reason==='')throw new \RuntimeException('A correction reason is required when changing an existing attendance status.');$pdo->prepare('INSERT INTO attendance_corrections (session_id,student_id,old_status,new_status,reason,corrected_by,created_at) VALUES (?,?,?,?,?,?,?)')->execute([$sessionId,$sid,$old,$status,mb_substr($reason,0,500),(int)$user['id'],gmdate('Y-m-d H:i:s')]);$changed=true;}$pdo->prepare('UPDATE attendance_records SET status=?,note=? WHERE session_id=? AND student_id=?')->execute([$status,$note,$sessionId,$sid]);}
                        else{$pdo->prepare('INSERT INTO attendance_records (session_id,student_id,status,note) VALUES (?,?,?,?)')->execute([$sessionId,$sid,$status,$note]);$changed=true;}if($status==='absent'&&$old!=='absent')$notifyAbsent[]=$sid;
                    }
                    $pdo->commit();if($changed){Auth::audit('attendance.saved','Attendance records saved',(int)$user['id'],'attendance_session',$sessionId);foreach(array_values(array_unique($notifyAbsent)) as $uid)notify_user((int)$uid,'Attendance marked absent','You were marked absent for '.$session['course_title'].' on '.$session['session_date'].' ('.$session['period_label'].').','attendance',app_url('?r=attendance'));}$message='Attendance saved.';
                }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=self::friendly($e);}
            }
        }
        if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='lock') {
            if (Csrf::validate($_POST['_csrf'] ?? null)) {$pdo->prepare('UPDATE attendance_sessions SET locked_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s'),$sessionId]);$session['locked_at']=gmdate('Y-m-d H:i:s');$message='Session locked.';}
        }
        $records=[];$q=$pdo->prepare('SELECT * FROM attendance_records WHERE session_id=?');$q->execute([$sessionId]);foreach($q->fetchAll() as $r)$records[(int)$r['student_id']]=$r;
        ob_start(); ?>
        <div class="page-head"><div><h1><?= e($session['course_title']) ?> attendance</h1><p class="muted"><?= e($session['session_date'].' · '.$session['period_label'].' · '.($session['batch_name']?:'All enrolled students')) ?></p></div><a class="btn" href="<?= e(app_url('?r=attendance')) ?>">Back</a></div><?php if($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <section class="card table-card"><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="save_marks"><div class="table-scroll"><table><thead><tr><th>Student</th><th>Status</th><th>Note</th></tr></thead><tbody><?php foreach($students as $st): $r=$records[(int)$st['id']]??null; ?><tr><td><strong><?= e($st['name']) ?></strong><small><?= e($st['email']) ?></small></td><td><select name="status[<?= (int)$st['id'] ?>]" <?= $session['locked_at']?'disabled':'' ?>><option value="">—</option><?php foreach(['present','absent','late','excused'] as $v): ?><option value="<?= $v ?>" <?= ($r['status']??'')===$v?'selected':'' ?>><?= ucfirst($v) ?></option><?php endforeach; ?></select></td><td><input name="note[<?= (int)$st['id'] ?>]" value="<?= e($r['note']??'') ?>" <?= $session['locked_at']?'disabled':'' ?>></td></tr><?php endforeach; ?></tbody></table></div><?php if(!$session['locked_at']): ?><label>Correction reason <small>Required only when changing previously saved status.</small><input name="correction_reason" maxlength="500"></label><button class="btn btn-primary">Save attendance</button><?php endif; ?></form><?php if(!$session['locked_at']): ?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="lock"><button class="btn">Lock session</button></form><?php else: ?><span class="badge badge-ok">Locked</span><?php endif; ?></section>
        <?php render_page('Attendance session', ob_get_clean());
    }
    private static function studentView(array $user): void
    {
        $pdo=Database::connection();$st=$pdo->prepare("SELECT c.title,COUNT(r.student_id) total,SUM(CASE WHEN r.status='present' THEN 1 ELSE 0 END) present,SUM(CASE WHEN r.status='late' THEN 1 ELSE 0 END) late,SUM(CASE WHEN r.status='absent' THEN 1 ELSE 0 END) absent,SUM(CASE WHEN r.status='excused' THEN 1 ELSE 0 END) excused FROM attendance_records r JOIN attendance_sessions a ON a.id=r.session_id JOIN courses c ON c.id=a.course_id WHERE r.student_id=? GROUP BY c.id,c.title ORDER BY c.title");$st->execute([(int)$user['id']]);$rows=$st->fetchAll();ob_start();?><div class="page-head"><div><h1>My attendance</h1><p class="muted">Attendance percentages by course.</p></div><a class="btn" href="<?= e(app_url('?r=attendance-export')) ?>">Export CSV</a></div><div class="card-grid"><?php foreach($rows as $r):$pct=(int)$r['total']?round(((int)$r['present']+(int)$r['late'])/(int)$r['total']*100):0;?><article class="card"><h2><?= e($r['title']) ?></h2><strong class="metric-lg"><?= $pct ?>%</strong><p class="muted"><?= (int)$r['present'] ?> present · <?= (int)$r['late'] ?> late · <?= (int)$r['absent'] ?> absent · <?= (int)$r['excused'] ?> excused</p></article><?php endforeach; ?><?php if(!$rows): ?><div class="card empty-state">No attendance records yet.</div><?php endif; ?></div><?php render_page('My attendance',ob_get_clean());
    }
}
