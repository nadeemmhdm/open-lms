<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = $_SESSION['user_id'];

// Check if student is in any batches
$batch_check = $pdo->prepare("SELECT COUNT(*) FROM student_batches WHERE student_id = ?");
$batch_check->execute([$user_id]);
$in_batches = $batch_check->fetchColumn() > 0;

// Fetch ALL projects for the student's batches (ignoring time for now to debug)
// Fetch projects assigned to any of the student's batches
$query = "SELECT p.*, GROUP_CONCAT(b.name SEPARATOR ', ') as batch_names 
          FROM projects p 
          JOIN project_batches pb ON p.id = pb.project_id 
          JOIN student_batches sb ON pb.batch_id = sb.batch_id 
          JOIN batches b ON pb.batch_id = b.id
          WHERE sb.student_id = ? 
          GROUP BY p.id
          ORDER BY p.end_date ASC";

$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$projects = $stmt->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 30px;">
    <h2>My Projects</h2>
    <p style="color: #64748b;">View and submit your assigned projects.</p>
</div>

<?php if (!$in_batches): ?>
    <div class="white-card" style="text-align: center; padding: 50px; border: 2px dashed #fee2e2;">
        <i class="fas fa-users-slash" style="font-size: 3rem; color: #ef4444; margin-bottom: 20px;"></i>
        <h4 style="color: #ef4444;">Enrollment Required</h4>
        <p>You have not been assigned to any classroom batches yet. <br>Please contact your instructor or administrator.</p>
    </div>
<?php elseif (empty($projects)): ?>
    <div class="white-card" style="text-align: center; padding: 50px;">
        <i class="fas fa-folder-open" style="font-size: 3rem; color: var(--slate-200); margin-bottom: 20px;"></i>
        <p>No projects have been assigned to your batches yet.</p>
    </div>
<?php else: ?>
    <div class="course-grid">
        <?php foreach ($projects as $proj): 
            $now = time();
            $start = strtotime($proj['start_date']);
            $end = strtotime($proj['end_date']);
            
            $is_upcoming = ($now < $start);
            $is_expired = ($now > $end);
            $is_active = (!$is_upcoming && !$is_expired);

            // Fetch submission status
            $sub_stmt = $pdo->prepare("SELECT submitted_at FROM project_submissions WHERE project_id = ? AND student_id = ?");
            $sub_stmt->execute([$proj['id'], $user_id]);
            $submission = $sub_stmt->fetch();
        ?>
        <div class="course-card fade-in" style="<?= $is_expired ? 'opacity: 0.7;' : '' ?>">
            <div class="course-content">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px; flex-wrap: wrap; gap: 8px;">
                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                        <?php 
                        $bnames = explode(', ', $proj['batch_names']);
                        foreach($bnames as $bn) echo "<span class='badge' style='background: #f1f5f9; color: #475569; font-size: 0.65rem; border: 1px solid #e2e8f0;'>".htmlspecialchars($bn)."</span>";
                        ?>
                    </div>
                    <?php if ($is_upcoming): ?>
                        <span class="badge" style="background: #f1f5f9; color: #475569;">Upcoming</span>
                    <?php elseif ($is_expired): ?>
                        <span class="badge badge-danger">Expired</span>
                    <?php else: ?>
                        <span class="badge badge-success">Active</span>
                    <?php endif; ?>
                </div>

                <h3 class="course-title"><?= htmlspecialchars($proj['title']) ?></h3>
                
                <div style="margin: 15px 0;">
                    <?php if ($submission): ?>
                        <div style="color: #10b981; font-size: 0.85rem; font-weight: 700;">
                            <i class="fas fa-check-circle"></i> Submitted on <?= date('d M', strtotime($submission['submitted_at'])) ?>
                        </div>
                    <?php else: ?>
                        <div style="color: #ef4444; font-size: 0.85rem; font-weight: 700;">
                            <i class="fas fa-exclamation-circle"></i> Not Submitted Yet
                        </div>
                    <?php endif; ?>
                </div>

                <div style="background: var(--slate-50); padding: 12px; border-radius: 12px; margin-bottom: 20px;">
                    <div style="font-size: 0.8rem; color: #64748b;">
                        <i class="fas fa-calendar-alt"></i> Deadline: <br>
                        <strong style="color: #1e293b;"><?= date('d M, h:i A', $end) ?></strong>
                    </div>
                </div>

                <?php if ($is_active): ?>
                    <a href="project_details.php?id=<?= $proj['id'] ?>" class="btn btn-primary" style="width: 100%;">
                        <?= $submission ? 'Edit Submission' : 'Launch Project' ?>
                    </a>
                <?php elseif ($is_upcoming): ?>
                    <button class="btn" style="width: 100%; background: #f1f5f9; color: #94a3b8; cursor: not-allowed;" disabled>
                        Starts on <?= date('d M, h:i A', $start) ?>
                    </button>
                <?php else: ?>
                    <a href="project_details.php?id=<?= $proj['id'] ?>" class="btn" style="width: 100%; border: 1px solid #e2e8f0;">
                         View Results
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include $path_to_root . 'includes/footer.php'; ?>
