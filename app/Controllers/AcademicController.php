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

        ob_start(); ?>
        <div class="page-head"><div><h1>Academic setup</h1><p class="muted">Configure years, departments, classes, sections, batches, semesters and student batch membership.</p></div></div>
        <?php if($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

        <div class="academic-grid">
          <section class="card"><h2>Academic years</h2>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_year">
              <label>Name<input name="name" placeholder="2026–2027" required maxlength="80"></label>
              <div class="form-row"><label>Starts<input type="date" name="starts_on"></label><label>Ends<input type="date" name="ends_on"></label></div>
              <label class="check-row"><input type="checkbox" name="is_active" value="1"> Make active year</label><button class="btn btn-primary">Add academic year</button>
            </form>
            <div class="compact-list"><?php foreach($years as $year): ?><div><span><strong><?= e($year['name']) ?></strong><small><?= e(($year['starts_on'] ?: '—').' → '.($year['ends_on'] ?: '—')) ?></small></span><?php if($year['is_active']): ?><span class="badge badge-ok">Active</span><?php else: ?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="activate_year"><input type="hidden" name="year_id" value="<?= (int)$year['id'] ?>"><button class="btn btn-small">Set active</button></form><?php endif; ?></div><?php endforeach; ?></div>
          </section>

          <section class="card"><h2>Departments & classes</h2>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_department"><div class="form-row"><label>Department<input name="name" required maxlength="120"></label><label>Code<input name="code" maxlength="40"></label></div><button class="btn">Add department</button></form><hr>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_class"><label>Class / grade / year<input name="name" required maxlength="100"></label><label>Department<select name="department_id"><option value="">None</option><?php foreach($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></label><button class="btn">Add class</button></form>
            <div class="compact-list"><?php foreach($classes as $c): ?><div><span><strong><?= e($c['name']) ?></strong><small><?= e($c['department_name'] ?: 'No department') ?></small></span></div><?php endforeach; ?></div>
          </section>

          <section class="card"><h2>Sections & batches</h2>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_section"><div class="form-row"><label>Class<select name="class_id" required><option value="">Choose</option><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label><label>Section<input name="name" placeholder="A" required maxlength="80"></label></div><button class="btn">Add section</button></form><hr>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_batch"><label>Batch name<input name="name" placeholder="BCA 2026 A" required maxlength="100"></label><div class="form-row"><label>Academic year<select name="academic_year_id"><option value="">None</option><?php foreach($years as $y): ?><option value="<?= (int)$y['id'] ?>"><?= e($y['name']) ?></option><?php endforeach; ?></select></label><label>Class<select name="class_id"><option value="">None</option><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label></div><label>Section<select name="section_id"><option value="">None</option><?php foreach($sections as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['class_name'].' / '.$s['name']) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary">Add batch</button></form>
          </section>

          <section class="card"><h2>Semesters</h2>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="create_semester"><label>Name<input name="name" placeholder="Semester 1" required maxlength="80"></label><label>Academic year<select name="academic_year_id"><option value="">None</option><?php foreach($years as $y): ?><option value="<?= (int)$y['id'] ?>"><?= e($y['name']) ?></option><?php endforeach; ?></select></label><div class="form-row"><label>Starts<input type="date" name="starts_on"></label><label>Ends<input type="date" name="ends_on"></label></div><button class="btn">Add semester</button></form>
            <div class="compact-list"><?php foreach($semesters as $s): ?><div><span><strong><?= e($s['name']) ?></strong><small><?= e($s['academic_year_name'] ?: 'No academic year') ?></small></span></div><?php endforeach; ?></div>
          </section>

          <section class="card academic-wide"><h2>Batch membership</h2><p class="hint">A student can belong to one or more batches. Course-to-batch assignment grants learning access without manually enrolling every student.</p>
            <form method="post" class="form-row-3"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="add_batch_member"><label>Batch<select name="batch_id" required><option value="">Choose</option><?php foreach($batches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></label><label>Student<select name="user_id" required><option value="">Choose</option><?php foreach($students as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name'].' · '.$s['email']) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary align-end">Assign student</button></form>
            <div class="table-scroll"><table><thead><tr><th>Batch</th><th>Academic year</th><th>Class / section</th><th>Students</th></tr></thead><tbody><?php foreach($batches as $b): ?><tr><td><strong><?= e($b['name']) ?></strong></td><td><?= e($b['academic_year_name'] ?: '—') ?></td><td><?= e(trim(($b['class_name'] ?: '').' '.($b['section_name'] ?: '')) ?: '—') ?></td><td><?= (int)$b['member_count'] ?></td></tr><?php endforeach; ?></tbody></table></div>
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
                    $pdo->prepare('INSERT INTO academic_years (name,starts_on,ends_on,is_active) VALUES (?,?,?,?)')->execute([$name, self::dateOrNull($_POST['starts_on'] ?? null), self::dateOrNull($_POST['ends_on'] ?? null), isset($_POST['is_active']) ? 1 : 0]);
                    Auth::audit('academics.year.create', 'Created academic year ' . $name, null, 'academic_year', (int)$pdo->lastInsertId());
                    return ['Academic year created.', null];
                case 'activate_year':
                    $id = (int)($_POST['year_id'] ?? 0); if ($id < 1) return [null, 'Invalid academic year.'];
                    $pdo->beginTransaction(); $pdo->exec('UPDATE academic_years SET is_active=0'); $pdo->prepare('UPDATE academic_years SET is_active=1 WHERE id=?')->execute([$id]); $pdo->commit();
                    Auth::audit('academics.year.activate', 'Changed active academic year', null, 'academic_year', $id); return ['Active academic year updated.', null];
                case 'create_department':
                    $name = trim((string)($_POST['name'] ?? '')); $code = trim((string)($_POST['code'] ?? '')) ?: null;
                    if ($name === '') return [null, 'Department name is required.'];
                    $pdo->prepare('INSERT INTO departments (name,code) VALUES (?,?)')->execute([$name,$code]); return ['Department created.', null];
                case 'create_class':
                    $name = trim((string)($_POST['name'] ?? '')); if ($name === '') return [null, 'Class name is required.'];
                    $pdo->prepare('INSERT INTO classes (name,department_id) VALUES (?,?)')->execute([$name,self::idOrNull($_POST['department_id'] ?? null)]); return ['Class created.', null];
                case 'create_section':
                    $name = trim((string)($_POST['name'] ?? '')); $classId = (int)($_POST['class_id'] ?? 0); if ($name === '' || $classId < 1) return [null, 'Class and section name are required.'];
                    $pdo->prepare('INSERT INTO sections (class_id,name) VALUES (?,?)')->execute([$classId,$name]); return ['Section created.', null];
                case 'create_batch':
                    $name = trim((string)($_POST['name'] ?? '')); if ($name === '') return [null, 'Batch name is required.'];
                    $pdo->prepare('INSERT INTO batches (name,academic_year_id,class_id,section_id) VALUES (?,?,?,?)')->execute([$name,self::idOrNull($_POST['academic_year_id'] ?? null),self::idOrNull($_POST['class_id'] ?? null),self::idOrNull($_POST['section_id'] ?? null)]); return ['Batch created.', null];
                case 'create_semester':
                    $name = trim((string)($_POST['name'] ?? '')); if ($name === '') return [null, 'Semester name is required.'];
                    $pdo->prepare('INSERT INTO semesters (name,academic_year_id,starts_on,ends_on) VALUES (?,?,?,?)')->execute([$name,self::idOrNull($_POST['academic_year_id'] ?? null),self::dateOrNull($_POST['starts_on'] ?? null),self::dateOrNull($_POST['ends_on'] ?? null)]); return ['Semester created.', null];
                case 'add_batch_member':
                    $batchId=(int)($_POST['batch_id']??0); $userId=(int)($_POST['user_id']??0); if($batchId<1||$userId<1) return [null,'Choose a batch and student.'];
                    if (!self::isStudent($pdo,$userId)) return [null,'The selected account is not an active student.'];
                    $sql = \OpenLMS\Core\Config::get('database.driver') === 'sqlite' ? 'INSERT OR IGNORE INTO batch_members (batch_id,user_id,assigned_at) VALUES (?,?,?)' : 'INSERT IGNORE INTO batch_members (batch_id,user_id,assigned_at) VALUES (?,?,?)';
                    $pdo->prepare($sql)->execute([$batchId,$userId,$now]); Auth::audit('academics.batch.member', 'Assigned student to batch', null, 'batch', $batchId); return ['Student assigned to batch.', null];
                default: return [null, 'Unknown academic action.'];
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = $e->getMessage();
            if (str_contains($msg, 'UNIQUE') || str_contains($msg, 'Duplicate')) return [null, 'That academic record already exists.'];
            return [null, 'Unable to save academic setup.'];
        }
    }

    private static function isStudent(\PDO $pdo, int $userId): bool
    {
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id INNER JOIN users u ON u.id=ur.user_id WHERE ur.user_id=? AND r.name='student' AND u.status='active'"); $stmt->execute([$userId]); return (int)$stmt->fetchColumn()>0;
    }
    private static function idOrNull(mixed $value): ?int { $id=(int)$value; return $id>0?$id:null; }
    private static function dateOrNull(mixed $value): ?string { $v=trim((string)$value); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:null; }
}
