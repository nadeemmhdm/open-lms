<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

// Sub-admin permission check
if ($_SESSION['role'] === 'sub_admin' && !hasPermission('batches')) {
    redirect('index.php');
}

// Handle Delete Batch
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $pdo->beginTransaction();
        
        // 1. Remove access from student_courses for courses in this batch 
        // IF the students have no other batch for those courses.
        $stmt = $pdo->prepare("DELETE FROM student_courses 
                               WHERE course_id IN (SELECT course_id FROM batch_courses WHERE batch_id = ?)
                               AND student_id IN (SELECT student_id FROM student_batches WHERE batch_id = ?)
                               AND course_id NOT IN (
                                   SELECT bc.course_id 
                                   FROM student_batches sb 
                                   JOIN batch_courses bc ON sb.batch_id = bc.batch_id 
                                   WHERE sb.batch_id != ?
                               )");
        $stmt->execute([$id, $id, $id]);
        
        // 2. Delete the batch (cascade will handle student_batches and batch_courses)
        $pdo->prepare("DELETE FROM batches WHERE id = ?")->execute([$id]);
        
        $pdo->commit();
        redirect('batches.php');
    } catch (PDOException $e) {
        $pdo->rollBack();
        die("Error deleting batch: " . $e->getMessage());
    }
}

// Handle Toggle Batch Status (Active/Closed)
if (isset($_POST['toggle_status'])) {
    $id = $_POST['batch_id'];
    $new_status = $_POST['status'];
    $close_msg = trim($_POST['close_message'] ?? '');
    
    $stmt = $pdo->prepare("UPDATE batches SET status = ?, close_message = ? WHERE id = ?");
    $stmt->execute([$new_status, $close_msg, $id]);
    redirect('batches.php');
}

