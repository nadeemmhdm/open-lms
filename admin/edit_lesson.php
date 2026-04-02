<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

$lesson_id = $_GET['id'] ?? 0;
if (!$lesson_id)
    redirect('batches.php');

$stmt = $pdo->prepare("SELECT * FROM lessons WHERE id = ?");
$stmt->execute([$lesson_id]);
$lesson = $stmt->fetch();

if (!$lesson)
    redirect('batches.php');

// Handle Deletion of Resources
if (isset($_GET['delete_resource'])) {
    $res_id = $_GET['delete_resource'];
    // Optional: unlink file if type is not link
    $stmt = $pdo->prepare("SELECT file_url, type FROM resources WHERE id = ?");
    $stmt->execute([$res_id]);
    $res = $stmt->fetch();
    if ($res && $res['type'] !== 'link') {
        if (file_exists($path_to_root . $res['file_url'])) {
            unlink($path_to_root . $res['file_url']);
        }
    }
    $pdo->prepare("DELETE FROM resources WHERE id = ?")->execute([$res_id]);
    redirect("edit_lesson.php?id=$lesson_id");
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_lesson'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $video_url = trim($_POST['video_url']);
    $image_url = trim($_POST['image_url']);
    $publish_date = !empty($_POST['publish_date']) ? $_POST['publish_date'] : null;
    $is_optional = isset($_POST['is_optional']) ? 1 : 0;
    $exam_id = $_POST['exam_id'] ?: null;

    $stmt = $pdo->prepare("UPDATE lessons SET title = ?, content = ?, video_url = ?, image_url = ?, publish_date = ?, is_optional = ?, exam_id = ? WHERE id = ?");
    $stmt->execute([$title, $content, $video_url, $image_url, $publish_date, $is_optional, $exam_id, $lesson_id]);
    $message = "Lesson updated successfully!";

    // Refresh data
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE id = ?");
    $stmt->execute([$lesson_id]);
    $lesson = $stmt->fetch();

    // Handle New Resources in Edit? (Maybe just redirect or add form below)
    if (!empty($_POST['resource_links'])) {
        $links = explode("\n", $_POST['resource_links']);
        foreach ($links as $link) {
            $link = trim($link);
            if (!empty($link)) {
                $pdo->prepare("INSERT INTO resources (lesson_id, title, file_url, type) VALUES (?, ?, ?, 'link')")
                    ->execute([$lesson_id, 'External Link', $link]);
            }
        }
    }
}

$resources = $pdo->prepare("SELECT * FROM resources WHERE lesson_id = ?");
$resources->execute([$lesson_id]);
$lesson_resources = $resources->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 20px;">
    <a href="add_lesson.php?batch_id=<?= $lesson['batch_id'] ?>" style="color: #666;"><i class="fas fa-arrow-left"></i>
        Back to Lessons</a>
</div>

<h2>Edit Lesson:
    <?= htmlspecialchars($lesson['title']) ?>
</h2>

<?php if ($message): ?>
    <div class="badge badge-success" style="display: block; padding: 15px; margin-bottom: 20px;">
        <?= $message ?>
    </div>
<?php endif; ?>

