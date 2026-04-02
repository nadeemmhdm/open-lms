<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

$message = '';

// Handle CRUD Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!checkToken($_POST['csrf_token'] ?? '')) {
        $message = "<div class='badge badge-danger' style='display:block; padding: 12px; margin-bottom: 20px;'>Security error: CSRF token invalid.</div>";
    } elseif (isset($_POST['add_project']) || isset($_POST['edit_project'])) {
        $batch_ids = $_POST['batch_ids'] ?? [];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        // Format dates correctly for MySQL (replace 'T' with space)
        $start_date = str_replace('T', ' ', $_POST['start_date']);
        $end_date = str_replace('T', ' ', $_POST['end_date']);
        
        $allowed_types = strtolower(str_replace(' ', '', $_POST['allowed_types']));
        $max_size = (int)$_POST['max_size_mb'];
        $video_url = trim($_POST['video_url']);
        $external_links = trim($_POST['external_links']);
        $sub_types = isset($_POST['sub_types']) ? implode(',', $_POST['sub_types']) : 'file';

        $image_url = $_POST['existing_image'] ?? null;
        if (isset($_FILES['project_image']) && $_FILES['project_image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['project_image']['name'], PATHINFO_EXTENSION));
            $allowed_img_exts = ['jpg', 'jpeg', 'png', 'webp'];
            
            if (in_array($ext, $allowed_img_exts)) {
                $new_name = "proj_img_" . time() . "." . $ext;
                if (move_uploaded_file($_FILES['project_image']['tmp_name'], $path_to_root . "uploads/images/" . $new_name)) {
                    $image_url = $new_name;
                }
            } else {
                $message = "<div class='badge badge-danger' style='display:block; padding: 12px; margin-bottom: 20px;'>Error: Invalid image format. Only JPG, PNG, WEBP allowed.</div>";
                goto end_crud;
            }
        }

        try {
            if (isset($_POST['add_project'])) {
                $stmt = $pdo->prepare("INSERT INTO projects (title, description, image_url, video_url, external_links, submission_types, allowed_types, max_size_mb, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $description, $image_url, $video_url, $external_links, $sub_types, $allowed_types, $max_size, $start_date, $end_date]);
                $pid = $pdo->lastInsertId();
            } else {
                $pid = $_POST['project_id'];
                $stmt = $pdo->prepare("UPDATE projects SET title = ?, description = ?, image_url = ?, video_url = ?, external_links = ?, submission_types = ?, allowed_types = ?, max_size_mb = ?, start_date = ?, end_date = ? WHERE id = ?");
                $stmt->execute([$title, $description, $image_url, $video_url, $external_links, $sub_types, $allowed_types, $max_size, $start_date, $end_date, $pid]);
                $pdo->prepare("DELETE FROM project_batches WHERE project_id = ?")->execute([$pid]);
            }
            
            // Assign batches
            if (!empty($batch_ids)) {
                $stmt = $pdo->prepare("INSERT INTO project_batches (project_id, batch_id) VALUES (?, ?)");
                foreach ($batch_ids as $bid) {
                    $stmt->execute([$pid, $bid]);
                }
            }
            $message = "<div class='badge badge-success' style='display:block; padding: 12px; margin-bottom: 20px;'>Project saved successfully!</div>";
        } catch (PDOException $e) {
            $message = "<div class='badge badge-danger' style='display:block; padding: 12px; margin-bottom: 20px;'>Error: " . $e->getMessage() . "</div>";
        }
    }
    end_crud:
}

if (isset($_GET['delete'])) {
    if (isset($_GET['token']) && checkToken($_GET['token'])) {
        $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$_GET['delete']]);
        redirect('projects.php');
    } else {
        $message = "<div class='badge badge-danger' style='display:block; padding: 12px; margin-bottom: 20px;'>Security error: Delete unauthorized.</div>";
    }
}

