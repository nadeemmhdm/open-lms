<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = (int)$_SESSION['user_id'];

/**
 * Fetch exams for student's batches
 * Using LEFT JOIN instead of subqueries for better performance and reliability on shared hosts.
 */
$sql = "(SELECT e.*, b.name as batch_name, b.status as batch_status, b.close_message,
               (SELECT COUNT(*) FROM student_exams se WHERE se.exam_id = e.id AND se.student_id = ? AND se.status = 'submitted') as submitted_count,
               (SELECT id FROM student_exams se WHERE se.exam_id = e.id AND se.student_id = ? AND se.status = 'ongoing' LIMIT 1) as ongoing_attempt_id,
               (SELECT status FROM student_exams se WHERE se.exam_id = e.id AND se.student_id = ? AND se.status = 'ongoing' LIMIT 1) as ongoing_status
        FROM exams e 
        JOIN exam_batches eb ON e.id = eb.exam_id
        JOIN student_batches sb ON eb.batch_id = sb.batch_id 
        JOIN batches b ON eb.batch_id = b.id
        WHERE sb.student_id = ? AND e.is_published = 1 AND (e.publish_date IS NULL OR e.publish_date <= NOW())
        GROUP BY e.id)
        UNION
        (SELECT e.*, 'Private Access' as batch_name, 'active' as batch_status, '' as close_message,
               (SELECT COUNT(*) FROM student_exams se WHERE se.exam_id = e.id AND se.student_id = ? AND se.status = 'submitted') as submitted_count,
               (SELECT id FROM student_exams se WHERE se.exam_id = e.id AND se.student_id = ? AND se.status = 'ongoing' LIMIT 1) as ongoing_attempt_id,
               (SELECT status FROM student_exams se WHERE se.exam_id = e.id AND se.student_id = ? AND se.status = 'ongoing' LIMIT 1) as ongoing_status
        FROM exams e
        JOIN student_private_exams spe ON e.id = spe.exam_id
        WHERE spe.student_id = ? AND e.is_published = 1 AND (e.publish_date IS NULL OR e.publish_date <= NOW())
        GROUP BY e.id)
        ORDER BY start_time DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
    $exams = $stmt->fetchAll();
} catch (PDOException $e) {
    // If the query fails, it's likely due to missing columns. 
    // We'll catch it to prevent a blank 500 error page.
    $error_msg = "Database Error: " . $e->getMessage();
    $exams = [];
}

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="row fade-in">
    <div class="col-12" style="margin-bottom: 30px;">
        <h2 style="font-weight: 800; color: var(--dark); letter-spacing: -0.5px;">Examination Portal</h2>
        <p style="color: #666; font-size: 1.1rem;">Access your scheduled assessments and track your performance.</p>
    </div>

    <?php if (isset($error_msg)): ?>
        <div class="col-12">
            <div class="white-card" style="border-left: 5px solid var(--danger); background: #fff5f5; padding: 20px;">
                <h4 style="color: var(--danger);"><i class="fas fa-exclamation-triangle"></i> Assessment System Error</h4>
                <p style="margin: 10px 0; color: #444;"><?= htmlspecialchars($error_msg) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <div class="col-12">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 25px;">
            <?php foreach ($exams as $ex):
                $start = strtotime($ex['start_time']);
                $duration_seconds = $ex['duration_minutes'] * 60;
                $end = $start + $duration_seconds;
                
                // Entrance window: 10 minutes from start time
                $join_window_end = $start + (10 * 60); 
                $now = time();

                $status = 'Upcoming';
                $status_color = 'var(--primary)';
                $can_start = false;
                $btn_text = 'Awaiting Start';
                
                $max_att = $ex['max_attempts'] ?: 1;
                $has_submitted = ($ex['submitted_count'] > 0);
                $has_ongoing = !empty($ex['ongoing_attempt_id']);
                $attempts_left = $max_att - $ex['submitted_count'];

                if ($ex['batch_status'] == 'closed') {
                    $status = 'Batch Archived';
                    $status_color = '#94a3b8';
                    $btn_text = 'Archived';
                } elseif ($has_ongoing) {
                    $status = 'In Progress';
                    $status_color = '#f39c12';
                    $can_start = true;
                    $btn_text = 'Resume Attempt';
                } elseif ($has_submitted && $attempts_left <= 0) {
                    $status = 'Completed';
                    $status_color = 'var(--success)';
                    $btn_text = 'View Results';
                } elseif ($now >= $start && $now <= $join_window_end) {
                    $status = 'Live Now';
                    $status_color = '#e74c3c';
                    $can_start = true;
                    $btn_text = ($has_submitted ? 'Start New Attempt' : 'Start Examination');
                } elseif ($now > $join_window_end && $now <= $end) {
                    // Logic for resuming if ongoing, else missed
                    if ($has_ongoing) {
                        $status = 'Resume Test';
                        $status_color = '#f39c12';
                        $can_start = true;
                        $btn_text = 'Continue Attempt';
                    } else {
                        $status = 'Entry Missed';
                        $status_color = '#64748b';
                        $btn_text = 'Closed';
                    }
                } elseif ($now > $end) {
                    $status = 'Exam Ended';
                    $status_color = '#94a3b8';
                    $btn_text = 'Closed';
                }
                ?>
                <div class="white-card" style="border-radius: 24px; box-shadow: 0 15px 35px rgba(0,0,0,0.08); overflow: hidden; border: 1px solid #edf2f7; transition: 0.3s; transform: translateY(0);">
                    <div style="height: 10px; background: <?= $status_color ?>;"></div>
                    <div style="padding: 30px;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                            <span class="badge" style="background: <?= $status_color ?>15; color: <?= $status_color ?>; padding: 8px 16px; border-radius: 12px; font-weight: 700; font-size: 0.85rem; border: 1px solid <?= $status_color ?>30;">
                                <i class="fas <?= $status == 'Live Now' ? 'fa-signal' : 'fa-info-circle' ?>" style="margin-right: 5px;"></i> <?= strtoupper($status) ?>
                            </span>
                            <div style="text-align: right;">
                                <div style="font-weight: 800; color: #475569; font-size: 0.8rem;">
                                    <?php if ($max_att > 1): ?>
                                        <i class="fas fa-redo"></i> Attempt <?= $ex['submitted_count'] ?>/<?= $max_att ?>
                                    <?php else: ?>
                                        <i class="fas fa-user-check"></i> Single Entry
                                    <?php endif; ?>
                                </div>
                                <div style="font-weight: 700; color: #2d3436; opacity: 0.5; font-size: 0.7rem;"><?= htmlspecialchars($ex['batch_name']) ?></div>
                            </div>
                        </div>

                        <h3 style="margin-bottom: 10px; color: #2d3436; font-size: 1.5rem; font-weight: 800; line-height: 1.3;"><?= htmlspecialchars($ex['title']) ?></h3>
                        
                        <div style="display: flex; gap: 15px; margin-bottom: 25px; color: #7f8c8d; font-size: 0.9rem;">
                            <span title="Date"><i class="far fa-calendar-alt"></i> <?= date('M d', $start) ?></span>
                            <span title="Start Time"><i class="far fa-clock"></i> <?= date('h:i A', $start) ?></span>
                            <span title="Duration"><i class="fas fa-hourglass-start"></i> <?= $ex['duration_minutes'] ?>m</span>
                        </div>

                        <?php if (!empty($ex['instructions'])): ?>
                            <div style="margin-bottom: 25px;">
                                <div style="font-size: 0.75rem; color: #95a5a6; font-weight: 800; text-transform: uppercase; margin-bottom: 10px; letter-spacing: 1px;">Information & Guidelines</div>
                                <div style="font-size: 0.95rem; color: #4a5568; background: #f8fafc; padding: 15px; border-radius: 16px; border: 1px solid #edf2f7; white-space: pre-wrap; line-height: 1.6; max-height: 150px; overflow-y: auto;"><?= htmlspecialchars($ex['instructions']) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($ex['batch_status'] == 'closed' && !empty($ex['close_message'])): ?>
                            <div style="background: #fff5f5; border: 1px solid #feb2b2; padding: 15px; border-radius: 16px; margin-bottom: 20px; color: #c53030; font-size: 0.9rem; display: flex; gap: 10px;">
                                <i class="fas fa-exclamation-triangle" style="margin-top: 3px;"></i>
                                <span><?= htmlspecialchars($ex['close_message']) ?></span>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 10px;">
                            <?php if ($can_start && $ex['batch_status'] != 'closed'): ?>
                                <a href="take_exam.php?id=<?= $ex['id'] ?>" class="btn btn-primary" style="width: 100%; padding: 16px; border-radius: 16px; font-weight: 700; <?= $status == 'Live Now' ? 'background:linear-gradient(135deg, #4f46e5, #4338ca);' : '' ?>">
                                    <?= $btn_text ?> <i class="fas fa-chevron-right" style="margin-left: 8px; font-size: 0.8rem;"></i>
                                </a>
                            <?php elseif ($has_submitted && $attempts_left <= 0): ?>
                                <a href="view_result.php?exam_id=<?= $ex['id'] ?>" class="btn" style="width: 100%; border: 2px solid #bbf7d0; background: #f0fdf4; color: #16a34a; padding: 16px; border-radius: 16px; font-weight: 800;">
                                    <i class="fas fa-poll"></i> View Latest Result
                                </a>
                                <?php if ($ex['submitted_count'] > 1): ?>
                                    <div style="text-align: center; margin-top: 10px; font-size: 0.8rem; color: #64748b; font-weight: 600;">Multiple Attempts Completed</div>
                                <?php endif; ?>
                            <?php elseif ($ex['batch_status'] == 'closed'): ?>
                                <button class="btn" disabled style="width: 100%; background: #f1f2f6; color: #a4b0be; border: none; padding: 16px; border-radius: 16px;">Support Period Ended</button>
                            <?php elseif ($now < $start): ?>
                                <div class="exam-countdown" data-start="<?= $start ?>" style="text-align: center; background: #ebf4ff; color: #3182ce; padding: 16px; border-radius: 16px; font-weight: 800; border: 1px dashed #3182ce; display: flex; align-items: center; justify-content: center; gap: 10px;">
                                    <i class="fas fa-stopwatch fa-spin-hover"></i> 
                                    <span>Starts in: <span class="countdown-clock">--h --m --s</span></span>
                                </div>
                            <?php else: ?>
                                <button class="btn" disabled style="width: 100%; background: #fdfdfd; color: #dee2e6; border: 1px solid #eee; padding: 16px; border-radius: 16px; cursor: not-allowed;"><?= $btn_text ?></button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($exams) && !isset($error_msg)): ?>
                <div class="white-card" style="grid-column: 1 / -1; text-align: center; padding: 100px 40px; border-radius: 30px; border: 2px dashed #edf2f7;">
                    <div style="font-size: 5rem; color: #edf2f7; margin-bottom: 25px;">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h3 style="color: #2d3436; font-size: 1.5rem; font-weight: 800;">No Exams Assigned</h3>
                    <p style="color: #7f8c8d; font-size: 1.1rem; max-width: 450px; margin: 0 auto;">Your examination schedule is currently empty. Please check with your batch coordinator for updates.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function updateExamCountdowns() {
    const now = Math.floor(Date.now() / 1000);
    const timers = document.querySelectorAll('.exam-countdown');
    
    timers.forEach(timer => {
        const startTime = parseInt(timer.getAttribute('data-start'));
        const diff = startTime - now;
        const clockDisplay = timer.querySelector('.countdown-clock');

        if (diff <= 0) {
            // Exam should be live now! Reload to show the Start button.
            location.reload();
            return;
        }

        const h = Math.floor(diff / 3600);
        const m = Math.floor((diff % 3600) / 60);
        const s = Math.floor(diff % 60);

        let finalTime = "";
        if (h > 0) finalTime += h.toString().padStart(2, '0') + "h ";
        finalTime += m.toString().padStart(2, '0') + "m ";
        finalTime += s.toString().padStart(2, '0') + "s";

        clockDisplay.innerText = finalTime;
    });
}

// Update every second
setInterval(updateExamCountdowns, 1000);
// Initial kick-off
updateExamCountdowns();
</script>

<style>
@media (max-width: 768px) {
    div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
}
.white-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.12) !important;
}
.exam-countdown:hover i.fa-spin-hover {
    animation: fa-spin 2s infinite linear;
}
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>