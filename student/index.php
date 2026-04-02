<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = $_SESSION['user_id'];

// Get Enrolled Batches Count (Quick check, though index doesn't use it directly)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM student_batches WHERE student_id = ?");
$stmt->execute([$user_id]);
$batch_count = $stmt->fetchColumn();

// Get latest announcements for the student (Global/Batch broadcasts only)
$sql = "SELECT n.*, 
               NOT EXISTS (SELECT 1 FROM notification_reads nr WHERE nr.notification_id = n.id AND nr.user_id = ?) as is_new
        FROM notifications n 
        LEFT JOIN student_batches sb ON n.batch_id = sb.batch_id 
        WHERE n.user_id IS NULL 
        AND (
            (n.target_role = 'all')
            OR (n.target_role = 'student' AND n.batch_id IS NULL)
            OR (n.target_role = 'student' AND sb.student_id = ?)
        )
        GROUP BY n.id
        ORDER BY n.created_at DESC LIMIT 5";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id, $user_id]);
$notifs = $stmt->fetchAll();

// Get next class (Upcoming only)
$now = date('Y-m-d H:i:s');
// Add 1 hour buffer for "active" meetings (show even if started 30 mins ago for example)
$active_window = date('Y-m-d H:i:s', strtotime('-1 hour'));

$sql = "SELECT s.*, b.name as batch_name FROM schedules s 
        JOIN student_batches sb ON s.batch_id = sb.batch_id 
        JOIN batches b ON s.batch_id = b.id
        WHERE sb.student_id = ? AND s.end_time > ? 
        ORDER BY s.start_time ASC LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id, $now]);
$next_class = $stmt->fetch();