// Fetch Data
$batches = $pdo->query("SELECT id, name FROM batches WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$projects = $pdo->query("SELECT p.*, (SELECT GROUP_CONCAT(b.name SEPARATOR ', ') FROM project_batches pb JOIN batches b ON pb.batch_id = b.id WHERE pb.project_id = p.id) as batch_names FROM projects p ORDER BY p.created_at DESC")->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
    <h2>Manage Projects (Multi-Batch)</h2>
    <button onclick="openProjectModal()" class="btn btn-primary">
        <i class="fas fa-plus"></i> Create New Project
    </button>
</div>

<?php echo $message; ?>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th style="width: 200px;">Assigned Batches</th>
                <th>Project Title</th>
                <th>Submission Types</th>
                <th>Duration</th>
                <th>Submissions</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($projects as $proj): 
                $sub_count = $pdo->prepare("SELECT COUNT(*) FROM project_submissions WHERE project_id = ?");
                $sub_count->execute([$proj['id']]);
                $count = $sub_count->fetchColumn();
            ?>
            <tr>
                <td>
                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                        <?php 
                        if ($proj['batch_names']) {
                            $bnames = explode(', ', $proj['batch_names']);
                            foreach($bnames as $bn) echo "<span class='badge' style='background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; font-size: 0.65rem;'>".htmlspecialchars($bn)."</span>";
                        } else {
                            echo "<span class='badge badge-danger'>No Batches</span>";
                        }
                        ?>
                    </div>
                </td>
                <td>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <?php if($proj['image_url']): ?>
                            <img src="<?= $path_to_root ?>uploads/images/<?= $proj['image_url'] ?>" style="width: 40px; height: 40px; border-radius: 8px; object-fit: cover;">
                        <?php endif; ?>
                        <strong><?= htmlspecialchars($proj['title']) ?></strong>
                    </div>
                </td>
                <td>
                    <?php 
                        $types = isset($proj['submission_types']) ? explode(',', $proj['submission_types']) : ['file'];
                        foreach($types as $t) echo "<span class='badge' style='background: #f1f5f9; color: #475569; margin-right: 4px; font-size: 0.7rem;'>".ucfirst($t)."</span>";
                    ?>
                </td>
                <td>
                    <small>
                        <i class="fas fa-play-circle text-success"></i> <?= date('d M, h:i A', strtotime($proj['start_date'])) ?><br>
                        <i class="fas fa-stop-circle text-danger"></i> <?= date('d M, h:i A', strtotime($proj['end_date'])) ?>
                    </small>
                </td>
                <td><span class='badge badge-success'><?= $count ?> Students</span></td>
                <td>
                    <div style="display: flex; gap: 6px;">
                        <a href="project_submissions.php?id=<?= $proj['id'] ?>" class="btn btn-sm btn-success" title="View Submissions"><i class="fas fa-eye"></i></a>
                        <?php 
                        // Get batch IDs for this project
                        $stmt = $pdo->prepare("SELECT batch_id FROM project_batches WHERE project_id = ?");
                        $stmt->execute([$proj['id']]);
                        $proj['batch_ids'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        ?>
                        <button onclick='editProject(<?= json_encode($proj) ?>)' class="btn btn-sm" style="background: var(--dark); color: white;" title="Edit"><i class="fas fa-edit"></i></button>
                        <a href="?delete=<?= $proj['id'] ?>&token=<?= generateToken() ?>" onclick="return confirm('Delete this project?')" class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash"></i></a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Project Modal -->
<div id="projectModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center; padding: 20px;">
    <div class="white-card" style="width: 100%; max-width: 800px; max-height: 90vh; overflow-y: auto; border-radius: 24px; padding: 40px;">
        <h3 id="modalTitle" style="font-weight: 800; color: #1e293b;">Create New Project</h3>
        <form id="projectForm" method="POST" enctype="multipart/form-data" style="margin-top: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">
            <input type="hidden" name="project_id" id="project_id">
            <input type="hidden" name="existing_image" id="existing_image">
            
            <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label style="font-weight: 700; color: #475569; font-size: 0.85rem;">ASSIGN TO BATCHES (Multiple Selection Ctrl+Click)</label>
                    <select name="batch_ids[]" id="batch_ids" class="form-control" multiple required style="height: 120px; padding: 10px;">
                        <?php foreach ($batches as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-weight: 700; color: #475569; font-size: 0.85rem;">PROJECT TITLE</label>
                    <input type="text" name="title" id="title" class="form-control" required style="height: 50px; border-radius: 12px; border: 2px solid #f1f5f9;">
                </div>
            </div>

            <div class="form-group">
                <label style="font-weight: 700; color: #475569; font-size: 0.85rem;">DESCRIPTION</label>
                <textarea name="description" id="description" class="form-control" rows="3" style="border-radius: 12px; border: 2px solid #f1f5f9;"></textarea>
            </div>

            <!-- Resources Section -->
            <div style="background: #f8fafc; padding: 25px; border-radius: 20px; margin-bottom: 25px; border: 1px solid #f1f5f9;">
                <h4 style="margin-bottom: 15px; font-size: 0.85rem; text-transform: uppercase; color: #64748b; font-weight: 800; opacity: 0.8;">Content Resources</h4>
                <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label style="font-weight: 700; color: #475569; font-size: 0.8rem;">Thumbnail Image</label>
                        <input type="file" name="project_image" class="form-control" accept="image/*">
                    </div>
                    <div class="form-group">
                        <label style="font-weight: 700; color: #475569; font-size: 0.8rem;">Video Embed URL</label>
                        <input type="url" name="video_url" id="video_url" class="form-control" placeholder="https://youtube.com/...">
                    </div>
                </div>
                <div class="form-group" style="margin-top: 15px;">
                    <label style="font-weight: 700; color: #475569; font-size: 0.8rem;">Resource Links (Format: Label|URL, one per line)</label>
                    <textarea name="external_links" id="external_links" class="form-control" rows="2"></textarea>
                </div>
            </div>

            <!-- Submission Settings -->
            <div style="background: #fff; border: 1px solid #e2e8f0; padding: 20px; border-radius: 16px; margin-bottom: 20px;">
                <h4 style="margin-bottom: 15px; font-size: 0.9rem; text-transform: uppercase; color: #64748b;">Collect From Students</h4>
                <div style="display: flex; gap: 30px; margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="sub_types[]" value="file" id="type_file" checked> File Uploads
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="sub_types[]" value="link" id="type_link"> Link Collection
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="sub_types[]" value="text" id="type_text"> Written Text/Code
                    </label>
                </div>

                <div id="file_settings" class="row" style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Allowed Extensions</label>
                        <input type="text" name="allowed_types" id="allowed_types" class="form-control" value="pdf,zip,doc,docx,jpg,png,txt,py,java,cpp,c,html,css,js,php">
                    </div>
                    <div class="form-group">
                        <label>Max Size (MB)</label>
                        <input type="number" name="max_size_mb" id="max_size_mb" class="form-control" value="5">
                    </div>
                </div>
            </div>

            <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Start Date & Time</label>
                    <input type="datetime-local" name="start_date" id="start_date" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>End Date & Time</label>
                    <input type="datetime-local" name="end_date" id="end_date" class="form-control" required>
                </div>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" name="add_project" id="submitBtn" class="btn btn-primary">Create Project</button>
                <button type="button" onclick="closeProjectModal()" class="btn" style="background: #eee;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openProjectModal() {
    document.getElementById('modalTitle').innerText = 'Create New Project';
    document.getElementById('submitBtn').name = 'add_project';
    document.getElementById('submitBtn').innerText = 'Create Project';
    document.getElementById('project_id').value = '';
    document.getElementById('existing_image').value = '';
    document.getElementById('projectForm').reset();
    document.getElementById('projectModal').style.display = 'flex';
}

function closeProjectModal() {
    document.getElementById('projectModal').style.display = 'none';
}

function editProject(proj) {
    document.getElementById('modalTitle').innerText = 'Edit Project';
    document.getElementById('submitBtn').name = 'edit_project';
    document.getElementById('submitBtn').innerText = 'Save Changes';
    
    document.getElementById('project_id').value = proj.id;
    document.getElementById('batch_id').value = proj.batch_id;
    document.getElementById('title').value = proj.title;
    document.getElementById('description').value = proj.description;
    document.getElementById('video_url').value = proj.video_url || '';
    document.getElementById('external_links').value = proj.external_links || '';
    document.getElementById('existing_image').value = proj.image_url || '';
    
    // Checkboxes
    const types = (proj.submission_types || 'file').split(',');
    document.getElementById('type_file').checked = types.includes('file');
    document.getElementById('type_link').checked = types.includes('link');
    document.getElementById('type_text').checked = types.includes('text');
    
    // Dates
    document.getElementById('start_date').value = proj.start_date.replace(' ', 'T').substring(0, 16);
    document.getElementById('end_date').value = proj.end_date.replace(' ', 'T').substring(0, 16);
    
    document.getElementById('allowed_types').value = proj.allowed_types;
    document.getElementById('max_size_mb').value = proj.max_size_mb;
    
    document.getElementById('projectModal').style.display = 'flex';
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