// Add/Edit Batch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['add_batch']) || isset($_POST['edit_batch']))) {
    $name = trim($_POST['name']);
    $schedule = trim($_POST['schedule']);
    $selected_courses = $_POST['course_ids'] ?? [];

    if (isset($_POST['add_batch'])) {
        $stmt = $pdo->prepare("INSERT INTO batches (name, schedule, status) VALUES (?,?, 'active')");
        $stmt->execute([$name, $schedule]);
        $batch_id = $pdo->lastInsertId();
    } else {
        $batch_id = (int)$_POST['batch_id'];
        
        // Before clearing old courses, we might need to revoke access if a course is removed
        // Actually, for simplicity on "edit", we can just re-sync everyone later or keep it as is.
        // But the user wants a "Clean Model". 
        // Let's revoke access for courses removed from this batch.
        $stmt = $pdo->prepare("SELECT course_id FROM batch_courses WHERE batch_id = ?");
        $stmt->execute([$batch_id]);
        $old_course_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $removed_courses = array_diff($old_course_ids, $selected_courses);
        
        if (!empty($removed_courses)) {
            $placeholders = implode(',', array_fill(0, count($removed_courses), '?'));
            $revokeStmt = $pdo->prepare("DELETE FROM student_courses 
                                         WHERE student_id IN (SELECT student_id FROM student_batches WHERE batch_id = ?)
                                         AND course_id IN ($placeholders)
                                         AND course_id NOT IN (
                                             SELECT bc.course_id 
                                             FROM student_batches sb 
                                             JOIN batch_courses bc ON sb.batch_id = bc.batch_id 
                                             WHERE sb.batch_id != ?
                                         )");
            $revokeStmt->execute(array_merge([$batch_id], $removed_courses, [$batch_id]));
        }

        $stmt = $pdo->prepare("UPDATE batches SET name = ?, schedule = ? WHERE id = ?");
        $stmt->execute([$name, $schedule, $batch_id]);
        
        // Clear old courses
        $pdo->prepare("DELETE FROM batch_courses WHERE batch_id = ?")->execute([$batch_id]);
    }

    // Insert new course relations
    if (!empty($selected_courses)) {
        $stmt = $pdo->prepare("INSERT INTO batch_courses (batch_id, course_id) VALUES (?, ?)");
        foreach ($selected_courses as $c_id) {
            $stmt->execute([$batch_id, $c_id]);
        }
        
        // ALSO: Auto-enroll existing students in newly added courses
        if (isset($_POST['edit_batch'])) {
            $enrollStmt = $pdo->prepare("INSERT IGNORE INTO student_courses (student_id, course_id, access_type)
                                         SELECT student_id, ?, 'free' FROM student_batches WHERE batch_id = ?");
            foreach ($selected_courses as $c_id) {
                $enrollStmt->execute([$c_id, $batch_id]);
            }
        }
    }

    redirect('batches.php');
}

// Fetch Courses for Dropdown
$courses = $pdo->query("SELECT id, title FROM courses WHERE status = 1 ORDER BY title ASC")->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
    <div>
        <h2 style="margin: 0;">Batch Management</h2>
        <p style="color: #666; margin: 5px 0 0 0;">Create and manage student batches with multiple courses</p>
    </div>
    <button onclick="openAddModal()" class="btn btn-primary" style="display: flex; align-items: center; gap: 8px;">
        <i class="fas fa-plus"></i> Create New Batch
    </button>
</div>

<!-- Add/Edit Batch Modal -->
<div id="batchModal" class="modal-overlay">
    <div class="modal-content fade-in" style="width: 550px;">
        <div class="modal-header">
            <h3 id="modalTitle">Create New Batch</h3>
            <span onclick="closeModal('batchModal')" class="close-btn">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="batch_id" id="batch_id">
            
            <div class="form-group">
                <label>Batch Name</label>
                <input type="text" name="name" id="batch_name" class="form-control" placeholder="e.g. Science Batch - Morning" required>
            </div>
            
            <div class="form-group">
                <label>Assigned Courses (Select Multiple)</label>
                <div style="border: 2px solid #edeff2; border-radius: 12px; padding: 15px; max-height: 200px; overflow-y: auto; background: #fbfbfb;">
                    <?php if (empty($courses)): ?>
                        <p style="color: #999; font-size: 0.9rem;">No active courses found. Please create courses first.</p>
                    <?php endif; ?>
                    <?php foreach ($courses as $c): ?>
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                            <input type="checkbox" name="course_ids[]" value="<?= $c['id'] ?>" id="c_<?= $c['id'] ?>" class="course-check">
                            <label for="c_<?= $c['id'] ?>" style="margin: 0; cursor: pointer; font-size: 0.95rem;"><?= htmlspecialchars($c['title']) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label>Schedule Description</label>
                <input type="text" name="schedule" id="batch_schedule" class="form-control" placeholder="e.g. Mon, Wed, Fri (09:00 AM - 11:00 AM)">
            </div>
            
            <div id="modalSubmitBtn" style="margin-top: 20px;">
                <button type="submit" name="add_batch" class="btn btn-primary" style="width: 100%; padding: 14px; border-radius: 12px;">Initialize Batch</button>
            </div>
        </form>
    </div>
</div>

<!-- Status Modal -->
<div id="closeBatchModal" class="modal-overlay">
    <div class="modal-content fade-in">
        <div class="modal-header">
            <h3>Update Batch Status</h3>
            <span onclick="closeModal('closeBatchModal')" class="close-btn">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="batch_id" id="status_batch_id">
            <input type="hidden" name="toggle_status" value="1">
            <div class="form-group">
                <label>Current Status</label>
                <select name="status" id="status_val" class="form-control" onchange="toggleMsgField(this.value)">
                    <option value="active">Active (Ongoing)</option>
                    <option value="closed">Closed (Archive)</option>
                </select>
            </div>
            <div class="form-group" id="msgField">
                <label>Closing Message for Students</label>
                <textarea name="close_message" id="close_message" class="form-control" rows="4" placeholder="Mention reason or next steps..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; border-radius: 12px;">Save Status</button>
        </form>
    </div>
</div>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <tr>
                    <th style="width: 200px;">Batch Identity</th>
                    <th>Linked Courses</th>
                    <th>Schedule</th>
                    <th>Status</th>
                    <th>Enrollment</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sql = "SELECT b.*, 
                        (SELECT GROUP_CONCAT(c.title SEPARATOR ' | ') FROM batch_courses bc JOIN courses c ON bc.course_id = c.id WHERE bc.batch_id = b.id) as course_titles,
                        (SELECT GROUP_CONCAT(bc.course_id) FROM batch_courses bc WHERE bc.batch_id = b.id) as course_ids,
                        (SELECT COUNT(*) FROM student_batches sb WHERE sb.batch_id = b.id) as student_count
                        FROM batches b 
                        ORDER BY b.id DESC";
                $stmt = $pdo->query($sql);

                while ($row = $stmt->fetch()) {
                    $status_clr = $row['status'] == 'active' ? '#10b981' : '#64748b';
                    $status_bg = $row['status'] == 'active' ? '#f0fdf4' : '#f1f5f9';
                    ?>
                    <tr>
                        <td data-label="Batch Identity">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px;">
                                <div style="font-weight: 800; color: #1e293b; font-size: 1.1rem;"><?= htmlspecialchars($row['name']) ?></div>
                                <div style="color: #94a3b8; font-size: 0.8rem; font-weight: 600; background: #f8fafc; padding: 2px 8px; border-radius: 6px; border: 1px solid #e2e8f0;">ID: #<?= $row['id'] ?></div>
                            </div>
                        </td>
                        <td data-label="Linked Courses">
                            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                <?php 
                                if ($row['course_titles']) {
                                    $titles = explode(' | ', $row['course_titles']);
                                    foreach($titles as $t) {
                                        echo '<span class="badge" style="background:#f8fafc; color:#475569; border:1px solid #e2e8f0; font-size:0.75rem; padding: 4px 10px;">'.htmlspecialchars($t).'</span>';
                                    }
                                } else {
                                    echo '<span style="color:#ef4444; font-size:0.85rem; font-weight:600;"><i class="fas fa-exclamation-triangle"></i> No Courses</span>';
                                }
                                ?>
                            </div>
                        </td>
                        <td data-label="Schedule">
                            <div style="font-size: 0.9rem; color: #475569; font-weight: 600;"><i class="far fa-clock" style="margin-right: 6px; color: #4f46e5;"></i> <?= htmlspecialchars($row['schedule'] ?: 'TBA') ?></div>
                        </td>
                        <td data-label="Status">
                            <span class="badge" style="background: <?= $status_bg ?>; color: <?= $status_clr ?>; padding: 6px 12px; border-radius: 8px; font-weight: 800; font-size: 0.75rem; border: 1px solid <?= $row['status'] == 'active' ? '#10b98130' : '#e2e8f0' ?>;">
                                <?= strtoupper($row['status']) ?>
                            </span>
                        </td>
                        <td data-label="Enrollment" style="text-align: center;">
                            <div style="font-weight: 800; font-size: 1.2rem; color: #1e293b;"><?= $row['student_count'] ?></div>
                            <div style="color: #94a3b8; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Students</div>
                        </td>
                        <td data-label="Actions">
                            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                <a href="add_lesson.php?batch_id=<?= $row['id'] ?>" class="btn-action" style="background:#4f46e5; color:white;" title="Lessons"><i class="fas fa-book-open"></i></a>
                                <a href="assign_students.php?batch_id=<?= $row['id'] ?>" class="btn-action" style="background:#f0f9ff; color:#0ea5e9; border:1px solid #e0f2fe;" title="Students"><i class="fas fa-users"></i></a>
                                <button onclick='openEditModal(<?= json_encode($row) ?>)' class="btn-action" style="background:#f8fafc; color:#334155; border:1px solid #e2e8f0;" title="Edit"><i class="fas fa-edit"></i></button>
                                <button onclick='openStatusModal(<?= json_encode($row) ?>)' class="btn-action" style="background: #fffbeb; color: #d97706; border:1px solid #fef3c7;" title="Status"><i class="fas fa-power-off"></i></button>
                                <a href="batches.php?delete=<?= $row['id'] ?>" class="btn-action" style="background:#fff1f2; color:#e11d48; border:1px solid #ffe4e6;" onclick="return confirm('Delete record?')" title="Delete"><i class="fas fa-trash-alt"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
    </table>
</div>

<style>
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.6);
    z-index: 2000;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(5px);
}
.modal-content {
    background: white; padding: 30px; border-radius: 20px;
    width: 480px; max-width: 90%;
    box-shadow: 0 20px 50px rgba(0,0,0,0.2);
}
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
.close-btn { cursor: pointer; font-size: 1.8rem; color: #999; }
.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; }
.form-control { width: 100%; padding: 12px; border: 2px solid #edeff2; border-radius: 10px; }
.btn-action {
    width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 10px; transition: 0.2s; cursor: pointer; text-decoration: none;
}
.btn-action:hover { transform: scale(1.1); filter: brightness(0.9); }

@media (max-width: 992px) {
    .table-container { background: transparent; box-shadow: none; padding: 0; }
    .table-container table, .table-container thead, .table-container tbody, .table-container th, .table-container td, .table-container tr { display: block; }
    .table-container thead tr { position: absolute; top: -9999px; left: -9999px; }
    .table-container tr { background: white; border-radius: 24px; padding: 25px; margin-bottom: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; position: relative; }
    .table-container td { border: none; padding: 12px 0; position: relative; padding-left: 50%; border-bottom: 1px solid #f8fafc; }
    .table-container td:last-child { border-bottom: none; padding-top: 20px; }
    .table-container td::before {
        content: attr(data-label);
        position: absolute;
        left: 0;
        width: 45%;
        padding-right: 10px;
        white-space: nowrap;
        font-weight: 800;
        color: #94a3b8;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .table-container td[data-label="Batch Identity"], .table-container td[data-label="Actions"] { padding-left: 0; }
    .table-container td[data-label="Batch Identity"]::before, .table-container td[data-label="Actions"]::before { display: none; }
    .table-container td[data-label="Batch Identity"] { border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 5px; }
}
</style>

<script>
function openModal(id) { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

function openAddModal() {
    document.getElementById('modalTitle').innerText = 'Create New Batch';
    document.getElementById('batch_id').value = '';
    document.getElementById('batch_name').value = '';
    document.getElementById('batch_schedule').value = '';
    
    // Uncheck all courses
    document.querySelectorAll('.course-check').forEach(cb => cb.checked = false);
    
    document.getElementById('modalSubmitBtn').innerHTML = '<button type="submit" name="add_batch" class="btn btn-primary" style="width: 100%; padding: 14px; border-radius: 12px;">Initialize Batch</button>';
    openModal('batchModal');
}

function openEditModal(batch) {
    document.getElementById('modalTitle').innerText = 'Edit Batch Info: ' + batch.name;
    document.getElementById('batch_id').value = batch.id;
    document.getElementById('batch_name').value = batch.name;
    document.getElementById('batch_schedule').value = batch.schedule || '';
    
    // Set Checkboxes
    const currentCourseIds = batch.course_ids ? batch.course_ids.split(',') : [];
    document.querySelectorAll('.course-check').forEach(cb => {
        cb.checked = currentCourseIds.includes(cb.value);
    });

    document.getElementById('modalSubmitBtn').innerHTML = '<button type="submit" name="edit_batch" class="btn btn-primary" style="width: 100%; padding: 14px; border-radius: 12px;">Update Batch Records</button>';
    openModal('batchModal');
}

function openStatusModal(batch) {
    document.getElementById('status_batch_id').value = batch.id;
    document.getElementById('status_val').value = batch.status;
    document.getElementById('close_message').value = batch.close_message || '';
    toggleMsgField(batch.status);
    openModal('closeBatchModal');
}

function toggleMsgField(val) {
    document.getElementById('msgField').style.display = (val === 'closed') ? 'block' : 'none';
}

window.onclick = function(e) {
    if (e.target.className === 'modal-overlay') {
        e.target.style.display = 'none';
    }
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