// Get Recent Events (Limit 3)
$events = $pdo->query("SELECT * FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 3")->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="row">
    <!-- Welcome Section -->
    <div class="col-12"
        style="background: linear-gradient(135deg, var(--primary), var(--primary-light)); border-radius: 16px; padding: 30px; color: white; margin-bottom: 30px; box-shadow: 0 10px 20px rgba(78, 84, 200, 0.2);">
        <h2 style="font-weight: 700; margin-bottom: 10px;">Welcome back,
            <?= htmlspecialchars($_SESSION['name']) ?>! 👋
        </h2>
        <p style="opacity: 0.9; margin-bottom: 20px;">You are making great progress. Keep it up!</p>
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <a href="my_courses.php" class="btn"
                style="background: white; color: var(--primary); font-weight: 700; padding: 12px 30px; border-radius: 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">Continue Learning</a>
            <a href="explore_courses.php" class="btn"
                style="background: rgba(255,255,255,0.2); color: white; font-weight: 700; padding: 12px 30px; border-radius: 30px; backdrop-filter: blur(5px); border: 1px solid rgba(255,255,255,0.3);">Explore New Courses</a>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px;">
        <!-- Left Column -->
        <div>
            <!-- Next Class Widget -->
            <?php if ($next_class):
                $start = strtotime($next_class['start_time']);
                $end = strtotime($next_class['end_time']);
                $now_ts = time();

                // Allow joining 15 mins before
                $can_join = ($now_ts >= ($start - 900) && $now_ts <= $end);
                $is_live = ($now_ts >= $start && $now_ts <= $end);
                $status_color = $is_live ? '#e74c3c' : '#2ecc71';
                $status_text = $is_live ? 'LIVE NOW' : 'UPCOMING';
                ?>
                <div class="white-card"
                    style="background: white; padding: 25px; border-radius: 16px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between; border-left: 5px solid <?= $status_color ?>;">
                    <div>
                        <span class="badge"
                            style="background: <?= $status_color ?>20; color: <?= $status_color ?>; margin-bottom: 10px;">
                            <?= $status_text ?>
                        </span>
                        <h3 style="margin: 5px 0;">
                            <?= htmlspecialchars($next_class['title']) ?>
                        </h3>
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 5px;"><i class="far fa-clock"></i>
                            <?= date('h:i A', $start) ?> -
                            <?= date('h:i A', $end) ?>
                        </p>
                        <small style="color: #888;">Batch:
                            <?= htmlspecialchars($next_class['batch_name']) ?>
                        </small>
                    </div>
                    <div>
                        <?php if ($can_join): ?>
                            <a href="<?= htmlspecialchars($next_class['meeting_link']) ?>" target="_blank"
                                class="btn btn-primary pulse-btn">Join Class</a>
                        <?php else: ?>
                            <button class="btn" disabled style="background: #eee; color: #999; cursor: not-allowed;">
                                Join in
                                <?= ceil(($start - $now_ts) / 60) ?>m
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="white-card"
                    style="background: white; padding: 20px; border-radius: 16px; margin-bottom: 30px; text-align: center; color: #888;">
                    <i class="fas fa-calendar-check" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>
                    <p>No upcoming classes scheduled.</p>
                </div>
            <?php endif; ?>

            <h3 style="margin-bottom: 20px;">Latest Announcements</h3>
            <?php if (count($notifs) > 0): ?>
                <?php foreach ($notifs as $n): ?>
                    <div class="white-card"
                        style="background: white; padding: 20px; border-radius: 16px; margin-bottom: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); <?= $n['is_new'] ? 'border-left: 5px solid #4f46e5;' : '' ?>">
                        <div style="display: flex; gap: 15px;">
                            <div
                                style="background: <?= $n['is_new'] ? '#f0f4ff' : 'rgba(78, 84, 200, 0.1)' ?>; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--primary); flex-shrink: 0;">
                                <i class="fas fa-bell"></i>
                            </div>
                            <div style="flex: 1;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                    <h4 style="font-size: 1rem; margin: 0;">
                                        <?= htmlspecialchars($n['title']) ?>
                                    </h4>
                                    <?php if ($n['is_new']): ?>
                                        <span class="badge" style="background: #4f46e5; color: white; font-size: 0.6rem;">NEW</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 0.9rem; color: #555; white-space: pre-wrap; line-height: 1.6;">
                                    <?= htmlspecialchars($n['message']) ?>
                                </div>

                                <?php if ($n['attachment_url']): ?>
                                    <div style="margin-top: 15px; background: #f8f9fa; padding: 10px; border-radius: 8px;">
                                        <?php if ($n['attachment_type'] == 'image'): ?>
                                            <img src="<?= htmlspecialchars($path_to_root . $n['attachment_url']) ?>"
                                                style="max-width: 100%; border-radius: 4px; display: block;">
                                        <?php else: ?>
                                            <video src="<?= htmlspecialchars($path_to_root . $n['attachment_url']) ?>" controls
                                                style="max-width: 100%; border-radius: 4px; display: block;"></video>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <small style="display: block; margin-top: 10px; color: #999;">
                                    <?= date('M d, h:i A', strtotime($n['created_at'])) ?>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: #999;">No new announcements.</p>
            <?php endif; ?>
        </div>

        <!-- Right Column (Events) -->
        <div>
            <h3 style="margin-bottom: 20px;">Upcoming Events</h3>
            <?php foreach ($events as $e): ?>
                <div class="white-card"
                    style="background: white; border-radius: 16px; overflow: hidden; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                    <?php if ($e['image_url']): ?>
                        <div style="height: 120px; overflow: hidden;">
                            <img src="<?= htmlspecialchars($e['image_url']) ?>"
                                style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                    <?php endif; ?>
                    <div style="padding: 15px;">
                        <span class="badge badge-warning" style="margin-bottom: 5px;">
                            <?= date('M d', strtotime($e['event_date'])) ?>
                            <?php if ($e['event_end_date']): ?>
                                | <?= date('g:i a', strtotime($e['event_date'])) ?> - <?= date('g:i a', strtotime($e['event_end_date'])) ?>
                            <?php else: ?>
                                | <?= date('g:i a', strtotime($e['event_date'])) ?>
                            <?php endif; ?>
                        </span>
                        <h4 style="font-size: 1rem; margin: 10px 0;">
                            <?= htmlspecialchars($e['title']) ?>
                        </h4>
                        <a href="events.php" style="font-size: 0.85rem; color: var(--primary); font-weight: 600;">View
                            Details <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<style>
    /* Dashboard Specific Responsive */
    @media (max-width: 992px) {
        .row>div[style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }
    }

    .pulse-btn {
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.7);
        }

        70% {
            transform: scale(1.05);
            box-shadow: 0 0 0 10px rgba(231, 76, 60, 0);
        }

        100% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(231, 76, 60, 0);
        }
    }
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>