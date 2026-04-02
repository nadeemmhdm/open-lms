<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

$exam_id = $_GET['id'] ?? 0;
if (!$exam_id) redirect('exams.php');

$stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if (!$exam) redirect('exams.php');

// Fetch currently assigned batches
$stmt = $pdo->prepare("SELECT batch_id FROM exam_batches WHERE exam_id = ?");
$stmt->execute([$exam_id]);
$current_batch_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_exam'])) {
    $batch_ids = $_POST['batch_ids'] ?? [];
    $primary_batch_id = !empty($batch_ids) ? $batch_ids[0] : 0;
    $title = trim($_POST['title']);
    $start = $_POST['start_time'];
    $publish_at = !empty($_POST['publish_date']) ? $_POST['publish_date'] : $start;
    $duration = (int)$_POST['duration'];
    $instructions = $_POST['instructions'];

    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("UPDATE exams SET batch_id = ?, title = ?, start_time = ?, publish_date = ?, duration_minutes = ?, instructions = ? WHERE id = ?");
        $stmt->execute([$primary_batch_id, $title, $start, $publish_at, $duration, $instructions, $exam_id]);

        // Sync exam_batches
        $pdo->prepare("DELETE FROM exam_batches WHERE exam_id = ?")->execute([$exam_id]);
        $stmt = $pdo->prepare("INSERT INTO exam_batches (exam_id, batch_id) VALUES (?, ?)");
        foreach ($batch_ids as $bid) {
            $stmt->execute([$exam_id, $bid]);
        }

        $pdo->commit();
        $message = "Examination settings updated successfully!";
        
        // Refresh
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
        $stmt->execute([$exam_id]);
        $exam = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT batch_id FROM exam_batches WHERE exam_id = ?");
        $stmt->execute([$exam_id]);
        $current_batch_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Update Failed: " . $e->getMessage();
    }
}

$batches = $pdo->query("SELECT id, name FROM batches ORDER BY name ASC")->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="fade-in">
    <div style="margin-bottom: 25px; display: flex; align-items: center; gap: 15px;">
        <a href="exams.php" class="btn" style="background: white; color: #64748b; padding: 10px 15px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h2 style="font-weight: 800; margin: 0;">Edit Examination</h2>
    </div>

    <?php if ($message): ?>
        <div class="badge badge-success" style="display: block; padding: 15px; margin-bottom: 25px; border-radius: 12px;">
            <i class="fas fa-check-circle"></i> <?= $message ?>
        </div>
    <?php endif; ?>

    <div class="white-card" style="border-radius: 24px;">
        <form method="POST">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-bottom: 25px;">
                <div class="form-group">
                    <label>Exam Title</label>
                    <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($exam['title']) ?>" required>
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>Target Batches (Select one or more)</label>
                    <div style="max-height: 200px; overflow-y: auto; background: #f8fafc; padding: 20px; border-radius: 16px; border: 2px solid #edeff2;">
                        <?php foreach ($batches as $b): ?>
                            <label style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px; cursor: pointer;">
                                <input type="checkbox" name="batch_ids[]" value="<?= $b['id'] ?>" <?= in_array($b['id'], $current_batch_ids) ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                                <span style="font-weight: 600; color: #475569;"><?= htmlspecialchars($b['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label>Start Date & Time (IST)</label>
                    <input type="datetime-local" name="start_time" class="form-control"
                        value="<?= date('Y-m-d\TH:i', strtotime($exam['start_time'])) ?>" required>
                </div>
                <div class="form-group">
                    <label>Show to Students From (Publish Date)</label>
                    <input type="datetime-local" name="publish_date" class="form-control"
                        value="<?= $exam['publish_date'] ? date('Y-m-d\TH:i', strtotime($exam['publish_date'])) : '' ?>" placeholder="Leave blank to use Start Time">
                </div>
                <div class="form-group">
                    <label>Duration (Minutes)</label>
                    <input type="number" name="duration" class="form-control" min="10"
                        value="<?= $exam['duration_minutes'] ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Candidate Instructions (Spaces and Alignment Preserved)</label>
                <textarea name="instructions" class="form-control" rows="8" style="resize: vertical; font-family: inherit;"><?= htmlspecialchars($exam['instructions']) ?></textarea>
                <small style="color: #94a3b8;">Students will see exactly what you type here, including line breaks and indentation.</small>
            </div>

            <div style="display: flex; gap: 15px; margin-top: 30px; border-top: 1px solid #f1f5f9; padding-top: 30px;">
                <button type="submit" name="update_exam" class="btn btn-primary" style="padding: 15px 40px; border-radius: 16px;">
                    Save All Changes <i class="fas fa-save" style="margin-left: 8px;"></i>
                </button>
                <a href="manage_questions.php?exam_id=<?= $exam_id ?>" class="btn" style="background: #eef2ff; color: #4f46e5; padding: 15px 30px; border-radius: 16px;">
                    Manage Questions <i class="fas fa-list-ol" style="margin-left: 8px;"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>