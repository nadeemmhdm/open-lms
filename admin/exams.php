<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';
require_once $path_to_root . 'includes/mailer.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_exam'])) {
    $batch_ids = $_POST['batch_ids']; // Array of batch IDs
    $primary_batch_id = $batch_ids[0]; // For legacy support in exams table
    $title = trim($_POST['title']);
    $start = $_POST['start_time'];
    $publish_at = !empty($_POST['publish_date']) ? $_POST['publish_date'] : $start; 
    $duration = (int)$_POST['duration'];
    $max_attempts = (int)$_POST['max_attempts'];
    $instructions = $_POST['instructions'];
    $is_cert = isset($_POST['is_certificate_exam']) ? 1 : 0;
    $is_private = isset($_POST['is_private']) ? 1 : 0;
    $course_id = !empty($_POST['course_id']) ? $_POST['course_id'] : null;

    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO exams (batch_id, title, start_time, publish_date, duration_minutes, max_attempts, instructions, is_published, is_certificate_exam, is_private, course_id) VALUES (?,?,?,?,?,?,?, 1, ?, ?, ?)");
        $stmt->execute([$primary_batch_id, $title, $start, $publish_at, $duration, $max_attempts, $instructions, $is_cert, $is_private, $course_id]);
        $exam_id = $pdo->lastInsertId();

        // Insert into exam_batches
        $stmt = $pdo->prepare("INSERT INTO exam_batches (exam_id, batch_id) VALUES (?, ?)");
        foreach ($batch_ids as $bid) {
            $stmt->execute([$exam_id, $bid]);
        }

        $pdo->commit();
        $message = "Exam assigned successfully! You can now add questions.";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Database Error: " . $e->getMessage();
    }
}

// Actions
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM exams WHERE id = ?")->execute([$_GET['delete']]);
    $message = "Exam removed successfully.";
}

if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $pdo->prepare("UPDATE exams SET is_published = 1 - is_published WHERE id = ?")->execute([$id]);
    redirect('exams.php');
}

