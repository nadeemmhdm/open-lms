<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    redirect('../index.php');
}

$batch_id = (int)($_GET['batch_id'] ?? 0);
if (!$batch_id) {
    redirect('batches.php');
}

// Get Batch Info
$stmt = $pdo->prepare("SELECT b.*, 
    (SELECT GROUP_CONCAT(c.title SEPARATOR ', ') FROM batch_courses bc JOIN courses c ON bc.course_id = c.id WHERE bc.batch_id = b.id) as course_titles 
    FROM batches b WHERE b.id = ?");
$stmt->execute([$batch_id]);
$batch = $stmt->fetch();

if (!$batch) {
    die("Batch not found. Please ensure the batch ID is correct.");
}

// Handle assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_students'])) {
    $student_ids = $_POST['students'] ?? [];
    if (!empty($student_ids)) {
        try {
            $pdo->beginTransaction();
            
            $insertBatchStmt = $pdo->prepare("INSERT IGNORE INTO student_batches (student_id, batch_id) VALUES (?, ?)");
            // Also auto-enroll in all courses linked to this batch
            $insertCourseStmt = $pdo->prepare("INSERT IGNORE INTO student_courses (student_id, course_id, access_type) 
                                               SELECT ?, course_id, 'free' FROM batch_courses WHERE batch_id = ?");
            
            foreach ($student_ids as $sid) {
                $insertBatchStmt->execute([$sid, $batch_id]);
                $insertCourseStmt->execute([$sid, $batch_id]);
            }
            
            $pdo->commit();
            $message = "Students assigned and auto-enrolled successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error during assignment: " . $e->getMessage();
        }
    }
}

// Handle de-assignment
if (isset($_GET['deassign'])) {
    $sid = (int)$_GET['deassign'];
    try {
        $pdo->beginTransaction();
        
        // 1. Remove from batch
        $stmt = $pdo->prepare("DELETE FROM student_batches WHERE student_id = ? AND batch_id = ?");
        $stmt->execute([$sid, $batch_id]);
        
        // 2. ALSO remove from direct course access (student_courses) for courses in this batch
        // ONLY if the student is not enrolled in any OTHER batch for the same courses.
        $stmt = $pdo->prepare("DELETE FROM student_courses 
                               WHERE student_id = ? 
                               AND course_id IN (SELECT course_id FROM batch_courses WHERE batch_id = ?)
                               AND course_id NOT IN (
                                   SELECT bc.course_id 
                                   FROM student_batches sb 
                                   JOIN batch_courses bc ON sb.batch_id = bc.batch_id 
                                   WHERE sb.student_id = ? AND sb.batch_id != ?
                               )");
        $stmt->execute([$sid, $batch_id, $sid, $batch_id]);
        
        $pdo->commit();
        $message = "Student removed from batch and associated courses.";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Failed to remove student: " . $e->getMessage();
    }
}

// Get Currently Assigned Students
$stmt = $pdo->prepare("SELECT u.* FROM users u JOIN student_batches sb ON u.id = sb.student_id WHERE sb.batch_id = ?");
$stmt->execute([$batch_id]);
$assigned_students = $stmt->fetchAll();

// Get Students NOT in this batch
$sql = "SELECT * FROM users WHERE role = 'student' AND status = 'active' AND id NOT IN (SELECT student_id FROM student_batches WHERE batch_id = ?)";
$stmt = $pdo->prepare($sql);
$stmt->execute([$batch_id]);
$available_students = $stmt->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 25px;">
    <a href="batches.php" style="color: #64748b; font-weight: 600;"><i class="fas fa-arrow-left"></i> Back to Batch Management</a>
</div>

<div style="margin-bottom: 40px;">
    <h2 style="font-weight: 800; color: var(--dark); margin: 0;">Enrollment Manager</h2>
    <p style="color: #64748b; margin-top: 5px;">Managing: <span style="color: var(--primary); font-weight: 700;"><?= htmlspecialchars($batch['name']) ?></span></p>
    <small style="color: #94a3b8; font-weight: 600; text-transform: uppercase;"><?= htmlspecialchars($batch['course_titles'] ?: 'No linked courses') ?></small>
</div>

<?php if (isset($message)): ?>
    <div class="badge badge-success" style="display: block; padding: 15px; margin-bottom: 30px; border-radius: 12px; font-weight: 700;">
        <i class="fas fa-check-circle"></i> <?= $message ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
    <!-- Available Students -->
    <div class="white-card" style="background: white; padding: 30px; border-radius: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); height: fit-content; border: 1px solid #f1f5f9;">
        <h4 style="font-weight: 800; color: var(--dark); margin-bottom: 25px;">Available Students</h4>
        <?php if (empty($available_students)): ?>
            <div style="text-align: center; padding: 30px; background: #f8fafc; border-radius: 16px; border: 2px dashed #e2e8f0;">
                <p style="color: #64748b; margin: 0;">No active students available for enrollment.</p>
            </div>
        <?php else: ?>
            <form method="POST">
                <div style="max-height: 500px; overflow-y: auto; padding-right: 10px; margin-bottom: 25px;">
                    <?php foreach ($available_students as $s): ?>
                        <label style="display: flex; align-items: center; padding: 15px; border: 1px solid #f1f5f9; border-radius: 12px; margin-bottom: 10px; cursor: pointer; transition: 0.2s; background: #fcfdfe;">
                            <input type="checkbox" name="students[]" value="<?= $s['id'] ?>" style="width: 18px; height: 18px; margin-right: 15px; accent-color: var(--primary);">
                            <div>
                                <strong style="color: #1e293b; display: block;"><?= htmlspecialchars($s['name']) ?></strong>
                                <small style="color: #64748b; font-weight: 600;"><?= htmlspecialchars($s['email']) ?></small>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="assign_students" class="btn btn-primary" style="width: 100%; border-radius: 12px; padding: 14px; font-weight: 800;">
                    <i class="fas fa-user-plus"></i> Enroll Selected Students
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Currently Assigned -->
    <div class="white-card" style="background: white; padding: 30px; border-radius: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); height: fit-content; border: 1px solid #f1f5f9;">
        <h4 style="font-weight: 800; color: var(--dark); margin-bottom: 5px;">Enrolled Students</h4>
        <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 25px;">Directly removing students from the batch below.</p>
        
        <?php if (empty($assigned_students)): ?>
            <div style="text-align: center; padding: 30px; background: #fcfdfe; border-radius: 16px; border: 2px dashed #e2e8f0;">
                <p style="color: #94a3b8; font-weight: 600;">No students enrolled in this batch yet.</p>
            </div>
        <?php else: ?>
            <div style="max-height: 550px; overflow-y: auto; padding-right: 10px;">
                <?php foreach ($assigned_students as $s): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px; border-radius: 12px; background: #f8fafc; margin-bottom: 12px; border: 1px solid #f1f5f9;">
                        <div>
                            <strong style="color: #1e293b; display: block;"><?= htmlspecialchars($s['name']) ?></strong>
                            <small style="color: #64748b;"><?= htmlspecialchars($s['email']) ?></small>
                        </div>
                        <a href="?batch_id=<?= $batch_id ?>&deassign=<?= $s['id'] ?>" 
                           onclick="return confirm('Remove this student from the batch? Students records will remain but access to batch materials will be revoked.')"
                           style="width: 35px; height: 35px; background: #fff1f2; color: #e11d48; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: 0.2s;" 
                           title="De-assign Student">
                           <i class="fas fa-user-minus"></i>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>