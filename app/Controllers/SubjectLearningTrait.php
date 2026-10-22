<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;

trait SubjectLearningTrait
{
    public static function subjects(): void
    {
        Auth::requireAuth();
        $pdo = Database::connection();
        $user = Auth::user();
        $roles=(array)($user['roles']??[]);
        if(in_array('parent',$roles,true) && !(bool)array_intersect(['student','teacher','hod','administrator','super_admin'],$roles)){
            render_status_page(403,'Parent learning view','Parent accounts use the Parent portal to view linked student course progress. Lesson and subject browsing is not available.','Open Parent portal',app_url('?r=parent-portal'));return;
        }
        $canManageSubjects = self::canManageSubjects($user);
        $message = $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Invalid security token.';
            } elseif (!$canManageSubjects) {
                $error = 'You do not have permission to change subjects.';
            } else {
                $action = (string)($_POST['action'] ?? '');
                try {
                    if ($action === 'create_subject') {
                        $name = trim((string)($_POST['name'] ?? ''));
                        $code = strtoupper(trim((string)($_POST['code'] ?? ''))) ?: null;
                        $description = trim((string)($_POST['description'] ?? '')) ?: null;
                        $thumbnail = safe_external_url((string)($_POST['thumbnail'] ?? ''));
                        if ($name === '') throw new \RuntimeException('Subject name is required.');
                        $now = gmdate('Y-m-d H:i:s');
                        $pdo->prepare('INSERT INTO subjects (name,code,description,thumbnail,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)')
                            ->execute([$name,$code,$description,$thumbnail,'active',$now,$now]);
                        $id = (int)$pdo->lastInsertId();
                        Auth::audit('subjects.create', 'Created subject ' . $name, null, 'subject', $id);
                        $message = 'Subject created.';
                    } elseif ($action === 'archive_subject') {
                        $id = (int)($_POST['subject_id'] ?? 0);
                        $pdo->prepare("UPDATE subjects SET status='archived',updated_at=? WHERE id=?")->execute([gmdate('Y-m-d H:i:s'),$id]);
                        Auth::audit('subjects.archive', 'Archived subject', null, 'subject', $id);
                        $message = 'Subject archived.';
                    } elseif ($action === 'delete_subject') {
                        $id = (int)($_POST['subject_id'] ?? 0);
                        $st = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE subject_id=?');
                        $st->execute([$id]);
                        if ((int)$st->fetchColumn() > 0) throw new \RuntimeException('Delete or move the courses in this subject first. Subjects with course history cannot be deleted.');
                        $pdo->prepare('DELETE FROM subjects WHERE id=?')->execute([$id]);
                        Auth::audit('subjects.delete', 'Deleted empty subject', null, 'subject', $id);
                        $message = 'Subject deleted.';
                    }
                } catch (\Throwable $e) {
                    $error = self::friendlyDbError($e, 'Unable to save subject.');
                }
            }
        }

        $subjects = self::subjectRowsForUser($pdo, $user);
        foreach ($subjects as &$subject) {
            $subject += self::subjectStats($pdo, (int)$subject['id'], $user);
        }
        unset($subject);

        ob_start(); ?>
        <div class="page-head"><div><h1>Subjects</h1><p class="muted"><?= in_array('student',$user['roles'],true) ? 'Your available subjects and learning progress.' : 'Subject cards, courses and lesson content.' ?></p></div><?php if($canManageSubjects): ?><a class="btn" href="#new-subject">New subject</a><?php endif; ?></div>
        <?php if($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

        <?php if(!$subjects): ?><section class="card empty-state"><h2>No subjects available</h2><p class="muted">Subjects will appear here when they are created and assigned.</p></section><?php else: ?>
        <div class="subject-grid"><?php foreach($subjects as $s): ?>
          <article class="subject-card card">
            <div class="subject-thumb"><?php if(!empty($s['thumbnail'])): ?><img src="<?= e($s['thumbnail']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span><?= e(self::initials($s['name'])) ?></span><?php endif; ?></div>
            <div class="subject-meta"><span class="badge"><?= e($s['code'] ?: 'SUBJECT') ?></span><h2><?= e($s['name']) ?></h2><p><?= e($s['description'] ?: 'No description yet.') ?></p></div>
            <div class="subject-stats"><span><strong><?= (int)$s['total_courses'] ?></strong> courses</span><span><strong><?= (int)$s['total_lessons'] ?></strong> lessons</span><span><strong><?= (int)$s['progress_percent'] ?>%</strong> progress</span></div>
            <div class="progress-track" aria-label="<?= (int)$s['progress_percent'] ?> percent complete"><span style="width:<?= (int)$s['progress_percent'] ?>%"></span></div>
            <div class="subject-footer"><small><?= e($s['teacher_name'] ?: 'Teacher not assigned') ?></small><a class="btn btn-small btn-primary" href="<?= e(app_url('?r=subject&id='.(int)$s['id'])) ?>">Open subject</a></div>
            <?php if($canManageSubjects && $s['status']!=='archived'): ?><form method="post" class="archive-form" onsubmit="return confirm('Archive this subject? Historical data will be preserved.')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="archive_subject"><input type="hidden" name="subject_id" value="<?= (int)$s['id'] ?>"><button class="link-danger" type="submit">Archive</button></form><?php endif; ?>
          </article>
        <?php endforeach; ?></div><?php endif; ?>

        <?php if($canManageSubjects): ?><section class="card form-card" id="new-subject"><h2>Create subject</h2><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_subject"><div class="form-row"><label>Subject name<input name="name" required maxlength="160"></label><label>Subject code<input name="code" maxlength="60" placeholder="CS101"></label></div><label>Description<textarea name="description" rows="3" maxlength="3000"></textarea></label><label>Thumbnail URL <small>Optional HTTPS image URL.</small><input name="thumbnail" type="url" placeholder="https://…"></label><button class="btn btn-primary">Create subject</button></form></section><?php endif; ?>
        <?php render_page('Subjects', ob_get_clean());
    }

    public static function subject(): void
    {
        Auth::requireAuth();
        $pdo = Database::connection();
        $user = Auth::user();
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM subjects WHERE id=? LIMIT 1'); $stmt->execute([$id]); $subject = $stmt->fetch();
        if (!$subject || !self::canViewSubject($pdo, $subject, $user)) self::notFoundOrDenied();
        $canManageSubjects = self::canManageSubjects($user);
        $canCreateCourse = $canManageSubjects || in_array('teacher', $user['roles'], true);
        $message = $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error='Invalid security token.';
            } else {
                $action=(string)($_POST['action']??'');
                try {
                    if ($action==='update_subject' && $canManageSubjects) {
                        $name=trim((string)($_POST['name']??'')); if($name==='') throw new \RuntimeException('Subject name is required.');
                        $code=strtoupper(trim((string)($_POST['code']??'')))?:null; $desc=trim((string)($_POST['description']??''))?:null; $thumb=safe_external_url((string)($_POST['thumbnail']??''));
                        $pdo->prepare('UPDATE subjects SET name=?,code=?,description=?,thumbnail=?,updated_at=? WHERE id=?')->execute([$name,$code,$desc,$thumb,gmdate('Y-m-d H:i:s'),$id]);
                        $subject=array_merge($subject,['name'=>$name,'code'=>$code,'description'=>$desc,'thumbnail'=>$thumb]); Auth::audit('subjects.update','Updated subject '.$name,null,'subject',$id); $message='Subject updated.';
                    } elseif ($action==='delete_subject' && $canManageSubjects) {
                        $st=$pdo->prepare('SELECT COUNT(*) FROM courses WHERE subject_id=?');$st->execute([$id]);
                        if((int)$st->fetchColumn()>0) throw new \RuntimeException('Delete or move the courses in this subject first. Subjects with course history cannot be deleted.');
                        $pdo->prepare('DELETE FROM subjects WHERE id=?')->execute([$id]);
                        Auth::audit('subjects.delete','Deleted empty subject',null,'subject',$id);
                        flash_set('success','Subject deleted.');
                        header('Location: '.app_url('?r=subjects'));exit;
                    } elseif ($action==='create_course' && $canCreateCourse) {
                        $title=trim((string)($_POST['title']??'')); if($title==='') throw new \RuntimeException('Course title is required.');
                        $teacherId = in_array('teacher',$user['roles'],true) && !$canManageSubjects ? (int)$user['id'] : self::idOrNull($_POST['teacher_id']??null);
                        $status=in_array((string)($_POST['status']??'draft'),['draft','published'],true)?(string)$_POST['status']:'draft';
                        $now=gmdate('Y-m-d H:i:s'); $pdo->prepare('INSERT INTO courses (subject_id,title,description,teacher_id,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)')->execute([$id,$title,trim((string)($_POST['description']??''))?:null,$teacherId,$status,$now,$now]);
                        $courseId=(int)$pdo->lastInsertId(); Auth::audit('courses.create','Created course '.$title,null,'course',$courseId); $message='Course created.';
                    } elseif (in_array($action,['update_course','delete_course'],true)) {
                        $courseId=(int)($_POST['course_id']??0);$st=$pdo->prepare('SELECT * FROM courses WHERE id=? AND subject_id=? LIMIT 1');$st->execute([$courseId,$id]);$target=$st->fetch();
                        if(!$target) throw new \RuntimeException('Course not found.');
                        $canManageCourse=$canManageSubjects || (in_array('teacher',$user['roles'],true) && (int)$target['teacher_id']===(int)$user['id']);
                        if(!$canManageCourse) throw new \RuntimeException('You do not have permission to manage this course.');
                        if($action==='update_course'){
                            $title=trim((string)($_POST['title']??''));if($title==='')throw new \RuntimeException('Course title is required.');
                            $status=in_array((string)($_POST['status']??'draft'),['draft','published','archived'],true)?(string)$_POST['status']:'draft';
                            $teacherId=$canManageSubjects?self::idOrNull($_POST['teacher_id']??null):(int)$target['teacher_id'];
                            $pdo->prepare('UPDATE courses SET title=?,description=?,teacher_id=?,status=?,updated_at=? WHERE id=?')->execute([$title,trim((string)($_POST['description']??''))?:null,$teacherId,$status,gmdate('Y-m-d H:i:s'),$courseId]);
                            Auth::audit('courses.update','Updated course '.$title,null,'course',$courseId);$message='Course updated.';
                        }else{
                            if(self::courseDeleteDependencyCount($pdo,$courseId)>0)throw new \RuntimeException('This course already contains learning data or student activity. Archive it instead of deleting it.');
                            $pdo->prepare('DELETE FROM courses WHERE id=?')->execute([$courseId]);Auth::audit('courses.delete','Deleted empty course',null,'course',$courseId);$message='Course deleted.';
                        }
                    } else if ($action !== '') {
                        $error='You do not have permission for that action.';
                    }
                } catch (\Throwable $e) { $error=self::friendlyDbError($e,'Unable to save changes.'); }
            }
        }

        $courses=self::courseRowsForSubject($pdo,$id,$user);
        foreach($courses as &$course){ $course['lesson_count']=self::courseLessonCount($pdo,(int)$course['id'],$user); $course['progress_percent']=self::courseProgress($pdo,(int)$course['id'],(int)$user['id'],$user); }
        unset($course);
        $teachers=$canManageSubjects?self::teacherAccounts($pdo):[];

        ob_start(); ?>
        <div class="crumbs"><a href="<?= e(app_url('?r=subjects')) ?>">Subjects</a><span>›</span><span><?= e($subject['name']) ?></span></div>
        <div class="page-head"><div><span class="badge"><?= e($subject['code'] ?: 'SUBJECT') ?></span><h1><?= e($subject['name']) ?></h1><p class="muted"><?= e($subject['description'] ?: 'No description yet.') ?></p></div><?php if($canCreateCourse): ?><a class="btn btn-primary" href="#new-course">New course</a><?php endif; ?></div>
        <?php if($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

        <?php if(!$courses): ?><section class="card empty-state"><h2>No courses available</h2><p class="muted">Courses assigned to you will appear here.</p></section><?php else: ?><div class="course-grid"><?php foreach($courses as $c): $canManageThisCourse=$canManageSubjects || (in_array('teacher',$user['roles'],true) && (int)$c['teacher_id']===(int)$user['id']); ?><article class="card course-card"><div class="course-card-top"><span class="badge <?= $c['status']==='published'?'badge-ok':'' ?>"><?= e($c['status']) ?></span><span class="muted"><?= (int)$c['lesson_count'] ?> lessons</span></div><h2><?= e($c['title']) ?></h2><p><?= e($c['description'] ?: 'No course description.') ?></p><div class="teacher-line"><span class="avatar-mini"><?= e(self::initials($c['teacher_name'] ?: 'NA')) ?></span><span><?= e($c['teacher_name'] ?: 'Teacher not assigned') ?></span></div><div class="progress-label"><span>Progress</span><strong><?= (int)$c['progress_percent'] ?>%</strong></div><div class="progress-track"><span style="width:<?= (int)$c['progress_percent'] ?>%"></span></div><a class="btn btn-primary btn-block" href="<?= e(app_url('?r=course&id='.(int)$c['id'])) ?>">Open course</a><?php if($canManageThisCourse): ?><details class="course-manage"><summary>Manage course</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="update_course"><input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>"><label>Title<input name="title" value="<?= e($c['title']) ?>" required></label><label>Description<textarea name="description" rows="3"><?= e($c['description']) ?></textarea></label><?php if($canManageSubjects): ?><label>Teacher<select name="teacher_id"><option value="">Unassigned</option><?php foreach($teachers as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$c['teacher_id']===(int)$t['id']?'selected':'' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?><label>Status<select name="status"><option value="draft" <?= $c['status']==='draft'?'selected':'' ?>>Draft</option><option value="published" <?= $c['status']==='published'?'selected':'' ?>>Published</option><option value="archived" <?= $c['status']==='archived'?'selected':'' ?>>Archived</option></select></label><button class="btn btn-small">Save course</button></form><form method="post" onsubmit="return confirm('Delete this course? Only empty courses can be deleted.')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_course"><input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-small btn-danger" type="submit">Delete empty course</button></form></details><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?>

        <?php if($canCreateCourse): ?><section class="card form-card" id="new-course"><h2>Create course</h2><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_course"><label>Course title<input name="title" required maxlength="180"></label><label>Description<textarea name="description" rows="4" maxlength="5000"></textarea></label><?php if($canManageSubjects): ?><label>Teacher<select name="teacher_id"><option value="">Unassigned</option><?php foreach($teachers as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name'].' · '.$t['email']) ?></option><?php endforeach; ?></select></label><?php endif; ?><label>Status<select name="status"><option value="draft">Draft</option><option value="published">Published</option></select></label><button class="btn btn-primary">Create course</button></form></section><?php endif; ?>

        <?php if($canManageSubjects): ?><details class="card settings-panel"><summary>Edit subject</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="update_subject"><div class="form-row"><label>Name<input name="name" value="<?= e($subject['name']) ?>" required></label><label>Code<input name="code" value="<?= e($subject['code']) ?>"></label></div><label>Description<textarea name="description" rows="3"><?= e($subject['description']) ?></textarea></label><label>Thumbnail URL<input type="url" name="thumbnail" value="<?= e($subject['thumbnail']) ?>"></label><button class="btn">Save subject</button></form><hr><form method="post" onsubmit="return confirm('Delete this subject? Only a subject with no courses can be deleted.')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_subject"><button class="btn btn-danger" type="submit">Delete empty subject</button></form></details><?php endif; ?>
        <?php render_page($subject['name'], ob_get_clean());
    }

    private static function courseDeleteDependencyCount(\PDO $pdo,int $courseId):int
    {
        $relations=[['course_sections','course_id'],['enrollments','course_id'],['course_batches','course_id'],['attendance_sessions','course_id'],['question_banks','course_id'],['quizzes','course_id'],['projects','course_id'],['grades','course_id'],['announcements','course_id'],['certificates','course_id'],['timetable_entries','course_id'],['live_classes','course_id'],['course_completions','course_id'],['registration_invite_courses','course_id'],['doubts','course_id']];
        $total=0;foreach($relations as [$table,$column]){try{$st=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=?");$st->execute([$courseId]);$total+=(int)$st->fetchColumn();}catch(\Throwable){}}
        return $total;
    }
}
