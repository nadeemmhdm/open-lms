<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = $_SESSION['user_id'];
$project_id = $_GET['id'] ?? redirect('projects.php');

// Fetch Project Details (Verify access and time)
$stmt = $pdo->prepare("SELECT p.*, b.name as batch_name 
                      FROM projects p 
                      JOIN student_batches sb ON p.batch_id = sb.batch_id 
                      JOIN batches b ON p.batch_id = b.id
                      WHERE p.id = ? AND sb.student_id = ? 
                      AND (p.start_date <= NOW())");
$stmt->execute([$project_id, $user_id]);
$project = $stmt->fetch();

if (!$project) {
    redirect('projects.php?error=not_found');
}

$is_expired = (time() > strtotime($project['end_date']));

// Check for existing submission
$sub_stmt = $pdo->prepare("SELECT * FROM project_submissions WHERE project_id = ? AND student_id = ?");
$sub_stmt->execute([$project_id, $user_id]);
$submission = $sub_stmt->fetch();

$sub_id = $submission ? $submission['id'] : null;

// Fetch files if submission exists
$files = [];
if ($sub_id) {
    $f_stmt = $pdo->prepare("SELECT * FROM project_files WHERE submission_id = ?");
    $f_stmt->execute([$sub_id]);
    $files = $f_stmt->fetchAll();
}

$message = '';
$sub_types_enabled = explode(',', $project['submission_types']);

// Handle Submission Form logic remains same...
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_project'])) {
    if ($is_expired) {
        $message = "<div class='badge badge-danger'>Deadline has passed. No further submissions allowed.</div>";
    } else {
        $sub_text = $_POST['submission_text'] ?? null;
        $sub_links = $_POST['submission_links'] ?? null;
        $errors = [];

        $files_to_upload = [];
        if (in_array('file', $sub_types_enabled) && isset($_FILES['project_files']) && !empty($_FILES['project_files']['name'][0])) {
            $total_files = count($_FILES['project_files']['name']);
            $max_size = $project['max_size_mb'] * 1024 * 1024;
            $allowed_exts = array_map('trim', explode(',', strtolower($project['allowed_types'])));

            for ($i = 0; $i < $total_files; $i++) {
                if ($_FILES['project_files']['error'][$i] == 0) {
                    $file_name = $_FILES['project_files']['name'][$i];
                    $file_size = $_FILES['project_files']['size'][$i];
                    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                    if (!in_array($ext, $allowed_exts)) $errors[] = "<strong>{$file_name}</strong>: Invalid type .{$ext}";
                    if ($file_size > $max_size) $errors[] = "<strong>{$file_name}</strong>: File too large";
                    
                    if (empty($errors)) $files_to_upload[] = ['name' => $file_name, 'tmp' => $_FILES['project_files']['tmp_name'][$i], 'ext' => $ext];
                }
            }
        }

        if (!empty($errors)) {
            $message = "<div class='badge badge-danger' style='text-align:left; line-height:1.6;'>".implode("<br>", $errors)."</div>";
        } else {
            try {
                $pdo->beginTransaction();
                if (!$submission) {
                    $stmt = $pdo->prepare("INSERT INTO project_submissions (project_id, student_id, submission_text, submission_links) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$project_id, $user_id, $sub_text, $sub_links]);
                    $sub_id = $pdo->lastInsertId();
                } else {
                    $stmt = $pdo->prepare("UPDATE project_submissions SET submission_text = ?, submission_links = ?, submitted_at = NOW() WHERE id = ?");
                    $stmt->execute([$sub_text, $sub_links, $sub_id]);
                }
                foreach ($files_to_upload as $f) {
                    $new_filename = "p{$project_id}_u{$user_id}_" . uniqid() . "." . $f['ext'];
                    if (move_uploaded_file($f['tmp'], $path_to_root . "uploads/projects/" . $new_filename)) {
                        $f_stmt = $pdo->prepare("INSERT INTO project_files (submission_id, file_url, file_name) VALUES (?, ?, ?)");
                        $f_stmt->execute([$sub_id, $new_filename, $f['name']]);
                    }
                }
                $pdo->commit();
                header("Location: project_details.php?id={$project_id}&success=1");
                exit();
            } catch (Exception $e) { $pdo->rollBack(); $message = "<div class='badge badge-danger'>Database Error: " . $e->getMessage() . "</div>"; }
        }
    }
}

// Delete single file
if (isset($_GET['delete_file'])) {
    if (!$is_expired) {
        $file_id = $_GET['delete_file'];
        $stmt = $pdo->prepare("SELECT pf.file_url FROM project_files pf JOIN project_submissions ps ON pf.submission_id = ps.id WHERE pf.id = ? AND ps.student_id = ?");
        $stmt->execute([$file_id, $user_id]);
        $file_to_del = $stmt->fetch();
        if ($file_to_del) {
            @unlink($path_to_root . "uploads/projects/" . $file_to_del['file_url']);
            $pdo->prepare("DELETE FROM project_files WHERE id = ?")->execute([$file_id]);
            header("Location: project_details.php?id={$project_id}&msg=file_deleted");
            exit();
        }
    }
}

if (isset($_GET['success'])) $message = "<div class='badge badge-success' style='display:block; padding: 12px; margin-bottom: 20px;'>Your submission has been saved!</div>";

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="project-header">
    <a href="projects.php" class="back-btn"><i class="fas fa-arrow-left"></i> My Projects</a>
    <h2 class="project-title"><?= htmlspecialchars($project['title']) ?></h2>
    <div class="header-pills">
        <span class="pill pill-warning"><?= htmlspecialchars($project['batch_name']) ?></span>
        <?php if($is_expired): ?><span class="pill pill-danger">PROJECT EXPIRED</span><?php endif; ?>
    </div>
</div>

<div class="responsive-wrapper">
    <!-- LEFT: Main Info & Upload -->
    <div class="main-content-area">
        <?php if($project['image_url']): ?>
            <div class="project-banner">
                <img src="<?= $path_to_root ?>uploads/images/<?= $project['image_url'] ?>">
            </div>
        <?php endif; ?>

        <div class="white-card glass-modern" style="padding: 30px; margin-bottom: 30px;">
            <h4 class="section-heading">Project Resources & Guidelines</h4>
            
            <?php if($project['video_url']): ?>
                <div class="video-container">
                    <?php 
                    $v_url = $project['video_url'];
                    if (strpos($v_url, 'youtube.com') !== false || strpos($v_url, 'youtu.be') !== false) {
                        preg_match('%(?:youtube\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $v_url, $match);
                        $vid_id = $match[1] ?? ''; echo "<iframe width='100%' height='450' src='https://www.youtube.com/embed/{$vid_id}' frameborder='0' allowfullscreen></iframe>";
                    } else { echo "<div class='video-link-fallback'><a href='{$v_url}' target='_blank'>View Video Tutorial <i class='fas fa-external-link-alt'></i></a></div>"; }
                    ?>
                </div>
            <?php endif; ?>

            <div class="project-instructions"><?= nl2br(htmlspecialchars($project['description'])) ?></div>

            <?php if($project['external_links']): 
                $links = explode("\n", $project['external_links']);
            ?>
                <div class="resource-links-box">
                    <h5 style="margin-bottom: 12px; font-size: 0.9rem;">Reference Materials:</h5>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        <?php foreach($links as $link): 
                            $parts = explode('|', $link);
                            if(count($parts) == 2):
                        ?><a href="<?= trim($parts[1]) ?>" target="_blank" class="resource-btn"><?= trim($parts[0]) ?></a><?php endif; endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- UPLOAD SECTION -->
        <div class="white-card glass-modern" style="padding: 30px;">
            <h4 class="section-heading">Submission Workspace</h4>
            <?= $message ?>

            <?php if(!$is_expired): ?>
                <form method="POST" enctype="multipart/form-data">
                    <?php if(in_array('text', $sub_types_enabled)): ?>
                        <div class="form-group">
                            <label>Code / Script / Written Analysis</label>
                            <textarea name="submission_text" class="terminal-editor" rows="10" placeholder="Type or paste your code here to preserve formatting..."><?= $submission['submission_text'] ?? '' ?></textarea>
                        </div>
                    <?php endif; ?>

                    <?php if(in_array('link', $sub_types_enabled)): ?>
                        <div class="form-group" style="margin-top: 25px;">
                            <label>Project Links (GitHub, Drive, or Portfolio)</label>
                            <textarea name="submission_links" class="form-control" rows="2" placeholder="GitHub Repository|https://..."><?= $submission['submission_links'] ?? '' ?></textarea>
                        </div>
                    <?php endif; ?>

                    <?php if(in_array('file', $sub_types_enabled)): ?>
                        <div class="custom-file-upload">
                            <label><i class="fas fa-cloud-upload-alt"></i> Select Project Files</label>
                            <input type="file" name="project_files[]" class="form-control" multiple accept=".<?= str_replace(',', ',.', $project['allowed_types']) ?>">
                            <div class="file-hints">
                                Allowed: <?= strtoupper($project['allowed_types']) ?> | Max: <?= $project['max_size_mb'] ?>MB per file.
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" name="submit_project" class="btn btn-primary submit-btn">
                        <i class="fas fa-paper-plane"></i> <?= $submission ? 'Update Submission' : 'Submit My Work' ?>
                    </button>
                </form>
            <?php else: ?>
                <div class="deadline-locked-box">
                    <i class="fas fa-lock"></i>
                    <p>Submissions for this project closed on <?= date('d M, h:i A', strtotime($project['end_date'])) ?></p>
                </div>
            <?php endif; ?>

            <!-- View Current Result -->
            <?php if(!empty($files) || $submission['submission_text'] || $submission['submission_links']): ?>
                <div class="view-submission-history">
                    <h5 style="margin-bottom: 15px;">Your Latest Uploads:</h5>
                    <?php if(!empty($files)): ?>
                        <div class="file-list-preview">
                            <?php foreach($files as $f): ?>
                                <div class="file-row">
                                    <span><i class="far fa-file-alt"></i> <?= htmlspecialchars($f['file_name']) ?></span>
                                    <div style="display:flex; gap: 8px;">
                                        <a href="<?= $path_to_root ?>uploads/projects/<?= $f['file_url'] ?>" target="_blank" class="f-btn"><i class="fas fa-download"></i></a>
                                        <?php if(!$is_expired): ?><a href="?id=<?= $project_id ?>&delete_file=<?= $f['id'] ?>" onclick="return confirm('Delete?')" class="f-btn-del"><i class="fas fa-trash"></i></a><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Status/Timeline -->
    <div class="sidebar-status-area">
        <div class="white-card glass-modern status-sticky" style="padding: 25px;">
            <h4 style="margin-bottom: 25px; font-weight:700;">Performance & Deadlines</h4>
            
            <div class="deadline-pill-lg <?= $is_expired ? 'expired' : '' ?>">
                <small>PROJECT DEADLINE</small>
                <div><?= date('d M, h:i A', strtotime($project['end_date'])) ?></div>
            </div>

            <div class="status-meta">
                <div class="meta-row">
                    <span>Current Status:</span>
                    <strong class="text-<?= $submission ? 'success' : 'danger' ?>"><?= $submission ? 'Submitted' : 'Pending Upload' ?></strong>
                </div>
                <?php if($submission && $submission['score'] !== null): ?>
                    <div class="meta-row highlight">
                        <span>Project Grade:</span>
                        <strong class="grade-text"><?= $submission['score'] ?>%</strong>
                    </div>
                <?php endif; ?>
            </div>

            <?php if($submission && $submission['feedback']): ?>
                <div class="instructor-feedback-box">
                    <small>Instructor Feedback:</small>
                    <p>"<?= htmlspecialchars($submission['feedback']) ?>"</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.project-header { margin-bottom: 30px; }
.back-btn { text-decoration: none; color: #64748b; font-size: 0.85rem; font-weight:600; display:flex; align-items:center; gap:8px; margin-bottom:12px; transition:0.3s; }
.back-btn:hover { color: var(--primary); }
.project-title { font-weight: 800; font-size: 1.8rem; letter-spacing: -0.5px; margin: 0; }
.header-pills { display: flex; gap: 8px; margin-top: 10px; }
.pill { padding: 4px 12px; font-size: 0.7rem; font-weight: 800; border-radius: 50px; text-transform: uppercase; letter-spacing: 0.5px; }
.pill-warning { background: #fef3c7; color: #d97706; }
.pill-danger { background: #fee2e2; color: #ef4444; }

.responsive-wrapper { display: flex; flex-wrap: wrap; gap: 30px; align-items: flex-start; }
.main-content-area { flex: 1; min-width: 320px; }
.sidebar-status-area { flex: 0 0 350px; }

.project-banner { width: 100%; height: 350px; border-radius: 20px; overflow: hidden; margin-bottom: 30px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
.project-banner img { width: 100%; height: 100%; object-fit: cover; }

.glass-modern { border: 1px solid #f1f5f9; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
.section-heading { font-weight: 800; border-bottom: 2px solid var(--slate-100); padding-bottom: 12px; margin-bottom: 20px; color: #1e293b; }

.video-container { margin: 25px 0; border-radius: 16px; overflow: hidden; background: #000; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }
.video-link-fallback { padding: 40px; text-align: center; background: #1e293b; }
.video-link-fallback a { color: #fff; text-decoration: none; font-weight: 700; opacity: 0.8; transition:0.3s; }
.video-link-fallback a:hover { opacity: 1; }

.project-instructions { color: #475569; line-height: 1.8; font-size: 1rem; margin-bottom: 30px; }
.resource-links-box { background: #f8fafc; padding: 20px; border-radius: 16px; border-left: 4px solid var(--primary); }
.resource-btn { background: #fff; border: 1px solid #e2e8f0; color: #1e293b; padding: 6px 16px; border-radius: 8px; font-size: 0.8rem; text-decoration: none; font-weight: 700; transition: 0.3s; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
.resource-btn:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

.terminal-editor { width: 100%; font-family: 'Courier New', monospace; font-size: 0.9rem; background: #0f172a !important; color: #34d399 !important; border: none !important; border-radius: 12px; padding: 25px !important; line-height: 1.6; }
.custom-file-upload { margin-top: 30px; background: #fafafa; border: 2px dashed #e2e8f0; border-radius: 16px; padding: 30px; text-align: center; }
.custom-file-upload label { display: block; font-weight: 800; color: #1e293b; margin-bottom: 15px; font-size: 1.1rem; }
.file-hints { font-size: 0.75rem; color: #64748b; margin-top: 10px; font-weight:600; }

.submit-btn { width: 100%; padding: 18px; border-radius: 12px; font-weight: 800; font-size: 1rem; margin-top: 30px; box-shadow: 0 10px 20px rgba(79, 70, 229, 0.2); }
.deadline-locked-box { padding: 40px; text-align: center; background: #f8fafc; border: 2px dashed #e2e8f0; border-radius: 20px; }
.deadline-locked-box i { font-size: 2.5rem; color: #94a3b8; margin-bottom: 15px; }

.file-list-preview { display: flex; flex-direction: column; gap: 10px; margin-top: 15px; }
.file-row { background: #fff; border: 1px solid #f1f5f9; padding: 12px 20px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
.file-row span { font-size: 0.85rem; font-weight: 700; color: #334155; }
.f-btn { color: var(--primary); padding: 5px; opacity: 0.7; }
.f-btn-del { color: #ef4444; padding: 5px; opacity: 0.7; }
.f-btn:hover, .f-btn-del:hover { opacity: 1; }

.status-sticky { position: sticky; top: 100px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
.deadline-pill-lg { background: #fef2f2; padding: 20px; border-radius: 16px; text-align: center; margin-bottom: 20px; }
.deadline-pill-lg.expired { background: #f1f5f9; }
.deadline-pill-lg small { color: #ef4444; font-weight: 800; font-size: 0.7rem; display: block; margin-bottom: 5px; letter-spacing: 1px; }
.deadline-pill-lg.expired small { color: #64748b; }
.deadline-pill-lg div { font-size: 1.1rem; font-weight: 900; color: #1e293b; }

.status-meta { display: grid; gap: 15px; margin-top: 25px; border-top: 1px solid #f1f5f9; padding-top: 25px; }
.meta-row { display: flex; justify-content: space-between; font-size: 0.9rem; }
.meta-row.highlight { background: #f0fdf4; padding: 12px; border-radius: 10px; border: 1px solid #dcfce7; }
.grade-text { font-size: 1.5rem; color: #166534; font-weight: 900; }

.instructor-feedback-box { margin-top: 25px; background: #fff; border: 1px solid #e2e8f0; padding: 15px; border-radius: 12px; }
.instructor-feedback-box small { color: #888; font-weight: 700; margin-bottom: 5px; display: block; }
.instructor-feedback-box p { font-style: italic; font-size: 0.85rem; color: #475569; margin: 0; }

@media (max-width: 1000px) {
    .sidebar-status-area { flex: 1 1 100%; order: -1; }
    .status-sticky { position: static; }
    .project-banner { height: 250px; }
}

@media (max-width: 600px) {
    .project-title { font-size: 1.4rem; }
    .project-banner { height: 200px; }
    .white-card { padding: 20px !important; }
}
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>
