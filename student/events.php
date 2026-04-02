<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

// Get all future events
$events = $pdo->query("SELECT * FROM events ORDER BY event_date ASC")->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 30px;">
    <h2>Upcoming Events & Activities</h2>
</div>

<?php if (empty($events)): ?>
    <div
        style="text-align: center; padding: 40px; background: white; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
        <i class="fas fa-calendar-times" style="font-size: 3rem; color: #ddd; margin-bottom: 20px;"></i>
        <p>No upcoming events.</p>
    </div>
<?php else: ?>
    <div class="row">
        <?php foreach ($events as $e):
            $start = strtotime($e['event_date']);
            $img = $e['image_url'] ?: 'https://via.placeholder.com/400x250?text=Event';
            ?>
            <div class="col-md-6 mb-4" style="margin-bottom: 30px;">
                <div class="white-card"
                    style="background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); transition: 0.3s; height: 100%;">
                    <div style="position: relative; height: 200px;">
                        <img src="<?= htmlspecialchars($img) ?>" alt="Event Image"
                            style="width: 100%; height: 100%; object-fit: cover;">
                        <div
                            style="position: absolute; top: 15px; right: 15px; background: rgba(0,0,0,0.7); color: white; padding: 5px 15px; border-radius: 20px; text-align: center;">
                            <span style="display: block; font-size: 1.2rem; font-weight: 700;">
                                <?= date('j', $start) ?>
                            </span>
                            <span style="font-size: 0.8rem; text-transform: uppercase;">
                                <?= date('M', $start) ?>
                            </span>
                        </div>
                    </div>
                    <div style="padding: 25px;">
                        <h3 style="margin-bottom: 10px; color: var(--primary);">
                            <?= htmlspecialchars($e['title']) ?>
                        </h3>
                        <div style="display: flex; gap: 15px; color: #888; font-size: 0.9rem; margin-bottom: 15px;">
                            <span><i class="far fa-clock"></i>
                                <?= date('h:i A', $start) ?>
                                <?php if ($e['event_end_date']): ?>
                                    - <?= date('h:i A', strtotime($e['event_end_date'])) ?>
                                <?php endif; ?>
                            </span>
                            <?php if ($e['video_url']): ?>
                                <span style="color: var(--danger);"><i class="fas fa-video"></i> Video</span>
                            <?php endif; ?>
                        </div>
                        <p style="color: #555; line-height: 1.6; margin-bottom: 20px;">
                            <?= nl2br(htmlspecialchars($e['description'])) ?>
                        </p>

                        <?php if ($e['video_url']): ?>
                            <div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
                                <a href="<?= htmlspecialchars($e['video_url']) ?>" target="_blank" class="btn btn-primary btn-block"
                                    style="text-align: center;">Watch Event Video</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
    @media (min-width: 768px) {
        .row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
        }
    }
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>