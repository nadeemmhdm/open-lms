<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\AssessmentEngine;

trait ExamManageTrait
{
    public static function manage(): void
    {
        require_feature('exams'); Auth::requirePermission('exams.manage'); $u=Auth::user(); $pdo=Database::connection(); $error=null;$message=null;
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!Csrf::validate($_POST['_csrf']??null))$error='Security token expired.';else try{$action=(string)($_POST['action']??'');
                if($action==='create'){
                    $courseId=(int)($_POST['course_id']??0);$course=self::course($courseId);if(!$course||!CoursePolicy::canManage($course,$u))throw new \RuntimeException('Course access denied.');$title=trim((string)($_POST['title']??''));if($title==='')throw new \RuntimeException('Exam title is required.');$start=utc_datetime_from_input($_POST['starts_at']??null);$end=utc_datetime_from_input($_POST['ends_at']??null);if(!$start||!$end||$end<=$start)throw new \RuntimeException('Exam start and end times are required.');$now=gmdate('Y-m-d H:i:s');
                    $pdo->prepare("INSERT INTO quizzes (course_id,title,duration_minutes,total_marks,pass_marks,attempt_limit,starts_at,ends_at,status,created_at,assessment_type,negative_marking,randomize_questions,shuffle_options,review_policy,result_publication,instructions,updated_at,require_fullscreen,restrict_copy,log_focus) VALUES (?,?,?,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$courseId,$title,max(1,(int)($_POST['duration_minutes']??60)),max(0,(float)($_POST['pass_marks']??0)),max(1,(int)($_POST['attempt_limit']??1)),$start,$end,'draft',$now,'exam',max(0,(float)($_POST['negative_marking']??0)),bool_post('randomize_questions'),bool_post('shuffle_options'),(string)($_POST['review_policy']??'after_close'),(string)($_POST['result_publication']??'manual'),trim((string)($_POST['instructions']??'')),$now,bool_post('require_fullscreen'),bool_post('restrict_copy'),1]);$message='Exam created.';
                }elseif($action==='attach'){$id=(int)($_POST['exam_id']??0);$exam=AssessmentEngine::quiz($id);if(!$exam||!AssessmentEngine::canManage($exam,$u))throw new \RuntimeException('Exam access denied.');$qid=(int)($_POST['question_id']??0);$st=$pdo->prepare('SELECT q.id,q.marks,qb.course_id FROM questions q JOIN question_banks qb ON qb.id=q.question_bank_id WHERE q.id=?');$st->execute([$qid]);$q=$st->fetch();if(!$q||(int)$q['course_id']!==(int)$exam['course_id'])throw new \RuntimeException('Question must belong to the same course.');$st=$pdo->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=? AND question_id=?');$st->execute([$id,$qid]);if(!(int)$st->fetchColumn()){$order=(int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM quiz_questions WHERE quiz_id='.(int)$id)->fetchColumn();$pdo->prepare('INSERT INTO quiz_questions (quiz_id,question_id,sort_order) VALUES (?,?,?)')->execute([$id,$qid,$order]);}self::recalc($id);$message='Question attached.';
                }elseif($action==='status'){$id=(int)($_POST['exam_id']??0);$exam=AssessmentEngine::quiz($id);if(!$exam||!AssessmentEngine::canManage($exam,$u))throw new \RuntimeException('Exam access denied.');$status=(string)($_POST['status']??'draft');if(!in_array($status,['draft','published','archived'],true))throw new \RuntimeException('Invalid status.');$cnt=$pdo->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?');$cnt->execute([$id]);if($status==='published'&&(int)$cnt->fetchColumn()<1)throw new \RuntimeException('Attach at least one question first.');$pdo->prepare('UPDATE quizzes SET status=?,updated_at=? WHERE id=?')->execute([$status,gmdate('Y-m-d H:i:s'),$id]);$message='Exam status updated.';
                }
            }catch(\Throwable $e){$error=$e instanceof \RuntimeException?$e->getMessage():'Unable to update exam.';}}
        $courses=self::manageableCourses($u);$where=Auth::hasAnyRole(['super_admin','administrator','hod','examiner'])?'1=1':'c.teacher_id=?';$st=$pdo->prepare("SELECT qz.*,c.title course_title,(SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=qz.id) question_count FROM quizzes qz JOIN courses c ON c.id=qz.course_id WHERE qz.assessment_type='exam' AND $where ORDER BY qz.id DESC");$st->execute($where==='1=1'?[]:[(int)$u['id']]);$exams=$st->fetchAll();$selected=(int)($_GET['exam_id']??($exams[0]['id']??0));$available=[];if($selected){$exam=AssessmentEngine::quiz($selected);if($exam&&AssessmentEngine::canManage($exam,$u)){$st=$pdo->prepare('SELECT q.id,q.prompt,q.type,q.marks,qb.name bank_name FROM questions q JOIN question_banks qb ON qb.id=q.question_bank_id WHERE qb.course_id=? ORDER BY q.id DESC');$st->execute([(int)$exam['course_id']]);$available=$st->fetchAll();}}
        ob_start();?><div class="page-head"><div><h1>Exams</h1><p class="muted">Server-timed examination windows, frozen randomized attempts, autosave, browser-event logging and controlled result publication.</p></div><a class="btn" href="<?=e(app_url('?r=assessment-review'))?>">Review attempts</a></div><?php if($message):?><div class="alert alert-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
        <div class="grid-2"><section class="card"><h2>Create exam</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="create"><label>Course<select name="course_id" required><?php foreach($courses as $c):?><option value="<?=(int)$c['id']?>"><?=e($c['title'])?></option><?php endforeach;?></select></label><label>Title<input name="title" required></label><div class="form-row"><label>Duration<input type="number" name="duration_minutes" min="1" value="60"></label><label>Pass marks<input type="number" name="pass_marks" min="0" step="0.25" value="0"></label></div><div class="form-row"><label>Starts<input type="datetime-local" name="starts_at" required></label><label>Ends<input type="datetime-local" name="ends_at" required></label></div><div class="form-row"><label>Attempts<input type="number" name="attempt_limit" min="1" value="1"></label><label>Negative marking<input type="number" name="negative_marking" min="0" step="0.25" value="0"></label></div><label class="check-row"><input type="checkbox" name="randomize_questions" value="1" checked> Randomize questions</label><label class="check-row"><input type="checkbox" name="shuffle_options" value="1" checked> Shuffle options</label><label class="check-row"><input type="checkbox" name="require_fullscreen" value="1"> Request full-screen mode</label><label class="check-row"><input type="checkbox" name="restrict_copy" value="1"> Restrict copy/paste/context menu</label><label>Review policy<select name="review_policy"><option value="after_close">After exam closes</option><option value="after_submit">After submission</option><option value="never">Never show answers</option></select></label><label>Result publication<select name="result_publication"><option value="manual">Manual</option><option value="immediate">Immediate after complete grading</option></select></label><label>Instructions<textarea name="instructions"></textarea></label><button class="btn btn-primary">Create exam</button></form></section><section class="card"><h2>Exams</h2><div class="compact-list"><?php foreach($exams as $x):?><a href="<?=e(app_url('?r=exams&exam_id='.(int)$x['id']))?>"><span><strong><?=e($x['title'])?></strong><small><?=e($x['course_title'].' · '.$x['status'])?></small></span><span class="badge"><?=(int)$x['question_count']?> Q · <?=e($x['total_marks'])?> marks</span></a><?php endforeach;?></div></section></div>
        <?php if($selected&&isset($exam)):?><section class="card"><h2>Configure <?=e($exam['title'])?></h2><div class="grid-2"><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="attach"><input type="hidden" name="exam_id" value="<?=$selected?>"><label>Add question<select name="question_id"><?php foreach($available as $q):?><option value="<?=(int)$q['id']?>"><?=e($q['bank_name'].' · '.mb_strimwidth($q['prompt'],0,90,'…'))?></option><?php endforeach;?></select></label><button class="btn">Attach</button></form><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="exam_id" value="<?=$selected?>"><label>Status<select name="status"><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select></label><button class="btn btn-primary">Update</button></form></div></section><?php endif;?>
        <?php render_page('Exams',ob_get_clean());
    }
    public static function assessments(): void
    {
        Auth::requireAuth();
        $u = Auth::user();
        if (!in_array('student', $u['roles'], true)) {
            $target = feature_enabled('quizzes') ? 'quizzes' : 'exams';
            header('Location: '.app_url('?r='.$target));
            exit;
        }

        $types = self::enabledAssessmentTypes();
        if (!$types) {
            http_response_code(404);
            render_page('Assessments unavailable', '<div class="card">Assessment modules are disabled.</div>');
            return;
        }

        $pdo = Database::connection();
        $marks = implode(',', array_fill(0, count($types), '?'));
        $sql = "SELECT qz.*,c.title course_title,(SELECT COUNT(*) FROM exam_attempts a WHERE a.quiz_id=qz.id AND a.user_id=?) attempt_count
                FROM quizzes qz
                JOIN courses c ON c.id=qz.course_id
                JOIN subjects s ON s.id=c.subject_id
                WHERE qz.status='published' AND c.status='published' AND s.status='active'
                  AND qz.assessment_type IN ($marks)
                  AND ((EXISTS(SELECT 1 FROM enrollments e WHERE e.course_id=c.id AND e.user_id=? AND e.status IN ('active','completed')))
                    OR EXISTS(SELECT 1 FROM course_batches cb JOIN batch_members bm ON bm.batch_id=cb.batch_id WHERE cb.course_id=c.id AND bm.user_id=?))
                ORDER BY COALESCE(qz.starts_at,'0000') DESC";
        $params = array_merge([(int)$u['id']], $types, [(int)$u['id'], (int)$u['id']]);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        $now = gmdate('Y-m-d H:i:s');

        ob_start();?><div class="page-head"><div><h1>Assessments</h1><p class="muted">Quizzes and exams available to you.</p></div></div><div class="card-grid"><?php foreach($rows as $q):$open=(!$q['starts_at']||$now>=$q['starts_at'])&&(!$q['ends_at']||$now<$q['ends_at']);?><article class="card"><span class="badge"><?=e(strtoupper($q['assessment_type']))?></span><h2><?=e($q['title'])?></h2><p class="muted"><?=e($q['course_title'])?> · <?=(int)$q['duration_minutes']?> min · <?=e($q['total_marks'])?> marks</p><p><?=e($q['instructions']??'')?></p><p class="muted">Attempts: <?=(int)$q['attempt_count']?> / <?=(int)$q['attempt_limit']?></p><?php if($open&&(int)$q['attempt_count']<(int)$q['attempt_limit']):?><form method="post" action="<?=e(app_url('?r=assessment-start'))?>"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="quiz_id" value="<?=(int)$q['id']?>"><button class="btn btn-primary">Start / resume</button></form><?php else:?><span class="badge"><?= $open?'Attempt limit reached':'Not currently open' ?></span><?php endif;?></article><?php endforeach;?><?php if(!$rows):?><div class="card empty-state">No published assessments are available.</div><?php endif;?></div><?php render_page('Assessments',ob_get_clean());
    }
    public static function start(): void
    {
        Auth::requireAuth();$u=Auth::user();if($_SERVER['REQUEST_METHOD']!=='POST'||!Csrf::validate($_POST['_csrf']??null)){http_response_code(400);render_page('Invalid request','<div class="card">Invalid start request.</div>');return;}$quiz=AssessmentEngine::quiz((int)($_POST['quiz_id']??0));try{if(!$quiz)throw new \RuntimeException('Assessment not found.');require_feature($quiz['assessment_type']==='exam'?'exams':'quizzes');$a=AssessmentEngine::start($quiz,$u);header('Location: '.app_url('?r=assessment-take&attempt_id='.(int)$a['id']));exit;}catch(\Throwable $e){flash_set('error',$e->getMessage());header('Location: '.app_url('?r=assessments'));exit;}
    }
}
