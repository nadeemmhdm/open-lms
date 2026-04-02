<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';
require_once $path_to_root . 'includes/mailer.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

// Sub-admin permission check
if ($_SESSION['role'] === 'sub_admin' && !hasPermission('announcements')) {
    redirect('index.php');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_notification'])) {

    $title = trim($_POST['title']);
    $role = $_POST['target_role'];
    $batch_id = !empty($_POST['batch_id']) ? $_POST['batch_id'] : null;
    $msg = $_POST['message'];
    $attach_url = null;
    $attach_type = null;

    // Handle File Upload (Image/Video)
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'webm', 'ogg'];
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $is_video = in_array($ext, ['mp4', 'webm', 'ogg']);
            $max_size = $is_video ? 50 * 1024 * 1024 : 5 * 1024 * 1024; // 50MB Video, 5MB Image

            if ($_FILES['attachment']['size'] <= $max_size) {
                $type = $is_video ? 'video' : 'image';
                $dir = $path_to_root . 'uploads/notifications/';
                if (!is_dir($dir))
                    mkdir($dir, 0777, true);

                $file_name = uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dir . $file_name)) {
                    $attach_url = 'uploads/notifications/' . $file_name;
                    $attach_type = $type;
                }
            } else {
                $message = "File too large. Max 50MB for video, 5MB for images.";
            }
        } else {
            $message = "Invalid file format.";
        }
    }

    $send_email = isset($_POST['send_email']);
    $direct_email = trim($_POST['direct_email'] ?? '');

    if (empty($message)) {
        $sql = "INSERT INTO notifications (title, message, target_role, batch_id, attachment_url, attachment_type) VALUES (?,?,?,?,?,?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$title, $msg, $role, $batch_id, $attach_url, $attach_type]);

        // Email Notification
        if ($send_email || !empty($direct_email)) {
            try {
                $email_subject = "Announcement: " . $title;
                $email_body = "<h3>New Announcement</h3><p>" . nl2br(htmlspecialchars($msg)) . "</p>";
                if ($attach_url) {
                    $email_body .= "<p>Visit the portal to view attachments.</p>";
                }
                
                if (!empty($direct_email)) {
                    sendLMSMail($direct_email, $email_subject, $email_body);
                }
                
                if ($send_email) {
                    notifyUsers($pdo, $role, $batch_id, $email_subject, $email_body);
                }
            } catch (Exception $e) {
                // Email failed
            }
        }

        $message = "Notification Sent Successfully!";
    }
}

if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([$_GET['delete']]);
    redirect('notifications.php');
}

// Get Batches for Filter
$batches = $pdo->query("SELECT id, name FROM batches ORDER BY name ASC")->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
    <h2>Announcement Management</h2>
    <div style="display: flex; gap: 10px;">
        <button onclick="document.getElementById('sendNotif').style.display='block'" class="btn btn-primary btn-sm">
            <i class="fas fa-bullhorn"></i> New Announcement
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="badge badge-success" style="display: block; padding: 15px; margin-bottom: 20px;">
        <?= $message ?>
    </div>
<?php endif; ?>

