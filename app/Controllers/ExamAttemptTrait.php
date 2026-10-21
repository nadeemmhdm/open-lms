<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\AssessmentEngine;

trait ExamAttemptTrait
{
    public static function take(): void
    {
        Auth::requireAuth();$u=Auth::user();$id=(int)($_GET['attempt_id']??0);$a=AssessmentEngine::attempt($id);if(!$a||(int)$a['user_id']!==(int)$u['id']){http_response_code(404);render_page('Not found','<div class="card">Attempt unavailable.</div>');return;}require_feature($a['assessment_type']==='exam'?'exams':'quizzes');if($a['status']!=='in_progress'){header('Location: '.app_url('?r=assessment-results&attempt_id='.$id));exit;}if(gmdate('Y-m-d H:i:s')>=$a['expires_at']){AssessmentEngine::finalize($id,'expired');header('Location: '.app_url('?r=assessment-results&attempt_id='.$id));exit;}
        $qs=AssessmentEngine::questionsForAttempt($id);$pdo=Database::connection();$st=$pdo->prepare('SELECT * FROM exam_answers WHERE attempt_id=?');$st->execute([$id]);$saved=[];foreach($st->fetchAll() as $x)$saved[(int)$x['question_id']]=$x;ob_start();?><div class="page-head exam-head"><div><h1><?=e($a['title'])?></h1><p class="muted"><?=e($a['course_title'])?> · Attempt <?=(int)$a['attempt_no']?></p></div><div class="exam-timer" data-expires-at="<?=e(gmdate('c',strtotime($a['expires_at'].' UTC')))?>">--:--</div></div><div class="alert alert-danger exam-expired" hidden>Time expired. Submitting…</div><form method="post" action="<?=e(app_url('?r=assessment-submit'))?>" id="assessment-form" data-attempt-id="<?=$id?>" data-autosave-url="<?=e(app_url('?r=assessment-save'))?>" data-event-url="<?=e(app_url('?r=assessment-event'))?>" data-fullscreen="<?=(int)$a['require_fullscreen']?>" data-restrict-copy="<?=(int)$a['restrict_copy']?>"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="attempt_id" value="<?=$id?>"><div class="assessment-list"><?php foreach($qs as $i=>$q):$ans=$saved[(int)$q['id']]??null;$sel=json_decode((string)($ans['selected_options_json']??'[]'),true);$sel=is_array($sel)?array_map('intval',$sel):[];?><section class="card question-card"><div class="question-meta"><span>Question <?=$i+1?></span><span><?=e($q['max_marks'])?> marks</span></div><h2><?=e($q['prompt'])?></h2><?php if($q['type']==='mcq'):foreach($q['options'] as $o):?><label class="choice"><input type="radio" name="answer[<?=(int)$q['id']?>]" value="<?=(int)$o['id']?>" <?=in_array((int)$o['id'],$sel,true)?'checked':''?>> <?=e($o['option_text'])?></label><?php endforeach;elseif($q['type']==='multiple_select'):foreach($q['options'] as $o):?><label class="choice"><input type="checkbox" name="answer_multi[<?=(int)$q['id']?>][]" value="<?=(int)$o['id']?>" <?=in_array((int)$o['id'],$sel,true)?'checked':''?>> <?=e($o['option_text'])?></label><?php endforeach;elseif($q['type']==='true_false'):?><label class="choice"><input type="radio" name="answer_text[<?=(int)$q['id']?>]" value="true" <?=strtolower((string)($ans['answer_text']??''))==='true'?'checked':''?>> True</label><label class="choice"><input type="radio" name="answer_text[<?=(int)$q['id']?>]" value="false" <?=strtolower((string)($ans['answer_text']??''))==='false'?'checked':''?>> False</label><?php elseif($q['type']==='descriptive'):?><textarea name="answer_text[<?=(int)$q['id']?>]" rows="8"><?=e($ans['answer_text']??'')?></textarea><?php elseif($q['type']==='matching'):?><p class="hint">Enter one pair per line using <code>Left => Right</code>.</p><textarea name="answer_text[<?=(int)$q['id']?>]" rows="6"><?=e($ans['answer_text']??'')?></textarea><?php else:?><input name="answer_text[<?=(int)$q['id']?>]" value="<?=e($ans['answer_text']??'')?>"><?php endif;?></section><?php endforeach;?></div><div class="exam-actions"><span class="muted" data-save-state>Answers auto-save while connected.</span><?php if((int)$a['require_fullscreen']===1):?><button class="btn" type="button" data-enter-fullscreen>Enter fullscreen</button><?php endif;?><button class="btn btn-primary" type="submit" onclick="return confirm('Submit this assessment? You cannot edit after submission.')">Submit assessment</button></div></form><p class="hint">Browser controls only detect/report events and cannot guarantee prevention of screenshots, developer tools, external devices, or all cheating. Deadlines and grading are enforced on the server.</p><?php render_page('Assessment',ob_get_clean());
    }
    public static function save(): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json');
        try {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) throw new \RuntimeException('Security token expired.');
            $id = (int)($_POST['attempt_id'] ?? 0);
            $attempt = AssessmentEngine::attempt($id);
            if (!$attempt || (int)$attempt['user_id'] !== (int)Auth::user()['id']) throw new \RuntimeException('Attempt unavailable.');
            if (!self::assessmentFeatureEnabled((string)$attempt['assessment_type'])) throw new \RuntimeException('Assessment module is disabled.');
            AssessmentEngine::saveAnswers($id, $_POST, (int)Auth::user()['id']);
            echo json_encode(['ok'=>true,'saved_at'=>gmdate('c')]);
        } catch (\Throwable $e) {
            http_response_code(409);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }
    public static function event(): void
    {
        Auth::requireAuth();
        header('Content-Type: application/json');
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data) || !Csrf::validate($data['_csrf'] ?? null)) {
            http_response_code(400); echo '{"ok":false}'; exit;
        }
        $id = (int)($data['attempt_id'] ?? 0);
        $attempt = AssessmentEngine::attempt($id);
        if (!$attempt || (int)$attempt['user_id'] !== (int)Auth::user()['id'] || !self::assessmentFeatureEnabled((string)$attempt['assessment_type'])) {
            http_response_code(404); echo '{"ok":false}'; exit;
        }
        AssessmentEngine::logEvent($id, (int)Auth::user()['id'], (string)($data['event_type'] ?? ''), (array)($data['metadata'] ?? []));
        echo '{"ok":true}';
        exit;
    }
    public static function submit(): void
    {
        Auth::requireAuth();
        $u = Auth::user();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validate($_POST['_csrf'] ?? null)) { http_response_code(400); return; }
        $id = (int)($_POST['attempt_id'] ?? 0);
        try {
            $attempt = AssessmentEngine::attempt($id);
            if (!$attempt || (int)$attempt['user_id'] !== (int)$u['id']) throw new \RuntimeException('Attempt unavailable.');
            require_feature(self::featureForType((string)$attempt['assessment_type']));
            AssessmentEngine::saveAnswers($id, $_POST, (int)$u['id']);
            AssessmentEngine::finalize($id, 'submitted');
            header('Location: '.app_url('?r=assessment-results&attempt_id='.$id));
            exit;
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
            header('Location: '.app_url('?r=assessment-take&attempt_id='.$id));
            exit;
        }
    }
    public static function results(): void
    {
        Auth::requireAuth();
        $u = Auth::user();
        $id = (int)($_GET['attempt_id'] ?? 0);
        $a = AssessmentEngine::attempt($id);
        if (!$a) { http_response_code(404); render_page('Not found','<div class="card">Result unavailable.</div>'); return; }
        require_feature(self::featureForType((string)$a['assessment_type']));
        $manage = AssessmentEngine::canManage($a, $u);
        if (!$manage && (int)$a['user_id'] !== (int)$u['id']) { http_response_code(404); return; }
        $published = !empty($a['results_published_at']);
        $pdo = Database::connection();
        $st = $pdo->prepare('SELECT e.*,q.prompt,q.type,q.explanation,aq.max_marks FROM exam_answers e JOIN questions q ON q.id=e.question_id JOIN attempt_questions aq ON aq.attempt_id=e.attempt_id AND aq.question_id=e.question_id WHERE e.attempt_id=? ORDER BY aq.sort_order');
        $st->execute([$id]);
        $answers = $st->fetchAll();
        ob_start();?><div class="page-head"><div><h1><?=e($a['title'])?> result</h1><p class="muted"><?=e($a['course_title'])?> · <?=e($a['grading_status'])?></p></div><?php if(in_array('student',$u['roles'],true)):?><a class="btn" href="<?=e(app_url('?r=assessments'))?>">Assessments</a><?php endif;?></div><?php if(!$manage&&!$published):?><div class="card empty-state"><h2>Result pending</h2><p>Your submission is recorded. The result has not been published yet.</p></div><?php else:?><div class="stats-grid"><div class="card stat"><span>Score</span><strong><?=e($a['score']??'—')?> / <?=e($a['total_marks'])?></strong></div><div class="card stat"><span>Pass mark</span><strong><?=e($a['pass_marks'])?></strong></div><div class="card stat"><span>Status</span><strong><?=((float)$a['score']>=(float)$a['pass_marks'])?'Pass':'Not passed'?></strong></div><div class="card stat"><span>Submitted</span><strong class="stat-small"><?=e($a['submitted_at']??'—')?></strong></div></div><?php if($a['review_policy']!=='never'&&($a['review_policy']==='after_submit'||($a['review_policy']==='after_close'&&!empty($a['ends_at'])&&gmdate('Y-m-d H:i:s')>=$a['ends_at'])||$manage)):?><section class="card"><h2>Review</h2><div class="compact-list"><?php foreach($answers as $x):?><div><span><strong><?=e($x['prompt'])?></strong><small><?=e('Awarded '.$x['awarded_marks'].' / '.$x['max_marks'])?><?=!empty($x['feedback'])?' · '.e($x['feedback']):''?></small></span></div><?php endforeach;?></div></section><?php endif;?><?php endif;?><?php render_page('Assessment result',ob_get_clean());
    }
}
