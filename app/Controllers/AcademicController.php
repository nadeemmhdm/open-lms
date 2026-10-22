<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;

final class AcademicController
{
    public static function index(): void
    {
        Auth::requirePermission('academics.manage');
        $pdo = Database::connection();
        $message = $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Invalid security token.';
            } else {
                [$message, $error] = self::handleAction($pdo, (string)($_POST['action'] ?? ''));
            }
        }

        $years = $pdo->query('SELECT * FROM academic_years ORDER BY is_active DESC, starts_on DESC, id DESC')->fetchAll();
        $departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();
        $classes = $pdo->query('SELECT c.*, d.name department_name FROM classes c LEFT JOIN departments d ON d.id=c.department_id ORDER BY c.name')->fetchAll();
        $sections = $pdo->query('SELECT s.*, c.name class_name FROM sections s INNER JOIN classes c ON c.id=s.class_id ORDER BY c.name,s.name')->fetchAll();
        $batches = $pdo->query('SELECT b.*, ay.name academic_year_name, c.name class_name, s.name section_name, (SELECT COUNT(*) FROM batch_members bm WHERE bm.batch_id=b.id) member_count FROM batches b LEFT JOIN academic_years ay ON ay.id=b.academic_year_id LEFT JOIN classes c ON c.id=b.class_id LEFT JOIN sections s ON s.id=b.section_id ORDER BY b.id DESC')->fetchAll();
        $semesters = $pdo->query('SELECT sm.*, ay.name academic_year_name FROM semesters sm LEFT JOIN academic_years ay ON ay.id=sm.academic_year_id ORDER BY sm.id DESC')->fetchAll();
        $students = $pdo->query("SELECT DISTINCT u.id,u.name,u.email FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id INNER JOIN roles r ON r.id=ur.role_id WHERE r.name='student' AND u.status='active' ORDER BY u.name")->fetchAll();
        $members = $pdo->query("SELECT bm.batch_id,bm.user_id,b.name batch_name,u.name student_name,u.email FROM batch_members bm JOIN batches b ON b.id=bm.batch_id JOIN users u ON u.id=bm.user_id ORDER BY b.name,u.name")->fetchAll();

        ob_start(); ?>
        <div class="page-head"><div><h1>Academic setup</h1><p class="muted">Create and manage years, departments, classes, sections, batches and semesters.</p></div></div>
        <?php if($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

        <div class="academic-grid">
          <section class="card"><h2>Academic years</h2>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_year">
              <label>Name<input name="name" placeholder="2026–2027" required maxlength="80"></label>
              <div class="form-row"><label>Starts<input type="date" name="starts_on"></label><label>Ends<input type="date" name="ends_on"></label></div>
              <label class="check-row"><input type="checkbox" name="is_active" value="1"> Make active year</label><button class="btn btn-primary">Add academic year</button>
            </form>
            <div class="compact-list"><?php foreach($years as $year): ?><div><span><strong><?= e($year['name']) ?></strong><small><?= e(($year['starts_on'] ?: '—').' → '.($year['ends_on'] ?: '—')) ?></small></span><div class="action-row"><?php if($year['is_active']): ?><span class="badge badge-ok">Active</span><?php else: ?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="activate_year"><input type="hidden" name="year_id" value="<?= (int)$year['id'] ?>"><button class="btn btn-small">Set active</button></form><?php endif; ?><details class="mini-manage"><summary>Manage</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="update_year"><input type="hidden" name="year_id" value="<?= (int)$year['id'] ?>"><label>Name<input name="name" value="<?= e($year['name']) ?>" required></label><div class="form-row"><label>Starts<input type="date" name="starts_on" value="<?= e($year['starts_on']) ?>"></label><label>Ends<input type="date" name="ends_on" value="<?= e($year['ends_on']) ?>"></label></div><button class="btn btn-small">Save</button></form><?php if(!$year['is_active']): ?><form method="post" onsubmit="return confirm('Delete this academic year?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_year"><input type="hidden" name="year_id" value="<?= (int)$year['id'] ?>"><button class="btn btn-small btn-danger" type="submit">Delete</button></form><?php endif; ?></details></div></div><?php endforeach; ?></div>
          </section>

          <section class="card"><h2>Departments & classes</h2>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_department"><div class="form-row"><label>Department<input name="name" required maxlength="120"></label><label>Code<input name="code" maxlength="40"></label></div><button class="btn">Add department</button></form>
            <div class="compact-list"><?php foreach($departments as $d): ?><div><span><strong><?= e($d['name']) ?></strong><small><?= e($d['code'] ?: 'No code') ?></small></span><details class="mini-manage"><summary>Manage</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="update_department"><input type="hidden" name="department_id" value="<?= (int)$d['id'] ?>"><label>Name<input name="name" value="<?= e($d['name']) ?>" required></label><label>Code<input name="code" value="<?= e($d['code']) ?>"></label><button class="btn btn-small">Save</button></form><form method="post" onsubmit="return confirm('Delete this department?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_department"><input type="hidden" name="department_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-small btn-danger">Delete</button></form></details></div><?php endforeach; ?></div><hr>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_class"><label>Class / grade / year<input name="name" required maxlength="100"></label><label>Department<select name="department_id"><option value="">None</option><?php foreach($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></label><button class="btn">Add class</button></form>
            <div class="compact-list"><?php foreach($classes as $c): ?><div><span><strong><?= e($c['name']) ?></strong><small><?= e($c['department_name'] ?: 'No department') ?></small></span><details class="mini-manage"><summary>Manage</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="update_class"><input type="hidden" name="class_id" value="<?= (int)$c['id'] ?>"><label>Name<input name="name" value="<?= e($c['name']) ?>" required></label><label>Department<select name="department_id"><option value="">None</option><?php foreach($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$c['department_id']===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></label><button class="btn btn-small">Save</button></form><form method="post" onsubmit="return confirm('Delete this class?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_class"><input type="hidden" name="class_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-small btn-danger">Delete</button></form></details></div><?php endforeach; ?></div>
          </section>

          <section class="card"><h2>Sections & batches</h2>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_section"><div class="form-row"><label>Class<select name="class_id" required><option value="">Choose</option><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label><label>Section<input name="name" placeholder="A" required maxlength="80"></label></div><button class="btn">Add section</button></form>
            <div class="compact-list"><?php foreach($sections as $s): ?><div><span><strong><?= e($s['class_name'].' / '.$s['name']) ?></strong></span><details class="mini-manage"><summary>Manage</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="update_section"><input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>"><label>Class<select name="class_id" required><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$s['class_id']===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label><label>Name<input name="name" value="<?= e($s['name']) ?>" required></label><button class="btn btn-small">Save</button></form><form method="post" onsubmit="return confirm('Delete this section?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_section"><input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-small btn-danger">Delete</button></form></details></div><?php endforeach; ?></div><hr>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_batch"><label>Batch name<input name="name" placeholder="BCA 2026 A" required maxlength="100"></label><div class="form-row"><label>Academic year<select name="academic_year_id"><option value="">None</option><?php foreach($years as $y): ?><option value="<?= (int)$y['id'] ?>"><?= e($y['name']) ?></option><?php endforeach; ?></select></label><label>Class<select name="class_id"><option value="">None</option><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label></div><label>Section<select name="section_id"><option value="">None</option><?php foreach($sections as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['class_name'].' / '.$s['name']) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary">Add batch</button></form>
          </section>

          <section class="card"><h2>Semesters</h2>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_semester"><label>Name<input name="name" placeholder="Semester 1" required maxlength="80"></label><label>Academic year<select name="academic_year_id"><option value="">None</option><?php foreach($years as $y): ?><option value="<?= (int)$y['id'] ?>"><?= e($y['name']) ?></option><?php endforeach; ?></select></label><div class="form-row"><label>Starts<input type="date" name="starts_on"></label><label>Ends<input type="date" name="ends_on"></label></div><button class="btn">Add semester</button></form>
            <div class="compact-list"><?php foreach($semesters as $s): ?><div><span><strong><?= e($s['name']) ?></strong><small><?= e($s['academic_year_name'] ?: 'No academic year') ?></small></span><details class="mini-manage"><summary>Manage</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="update_semester"><input type="hidden" name="semester_id" value="<?= (int)$s['id'] ?>"><label>Name<input name="name" value="<?= e($s['name']) ?>" required></label><label>Academic year<select name="academic_year_id"><option value="">None</option><?php foreach($years as $y): ?><option value="<?= (int)$y['id'] ?>" <?= (int)$s['academic_year_id']===(int)$y['id']?'selected':'' ?>><?= e($y['name']) ?></option><?php endforeach; ?></select></label><div class="form-row"><label>Starts<input type="date" name="starts_on" value="<?= e($s['starts_on']) ?>"></label><label>Ends<input type="date" name="ends_on" value="<?= e($s['ends_on']) ?>"></label></div><button class="btn btn-small">Save</button></form><form method="post" onsubmit="return confirm('Delete this semester?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_semester"><input type="hidden" name="semester_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-small btn-danger">Delete</button></form></details></div><?php endforeach; ?></div>
          </section>

          <section class="card academic-wide"><h2>Batch management</h2><p class="hint">Edit empty setup records freely. Deletion is blocked when a batch has students, course assignments, enrollments or active onboarding links.</p>
            <div class="table-scroll"><table><thead><tr><th>Batch</th><th>Academic year</th><th>Class / section</th><th>Students</th><th>Manage</th></tr></thead><tbody><?php foreach($batches as $b): ?><tr><td><strong><?= e($b['name']) ?></strong></td><td><?= e($b['academic_year_name'] ?: '—') ?></td><td><?= e(trim(($b['class_name'] ?: '').' '.($b['section_name'] ?: '')) ?: '—') ?></td><td><?= (int)$b['member_count'] ?></td><td><details class="table-manage"><summary>Manage</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="update_batch"><input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>"><label>Name<input name="name" value="<?= e($b['name']) ?>" required></label><label>Academic year<select name="academic_year_id"><option value="">None</option><?php foreach($years as $y): ?><option value="<?= (int)$y['id'] ?>" <?= (int)$b['academic_year_id']===(int)$y['id']?'selected':'' ?>><?= e($y['name']) ?></option><?php endforeach; ?></select></label><label>Class<select name="class_id"><option value="">None</option><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$b['class_id']===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label><label>Section<select name="section_id"><option value="">None</option><?php foreach($sections as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$b['section_id']===(int)$s['id']?'selected':'' ?>><?= e($s['class_name'].' / '.$s['name']) ?></option><?php endforeach; ?></select></label><button class="btn btn-small">Save batch</button></form><form method="post" onsubmit="return confirm('Delete this empty batch?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_batch"><input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>"><button class="btn btn-small btn-danger">Delete batch</button></form></details></td></tr><?php endforeach; ?></tbody></table></div>
          </section>

          <section class="card academic-wide"><h2>Batch membership</h2><p class="hint">Assign or remove students from batches. Course onboarding links can also assign batch membership automatically.</p>
            <form method="post" class="form-row-3"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="add_batch_member"><label>Batch<select name="batch_id" required><option value="">Choose</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></label><label>Student<select name="user_id" required><option value="">Choose</option><?php foreach($students as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name'].' · '.$s['email']) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary align-end">Assign student</button></form>
            <div class="table-scroll"><table><thead><tr><th>Batch</th><th>Student</th><th>Action</th></tr></thead><tbody><?php foreach($members as $m): ?><tr><td><?= e($m['batch_name']) ?></td><td><strong><?= e($m['student_name']) ?></strong><small><?= e($m['email']) ?></small></td><td><form method="post" class="inline-form" onsubmit="return confirm('Remove this student from the batch?')"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="remove_batch_member"><input type="hidden" name="batch_id" value="<?= (int)$m['batch_id'] ?>"><input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>"><button class="btn btn-small">Remove</button></form></td></tr><?php endforeach; ?><?php if(!$members): ?><tr><td colspan="3" class="muted">No students assigned to batches yet.</td></tr><?php endif; ?></tbody></table></div>
          </section>
        </div>
        <?php render_page('Academic setup', ob_get_clean());
    }

    private static function handleAction(\PDO $pdo, string $action): array
    {
        $now = gmdate('Y-m-d H:i:s');
        try {
            switch ($action) {
                case 'create_year':
                    $name = trim((string)($_POST['name'] ?? ''));
                    if ($name === '') return [null, 'Academic year name is required.'];
                    if (isset($_POST['is_active'])) $pdo->exec('UPDATE academic_years SET is_active=0');
                    $pdo->prepare('INSERT INTO academic_years (name,starts_on,ends_on,is_active) VALUES (?,?,?,?)')->execute([$name,self::dateOrNull($_POST['starts_on']??null),self::dateOrNull($_POST['ends_on']??null),isset($_POST['is_active'])?1:0]);
                    $id=(int)$pdo->lastInsertId(); Auth::audit('academics.year.create','Created academic year '.$name,null,'academic_year',$id); return ['Academic year created.',null];
                case 'update_year':
                    $id=(int)($_POST['year_id']??0);$name=trim((string)($_POST['name']??''));if($id<1||$name==='')return[null,'Valid academic year and name are required.'];
                    $pdo->prepare('UPDATE academic_years SET name=?,starts_on=?,ends_on=? WHERE id=?')->execute([$name,self::dateOrNull($_POST['starts_on']??null),self::dateOrNull($_POST['ends_on']??null),$id]);Auth::audit('academics.year.update','Updated academic year '.$name,null,'academic_year',$id);return['Academic year updated.',null];
                case 'activate_year':
                    $id=(int)($_POST['year_id']??0);if($id<1)return[null,'Invalid academic year.'];$pdo->beginTransaction();$pdo->exec('UPDATE academic_years SET is_active=0');$pdo->prepare('UPDATE academic_years SET is_active=1 WHERE id=?')->execute([$id]);$pdo->commit();Auth::audit('academics.year.activate','Changed active academic year',null,'academic_year',$id);return['Active academic year updated.',null];
                case 'delete_year':
                    $id=(int)($_POST['year_id']??0);if($id<1)return[null,'Invalid academic year.'];$st=$pdo->prepare('SELECT is_active FROM academic_years WHERE id=?');$st->execute([$id]);if((int)$st->fetchColumn()===1)return[null,'The active academic year cannot be deleted. Set another year active first.'];
                    if(self::dependentCount($pdo,[['batches','academic_year_id'],['semesters','academic_year_id'],['registration_invite_contexts','academic_year_id']],$id)>0)return[null,'This academic year is still used by batches, semesters or onboarding links.'];
                    $pdo->prepare('DELETE FROM academic_years WHERE id=?')->execute([$id]);Auth::audit('academics.year.delete','Deleted academic year',null,'academic_year',$id);return['Academic year deleted.',null];
                case 'create_department':
                    $name=trim((string)($_POST['name']??''));$code=trim((string)($_POST['code']??''))?:null;if($name==='')return[null,'Department name is required.'];$pdo->prepare('INSERT INTO departments (name,code) VALUES (?,?)')->execute([$name,$code]);return['Department created.',null];
                case 'update_department':
                    $id=(int)($_POST['department_id']??0);$name=trim((string)($_POST['name']??''));$code=trim((string)($_POST['code']??''))?:null;if($id<1||$name==='')return[null,'Department name is required.'];$pdo->prepare('UPDATE departments SET name=?,code=? WHERE id=?')->execute([$name,$code,$id]);return['Department updated.',null];
                case 'delete_department':
                    $id=(int)($_POST['department_id']??0);if($id<1)return[null,'Invalid department.'];if(self::countWhere($pdo,'classes','department_id',$id)>0)return[null,'Move or delete the classes in this department first.'];$pdo->prepare('DELETE FROM departments WHERE id=?')->execute([$id]);return['Department deleted.',null];
                case 'create_class':
                    $name=trim((string)($_POST['name']??''));if($name==='')return[null,'Class name is required.'];$pdo->prepare('INSERT INTO classes (name,department_id) VALUES (?,?)')->execute([$name,self::idOrNull($_POST['department_id']??null)]);return['Class created.',null];
                case 'update_class':
                    $id=(int)($_POST['class_id']??0);$name=trim((string)($_POST['name']??''));if($id<1||$name==='')return[null,'Class name is required.'];$pdo->prepare('UPDATE classes SET name=?,department_id=? WHERE id=?')->execute([$name,self::idOrNull($_POST['department_id']??null),$id]);return['Class updated.',null];
                case 'delete_class':
                    $id=(int)($_POST['class_id']??0);if($id<1)return[null,'Invalid class.'];if(self::dependentCount($pdo,[['sections','class_id'],['batches','class_id']],$id)>0)return[null,'Delete or move sections and batches that use this class first.'];$pdo->prepare('DELETE FROM classes WHERE id=?')->execute([$id]);return['Class deleted.',null];
                case 'create_section':
                    $name=trim((string)($_POST['name']??''));$classId=(int)($_POST['class_id']??0);if($name===''||$classId<1)return[null,'Class and section name are required.'];$pdo->prepare('INSERT INTO sections (class_id,name) VALUES (?,?)')->execute([$classId,$name]);return['Section created.',null];
                case 'update_section':
                    $id=(int)($_POST['section_id']??0);$name=trim((string)($_POST['name']??''));$classId=(int)($_POST['class_id']??0);if($id<1||$name===''||$classId<1)return[null,'Class and section name are required.'];$pdo->prepare('UPDATE sections SET class_id=?,name=? WHERE id=?')->execute([$classId,$name,$id]);return['Section updated.',null];
                case 'delete_section':
                    $id=(int)($_POST['section_id']??0);if($id<1)return[null,'Invalid section.'];if(self::countWhere($pdo,'batches','section_id',$id)>0)return[null,'Move or delete batches that use this section first.'];$pdo->prepare('DELETE FROM sections WHERE id=?')->execute([$id]);return['Section deleted.',null];
                case 'create_batch':
                    $name=trim((string)($_POST['name']??''));if($name==='')return[null,'Batch name is required.'];$classId=self::idOrNull($_POST['class_id']??null);$sectionId=self::idOrNull($_POST['section_id']??null);if(!self::validSectionForClass($pdo,$sectionId,$classId))return[null,'The selected section does not belong to the selected class.'];$pdo->prepare('INSERT INTO batches (name,academic_year_id,class_id,section_id) VALUES (?,?,?,?)')->execute([$name,self::idOrNull($_POST['academic_year_id']??null),$classId,$sectionId]);return['Batch created.',null];
                case 'update_batch':
                    $id=(int)($_POST['batch_id']??0);$name=trim((string)($_POST['name']??''));if($id<1||$name==='')return[null,'Valid batch and name are required.'];$classId=self::idOrNull($_POST['class_id']??null);$sectionId=self::idOrNull($_POST['section_id']??null);if(!self::validSectionForClass($pdo,$sectionId,$classId))return[null,'The selected section does not belong to the selected class.'];$pdo->prepare('UPDATE batches SET name=?,academic_year_id=?,class_id=?,section_id=? WHERE id=?')->execute([$name,self::idOrNull($_POST['academic_year_id']??null),$classId,$sectionId,$id]);Auth::audit('academics.batch.update','Updated batch '.$name,null,'batch',$id);return['Batch updated.',null];
                case 'delete_batch':
                    $id=(int)($_POST['batch_id']??0);if($id<1)return[null,'Invalid batch.'];if(self::dependentCount($pdo,[['batch_members','batch_id'],['course_batches','batch_id'],['enrollments','batch_id'],['registration_invite_contexts','batch_id']],$id)>0)return[null,'This batch is in use by students, courses, enrollments or onboarding links. Remove those links first.'];$pdo->prepare('DELETE FROM batches WHERE id=?')->execute([$id]);Auth::audit('academics.batch.delete','Deleted empty batch',null,'batch',$id);return['Batch deleted.',null];
                case 'create_semester':
                    $name=trim((string)($_POST['name']??''));if($name==='')return[null,'Semester name is required.'];$pdo->prepare('INSERT INTO semesters (name,academic_year_id,starts_on,ends_on) VALUES (?,?,?,?)')->execute([$name,self::idOrNull($_POST['academic_year_id']??null),self::dateOrNull($_POST['starts_on']??null),self::dateOrNull($_POST['ends_on']??null)]);return['Semester created.',null];
                case 'update_semester':
                    $id=(int)($_POST['semester_id']??0);$name=trim((string)($_POST['name']??''));if($id<1||$name==='')return[null,'Semester name is required.'];$pdo->prepare('UPDATE semesters SET name=?,academic_year_id=?,starts_on=?,ends_on=? WHERE id=?')->execute([$name,self::idOrNull($_POST['academic_year_id']??null),self::dateOrNull($_POST['starts_on']??null),self::dateOrNull($_POST['ends_on']??null),$id]);return['Semester updated.',null];
                case 'delete_semester':
                    $id=(int)($_POST['semester_id']??0);if($id<1)return[null,'Invalid semester.'];$pdo->prepare('DELETE FROM semesters WHERE id=?')->execute([$id]);return['Semester deleted.',null];
                case 'add_batch_member':
                    $batchId=(int)($_POST['batch_id']??0);$userId=(int)($_POST['user_id']??0);if($batchId<1||$userId<1)return[null,'Choose a batch and student.'];if(!self::isStudent($pdo,$userId))return[null,'The selected account is not an active student.'];$sql=\OpenLMS\Core\Config::get('database.driver')==='sqlite'?'INSERT OR IGNORE INTO batch_members (batch_id,user_id,assigned_at) VALUES (?,?,?)':'INSERT IGNORE INTO batch_members (batch_id,user_id,assigned_at) VALUES (?,?,?)';$pdo->prepare($sql)->execute([$batchId,$userId,$now]);Auth::audit('academics.batch.member','Assigned student to batch',null,'batch',$batchId);return['Student assigned to batch.',null];
                case 'remove_batch_member':
                    $batchId=(int)($_POST['batch_id']??0);$userId=(int)($_POST['user_id']??0);if($batchId<1||$userId<1)return[null,'Invalid batch member.'];$pdo->prepare('DELETE FROM batch_members WHERE batch_id=? AND user_id=?')->execute([$batchId,$userId]);Auth::audit('academics.batch.member_remove','Removed student from batch',null,'batch',$batchId);return['Student removed from batch.',null];
                default:return[null,'Unknown academic action.'];
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg=$e->getMessage();
            if(str_contains($msg,'UNIQUE')||str_contains($msg,'Duplicate'))return[null,'That academic record already exists.'];
            if(str_contains(strtolower($msg),'foreign key'))return[null,'This record is still in use and cannot be deleted yet.'];
            return[null,'Unable to save academic setup.'];
        }
    }

    private static function dependentCount(\PDO $pdo,array $relations,int $id):int
    {
        $total=0;foreach($relations as [$table,$column]){$total+=self::countWhere($pdo,$table,$column,$id);}return $total;
    }
    private static function countWhere(\PDO $pdo,string $table,string $column,int $id):int
    {
        if(!preg_match('/^[a-z_]+$/',$table.$column))return 0;
        try{$st=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=?");$st->execute([$id]);return(int)$st->fetchColumn();}catch(\Throwable){return 0;}
    }
    private static function validSectionForClass(\PDO $pdo,?int $sectionId,?int $classId):bool
    {
        if($sectionId===null)return true;if($classId===null)return false;$st=$pdo->prepare('SELECT COUNT(*) FROM sections WHERE id=? AND class_id=?');$st->execute([$sectionId,$classId]);return(int)$st->fetchColumn()>0;
    }
    private static function isStudent(\PDO $pdo,int $userId):bool
    {
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id INNER JOIN users u ON u.id=ur.user_id WHERE ur.user_id=? AND r.name='student' AND u.status='active'");$stmt->execute([$userId]);return(int)$stmt->fetchColumn()>0;
    }
    private static function idOrNull(mixed $value):?int{$id=(int)$value;return$id>0?$id:null;}
    private static function dateOrNull(mixed $value):?string{$v=trim((string)$value);return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:null;}
}
