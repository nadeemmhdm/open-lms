<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';
require_once $path_to_root . 'includes/mailer.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

// Sub-admin permission check
if ($_SESSION['role'] === 'sub_admin' && !hasPermission('events')) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_event'])) {
    $stmt = $pdo->prepare("INSERT INTO events (title, description, image_url, video_url, event_date, event_end_date) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$_POST['title'], $_POST['description'], $_POST['image_url'], $_POST['video_url'], $_POST['event_date'], $_POST['event_end_date'] ?: null]);
    
    // Send Global Email
    try {
        $e_title = $_POST['title'];
        $e_date = date('M d, Y h:i A', strtotime($_POST['event_date']));
        $e_desc = $_POST['description'];
        $subject = "New Event Scheduled: $e_title";
        $body = "<h2>$e_title</h2><p><b>Date:</b> $e_date</p><p>$e_desc</p>";
        notifyUsers($pdo, 'all', null, $subject, $body);
    } catch (Exception $e) {}

    redirect('events.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_event'])) {
    $stmt = $pdo->prepare("UPDATE events SET title = ?, description = ?, image_url = ?, video_url = ?, event_date = ?, event_end_date = ? WHERE id = ?");
    $stmt->execute([$_POST['title'], $_POST['description'], $_POST['image_url'], $_POST['video_url'], $_POST['event_date'], $_POST['event_end_date'] ?: null, $_POST['event_id']]);
    redirect('events.php');
}

if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$_GET['delete']]);
    redirect('events');
}

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
    <h2>Manage Events</h2>
    <button onclick="document.getElementById('addEvent').style.display='block'" class="btn btn-primary btn-sm">
        <i class="fas fa-calendar-plus"></i> Add Event
    </button>
</div>

<!-- Add Event Form -->
<div id="addEvent" style="display: none; background: white; padding: 25px; border-radius: 16px; margin-bottom: 30px;"
    class="fade-in">
    <h3>New Event</h3>
    <form method="POST">
        <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label>Event Title</label>
                <input type="text" name="title" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Start Date & Time</label>
                <input type="datetime-local" name="event_date" class="form-control" required>
            </div>
            <div class="form-group">
                <label>End Date & Time (Optional)</label>
                <input type="datetime-local" name="event_end_date" class="form-control">
            </div>
            <div class="form-group">
                <label>Image URL</label>
                <input type="text" name="image_url" class="form-control">
            </div>
            <div class="form-group">
                <label>Video URL (Optional)</label>
                <input type="text" name="video_url" class="form-control">
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="3"></textarea>
            </div>
        </div>
        <button type="submit" name="add_event" class="btn btn-primary">Publish Event</button>
        <button type="button" onclick="document.getElementById('addEvent').style.display='none'" class="btn"
            style="background: #eee;">Cancel</button>
    </form>
</div>

<div class="course-grid">
    <?php
    $stmt = $pdo->query("SELECT * FROM events ORDER BY event_date DESC");
    while ($row = $stmt->fetch()) {
        $img = $row['image_url'] ?: 'https://via.placeholder.com/400x200?text=Event';
        ?>
        <div class='course-card'>
            <div class='course-img' style='height: 200px;'>
                <img src='<?php echo $img; ?>' alt='Event'>
                <div style='position: absolute; top: 10px; right: 10px; display: flex; gap: 5px;'>
                    <button onclick='openEditEvent(<?php echo htmlspecialchars(json_encode($row)); ?>)' style='background: white; border: none; padding: 5px 10px; border-radius: 50%; color: var(--primary); cursor: pointer;'><i class='fas fa-edit'></i></button>
                    <a href='?delete=<?php echo $row['id']; ?>' style='background: white; padding: 5px 10px; border-radius: 50%; color: var(--danger);' onclick="return confirm('Delete this event?')"><i class='fas fa-trash'></i></a>
                </div>
            </div>
            <div class='course-content'>
                <h4 style='color: var(--primary);'><?php echo htmlspecialchars($row['title']); ?></h4>
                <div style='font-size: 0.85rem; color: #888; margin-bottom: 10px;'>
                    <i class='far fa-calendar'></i> <?php echo date('F j, Y, h:i a', strtotime($row['event_date'])); ?>
                    <?php echo ($row['event_end_date'] ? " - " . date('h:i a', strtotime($row['event_end_date'])) : ""); ?>
                </div>
                <p style='font-size: 0.9rem; color: #555;'><?php echo htmlspecialchars(substr($row['description'], 0, 100)) . '...'; ?></p>
                <?php if ($row['video_url']): ?>
                    <a href='<?php echo htmlspecialchars($row['video_url']); ?>' target='_blank' class='btn btn-sm btn-primary' style='margin-top: 10px;'>Watch Video</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    ?>
</div>

<!-- Edit Event Form Modal -->
<div id="editEventModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(5px);">
    <div style="background: white; padding: 25px; border-radius: 16px; width: 600px; max-width: 95%;" class="fade-in">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0;">Edit Event</h3>
            <span onclick="document.getElementById('editEventModal').style.display='none'" style="cursor: pointer; font-size: 1.5rem; color: #999;">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="event_id" id="edit_event_id">
            <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Event Title</label>
                    <input type="text" name="title" id="edit_title" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Start Date & Time</label>
                    <input type="datetime-local" name="event_date" id="edit_event_date" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>End Date & Time (Optional)</label>
                    <input type="datetime-local" name="event_end_date" id="edit_event_end_date" class="form-control">
                </div>
                <div class="form-group">
                    <label>Image URL</label>
                    <input type="text" name="image_url" id="edit_image_url" class="form-control">
                </div>
                <div class="form-group">
                    <label>Video URL (Optional)</label>
                    <input type="text" name="video_url" id="edit_video_url" class="form-control">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>Description</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 10px;">
                <button type="submit" name="edit_event" class="btn btn-primary" style="flex: 1;">Update Event</button>
                <button type="button" onclick="document.getElementById('editEventModal').style.display='none'" class="btn" style="background: #f1f5f9; color: #64748b; flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditEvent(event) {
    document.getElementById('edit_event_id').value = event.id;
    document.getElementById('edit_title').value = event.title;
    document.getElementById('edit_description').value = event.description;
    document.getElementById('edit_image_url').value = event.image_url;
    document.getElementById('edit_video_url').value = event.video_url;

    const formatDate = (dateStr) => {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        const pad = (n) => n.toString().padStart(2, '0');
        // Need to compensate for local timezone since datetime-local expects local time
        // but new Date(dateStr) might interpret as UTC if not careful.
        // Actually event_date from MySQL is usually YYYY-MM-DD HH:MM:SS
        // replace space with T is usually enough if it's local time string
        return dateStr.replace(' ', 'T').substring(0, 16);
    };

    document.getElementById('edit_event_date').value = formatDate(event.event_date);
    document.getElementById('edit_event_end_date').value = formatDate(event.event_end_date);
    
    document.getElementById('editEventModal').style.display = 'flex';
}

window.onclick = function(event) {
    const modal = document.getElementById('editEventModal');
    if (event.target == modal) {
        modal.style.display = "none";
    }
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>