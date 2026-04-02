<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    redirect('../index.php');
}

$message = '';
$batch_id = $_GET['batch_id'] ?? 0;
if (!$batch_id)
    redirect('batches.php');

// Handle Lesson Upload
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $video_url = trim($_POST['video_url']);
    $image_url = trim($_POST['image_url']);
    $publish_date = !empty($_POST['publish_date']) ? $_POST['publish_date'] : null;
    $course_id = $_POST['course_id'] ?: null;
    $exam_id = $_POST['exam_id'] ?: null;
    $is_optional = isset($_POST['is_optional']) ? 1 : 0;
    $file_path = '';

    // Handle 50MB Video Upload
    if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] == 0) {
        $max_size = 50 * 1024 * 1024; // 50MB
        if ($_FILES['video_file']['size'] > $max_size) {
            $message = "File too large. Max 50MB allowed.";
        } else {
            $ext = pathinfo($_FILES['video_file']['name'], PATHINFO_EXTENSION);
            if (in_array(strtolower($ext), ['mp4', 'webm', 'ogg'])) {
                $upload_dir = $path_to_root . 'uploads/videos/';
                if (!is_dir($upload_dir))
                    mkdir($upload_dir, 0777, true);
                $file_name = uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['video_file']['tmp_name'], $upload_dir . $file_name)) {
                    $file_path = 'uploads/videos/' . $file_name;
                }
            } else {
                $message = "Invalid video format. Use MP4, WEBM, or OGG.";
            }
        }
    }

    if (!$message) {
        $sql = "INSERT INTO lessons (batch_id, course_id, exam_id, title, content, video_url, video_file, image_url, is_optional, publish_date) VALUES (?,?,?,?,?,?,?,?,?,?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$batch_id, $course_id, $exam_id, $title, $content, $video_url, $file_path, $image_url, $is_optional, $publish_date]);

        $lesson_id = $pdo->lastInsertId();

        // Handle Resource Files (Multiple)
        if (isset($_FILES['resources'])) {
            $r_files = $_FILES['resources'];
            $r_count = count($r_files['name']);

            for ($i = 0; $i < $r_count; $i++) {
                if ($r_files['error'][$i] == 0) {
                    $r_name = $r_files['name'][$i];
                    $r_ext = pathinfo($r_name, PATHINFO_EXTENSION);
                    $dest_dir = $path_to_root . 'uploads/resources/';
                    if (!is_dir($dest_dir))
                        mkdir($dest_dir, 0777, true);

                    $dest_file = uniqid() . '_' . $r_name;
                    if (move_uploaded_file($r_files['tmp_name'][$i], $dest_dir . $dest_file)) {
                        $file_url = 'uploads/resources/' . $dest_file;
                        $type = in_array(strtolower($r_ext), ['pdf', 'doc', 'docx']) ? 'pdf' : 'other';
                        $pdo->prepare("INSERT INTO resources (lesson_id, title, file_url, type) VALUES (?, ?, ?, ?)")
                            ->execute([$lesson_id, $r_name, $file_url, $type]);
                    }
                }
            }
        }

        // Handle Links
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

        $message = "Lesson & Resources Added Successfully!";
    }
}

// Handle Actions (Delete/Hide)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $action = $_GET['action'];

    if ($action == 'delete') {
        // Fetch file to delete physically
        $stmt = $pdo->prepare("SELECT video_file FROM lessons WHERE id = ?");
        $stmt->execute([$id]);
        $file = $stmt->fetchColumn();
        if ($file && file_exists($path_to_root . $file)) {
            unlink($path_to_root . $file);
        }
        $pdo->prepare("DELETE FROM lessons WHERE id = ?")->execute([$id]);
        $message = "Lesson deleted successfully.";
    } elseif ($action == 'hide') {
        $pdo->prepare("UPDATE lessons SET is_hidden = 1 WHERE id = ?")->execute([$id]);
    } elseif ($action == 'show') {
        $pdo->prepare("UPDATE lessons SET is_hidden = 0 WHERE id = ?")->execute([$id]);
    }
}

