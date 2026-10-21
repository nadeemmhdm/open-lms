<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use PDO;

final class AssessmentEngine
{
    public static function quiz(int $id): ?array
    {
        $st=Database::connection()->prepare('SELECT qz.*,c.title course_title,c.teacher_id,c.status course_status,s.status subject_status FROM quizzes qz JOIN courses c ON c.id=qz.course_id JOIN subjects s ON s.id=c.subject_id WHERE qz.id=?');
        $st->execute([$id]); return $st->fetch()?:null;
    }

    public static function canManage(array $quiz,array $user): bool
    {
        return Auth::hasAnyRole(['super_admin','administrator','hod','examiner']) || (in_array('teacher',$user['roles'],true) && (int)$quiz['teacher_id']===(int)$user['id']);
    }

    public static function canStudentAccess(array $quiz,array $user): bool
    {
        if(!in_array('student',$user['roles'],true) || $quiz['status']!=='published') return false;
        return CoursePolicy::canViewCourse(['id'=>$quiz['course_id'],'teacher_id'=>$quiz['teacher_id'],'status'=>$quiz['course_status'],'subject_status'=>$quiz['subject_status']],$user);
    }

    public static function start(array $quiz,array $user): array
    {
        if(!self::canStudentAccess($quiz,$user)) throw new \RuntimeException('Assessment access denied.');
        $now=gmdate('Y-m-d H:i:s');
        if(!empty($quiz['starts_at']) && $now < $quiz['starts_at']) throw new \RuntimeException('This assessment has not opened yet.');
        if(!empty($quiz['ends_at']) && $now >= $quiz['ends_at']) throw new \RuntimeException('This assessment is closed.');
        $pdo=Database::connection();
        $st=$pdo->prepare("SELECT * FROM exam_attempts WHERE quiz_id=? AND user_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1");$st->execute([(int)$quiz['id'],(int)$user['id']]);$active=$st->fetch();
        if($active){ if($active['expires_at']>$now) return $active; self::finalize((int)$active['id'],'expired'); }
        $st=$pdo->prepare('SELECT COUNT(*) FROM exam_attempts WHERE quiz_id=? AND user_id=?');$st->execute([(int)$quiz['id'],(int)$user['id']]);$used=(int)$st->fetchColumn();
        if($used >= max(1,(int)$quiz['attempt_limit'])) throw new \RuntimeException('Attempt limit reached.');
        $attemptNo=$used+1;$started=$now;$expires=gmdate('Y-m-d H:i:s',time()+max(1,(int)$quiz['duration_minutes'])*60);if(!empty($quiz['ends_at'])&&$quiz['ends_at']<$expires)$expires=$quiz['ends_at'];
        $pdo->beginTransaction();
        try{
            $pdo->prepare("INSERT INTO exam_attempts (quiz_id,user_id,started_at,expires_at,status,attempt_no,last_activity_at,ip_address,user_agent,grading_status) VALUES (?,?,?,?,'in_progress',?,?,?,?, 'pending')")
                ->execute([(int)$quiz['id'],(int)$user['id'],$started,$expires,$attemptNo,$started,client_ip(),mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)]);
            $aid=(int)$pdo->lastInsertId();
            $st=$pdo->prepare('SELECT q.* FROM quiz_questions qq JOIN questions q ON q.id=qq.question_id WHERE qq.quiz_id=? ORDER BY qq.sort_order,q.id');$st->execute([(int)$quiz['id']]);$questions=$st->fetchAll();
            if(!$questions) throw new \RuntimeException('Assessment has no questions.');
            if((int)$quiz['randomize_questions']===1) shuffle($questions);
            $order=0;foreach($questions as $q){$opts=[];$o=$pdo->prepare('SELECT id FROM question_options WHERE question_id=? ORDER BY sort_order,id');$o->execute([(int)$q['id']]);$opts=array_map('intval',$o->fetchAll(PDO::FETCH_COLUMN));if((int)$quiz['shuffle_options']===1)shuffle($opts);$pdo->prepare('INSERT INTO attempt_questions (attempt_id,question_id,sort_order,option_order_json,max_marks) VALUES (?,?,?,?,?)')->execute([$aid,(int)$q['id'],++$order,json_encode($opts),(float)$q['marks']]);}
            $pdo->commit();Auth::audit('assessment.started','Assessment attempt started',(int)$user['id'],'exam_attempt',$aid);return self::attempt($aid)??throw new \RuntimeException('Attempt creation failed.');
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function attempt(int $id): ?array
    {
        $st=Database::connection()->prepare('SELECT a.*,qz.title,qz.course_id,qz.assessment_type,qz.negative_marking,qz.review_policy,qz.result_publication,qz.ends_at,qz.require_fullscreen,qz.restrict_copy,qz.log_focus,qz.pass_marks,qz.total_marks,c.title course_title,c.teacher_id,c.status course_status,s.status subject_status FROM exam_attempts a JOIN quizzes qz ON qz.id=a.quiz_id JOIN courses c ON c.id=qz.course_id JOIN subjects s ON s.id=c.subject_id WHERE a.id=?');$st->execute([$id]);return $st->fetch()?:null;
    }

    public static function questionsForAttempt(int $attemptId): array
    {
        $pdo=Database::connection();$st=$pdo->prepare('SELECT aq.sort_order,aq.option_order_json,aq.max_marks,q.* FROM attempt_questions aq JOIN questions q ON q.id=aq.question_id WHERE aq.attempt_id=? ORDER BY aq.sort_order');$st->execute([$attemptId]);$rows=$st->fetchAll();
        foreach($rows as &$q){$ids=json_decode((string)$q['option_order_json'],true);$q['options']=[];if(is_array($ids)&&$ids){$marks=implode(',',array_fill(0,count($ids),'?'));$os=$pdo->prepare("SELECT id,option_text,is_correct FROM question_options WHERE id IN ($marks)");$os->execute(array_map('intval',$ids));$map=[];foreach($os->fetchAll() as $o)$map[(int)$o['id']]=$o;foreach($ids as $oid)if(isset($map[(int)$oid]))$q['options'][]=$map[(int)$oid];}}
        unset($q);return $rows;
    }

    public static function saveAnswers(int $attemptId,array $payload,int $userId): void
    {
        $attempt=self::attempt($attemptId);if(!$attempt||(int)$attempt['user_id']!==$userId||$attempt['status']!=='in_progress')throw new \RuntimeException('Attempt is not active.');
        if(gmdate('Y-m-d H:i:s') >= $attempt['expires_at']){self::finalize($attemptId,'expired');throw new \RuntimeException('Assessment time expired.');}
        $allowed=array_fill_keys(array_map(fn($q)=>(int)$q['id'],self::questionsForAttempt($attemptId)),true);$pdo=Database::connection();$now=gmdate('Y-m-d H:i:s');
        foreach($allowed as $qid=>$_){$text=trim((string)($payload['answer'][$qid]??$payload['answer_text'][$qid]??''));$selected=$payload['answer_multi'][$qid]??null;if($selected===null&&isset($payload['answer'][$qid])&&ctype_digit((string)$payload['answer'][$qid]))$selected=[(int)$payload['answer'][$qid]];$selected=is_array($selected)?array_values(array_unique(array_map('intval',$selected))):[];$json=$selected?json_encode($selected):null;$st=$pdo->prepare('SELECT id FROM exam_answers WHERE attempt_id=? AND question_id=?');$st->execute([$attemptId,$qid]);if($st->fetchColumn())$pdo->prepare('UPDATE exam_answers SET answer_text=?,selected_options_json=?,saved_at=? WHERE attempt_id=? AND question_id=?')->execute([$text,$json,$now,$attemptId,$qid]);elseif($text!==''||$json!==null)$pdo->prepare('INSERT INTO exam_answers (attempt_id,question_id,answer_text,selected_options_json,saved_at) VALUES (?,?,?,?,?)')->execute([$attemptId,$qid,$text,$json,$now]);}
        $pdo->prepare('UPDATE exam_attempts SET last_activity_at=? WHERE id=?')->execute([$now,$attemptId]);
    }

    public static function logEvent(int $attemptId,int $userId,string $type,array $metadata=[]): void
    {
        $attempt=self::attempt($attemptId);if(!$attempt||(int)$attempt['user_id']!==$userId||$attempt['status']!=='in_progress')return;$allowed=['visibility_hidden','visibility_visible','window_blur','window_focus','fullscreen_exit','fullscreen_enter','copy_attempt','paste_attempt','contextmenu_attempt','autosave_error','reconnected'];if(!in_array($type,$allowed,true))return;Database::connection()->prepare('INSERT INTO exam_events (attempt_id,event_type,metadata_json,created_at) VALUES (?,?,?,?)')->execute([$attemptId,$type,json_encode($metadata,JSON_UNESCAPED_SLASHES),gmdate('Y-m-d H:i:s')]);
    }

    public static function finalize(int $attemptId,string $reason='submitted'): array
    {
        $pdo=Database::connection();$attempt=self::attempt($attemptId);if(!$attempt)throw new \RuntimeException('Attempt not found.');if($attempt['status']!=='in_progress')return $attempt;
        $questions=self::questionsForAttempt($attemptId);$st=$pdo->prepare('SELECT * FROM exam_answers WHERE attempt_id=?');$st->execute([$attemptId]);$answers=[];foreach($st->fetchAll() as $a)$answers[(int)$a['question_id']]=$a;$score=0.0;$pending=false;
        $pdo->beginTransaction();try{foreach($questions as $q){$qid=(int)$q['id'];$a=$answers[$qid]??null;$marks=(float)$q['max_marks'];$awarded=0.0;$correct=null;if($q['type']==='descriptive'){$pending=true;$awarded=$a&&$a['awarded_marks']!==null?(float)$a['awarded_marks']:0.0;}else{[$correct,$awarded]=self::autoGrade($q,$a,$marks,(float)$attempt['negative_marking']);}$score+=$awarded;if($a)$pdo->prepare('UPDATE exam_answers SET awarded_marks=?,is_correct=? WHERE id=?')->execute([$awarded,$correct===null?null:($correct?1:0),(int)$a['id']]);else$pdo->prepare('INSERT INTO exam_answers (attempt_id,question_id,answer_text,awarded_marks,saved_at,is_correct) VALUES (?,?,NULL,?,?,?)')->execute([$attemptId,$qid,$awarded,gmdate('Y-m-d H:i:s'),$correct===null?null:($correct?1:0)]);}
            $score=max(0,$score);$status=$pending?'pending_manual':'graded';$submitted=gmdate('Y-m-d H:i:s');$publish=(!$pending&&$attempt['result_publication']==='immediate')?$submitted:null;$pdo->prepare("UPDATE exam_attempts SET submitted_at=?,status='submitted',score=?,submitted_reason=?,grading_status=?,results_published_at=?,last_activity_at=? WHERE id=?")->execute([$submitted,$score,$reason,$status,$publish,$submitted,$attemptId]);$pdo->commit();if(!$pending&&$attempt['result_publication']==='immediate'){self::upsertGrade($attemptId);notify_user((int)$attempt['user_id'],'Assessment result published',$attempt['title'].' result is available.','assessment',app_url('?r=assessment-results&attempt_id='.$attemptId));}Auth::audit('assessment.submitted','Assessment submitted',(int)$attempt['user_id'],'exam_attempt',$attemptId);return self::attempt($attemptId)??$attempt;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function manualGrade(int $attemptId,array $marks,array $feedback,int $graderId,bool $publish): void
    {
        $pdo=Database::connection();$attempt=self::attempt($attemptId);if(!$attempt)throw new \RuntimeException('Attempt not found.');$questions=self::questionsForAttempt($attemptId);$total=0.0;$pdo->beginTransaction();try{foreach($questions as $q){$qid=(int)$q['id'];$st=$pdo->prepare('SELECT id,awarded_marks FROM exam_answers WHERE attempt_id=? AND question_id=?');$st->execute([$attemptId,$qid]);$a=$st->fetch();$award=$a&&$a['awarded_marks']!==null?(float)$a['awarded_marks']:0.0;if($q['type']==='descriptive'){$award=max(0,min((float)$q['max_marks'],(float)($marks[$qid]??0)));$fb=mb_substr(trim((string)($feedback[$qid]??'')),0,2000);if($a)$pdo->prepare('UPDATE exam_answers SET awarded_marks=?,feedback=?,graded_by=?,graded_at=? WHERE id=?')->execute([$award,$fb,$graderId,gmdate('Y-m-d H:i:s'),(int)$a['id']]);} $total+=$award;}$shouldPublish=$publish||$attempt['result_publication']==='immediate';$pub=$shouldPublish?gmdate('Y-m-d H:i:s'):($attempt['results_published_at']??null);$pdo->prepare("UPDATE exam_attempts SET score=?,grading_status='graded',results_published_at=? WHERE id=?")->execute([max(0,$total),$pub,$attemptId]);$pdo->commit();if($shouldPublish){self::upsertGrade($attemptId);notify_user((int)$attempt['user_id'],'Assessment result published',$attempt['title'].' result is available.','assessment',app_url('?r=assessment-results&attempt_id='.$attemptId));}Auth::audit('assessment.graded','Assessment manually graded',$graderId,'exam_attempt',$attemptId);}catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function publishResult(int $attemptId,int $actorId): void
    {
        $a=self::attempt($attemptId);if(!$a||$a['grading_status']!=='graded')throw new \RuntimeException('Complete grading before publishing.');if(empty($a['results_published_at'])){Database::connection()->prepare('UPDATE exam_attempts SET results_published_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s'),$attemptId]);self::upsertGrade($attemptId);notify_user((int)$a['user_id'],'Assessment result published',$a['title'].' result is available.','assessment',app_url('?r=assessment-results&attempt_id='.$attemptId));}Auth::audit('assessment.result_published','Assessment result published',$actorId,'exam_attempt',$attemptId);
    }

    private static function autoGrade(array $q,?array $a,float $marks,float $negative): array
    {
        if(!$a)return[false,0.0];$type=$q['type'];$correct=false;
        if(in_array($type,['mcq','multiple_select'],true)){$given=json_decode((string)($a['selected_options_json']??'[]'),true);$given=is_array($given)?array_values(array_unique(array_map('intval',$given))):[];$right=[];foreach($q['options'] as $o)if((int)$o['is_correct']===1)$right[]=(int)$o['id'];sort($given);sort($right);$correct=$given===$right&&$right!==[];}
        elseif($type==='true_false'){$correct=strtolower(trim((string)$a['answer_text']))===strtolower(trim((string)$q['answer_text']));}
        elseif(in_array($type,['fill_blank','short_answer'],true)){$given=mb_strtolower(trim(preg_replace('/\s+/u',' ',(string)$a['answer_text'])??''));$answers=array_map(fn($x)=>mb_strtolower(trim(preg_replace('/\s+/u',' ',$x)??'')),explode('||',(string)$q['answer_text']));$correct=$given!==''&&in_array($given,$answers,true);}
        elseif($type==='matching'){$expected=json_decode((string)$q['answer_text'],true);$given=self::parseMatching((string)$a['answer_text']);if(is_array($expected))ksort($expected);$correct=is_array($expected)&&$given===$expected;}
        else return[null,0.0];
        return[$correct,$correct?$marks:-$negative];
    }

    private static function parseMatching(string $text): array
    {
        $map=[];foreach(preg_split('/\R+/',trim($text))?:[] as $line){if(!str_contains($line,'=>'))continue;[$l,$r]=array_map('trim',explode('=>',$line,2));if($l!==''&&$r!=='')$map[$l]=$r;}ksort($map);return $map;
    }

    private static function upsertGrade(int $attemptId): void
    {
        $a=self::attempt($attemptId);if(!$a||$a['grading_status']!=='graded')return;$pdo=Database::connection();$type=$a['assessment_type']==='exam'?'exam':'quiz';$st=$pdo->prepare('SELECT id FROM grades WHERE user_id=? AND item_type=? AND item_id=?');$st->execute([(int)$a['user_id'],$type,(int)$a['quiz_id']]);$id=$st->fetchColumn();$now=gmdate('Y-m-d H:i:s');if($id)$pdo->prepare('UPDATE grades SET marks=?,max_marks=?,graded_at=? WHERE id=?')->execute([(float)$a['score'],(float)$a['total_marks'],$now,(int)$id]);else$pdo->prepare('INSERT INTO grades (user_id,course_id,item_type,item_id,marks,max_marks,graded_by,graded_at) VALUES (?,?,?,?,?,?,NULL,?)')->execute([(int)$a['user_id'],(int)$a['course_id'],$type,(int)$a['quiz_id'],(float)$a['score'],(float)$a['total_marks'],$now]);
    }
}
