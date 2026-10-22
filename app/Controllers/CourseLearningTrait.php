<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;

trait CourseLearningTrait
{
    public static function course(): void
    {
        Auth::requireAuth();
        $pdo=Database::connection(); $user=Auth::user(); $id=(int)($_GET['id']??0);
        $course=self::loadCourse($pdo,$id); if(!$course || !CoursePolicy::canViewCourse($course,$user)) self::notFoundOrDenied();
        $canManage=CoursePolicy::canManage($course,$user); $message=$error=null;

        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!Csrf::validate($_POST['_csrf']??null)) $error='Invalid security token.';
            else {
                $action=(string)($_POST['action']??'');
                try {
                    if($action==='update_course' && $canManage){
                        $title=trim((string)($_POST['title']??'')); if($title==='') throw new \RuntimeException('Course title is required.');
                        $teacherId=$course['teacher_id']; if(self::canManageSubjects($user)) $teacherId=self::idOrNull($_POST['teacher_id']??null);
                        $status=in_array((string)($_POST['status']??''),['draft','published','archived'],true)?(string)$_POST['status']:$course['status'];
                        $pdo->prepare('UPDATE courses SET title=?,description=?,teacher_id=?,status=?,updated_at=? WHERE id=?')->execute([$title,trim((string)($_POST['description']??''))?:null,$teacherId,$status,gmdate('Y-m-d H:i:s'),$id]);
                        Auth::audit('courses.update','Updated course '.$title,null,'course',$id); $message='Course updated.'; $course=self::loadCourse($pdo,$id);
                    } elseif($action==='delete_course' && $canManage){
                        if(self::courseDeleteDependencyCount($pdo,$id)>0) throw new \RuntimeException('This course contains chapters, enrollments, assessments, projects, Q&A, or other learning history. Archive it instead of deleting it.');
                        $subjectId=(int)$course['subject_id'];$pdo->prepare('DELETE FROM courses WHERE id=?')->execute([$id]);Auth::audit('courses.delete','Deleted empty course',null,'course',$id);flash_set('success','Course deleted.');header('Location: '.app_url('?r=subject&id='.$subjectId));exit;
                    } elseif($action==='add_section' && $canManage){
                        $title=trim((string)($_POST['title']??'')); if($title==='') throw new \RuntimeException('Chapter title is required.');
                        $stmt=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM course_sections WHERE course_id=?');$stmt->execute([$id]);$order=(int)$stmt->fetchColumn();
                        $pdo->prepare('INSERT INTO course_sections (course_id,title,sort_order) VALUES (?,?,?)')->execute([$id,$title,$order]); Auth::audit('course_chapters.create','Created course chapter '.$title,null,'course',$id); $message='Chapter added.';
                    } elseif($action==='update_section' && $canManage){
                        $sectionId=(int)($_POST['section_id']??0);if(!self::sectionBelongsToCourse($pdo,$sectionId,$id))throw new \RuntimeException('Invalid chapter.');
                        $title=trim((string)($_POST['title']??''));if($title==='')throw new \RuntimeException('Chapter title is required.');
                        $pdo->prepare('UPDATE course_sections SET title=? WHERE id=? AND course_id=?')->execute([$title,$sectionId,$id]);Auth::audit('course_chapters.update','Renamed course chapter '.$title,null,'course',$id);$message='Chapter updated.';
                    } elseif($action==='delete_section' && $canManage){
                        $sectionId=(int)($_POST['section_id']??0);if(!self::sectionBelongsToCourse($pdo,$sectionId,$id))throw new \RuntimeException('Invalid chapter.');
                        $st=$pdo->prepare('SELECT COUNT(*) FROM lessons WHERE course_section_id=?');$st->execute([$sectionId]);if((int)$st->fetchColumn()>0)throw new \RuntimeException('Delete or move the lessons in this chapter first.');
                        $pdo->prepare('DELETE FROM course_sections WHERE id=? AND course_id=?')->execute([$sectionId,$id]);Auth::audit('course_chapters.delete','Deleted empty course chapter',null,'course',$id);$message='Chapter deleted.';
                    } elseif($action==='create_lesson' && $canManage){
                        $sectionId=(int)($_POST['section_id']??0); if(!self::sectionBelongsToCourse($pdo,$sectionId,$id)) throw new \RuntimeException('Invalid chapter.');
                        $title=trim((string)($_POST['title']??'')); if($title==='') throw new \RuntimeException('Lesson title is required.');
                        $stmt=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM lessons WHERE course_section_id=?');$stmt->execute([$sectionId]);$order=(int)$stmt->fetchColumn();$now=gmdate('Y-m-d H:i:s');
                        $pdo->prepare('INSERT INTO lessons (course_section_id,title,content,objectives,instructions,duration_minutes,sort_order,status,release_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$sectionId,$title,'',null,null,self::positiveIntOrNull($_POST['duration_minutes']??null),$order,'draft',null,$now,$now]);
                        $lessonId=(int)$pdo->lastInsertId();Auth::audit('lessons.create','Created lesson '.$title,null,'lesson',$lessonId);$message='Lesson created.';
                    } elseif($action==='move_lesson' && $canManage){ self::moveLesson($pdo,(int)($_POST['lesson_id']??0),(string)($_POST['direction']??''),$id); $message='Lesson order updated.';
                    } elseif($action==='assign_batch' && $canManage){
                        $batchId=(int)($_POST['batch_id']??0);if($batchId<1) throw new \RuntimeException('Choose a batch.');self::insertIgnore($pdo,'course_batches',['course_id'=>$id,'batch_id'=>$batchId,'assigned_at'=>gmdate('Y-m-d H:i:s')],['course_id','batch_id']);Auth::audit('courses.batch.assign','Assigned course to batch',null,'course',$id);$message='Batch assigned.';
                    } elseif($action==='remove_batch' && $canManage){$batchId=(int)($_POST['batch_id']??0);$pdo->prepare('DELETE FROM course_batches WHERE course_id=? AND batch_id=?')->execute([$id,$batchId]);$message='Batch assignment removed.';
                    } elseif($action==='enroll_student' && $canManage){
                        $studentId=(int)($_POST['student_id']??0); if(!self::isStudent($pdo,$studentId)) throw new \RuntimeException('Choose an active student.');
                        self::upsertEnrollment($pdo,$studentId,$id,self::idOrNull($_POST['batch_id']??null));Auth::audit('courses.enroll','Enrolled student in course',null,'course',$id);$message='Student enrolled.';
                    } elseif($action!=='') $error='You do not have permission for that action.';
                } catch(\Throwable $e){$error=self::friendlyDbError($e,'Unable to update course.');}
            }
        }

