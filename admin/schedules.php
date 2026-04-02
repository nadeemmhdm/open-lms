<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';
require_once $path_to_root . 'includes/mailer.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

// Add Schedule
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_schedule'])) {
    $batch_id = $_POST['batch_id'];
    $title = trim($_POST['title']);
    $link = trim($_POST['meeting_link']);
    $start = $_POST['start_time'];
    $end = $_POST['end_time'];

    $stmt = $pdo->prepare("INSERT INTO schedules (batch_id, title, meeting_link, start_time, end_time) VALUES (?,?,?,?,?)");
    $stmt->execute([$batch_id, $title, $link, $start, $end]);

    // Send Mail
    try {
        $e_date = date('M d, Y h:i A', strtotime($start));
        $subject = "Class Scheduled: $title";
        $body = "<h2>$title</h2><p><b>Date:</b> $e_date</p><p><b>Meeting Link:</b> <a href='$link'>$link</a></p>";
        notifyUsers($pdo, 'student', $batch_id, $subject, $body);
    } catch (Exception $e) {}

    $message = "Class scheduled successfully (IST)!";
}

// Delete
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM schedules WHERE id = ?")->execute([$_GET['delete']]);
    redirect('schedules.php');
}

$batches = $pdo->query("SELECT id, name FROM batches ORDER BY name ASC")->fetchAll();
$schedules = $pdo->query("SELECT s.*, b.name as batch_name FROM schedules s JOIN batches b ON s.batch_id = b.id ORDER BY s.start_time DESC")->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;" class="fade-in">
    <h2><i class="fas fa-calendar-alt" style="color: var(--primary);"></i> Class Schedules (IST)</h2>
    <div style="display: flex; gap: 10px;">
        <button onclick="document.getElementById('addSchedule').style.display='block'" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> New Schedule
        </button>
    </div>
</div>

<?php if (isset($message)): ?>
    <div class="badge badge-success" style="display: block; padding: 15px; margin-bottom: 20px;"><?= $message ?></div>
<?php endif; ?>

<div id="addSchedule"
    style="display: none; background: white; padding: 25px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); margin-bottom: 30px;"
    class="fade-in">
    <h3 style="margin-bottom: 20px;">Schedule New Class</h3>
    <form method="POST">
        <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label>Select Batch</label>
                <select name="batch_id" class="form-control" required>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Class Title</label>
                <input type="text" name="title" class="form-control" placeholder="e.g. Intro to PHP" required>
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label>Meeting Link (Zoom/Google Meet/etc.)</label>
                <input type="url" name="meeting_link" class="form-control" placeholder="https://..." required>
            </div>
            <div class="form-group">
                <label>Start (IST)</label>
                <input type="datetime-local" name="start_time" class="form-control" required>
            </div>
            <div class="form-group">
                <label>End (IST)</label>
                <input type="datetime-local" name="end_time" class="form-control" required>
            </div>
        </div>
        <div style="margin-top: 20px; display: flex; gap: 10px;">
            <button type="submit" name="add_schedule" class="btn btn-primary">Save Schedule</button>
            <button type="button" onclick="document.getElementById('addSchedule').style.display='none'"
                class="btn btn-light">Cancel</button>
        </div>
    </form>
</div>

<div class="stat-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
    <?php foreach ($schedules as $s):
        $start = strtotime($s['start_time']);
        $end = strtotime($s['end_time']);
        $now = time();
        $isActive = ($now >= $start && $now <= $end);
        ?>
        <div class="white-card stat-card"
            style="padding: 20px; border-left: 5px solid <?= $isActive ? 'var(--success)' : ($now > $end ? '#eee' : 'var(--primary)') ?>; opacity: <?= $now > $end ? '0.7' : '1' ?>;">
            <div style="display: flex; justify-content: space-between; align-items: start; width: 100%;">
                <div>
                    <span class="badge"
                        style="background: rgba(0,0,0,0.05); color: #666; margin-bottom: 5px;"><?= htmlspecialchars($s['batch_name']) ?></span>
                    <h4 style="margin: 0; font-size: 1.1rem;"><?= htmlspecialchars($s['title']) ?></h4>
                </div>
                <a href="?delete=<?= $s['id'] ?>" onclick="return confirm('Delete this schedule?')"
                    style="color: var(--danger);"><i class="fas fa-trash"></i></a>
            </div>

            <div style="margin: 20px 0; font-size: 0.9rem; color: #666;">
                <div><i class="far fa-calendar"></i> <?= date('M d, Y', $start) ?></div>
                <div><i class="far fa-clock"></i> <?= date('h:i A', $start) ?> - <?= date('h:i A', $end) ?></div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <?php if ($isActive): ?>
                    <span style="color: var(--success); font-weight: 700; font-size: 0.8rem;"><i
                            class="fas fa-circle-notch fa-spin"></i> LIVE NOW</span>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($s['meeting_link']) ?>" target="_blank" class="btn btn-sm"
                    style="background: <?= $isActive ? 'var(--primary)' : '#f0f2f5' ?>; color: <?= $isActive ? 'white' : '#888' ?>; <?= !$isActive ? 'pointer-events: none;' : '' ?>">Join
                    Link</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>