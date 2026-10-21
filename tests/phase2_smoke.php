<?php
declare(strict_types=1);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: pdo_sqlite is not available.\n");
    exit(77);
}

$root = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($root . '/database/migrations/001_init_sqlite.sql'));
$pdo->prepare('INSERT INTO schema_migrations (version,applied_at) VALUES (?,?)')->execute(['001_init', gmdate('Y-m-d H:i:s')]);

$roles = ['super_admin','administrator','teacher','student','hod','examiner','parent'];
foreach ($roles as $role) {
    $pdo->prepare('INSERT INTO roles (name,label,is_system) VALUES (?,?,1)')->execute([$role,$role]);
}
$permissions = ['users.manage','settings.manage','features.manage','academics.manage','courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view','audit.view'];
foreach ($permissions as $permission) {
    $pdo->prepare('INSERT INTO permissions (name,label) VALUES (?,?)')->execute([$permission,$permission]);
}
$pdo->exec((string) file_get_contents($root . '/database/migrations/002_phase2_sqlite.sql'));
$pdo->prepare('INSERT INTO schema_migrations (version,applied_at) VALUES (?,?)')->execute(['002_phase2', gmdate('Y-m-d H:i:s')]);

$now = gmdate('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO users (name,email,password_hash,status,created_at,updated_at) VALUES ('Teacher','teacher@example.test','x','active',?,?)")->execute([$now,$now]);
$teacherId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (name,email,password_hash,status,created_at,updated_at) VALUES ('Student','student@example.test','x','active',?,?)")->execute([$now,$now]);
$studentId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO academic_years (name,is_active) VALUES ('2026',1)"); $yearId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO classes (name) VALUES ('BCA')"); $classId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO batches (name,academic_year_id,class_id) VALUES ('BCA A',?,?)")->execute([$yearId,$classId]); $batchId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO batch_members (batch_id,user_id,assigned_at) VALUES (?,?,?)')->execute([$batchId,$studentId,$now]);
$pdo->prepare("INSERT INTO subjects (name,code,status,created_at,updated_at) VALUES ('Programming','PRG','active',?,?)")->execute([$now,$now]); $subjectId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO courses (subject_id,title,teacher_id,status,created_at,updated_at) VALUES (?,?,?,'published',?,?)")->execute([$subjectId,'PHP',$teacherId,$now,$now]); $courseId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO course_batches (course_id,batch_id,assigned_at) VALUES (?,?,?)')->execute([$courseId,$batchId,$now]);
$pdo->prepare('INSERT INTO course_sections (course_id,title,sort_order) VALUES (?,?,1)')->execute([$courseId,'Basics']); $sectionId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO lessons (course_section_id,title,sort_order,status,created_at,updated_at) VALUES (?,?,1,'published',?,?)")->execute([$sectionId,'Intro',$now,$now]); $lessonId=(int)$pdo->lastInsertId();

$access = $pdo->prepare('SELECT COUNT(*) FROM course_batches cb JOIN batch_members bm ON bm.batch_id=cb.batch_id WHERE cb.course_id=? AND bm.user_id=?');
$access->execute([$courseId,$studentId]);
if ((int)$access->fetchColumn() !== 1) throw new RuntimeException('Batch access relationship failed.');

$pdo->prepare('INSERT INTO lesson_progress (user_id,lesson_id,completed_at,progress_percent) VALUES (?,?,?,100)')->execute([$studentId,$lessonId,$now]);
if ((int)$pdo->query('SELECT progress_percent FROM lesson_progress LIMIT 1')->fetchColumn() !== 100) throw new RuntimeException('Lesson progress failed.');

fwrite(STDOUT, "PASS: Phase 2 SQLite migrations and relationships.\n");