// Fetch Batch Courses
$batchCoursesStmt = $pdo->prepare("SELECT c.id, c.title FROM batch_courses bc JOIN courses c ON bc.course_id = c.id WHERE bc.batch_id = ?");
$batchCoursesStmt->execute([$batch_id]);
$batch_courses = $batchCoursesStmt->fetchAll();

// Fetch Existing Lessons with Course Titles
$stmt = $pdo->prepare("SELECT l.*, c.title as course_title FROM lessons l LEFT JOIN courses c ON l.course_id = c.id WHERE l.batch_id = ? ORDER BY l.id DESC");
$stmt->execute([$batch_id]);
$lessons = $stmt->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 20px;">
    <a href="batches.php" style="color: #666;"><i class="fas fa-arrow-left"></i> Back to Batch Management</a>
</div>

<h2>Curriculum & Content</h2>
<p style="color: #666; margin-bottom: 30px;">Batch ID: #<?= $batch_id ?> | Configure lessons and resources for this track.</p>

<?php if ($message)
    echo "<div class='badge badge-success' style='display:block; padding: 15px; margin-bottom: 20px;'>$message</div>"; ?>

<div class="row" style="display: grid; grid-template-columns: 450px 1fr; gap: 30px;">
    <div class="white-card" style="background: white; padding: 25px; border-radius: 16px; height: fit-content; position: sticky; top: 20px;">
        <form method="POST" enctype="multipart/form-data">
            <h4 style="margin-bottom: 20px; color: var(--primary);">Step 1: Lesson Overview</h4>
            
            <div class="form-group">
                <label>Lesson Title</label>
                <input type="text" name="title" class="form-control" placeholder="e.g. Introduction to Variables" required>
            </div>

            <div class="form-group">
                <label>Assign to Course (Optional)</label>
                <select name="course_id" class="form-control">
                    <option value="">-- Multiple / General --</option>
                    <?php foreach ($batch_courses as $bc): ?>
                        <option value="<?= $bc['id'] ?>"><?= htmlspecialchars($bc['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #888;">Categorize this lesson under a specific course.</small>
            </div>

            <h4 style="margin: 30px 0 15px; color: var(--primary);">Step 2: Video Content</h4>
            <div class="form-group">
                <label>Direct Video Upload (Max 50MB)</label>
                <input type="file" name="video_file" class="form-control" accept="video/mp4,video/webm,video/ogg">
            </div>
            <div class="form-group" style="text-align: center; color: #999; font-size: 0.8rem;">OR PROVIDE AN EXTERNAL SOURCE</div>
            <div class="form-group">
                <label>Streaming URL (YouTube/Vimeo)</label>
                <input type="url" name="video_url" class="form-control" placeholder="https://youtube.com/...">
            </div>

            <h4 style="margin: 30px 0 15px; color: var(--primary);">Step 3: Materials & Scheduling</h4>
            <div class="form-group">
                <label>Optional Publish Schedule (GMT+5.30)</label>
                <input type="datetime-local" name="publish_date" class="form-control">
                <small style="color: #666;">Leave empty to publish immediately. Students can only see scheduled lessons after the set time.</small>
            </div>
            <div class="form-group">
                <label>Thumbnail / Note Image URL</label>
                <input type="url" name="image_url" class="form-control">
            </div>
            <div class="form-group">
                <label>Lesson Transcript / Explanation (HTML/CSS/JS Supported)</label>
                <div id="monaco-editor" style="height: 400px; border: 1px solid #ddd; border-radius: 8px;"></div>
                <textarea name="content" id="lesson_content" style="display: none;"></textarea>
                <small style="color: #666;">You can write plain text or use &lt;script&gt;, &lt;style&gt;, &lt;pre&gt;&lt;code&gt; tags for full code support.</small>
            </div>

            <!-- Monaco Editor Support Scripts -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.36.1/min/vs/loader.min.js"></script>
            <script>
                require.config({ paths: { 'vs': 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.36.1/min/vs' }});
                require(['vs/editor/editor.main'], function() {
                    window.editor = monaco.editor.create(document.getElementById('monaco-editor'), {
                        value: "",
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

                // Sync before form submit
                document.querySelector('form').onsubmit = function() {
                    document.getElementById('lesson_content').value = editor.getValue();
                };
            </script>

            <div class="form-group">
                <label>Resource Downloads (PDF, ZIP, etc)</label>
                <input type="file" name="resources[]" class="form-control" multiple>
            </div>
            <div class="form-group">
                <label>Useful Links (One per line)</label>
                <textarea name="resource_links" class="form-control" rows="2" placeholder="https://github.com/..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 15px; font-size: 1rem; border-radius: 12px; margin-top: 10px;">
                <i class="fas fa-cloud-upload-alt"></i> Publish Lesson
            </button>
        </form>
    </div>

    <!-- Existing Lessons List -->
    <div>
        <h4 style="margin-bottom: 20px; display: flex; justify-content: space-between;">
            Published Lessons
            <span style="font-size: 0.9rem; font-weight: 400; color: #888;">Total: <?= count($lessons) ?></span>
        </h4>
        
        <?php if (empty($lessons)): ?>
            <div class="white-card" style="text-align: center; padding: 60px; color: #999;">
                <i class="fas fa-folder-open" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                <p>No lessons found for this batch yet.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($lessons as $l): ?>
            <div class="white-card"
                style="background: white; padding: 20px; border-radius: 16px; margin-bottom: 15px; display: flex; align-items: center; justify-content: space-between; border-left: 6px solid <?= $l['is_hidden'] ? '#bdc3c7' : 'var(--primary)' ?>; transition: 0.3s; <?= $l['is_hidden'] ? 'opacity: 0.7;' : '' ?>">
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                        <strong style="font-size: 1.1rem; color: #2c3e50;"><?= htmlspecialchars($l['title']) ?></strong>
                        <?php if ($l['is_hidden']): ?>
                            <span class="badge" style="background:#f1f2f6; color:#747d8c; font-size: 0.7rem;">HIDDEN</span>
                        <?php endif; ?>
                    </div>
                    
                    <div style="display: flex; gap: 15px; font-size: 0.85rem; color: #7f8c8d;">
                        <?php if ($l['course_title']): ?>
                            <span><i class="fas fa-layer-group" style="color: #3498db;"></i> <?= htmlspecialchars($l['course_title']) ?></span>
                        <?php endif; ?>
                        <span><i class="far fa-calendar-alt"></i> <?= date('M d, Y', strtotime($l['publish_date'] ?: $l['created_at'])) ?></span>
                        <?php if ($l['publish_date'] && strtotime($l['publish_date']) > time()): ?>
                            <span style="color: #f39c12;"><i class="fas fa-clock"></i> Scheduled: <?= date('M d, H:i', strtotime($l['publish_date'])) ?></span>
                        <?php endif; ?>
                        <?php if ($l['video_file'] || $l['video_url']): ?>
                            <span style="color: var(--success);"><i class="fas fa-play-circle"></i> Video Ready</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <a href="edit_lesson.php?id=<?= $l['id'] ?>" class="btn btn-sm" style="background: #f8f9fa; color: #333; border: 1px solid #ddd;" title="Edit Lesson"><i class="fas fa-pen"></i></a>
                    
                    <?php if ($l['is_hidden']): ?>
                        <a href="?batch_id=<?= $batch_id ?>&action=show&id=<?= $l['id'] ?>" class="btn btn-sm btn-success" title="Publish Lesson"><i class="fas fa-eye"></i></a>
                    <?php else: ?>
                        <a href="?batch_id=<?= $batch_id ?>&action=hide&id=<?= $l['id'] ?>" class="btn btn-sm btn-warning" title="Hide Lesson"><i class="fas fa-eye-slash"></i></a>
                    <?php endif; ?>
                    
                    <a href="?batch_id=<?= $batch_id ?>&action=delete&id=<?= $l['id'] ?>" onclick="return confirm('Truly delete this lesson and all its resources?')" class="btn btn-sm btn-danger" title="Delete Permanent"><i class="fas fa-trash"></i></a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
@media (max-width: 992px) {
    .row { grid-template-columns: 1fr !important; }
    .white-card { position: static !important; }
}
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>

<?php include $path_to_root . 'includes/footer.php'; ?>