        $sections=self::courseSections($pdo,$id,$user,$canManage);
        $batches=$canManage?$pdo->query('SELECT b.*, ay.name academic_year_name FROM batches b LEFT JOIN academic_years ay ON ay.id=b.academic_year_id ORDER BY b.name')->fetchAll():[];
        $assignedBatches=[];$students=[];$enrollments=[];$teachers=[];
        if($canManage){
            $st=$pdo->prepare('SELECT b.*,ay.name academic_year_name FROM course_batches cb INNER JOIN batches b ON b.id=cb.batch_id LEFT JOIN academic_years ay ON ay.id=b.academic_year_id WHERE cb.course_id=? ORDER BY b.name');$st->execute([$id]);$assignedBatches=$st->fetchAll();
            $students=$pdo->query("SELECT DISTINCT u.id,u.name,u.email FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id INNER JOIN roles r ON r.id=ur.role_id WHERE r.name='student' AND u.status='active' ORDER BY u.name")->fetchAll();
            $st=$pdo->prepare('SELECT e.*,u.name,u.email,b.name batch_name FROM enrollments e INNER JOIN users u ON u.id=e.user_id LEFT JOIN batches b ON b.id=e.batch_id WHERE e.course_id=? ORDER BY e.id DESC');$st->execute([$id]);$enrollments=$st->fetchAll();
            if(self::canManageSubjects($user))$teachers=self::teacherAccounts($pdo);
        }
        $progress=self::courseProgress($pdo,$id,(int)$user['id'],$user);

