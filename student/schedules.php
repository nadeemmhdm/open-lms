<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = $_SESSION['user_id'];

// Get Schedule based on enrollment
// Note: We use IST timezone set in config.php
$sql = "SELECT s.*, b.name as batch_name, c.title as course_title 
        FROM schedules s 
        JOIN student_batches sb ON s.batch_id = sb.batch_id 
        JOIN batches b ON s.batch_id = b.id
        JOIN courses c ON b.course_id = c.id
        WHERE sb.student_id = ? 
        ORDER BY s.start_time DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$schedules = $stmt->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 30px;" class="fade-in">
    <h2 style="font-weight: 700; color: var(--dark);">My Class Schedule</h2>
    <p style="color: #666;">View your live class timings and join links.</p>
</div>

<?php if (empty($schedules)): ?>
    <div class="white-card" style="text-align: center; padding: 60px; background: white; border-radius: 16px;">
        <i class="far fa-calendar-times" style="font-size: 4rem; color: #eee; margin-bottom: 20px;"></i>
        <h3>No classes scheduled.</h3>
        <p>Check back later for updates from your instructor.</p>
    </div>
<?php else: ?>
    <div class="schedule-list">
        <?php foreach ($schedules as $s):
            $start = strtotime($s['start_time']);
            $end = strtotime($s['end_time']);
            $now = time();

            // Indian Standard Time Check (Already synced via config)
            $isActive = ($now >= $start && $now <= $end);
            $hasEnded = ($now > $end);
            $isUpcoming = ($now < $start);

            $status_border = 'var(--primary)';
            if ($isActive)
                $status_border = 'var(--success)';
            if ($hasEnded)
                $status_border = '#ccc';
            ?>
            <div class="white-card stat-card"
                style="padding: 25px; border-radius: 16px; margin-bottom: 20px; border-left: 6px solid <?= $status_border ?>; flex-direction: row; gap: 20px;">
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <span class="badge"
                            style="background: <?= $isActive ? 'var(--success)15' : 'rgba(0,0,0,0.05)' ?>; color: <?= $isActive ? 'var(--success)' : '#666' ?>;">
                            <?= $isActive ? 'LIVE NOW' : ($hasEnded ? 'FINISHED' : 'UPCOMING') ?>
                        </span>
                        <span style="font-size: 0.85rem; color: #888;"><?= htmlspecialchars($s['batch_name']) ?></span>
                    </div>
                    <h3 style="margin: 0; font-size: 1.3rem; color: var(--dark);"><?= htmlspecialchars($s['title']) ?></h3>
                    <div style="margin-top: 10px; color: #555; font-size: 0.95rem;">
                        <span style="display: inline-block; margin-right: 15px;"><i class="far fa-calendar-alt"></i>
                            <?= date('D, M j, Y', $start) ?></span>
                        <span><i class="far fa-clock"></i> <?= date('h:i A', $start) ?> - <?= date('h:i A', $end) ?></span>
                    </div>
                </div>

                <div style="display: flex; align-items: center;">
                    <?php if ($isActive): ?>
                        <a href="<?= htmlspecialchars($s['meeting_link']) ?>" target="_blank" class="btn btn-primary pulse-button"
                            style="background: var(--success); border-radius: 10px; padding: 12px 25px;">
                            <i class="fas fa-video"></i> Join Live Class
                        </a>
                    <?php elseif ($isUpcoming): ?>
                        <div style="text-align: center; color: #888;">
                            <small style="display: block;">Starts in</small>
                            <strong><?= floor(($start - $now) / 3600) ?>h <?= floor((($start - $now) % 3600) / 60) ?>m</strong>
                        </div>
                    <?php else: ?>
                        <span class="badge" style="background: #f8f9fa; color: #aaa; padding: 10px 15px;">Class Ended</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
    @keyframes pulse {
        0% {
            box-shadow: 0 0 0 0 rgba(46, 204, 113, 0.4);
        }

        70% {
            box-shadow: 0 0 0 10px rgba(46, 204, 113, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(46, 204, 113, 0);
        }
    }

    .pulse-button {
        animation: pulse 2s infinite;
    }
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>