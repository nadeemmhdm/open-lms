<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use PDO;

trait AssessmentQuestionBankTrait
{
    public static function questionBank(): void
    {
        require_feature('question_bank'); Auth::requirePermission('questions.manage'); $user=Auth::user(); $pdo=Database::connection(); $error=null;$message=null;
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!Csrf::validate($_POST['_csrf']??null))$error='Security token expired.';else try{
                $action=(string)($_POST['action']??'');
                if($action==='create_bank'){
                    $courseId=(int)($_POST['course_id']??0);$course=self::course($courseId);if(!$course||!CoursePolicy::canManage($course,$user))throw new \RuntimeException('Course access denied.');$name=trim((string)($_POST['name']??''));if($name==='')throw new \RuntimeException('Question bank name is required.');$pdo->prepare('INSERT INTO question_banks (course_id,name,created_by,created_at) VALUES (?,?,?,?)')->execute([$courseId,mb_substr($name,0,160),(int)$user['id'],gmdate('Y-m-d H:i:s')]);$message='Question bank created.';
                }elseif($action==='add_question'){
                    $bankId=(int)($_POST['bank_id']??0);$bank=self::bank($bankId);if(!$bank||!self::canManageBank($bank,$user))throw new \RuntimeException('Question bank access denied.');$type=(string)($_POST['type']??'');if(!in_array($type,self::TYPES,true))throw new \RuntimeException('Unsupported question type.');$prompt=trim((string)($_POST['prompt']??''));$marks=(float)($_POST['marks']??1);if($prompt===''||$marks<=0)throw new \RuntimeException('Question and positive marks are required.');$answer=trim((string)($_POST['answer_text']??''));$explanation=trim((string)($_POST['explanation']??''));
                    if($type==='true_false'&&!in_array(strtolower($answer),['true','false'],true))throw new \RuntimeException('True/False answer must be true or false.');
                    $pdo->beginTransaction();$pdo->prepare('INSERT INTO questions (question_bank_id,type,prompt,answer_text,marks,created_at,updated_at,explanation) VALUES (?,?,?,?,?,?,?,?)')->execute([$bankId,$type,$prompt,$answer,$marks,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),$explanation]);$qid=(int)$pdo->lastInsertId();
                    if(in_array($type,['mcq','multiple_select'],true))self::saveOptions($pdo,$qid,(string)($_POST['options_text']??''));
                    if($type==='matching'){self::saveMatching($pdo,$qid,(string)($_POST['options_text']??''));}
                    $pdo->commit();Auth::audit('question.created','Question added',(int)$user['id'],'question',$qid);$message='Question added.';
                }elseif($action==='delete_question'){
                    $qid=(int)($_POST['question_id']??0);$q=self::question($qid);if(!$q||!self::canManageBank($q,$user))throw new \RuntimeException('Question access denied.');$pdo->prepare('DELETE FROM questions WHERE id=?')->execute([$qid]);$message='Question deleted.';
                }
            }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof \RuntimeException?$e->getMessage():'Unable to save question bank.';}
        }
        $courses=self::manageableCourses($user);$banks=self::banks($user);$selected=(int)($_GET['bank_id']??($banks[0]['id']??0));$questions=[];if($selected){$st=$pdo->prepare('SELECT q.*,(SELECT COUNT(*) FROM question_options qo WHERE qo.question_id=q.id) option_count FROM questions q WHERE q.question_bank_id=? ORDER BY q.id DESC');$st->execute([$selected]);$questions=$st->fetchAll();}
        ob_start();?><div class="page-head"><div><h1>Question bank</h1><p class="muted">Reusable questions for quizzes and exams. Teacher review remains required before publication.</p></div></div><?php if($message):?><div class="alert alert-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
        <div class="grid-2"><section class="card"><h2>Create bank</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="create_bank"><label>Course<select name="course_id" required><?php foreach($courses as $c):?><option value="<?=(int)$c['id']?>"><?=e($c['title'])?></option><?php endforeach;?></select></label><label>Bank name<input name="name" required></label><button class="btn btn-primary">Create</button></form><hr><h2>Banks</h2><div class="compact-list"><?php foreach($banks as $b):?><a href="<?=e(app_url('?r=questions&bank_id='.(int)$b['id']))?>"><span><strong><?=e($b['name'])?></strong><small><?=e($b['course_title'])?></small></span><span class="badge"><?=(int)$b['question_count']?> questions</span></a><?php endforeach;?></div></section>
        <section class="card"><h2>Add question</h2><?php if($selected):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="add_question"><input type="hidden" name="bank_id" value="<?=$selected?>"><div class="form-row"><label>Type<select name="type" data-question-type><?php foreach(self::TYPES as $t):?><option value="<?=e($t)?>"><?=e(ucwords(str_replace('_',' ',$t)))?></option><?php endforeach;?></select></label><label>Marks<input type="number" name="marks" min="0.25" step="0.25" value="1" required></label></div><label>Question<textarea name="prompt" required rows="3"></textarea></label><label>Correct answer / expected text<textarea name="answer_text" rows="2" placeholder="For True/False enter true or false. For fill/short answer enter expected text."></textarea></label><label>Options / matching pairs<textarea name="options_text" rows="5" placeholder="MCQ: prefix correct options with *&#10;*Paris&#10;London&#10;&#10;Matching: Term => Definition"></textarea></label><label>Explanation<textarea name="explanation" rows="2"></textarea></label><button class="btn btn-primary">Add question</button></form><?php else:?><p class="muted">Create a question bank first.</p><?php endif;?></section></div>
        <section class="card table-card"><h2>Questions</h2><div class="table-scroll"><table><thead><tr><th>Question</th><th>Type</th><th>Marks</th><th>Options</th><th></th></tr></thead><tbody><?php foreach($questions as $q):?><tr><td><?=e(mb_strimwidth($q['prompt'],0,100,'…'))?></td><td><span class="badge"><?=e($q['type'])?></span></td><td><?=e($q['marks'])?></td><td><?=(int)$q['option_count']?></td><td><form method="post" class="inline-form" onsubmit="return confirm('Delete this question?')"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="delete_question"><input type="hidden" name="question_id" value="<?=(int)$q['id']?>"><button class="btn btn-small">Delete</button></form></td></tr><?php endforeach;?></tbody></table></div></section>
        <?php render_page('Question bank',ob_get_clean());
    }
}