        ob_start(); ?>
        <div class="crumbs"><a href="<?= e(app_url('?r=subjects')) ?>">Subjects</a><span>›</span><a href="<?= e(app_url('?r=subject&id='.(int)$course['subject_id'])) ?>"><?= e($course['subject_name']) ?></a><span>›</span><span><?= e($course['title']) ?></span></div>
        <div class="page-head"><div><span class="badge <?= $course['status']==='published'?'badge-ok':'' ?>"><?= e($course['status']) ?></span><h1><?= e($course['title']) ?></h1><p class="muted"><?= e($course['description'] ?: 'No course description yet.') ?></p><div class="teacher-line"><span class="avatar-mini"><?= e(self::initials($course['teacher_name'] ?: 'NA')) ?></span><span><?= e($course['teacher_name'] ?: 'Teacher not assigned') ?></span></div></div><div class="course-progress-ring"><strong><?= $progress ?>%</strong><span>complete</span></div></div>
        <?php if($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

        <div class="lesson-layout"><section class="lesson-outline"><?php if(!$sections): ?><div class="card empty-state"><h2>No chapters yet</h2><p class="muted"><?= $canManage?'Add a chapter to begin organizing lessons.':'Learning content has not been published yet.' ?></p></div><?php endif; ?><?php foreach($sections as $section): ?><article class="card section-card"><div class="section-head"><div><small>Chapter <?= (int)$section['sort_order'] ?></small><h2><?= e($section['title']) ?></h2></div><span class="badge"><?= count($section['lessons']) ?> lessons</span></div><?php if($canManage): ?><details class="chapter-manage"><summary>Manage chapter</summary><form method="post" class="inline-create"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="update_section"><input type="hidden" name="section_id" value="<?= (int)$section['id'] ?>"><input name="title" value="<?= e($section['title']) ?>" required maxlength="180"><button class="btn btn-small">Rename</button></form><form method="post" class="inline-form" onsubmit="return confirm('Delete this chapter? Only an empty chapter can be deleted.')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_section"><input type="hidden" name="section_id" value="<?= (int)$section['id'] ?>"><button class="btn btn-small btn-danger" type="submit">Delete empty chapter</button></form></details><?php endif; ?><div class="lesson-list"><?php foreach($section['lessons'] as $lesson): ?><div class="lesson-row"><div class="lesson-index"><?= (int)$lesson['sort_order'] ?></div><div class="lesson-main"><a href="<?= e(app_url('?r=lesson&id='.(int)$lesson['id'])) ?>"><strong><?= e($lesson['title']) ?></strong></a><small><?= $lesson['duration_minutes']?(int)$lesson['duration_minutes'].' min · ':'' ?><?= e($lesson['status']) ?><?= !empty($lesson['release_at'])?' · release '.e(format_app_datetime((string)$lesson['release_at'])):'' ?></small></div><?php if(!$canManage): ?><span class="badge <?= (int)($lesson['progress_percent']??0)===100?'badge-ok':'' ?>"><?= (int)($lesson['progress_percent']??0)===100?'Done':'Open' ?></span><?php else: ?><div class="row-actions"><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="move_lesson"><input type="hidden" name="lesson_id" value="<?= (int)$lesson['id'] ?>"><button class="icon-btn" name="direction" value="up" title="Move up">↑</button><button class="icon-btn" name="direction" value="down" title="Move down">↓</button></form><a class="btn btn-small" href="<?= e(app_url('?r=lesson&id='.(int)$lesson['id'])) ?>">Edit</a></div><?php endif; ?></div><?php endforeach; ?></div><?php if($canManage): ?><form method="post" class="inline-create"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_lesson"><input type="hidden" name="section_id" value="<?= (int)$section['id'] ?>"><input name="title" placeholder="New lesson title" required maxlength="180"><input name="duration_minutes" type="number" min="1" max="1440" placeholder="Minutes"><button class="btn">Add lesson</button></form><?php endif; ?></article><?php endforeach; ?></section>

        <?php if($canManage): ?><aside class="course-tools"><section class="card"><h2>Add chapter</h2><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="add_section"><label>Chapter title<input name="title" required maxlength="180" placeholder="Chapter 1 · Foundations"></label><button class="btn btn-primary">Add chapter</button></form></section>
          <section class="card"><h2>Batch access</h2><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="assign_batch"><label>Assign batch<select name="batch_id" required><option value="">Choose</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name'].($b['academic_year_name']?' · '.$b['academic_year_name']:'')) ?></option><?php endforeach; ?></select></label><button class="btn">Assign</button></form><div class="tag-list"><?php foreach($assignedBatches as $b): ?><form method="post" class="tag"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="remove_batch"><input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>"><span><?= e($b['name']) ?></span><button title="Remove">×</button></form><?php endforeach; ?></div></section>
          <section class="card"><h2>Enroll student</h2><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="enroll_student"><label>Student<select name="student_id" required><option value="">Choose</option><?php foreach($students as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name'].' · '.$s['email']) ?></option><?php endforeach; ?></select></label><label>Batch <small>Optional metadata</small><select name="batch_id"><option value="">None</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></label><button class="btn">Enroll</button></form><div class="mini-list"><?php foreach(array_slice($enrollments,0,8) as $e): ?><div><span><?= e($e['name']) ?><small><?= e($e['email']) ?></small></span><span class="badge"><?= e($e['status']) ?></span></div><?php endforeach; ?></div></section>
          <details class="card settings-panel"><summary>Course settings</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="update_course"><label>Title<input name="title" value="<?= e($course['title']) ?>" required></label><label>Description<textarea name="description" rows="4"><?= e($course['description']) ?></textarea></label><?php if(self::canManageSubjects($user)): ?><label>Teacher<select name="teacher_id"><option value="">Unassigned</option><?php foreach($teachers as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$course['teacher_id']===(int)$t['id']?'selected':'' ?>><?= e($t['name'].' · '.$t['email']) ?></option><?php endforeach; ?></select></label><?php endif; ?><label>Status<select name="status"><?php foreach(['draft','published','archived'] as $st): ?><option value="<?= $st ?>" <?= $course['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary">Save course</button></form><hr><form method="post" onsubmit="return confirm('Delete this course? Only a completely empty course can be deleted.')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_course"><button class="btn btn-danger" type="submit">Delete empty course</button></form></details>
        </aside><?php endif; ?></div>
        <?php render_page($course['title'],ob_get_clean());
    }

}