$batches = $pdo->query("SELECT id, name FROM batches ORDER BY name ASC")->fetchAll();
$exams = $pdo->query("SELECT e.*, 
                     (SELECT GROUP_CONCAT(b.name SEPARATOR ', ') FROM exam_batches eb JOIN batches b ON eb.batch_id = b.id WHERE eb.exam_id = e.id) as batch_names,
                     (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.id) as question_count,
                     (SELECT COUNT(*) FROM student_exams se WHERE se.exam_id = e.id AND se.status = 'submitted') as submitted_count
                     FROM exams e ORDER BY e.start_time DESC")->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="fade-in">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <div>
            <h2 style="font-weight: 800; color: var(--dark); margin: 0;">Exam Management</h2>
            <p style="color: #64748b; margin-top: 5px;">Schedule and oversee academic assessments.</p>
        </div>
        <button onclick="document.getElementById('createExamModal').style.display='flex'" class="btn btn-primary">
            <i class="fas fa-plus"></i> Create New Exam
        </button>
    </div>

    <?php if ($message): ?>
        <div class="badge badge-success" style="display: block; padding: 15px; margin-bottom: 25px; border-radius: 12px; font-size: 0.95rem;">
            <i class="fas fa-check-circle"></i> <?= $message ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="badge badge-danger" style="display: block; padding: 15px; margin-bottom: 25px; border-radius: 12px; font-size: 0.95rem;">
            <i class="fas fa-exclamation-circle"></i> <?= $error ?>
        </div>
    <?php endif; ?>

    <!-- Modal for Creation -->
    <div id="createExamModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); z-index: 2000; align-items: center; justify-content: center; padding: 20px;">
        <div class="white-card" style="width: 100%; max-width: 800px; max-height: 90vh; border-radius: 24px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); position: relative; display: flex; flex-direction: column; overflow: hidden;">
            <div style="padding: 30px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
                <h3 style="margin: 0; font-weight: 800;">Schedule New Examination</h3>
                <i class="fas fa-times" style="cursor: pointer; color: #94a3b8;" onclick="document.getElementById('createExamModal').style.display='none'"></i>
            </div>
            <form method="POST" style="padding: 30px; overflow-y: auto; flex: 1;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label>Exam Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Midterm Physics" required>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Target Batches (Select one or more)</label>
                        <div style="max-height: 150px; overflow-y: auto; background: #f8fafc; padding: 15px; border-radius: 12px; border: 2px solid #edeff2;">
                            <?php foreach ($batches as $b): ?>
                                <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px; cursor: pointer;">
                                    <input type="checkbox" name="batch_ids[]" value="<?= $b['id'] ?>">
                                    <span style="font-size: 0.95rem; font-weight: 600; color: #475569;"><?= htmlspecialchars($b['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Exam Start Time (Local IST)</label>
                        <input type="datetime-local" name="start_time" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Display Exam From (Date & Time)</label>
                        <input type="datetime-local" name="publish_date" class="form-control" placeholder="Optional: When should students see this?">
                    </div>
                    <div class="form-group">
                        <label>Duration (Minutes)</label>
                        <input type="number" name="duration" class="form-control" min="10" value="60" required>
                    </div>
                    <div class="form-group">
                        <label>Entry Model (Attempts)</label>
                        <select name="max_attempts" class="form-control">
                            <option value="1">Single Attempt Only</option>
                            <option value="2">2 Attempts Allowed</option>
                            <option value="3">3 Attempts Allowed</option>
                            <option value="99">Unlimited Attempts</option>
                        </select>
                    </div>
                </div>

                <!-- Advanced Options -->
                <div style="background: #f8fafc; padding: 20px; border-radius: 16px; margin-bottom: 20px; border: 1px solid #e2e8f0;">
                    <h4 style="margin-top: 0; font-size: 0.95rem; font-weight: 800; color: #475569; margin-bottom: 15px;"><i class="fas fa-sliders-h"></i> Advanced Options</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="is_certificate_exam" value="1"> 
                                <span style="font-weight: 600; color: #1e293b;">Mark as Certificate Exam</span>
                            </label>
                            <small style="color: #64748b; margin-top: 5px; display: block;">Successfully passing this exam grants a certificate.</small>
                        </div>
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="is_private" value="1"> 
                                <span style="font-weight: 600; color: #1e293b;">Mark as Private Exam</span>
                            </label>
                            <small style="color: #64748b; margin-top: 5px; display: block;">Only accessible via direct link or specific enrollment.</small>
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Associate with Course (For End-of-Course Certificate)</label>
                            <select name="course_id" class="form-control">
                                <option value="">-- No Direct Course Association --</option>
                                <?php 
                                $course_list = $pdo->query("SELECT id, title FROM courses ORDER BY title ASC")->fetchAll();
                                foreach ($course_list as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color: #64748b; margin-top: 5px; display: block;">If selected, this exam will appear as the final step in the syllabus.</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Instructions for Candidates (Formatting Preserved)</label>
                    <textarea name="instructions" class="form-control" rows="4" style="resize: vertical;" placeholder="1. Minimum 50% required.&#10;2. Do not refresh.&#10;3. Full screen required."></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px;">
                    <button type="button" onclick="document.getElementById('createExamModal').style.display='none'" class="btn" style="background: #f1f5f9; color: #475569;">Discard</button>
                    <button type="submit" name="create_exam" class="btn btn-primary" style="padding: 12px 40px;" onclick="validateBatchSelection(event, 'createExamModal')">Publish Exam</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Exam Information</th>
                    <th>Target Batch</th>
                    <th>Stats</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($exams as $ex): 
                    $isPast = strtotime($ex['start_time']) + ($ex['duration_minutes'] * 60) < time();
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight: 700; color: var(--dark); font-size: 1rem;"><?= htmlspecialchars($ex['title']) ?></div>
                            <small style="color: #64748b;">
                                <i class="far fa-calendar-alt"></i> <?= date('M d, Y', strtotime($ex['start_time'])) ?> 
                                <span style="margin: 0 5px;">|</span>
                                <i class="far fa-clock"></i> <?= date('h:i A', strtotime($ex['start_time'])) ?>
                                <span style="margin: 0 5px;">|</span>
                                <i class="fas fa-hourglass-half"></i> <?= $ex['duration_minutes'] ?>m
                                <span style="margin: 0 5px;">|</span>
                                <i class="fas fa-redo"></i> <?= $ex['max_attempts'] > 1 ? ($ex['max_attempts'] == 99 ? 'Unlimited' : $ex['max_attempts'] . ' Attempts') : 'Single Entry' ?>
                            </small>
                        </td>
                        <td>
                            <div style="display: flex; flex-wrap: wrap; gap: 4px; max-width: 250px;">
                                <?php 
                                $bnames = explode(', ', $ex['batch_names'] ?? '');
                                foreach ($bnames as $bn): 
                                    if (empty($bn)) continue;
                                ?>
                                    <span class="badge" style="background: #eef2ff; color: #4f46e5; border: 1px solid #e0e7ff; font-size: 0.7rem;"><?= htmlspecialchars($bn) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-size: 0.85rem; color: #64748b;">
                                <div title="Total Questions"><i class="fas fa-list-ul" style="width: 20px;"></i> <?= $ex['question_count'] ?> Qs</div>
                                <a href="exam_submissions.php?exam_id=<?= $ex['id'] ?>" title="View Student Submissions" style="color: var(--primary); font-weight: 700;">
                                    <i class="fas fa-user-check" style="width: 20px;"></i> <?= $ex['submitted_count'] ?> Finalized
                                </a>
                            </div>
                        </td>
                        <td>
                            <?php if ($ex['is_published']): ?>
                                <span class="badge badge-success" style="font-size: 0.75rem;"><i class="fas fa-eye"></i> Published</span>
                            <?php else: ?>
                                <span class="badge" style="background: #f1f5f9; color: #94a3b8; font-size: 0.75rem;"><i class="fas fa-eye-slash"></i> Draft</span>
                            <?php endif; ?>
                            
                            <?php if ($ex['is_certificate_exam']): ?>
                                <br><span class="badge" style="background: #fef3c7; color: #d97706; font-size: 0.7rem; margin-top: 5px;"><i class="fas fa-certificate"></i> Cert</span>
                            <?php endif; ?>
                            
                            <?php if ($ex['is_private']): ?>
                                <br><span class="badge" style="background: #f1f5f9; color: #475569; font-size: 0.7rem; margin-top: 5px;"><i class="fas fa-lock"></i> Private</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: 6px;">
                                <a href="exam_submissions.php?exam_id=<?= $ex['id'] ?>" class="btn btn-sm" style="background: var(--primary); color: white;" title="Submissions & Grading"><i class="fas fa-user-graduate"></i></a>
                                <a href="manage_questions.php?exam_id=<?= $ex['id'] ?>" class="btn btn-sm" style="background: #f1f5ff; color: var(--primary);" title="Blueprint"><i class="fas fa-plus"></i></a>
                                <a href="edit_exam.php?id=<?= $ex['id'] ?>" class="btn btn-sm" style="background: #f8fafc; color: #475569;" title="Settings"><i class="fas fa-cog"></i></a>
                                <a href="?toggle_status=<?= $ex['id'] ?>" class="btn btn-sm" style="background: <?= $ex['is_published'] ? '#fff1f2; color: #e11d48;' : '#f0fdf4; color: #166534;' ?>" title="<?= $ex['is_published'] ? 'Hide' : 'Publish' ?>">
                                    <i class="fas <?= $ex['is_published'] ? 'fa-eye-slash' : 'fa-check' ?>"></i>
                                </a>
                                <a href="?delete=<?= $ex['id'] ?>" onclick="return confirm('Delete exam and all data?')" class="btn btn-sm" style="background: #fff1f2; color: #e11d48;"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($exams)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #94a3b8;">
                            No examinations scheduled yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<script>
function validateBatchSelection(event, modalId) {
    const modal = document.getElementById(modalId);
    const checkboxes = modal.querySelectorAll('input[name="batch_ids[]"]');
    let checked = false;
    checkboxes.forEach(cb => { if(cb.checked) checked = true; });
    
    if(!checked) {
        alert("Please select at least one batch.");
        event.preventDefault();
        return false;
    }
}
</script>

<style>
/* Ensure the checkbox list doesn't look like standard inputs */
/* input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
} */
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>