<!-- Send Form -->
<div id="sendNotif" style="display: none; background: white; padding: 25px; border-radius: 16px; margin-bottom: 30px;"
    class="fade-in">
    <h3>Create Announcement</h3>
    <form method="POST" enctype="multipart/form-data">
        <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label>Title</label>
                <input type="text" name="title" class="form-control" placeholder="Flash Sale / Exam Info / etc" required>
            </div>

            <div class="form-group">
                <label>Target Audience</label>
                <select name="target_role" id="targetRole" class="form-control" onchange="toggleBatchSelect()">
                    <option value="all">Everyone (Global)</option>
                    <option value="student">Students (Select Batch Optional)</option>
                    <option value="admin">Admins Only</option>
                </select>
            </div>

            <div class="form-group" id="batchSelectDiv" style="display: none;">
                <label>Specific Batch (Optional)</label>
                <select name="batch_id" class="form-control">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>">
                            <?= htmlspecialchars($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Attach Media (Image/Video max 50MB)</label>
                <input type="file" name="attachment" class="form-control" accept="image/*,video/*">
            </div>
        </div>

        <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label>Manual Email (Optional Specific User)</label>
                <input type="email" name="direct_email" class="form-control" placeholder="user@example.com">
            </div>

            <div class="form-group" style="display: flex; align-items: center; gap: 10px; height: 100%;">
                <input type="checkbox" name="send_email" id="send_email_chk" checked>
                <label for="send_email_chk" style="margin:0; cursor:pointer;">Also send Email to Target Audience?</label>
            </div>
        </div>

        <div class="form-group">
            <label>Announcement Message</label>
            <textarea name="message" class="form-control" rows="5" required
                style="white-space: pre-wrap; font-family: inherit;"></textarea>
        </div>

        <script>
            function toggleBatchSelect() {
                var role = document.getElementById('targetRole').value;
                var batchDiv = document.getElementById('batchSelectDiv');
                batchDiv.style.display = (role === 'student') ? 'block' : 'none';
            }
        </script>

        <button type="submit" name="send_notification" class="btn btn-primary">Publish Announcement</button>
        <button type="button" onclick="document.getElementById('sendNotif').style.display='none'" class="btn"
            style="background: #eee;">Cancel</button>
    </form>
</div>

<!-- List -->
<div class="row">
    <?php
    // Only show system announcements (user_id IS NULL)
    $sql = "SELECT n.*, b.name as batch_name FROM notifications n LEFT JOIN batches b ON n.batch_id = b.id WHERE n.user_id IS NULL ORDER BY n.created_at DESC";
    $stmt = $pdo->query($sql);

    while ($row = $stmt->fetch()) {
        $icon = 'fa-globe';
        $badge = 'Global';

        if ($row['target_role'] == 'student') {
            $icon = 'fa-user-graduate';
            $badge = $row['batch_name'] ? "Batch: {$row['batch_name']}" : "All Students";
        } elseif ($row['target_role'] == 'admin') {
            $icon = 'fa-user-cog';
            $badge = "Admins";
        }

        echo "
        <div class='col-12' style='background: white; padding: 20px; border-radius: 16px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); position: relative;'>
            <div style='display: flex; gap: 15px;'>
                <div style='background: rgba(78, 84, 200, 0.1); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 50%; color: var(--primary); font-size: 1.2rem; flex-shrink: 0;'>
                    <i class='fas {$icon}'></i>
                </div>
                <div style='flex: 1;'>
                    <div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;'>
                        <h4 style='margin: 0;'>{$row['title']} <span class='badge badge-warning' style='font-size: 0.7rem; margin-left: 10px;'>{$badge}</span></h4>
                        <a href='?delete={$row['id']}' onclick='return confirm(\"Delete?\")' style='color: #ccc;'><i class='fas fa-trash'></i></a>
                    </div>
                    
                    <div style='color: #444; font-size: 0.95rem; white-space: pre-wrap; line-height: 1.6; margin-bottom: 15px;'>" . htmlspecialchars($row['message']) . "</div>";

        if ($row['attachment_url']) {
            echo "<div style='margin-bottom: 15px; background: #f8f9fa; padding: 10px; border-radius: 8px; display: inline-block;'>";
            if ($row['attachment_type'] == 'image') {
                echo "<img src='{$path_to_root}{$row['attachment_url']}' style='max-width: 100%; max-height: 300px; border-radius: 4px; display: block;'>";
            } else {
                echo "<video src='{$path_to_root}{$row['attachment_url']}' controls style='max-width: 100%; max-height: 300px; border-radius: 4px; display: block;'></video>";
            }
            echo "</div>";
        }

        echo "      <div style='font-size: 0.8rem; color: #999;'>
                        Posted on " . date('M d, Y h:i A', strtotime($row['created_at'])) . "
                    </div>
                </div>
            </div>
        </div>
        ";
    }
    ?>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>