<div class="white-card"
    style="background: white; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
    <form method="POST">
        <div class="form-group">
            <label>Lesson Title</label>
            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($lesson['title']) ?>"
                required>
        </div>

        <div class="form-group">
            <label>External Video URL (YouTube/Vimeo)</label>
            <input type="url" name="video_url" class="form-control"
                value="<?= htmlspecialchars($lesson['video_url']) ?>">
        </div>

        <div class="form-group">
            <label>Cover Image URL</label>
            <input type="url" name="image_url" class="form-control"
                value="<?= htmlspecialchars($lesson['image_url']) ?>">
        </div>

        <div class="form-group">
            <label>Publish Schedule (Optional)</label>
            <input type="datetime-local" name="publish_date" class="form-control" 
                value="<?= $lesson['publish_date'] ? date('Y-m-d\TH:i', strtotime($lesson['publish_date'])) : '' ?>">
            <small style="color: #666;">Leave empty to publish immediately.</small>
        </div>

        <div class="form-group" style="background: #f0f9ff; padding: 15px; border-radius: 12px; border: 1px solid #bae6fd; margin-bottom: 20px;">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                <input type="checkbox" name="is_optional" value="1" <?= $lesson['is_optional'] ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                <span style="font-weight: 700; color: #0369a1;">Mark as Optional Lesson</span>
            </label>
        </div>

        <div class="form-group" style="margin-bottom: 20px;">
            <label>Linked Certification Exam</label>
            <select name="exam_id" class="form-control">
                <option value="">-- No Exam Link --</option>
                <?php 
                $exams_stmt = $pdo->prepare("SELECT id, title FROM exams WHERE batch_id = ? OR batch_id IS NULL");
                $exams_stmt->execute([$lesson['batch_id']]);
                while ($ex = $exams_stmt->fetch()) {
                    $sel = ($lesson['exam_id'] == $ex['id']) ? 'selected' : '';
                    echo "<option value='{$ex['id']}' $sel>".htmlspecialchars($ex['title'])."</option>";
                }
                ?>
            </select>
        </div>

        <div class="form-group">
            <label>Lesson Content / Notes (HTML/CSS/JS Supported)</label>
            <div id="monaco-editor" style="height: 500px; border: 1px solid #ddd; border-top-left-radius: 8px; border-top-right-radius: 8px;"></div>
            <textarea name="content" id="lesson_content" style="display: none;"><?= htmlspecialchars($lesson['content']) ?></textarea>
            <small style="color: #666;">Code highlighting is preserved for &lt;pre&gt;&lt;code&gt; blocks using Prism themes.</small>
        </div>

        <!-- Monaco Editor Support Scripts -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.36.1/min/vs/loader.min.js"></script>
        <script>
            require.config({ paths: { 'vs': 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.36.1/min/vs' }});
            require(['vs/editor/editor.main'], function() {
                var content = document.getElementById('lesson_content').value;
                window.editor = monaco.editor.create(document.getElementById('monaco-editor'), {
                    value: content,
                    language: 'html',
                    theme: 'vs-light',
                    automaticLayout: true,
                    minimap: { enabled: false },
                    fontSize: 14,
                    wordWrap: 'on'
                });

                // Update the hidden textarea on every change
                editor.model.onDidChangeContent((event) => {
                    document.getElementById('lesson_content').value = editor.getValue();
                });
            });

            // Ensure the content is synced before form submission
            document.querySelector('form').onsubmit = function() {
                document.getElementById('lesson_content').value = editor.getValue();
            };
        </script>

        <button type="submit" name="update_lesson" class="btn btn-primary" style="margin-bottom: 20px;">Save Lesson Info</button>

        <h4 style="margin: 30px 0 15px; color: var(--primary);">Manage Resources & Links</h4>
        <div style="margin-bottom: 20px;">
            <?php if (empty($lesson_resources)): ?>
                <p style="color: #888;">No resources attached to this lesson.</p>
            <?php else: ?>
                <ul style="list-style: none;">
                    <?php foreach ($lesson_resources as $res): ?>
                        <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: #f8f9fa; border-radius: 8px; margin-bottom: 8px;">
                            <span>
                                <i class="fas <?= $res['type'] == 'link' ? 'fa-link' : 'fa-file-alt' ?>" style="margin-right: 10px; color: #666;"></i>
                                <?= htmlspecialchars($res['title']) ?> 
                                <small style="color: #999; margin-left: 10px;">(<?= $res['type'] ?>)</small>
                            </span>
                            <a href="?id=<?= $lesson_id ?>&delete_resource=<?= $res['id'] ?>" onclick="return confirm('Delete this resource?')" style="color: var(--danger);"><i class="fas fa-trash"></i></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label>Add New External Links (One per line)</label>
            <textarea name="resource_links" class="form-control" rows="3" placeholder="https://..."></textarea>
        </div>

        <button type="submit" name="update_lesson" class="btn btn-success">Update All</button>
    </form>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>