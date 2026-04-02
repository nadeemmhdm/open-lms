<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

$exam_id = $_GET['exam_id'] ?? 0;
if (!$exam_id) redirect('exams.php');

// Fetch Exam
$stmt = $pdo->prepare("SELECT e.*, b.name as batch_name FROM exams e JOIN batches b ON e.batch_id = b.id WHERE e.id = ?");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if (!$exam) redirect('exams.php');

// Handle Delete Attempt
if (isset($_GET['delete_attempt'])) {
    $attempt_id = (int)$_GET['delete_attempt'];
    // By deleting from student_exams, cascading delete will clear student_answers
    $stmt = $pdo->prepare("DELETE FROM student_exams WHERE id = ?");
    $stmt->execute([$attempt_id]);
    redirect("exam_submissions.php?exam_id=$exam_id&msg=Attempt deleted. Student can now retake the exam.");
}

// Handle Publish
if (isset($_GET['publish_results'])) {
    $stmt = $pdo->prepare("UPDATE student_exams SET is_result_published = 1 WHERE exam_id = ? AND status = 'submitted'");
    $stmt->execute([$exam_id]);
    redirect("exam_submissions.php?exam_id=$exam_id&msg=Results published!");
}

// Fetch Submissions with LEFT JOIN and more info
$stmt = $pdo->prepare("SELECT se.*, u.name as student_name, u.email as student_email 
                       FROM student_exams se 
                       LEFT JOIN users u ON se.student_id = u.id 
                       WHERE se.exam_id = ? 
                       ORDER BY se.status DESC, se.submit_time DESC");
$stmt->execute([$exam_id]);
$submissions = $stmt->fetchAll();

// Diagnostic counters
$total_count = count($submissions);
$finalized_count = 0;
foreach ($submissions as $s) if ($s['status'] === 'submitted') $finalized_count++;

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-end;">
    <div>
        <a href="exams.php" style="color: #64748b; font-weight: 600; font-size: 0.9rem;"><i class="fas fa-arrow-left"></i> Back to Exams</a>
        <h2 style="font-weight: 800; color: var(--dark); margin: 15px 0 5px;"><?= htmlspecialchars($exam['title']) ?></h2>
        <div style="color: #64748b;"><i class="fas fa-users"></i> Batch: <?= htmlspecialchars($exam['batch_name']) ?></div>
    </div>
    
    <div style="display: flex; gap: 10px;">
        <a href="?exam_id=<?= $exam_id ?>&publish_results=1" class="btn btn-success" onclick="return confirm('Publish all finalized results? Students will be able to see their marks.')" style="border-radius: 12px; padding: 12px 25px;">
            <i class="fas fa-bullhorn"></i> Publish All Results
        </a>
    </div>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="badge badge-success" style="display: block; padding: 15px; margin-bottom: 25px; border-radius: 12px;">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['msg']) ?>
    </div>
<?php endif; ?>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Student Profile</th>
                <th>Attempt Info</th>
                <th>Status</th>
                <th>Score</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($submissions as $s): ?>
                <tr>
                    <td>
                        <div style="font-weight: 700; color: var(--dark);"><?= htmlspecialchars($s['student_name']) ?></div>
                        <small style="color: #64748b;"><?= htmlspecialchars($s['student_email']) ?></small>
                    </td>
                    <td>
                        <div style="font-size: 0.85rem; color: #475569;">
                            <div><i class="far fa-clock"></i> Start: <?= date('h:i A', strtotime($s['start_time'])) ?></div>
                            <?php if ($s['submit_time']): ?>
                                <div><i class="fas fa-check-double"></i> End: <?= date('h:i A', strtotime($s['submit_time'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($s['status'] == 'submitted'): ?>
                            <span class="badge badge-success">Submitted</span>
                        <?php elseif ($s['status'] == 'blocked'): ?>
                            <span class="badge badge-danger">Blocked (Cheat)</span>
                        <?php else: ?>
                            <span class="badge" style="background:#fefce8; color:#854d0e;">Ongoing</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['score'] !== null): ?>
                            <div style="font-weight: 800; color: var(--primary); font-size: 1.1rem;">
                                <?= (float)$s['score'] ?> / <?= $s['total_marks'] ?>
                            </div>
                            <?php if ($s['is_result_published']): ?>
                                <small style="color: var(--success); font-weight: 700;">(Published)</small>
                            <?php else: ?>
                                <small style="color: #94a3b8;">(Private)</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: #94a3b8;">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display: flex; gap: 8px;">
                            <a href="view_attempt.php?attempt_id=<?= $s['id'] ?>" class="btn btn-sm" style="background: #f1f5ff; color: var(--primary); padding: 8px 15px;">
                                <i class="fas fa-search-plus"></i> View & Grade
                            </a>
                            <a href="?exam_id=<?= $exam_id ?>&delete_attempt=<?= $s['id'] ?>" onclick="return confirm('WARNING: Permanently delete student answers? Student will be able to attempt again.')" 
                               class="btn btn-sm" style="background: #fff1f2; color: #e11d48; padding: 8px 15px;" title="Reset Attempt">
                                <i class="fas fa-undo"></i> Reset
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($submissions)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 60px 40px; color: #94a3b8; background: #fcfdfe; border-radius: 20px;">
                        <div style="font-size: 3.5rem; color: #f1f5f9; margin-bottom: 20px;">
                            <i class="fas fa-user-clock"></i>
                        </div>
                        <h4 style="color: #64748b; font-weight: 800; margin-bottom: 5px;">No Active Attempts Found</h4>
                        <p style="font-size: 0.9rem; margin: 0;">Students assigned to this batch haven't started this exam yet.</p>
                        <p style="font-size: 0.8rem; margin-top: 10px; opacity: 0.7;">Checked Exam ID: #<?= $exam_id ?></p>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div style="margin-top: 25px; display: flex; justify-content: space-between; align-items: center; background: white; padding: 15px 25px; border-radius: 16px; border: 1px solid #f1f5f9;">
    <div style="color: #64748b; font-size: 0.9rem; font-weight: 600;">
        <i class="fas fa-chart-pie" style="color: var(--primary);"></i> 
        Total Activity: <span style="color: var(--dark);"><?= $total_count ?> Students Started</span> 
        <span style="margin: 0 10px; color: #e2e8f0;">|</span>
        Finalized: <span style="color: var(--success);"><?= $finalized_count ?> Submissions</span>
    </div>
    <div style="color: #94a3b8; font-size: 0.8rem;">
        <i class="fas fa-sync-alt"></i> Auto-refreshed with latest data
    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>
