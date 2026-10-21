<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\AssessmentEngine;

trait ExamReviewTrait
{
    public static function review(): void
    {
        Auth::requirePermission('exams.manage');
        $types = self::enabledAssessmentTypes();
        if (!$types) { http_response_code(404); render_page('Assessments unavailable','<div class="card">Assessment modules are disabled.</div>'); return; }
        $u = Auth::user();
        $pdo = Database::connection();
        $where = Auth::hasAnyRole(['super_admin','administrator','hod','examiner']) ? '1=1' : 'c.teacher_id=?';
        $marks = implode(',', array_fill(0, count($types), '?'));
        $sql = "SELECT a.*,qz.title,qz.assessment_type,c.title course_title,u.name student_name FROM exam_attempts a JOIN quizzes qz ON qz.id=a.quiz_id JOIN courses c ON c.id=qz.course_id JOIN users u ON u.id=a.user_id WHERE a.status='submitted' AND qz.assessment_type IN ($marks) AND $where ORDER BY a.submitted_at DESC LIMIT 200";
        $params = $types;
        if ($where !== '1=1') $params[] = (int)$u['id'];
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        $selected = (int)($_GET['attempt_id'] ?? 0);
        $error = null; $message = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) $error = 'Security token expired.';
            else try {
                $id = (int)($_POST['attempt_id'] ?? 0);
                $a = AssessmentEngine::attempt($id);
                if (!$a || !AssessmentEngine::canManage($a, $u) || !self::assessmentFeatureEnabled((string)$a['assessment_type'])) throw new \RuntimeException('Attempt access denied.');
                $action = (string)($_POST['action'] ?? 'grade');
                if ($action === 'publish') AssessmentEngine::publishResult($id, (int)$u['id']);
                else AssessmentEngine::manualGrade($id, (array)($_POST['marks'] ?? []), (array)($_POST['feedback'] ?? []), (int)$u['id'], isset($_POST['publish']));
                $message = 'Attempt updated.';
                $selected = $id;
            } catch (\Throwable $e) { $error = $e->getMessage(); }
        }

        $attempt = $selected ? AssessmentEngine::attempt($selected) : null;
        if ($attempt && (!AssessmentEngine::canManage($attempt, $u) || !self::assessmentFeatureEnabled((string)$attempt['assessment_type']))) $attempt = null;
        $qs = $attempt ? AssessmentEngine::questionsForAttempt($selected) : [];
        $answers = [];
        if ($attempt) { $st=$pdo->prepare('SELECT * FROM exam_answers WHERE attempt_id=?'); $st->execute([$selected]); foreach($st->fetchAll() as $x) $answers[(int)$x['question_id']]=$x; }
        ob_start();?><div class="page-head"><div><h1>Assessment review</h1><p class="muted">Manual grading, event review and result publication.</p></div></div><?php if($message):?><div class="alert alert-success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><section class="card table-card"><div class="table-scroll"><table><thead><tr><th>Student</th><th>Assessment</th><th>Submitted</th><th>Grading</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['student_name'])?></td><td><?=e($r['title'])?><small><?=e(strtoupper($r['assessment_type']).' · '.$r['course_title'])?></small></td><td><?=e($r['submitted_at'])?></td><td><?=e($r['grading_status'])?></td><td><a class="btn btn-small" href="<?=e(app_url('?r=assessment-review&attempt_id='.(int)$r['id']))?>">Review</a></td></tr><?php endforeach;?></tbody></table></div></section><?php if($attempt):?><section class="card"><h2>Grade <?=e($attempt['title'])?></h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="attempt_id" value="<?=$selected?>"><?php foreach($qs as $q):$a=$answers[(int)$q['id']]??null;?><div class="grading-item"><strong><?=e($q['prompt'])?></strong><p class="muted">Answer: <?=e($a['answer_text']??($a['selected_options_json']??'No answer'))?></p><?php if($q['type']==='descriptive'):?><div class="form-row"><label>Marks / <?=e($q['max_marks'])?><input type="number" name="marks[<?=(int)$q['id']?>]" min="0" max="<?=e($q['max_marks'])?>" step="0.25" value="<?=e($a['awarded_marks']??0)?>"></label><label>Feedback<input name="feedback[<?=(int)$q['id']?>]" value="<?=e($a['feedback']??'')?>"></label></div><?php else:?><span class="badge"><?=e(($a['awarded_marks']??0).' / '.$q['max_marks'])?></span><?php endif;?></div><?php endforeach;?><label class="check-row"><input type="checkbox" name="publish" value="1"> Publish result after grading</label><button class="btn btn-primary" name="action" value="grade">Save grading</button><?php if($attempt['grading_status']==='graded'&&!$attempt['results_published_at']):?><button class="btn" name="action" value="publish">Publish result</button><?php endif;?></form></section><?php endif;?><?php render_page('Assessment review',ob_get_clean());
    }
    private static function featureForType(string $type): string
    {
        return $type === 'exam' ? 'exams' : 'quizzes';
    }
    private static function assessmentFeatureEnabled(string $type): bool
    {
        return feature_enabled(self::featureForType($type));
    }
    private static function enabledAssessmentTypes(): array
    {
        $types = [];
        if (feature_enabled('quizzes')) $types[] = 'quiz';
        if (feature_enabled('exams')) $types[] = 'exam';
        return $types;
    }
    private static function recalc(int $id):void{$pdo=Database::connection();$st=$pdo->prepare('SELECT COALESCE(SUM(q.marks),0) FROM quiz_questions qq JOIN questions q ON q.id=qq.question_id WHERE qq.quiz_id=?');$st->execute([$id]);$pdo->prepare('UPDATE quizzes SET total_marks=?,updated_at=? WHERE id=?')->execute([(float)$st->fetchColumn(),gmdate('Y-m-d H:i:s'),$id]);}
    private static function course(int $id):?array{$st=Database::connection()->prepare("SELECT c.*,s.status subject_status FROM courses c JOIN subjects s ON s.id=c.subject_id WHERE c.id=?");$st->execute([$id]);return $st->fetch()?:null;}
    private static function manageableCourses(array $u):array{$pdo=Database::connection();if(Auth::hasAnyRole(['super_admin','administrator','hod']))return $pdo->query("SELECT c.*,s.status subject_status FROM courses c JOIN subjects s ON s.id=c.subject_id WHERE c.status<>'archived' ORDER BY c.title")->fetchAll();$st=$pdo->prepare("SELECT c.*,s.status subject_status FROM courses c JOIN subjects s ON s.id=c.subject_id WHERE c.teacher_id=? AND c.status<>'archived' ORDER BY c.title");$st->execute([(int)$u['id']]);return $st->fetchAll();}
}
