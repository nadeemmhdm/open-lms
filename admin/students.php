<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

$message = '';

// Handle Add Student
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_student'])) {
    if (!checkToken($_POST['csrf_token'] ?? '')) {
        $message = "<div class='badge badge-danger' style='display:block; padding: 10px; margin-bottom: 20px;'>Security error: CSRF token invalid.</div>";
    } else {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'student')");
            $stmt->execute([$name, $email, $password]);
            $message = "<div class='badge badge-success' style='display:block; padding: 10px; margin-bottom: 20px;'>Student added successfully!</div>";
        } catch (PDOException $e) {
            $message = "<div class='badge badge-danger' style='display:block; padding: 10px; margin-bottom: 20px;'>Error: " . $e->getMessage() . "</div>";
        }
    }
}

// Handle Actions (Block, Archive, Delete, Activate)
if (isset($_GET['action']) && isset($_GET['id'])) {
    if (isset($_GET['token']) && checkToken($_GET['token'])) {
        $id = $_GET['id'];
        $act = $_GET['action'];

        // Safety check: Cannot delete/block self (though this is students page, good practice)
        if ($id != $_SESSION['user_id']) {
            if ($act == 'delete') {
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            } elseif ($act == 'block') {
                $pdo->prepare("UPDATE users SET status = 'blocked' WHERE id = ?")->execute([$id]);
            } elseif ($act == 'archive') {
                $pdo->prepare("UPDATE users SET status = 'archived' WHERE id = ?")->execute([$id]);
            } elseif ($act == 'activate') {
                $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
            }
        }
        redirect('students.php');
    } else {
        $message = "<div class='badge badge-danger' style='display:block; padding: 10px; margin-bottom: 20px;'>Security error: Action unauthorized.</div>";
    }
}

// Get Stats
$stats = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked
FROM users WHERE role = 'student'")->fetch();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
    <h2>Manage Students</h2>
    <div style="display: flex; gap: 10px;">
        <button onclick="document.getElementById('addStudentForm').style.display='block'"
            class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> Add Student
        </button>
    </div>
</div>

<!-- Stats Row -->
<div class="row" style="margin-bottom: 20px;">
    <div class="col-md-4">
        <div class="stat-card" style="background: white; padding: 20px;">
            <h3><?= $stats['total'] ?></h3>
            <p>Total Students</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card" style="background: white; padding: 20px;">
            <h3 style="color: var(--success);"><?= $stats['active'] ?></h3>
            <p>Active</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card" style="background: white; padding: 20px;">
            <h3 style="color: var(--danger);"><?= $stats['blocked'] ?></h3>
            <p>Blocked/Archived</p>
        </div>
    </div>
</div>

<?php echo $message; ?>

<!-- Add Student Form -->
<div id="addStudentForm"
    style="display: none; background: white; padding: 20px; border-radius: 16px; margin-bottom: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.05);"
    class="fade-in">
    <h3 style="margin-bottom: 20px;">Add New Student</h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">
        <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Default Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
        </div>
        <div style="margin-top: 20px;">
            <button type="submit" name="add_student" class="btn btn-primary">Create Account</button>
            <button type="button" onclick="document.getElementById('addStudentForm').style.display='none'" class="btn"
                style="background: #eee; color: #333; margin-left: 10px;">Cancel</button>
        </div>
    </form>
</div>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Batches</th>
                <th>Last Access</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Get students with their batches
            $stmt = $pdo->query("SELECT u.*, 
                (SELECT COUNT(*) FROM student_batches sb WHERE sb.student_id = u.id) as batch_count 
                FROM users u WHERE u.role = 'student' ORDER BY u.id DESC");

            while ($row = $stmt->fetch()) {
                $statusColor = 'success';
                if ($row['status'] == 'blocked')
                    $statusColor = 'danger';
                if ($row['status'] == 'archived')
                    $statusColor = 'warning';

                $lastAccessFormatted = 'Never';
                $onlineStatus = '<span class="status-dot offline" title="Never"></span>';

                if ($row['last_access']) {
                    $lastAccessTime = strtotime($row['last_access']);
                    if ((time() - $lastAccessTime) < 300) {
                        $onlineStatus = '<span class="status-dot online" title="Online"></span>';
                        $lastAccessFormatted = '<span style="color: var(--success); font-weight: 700;">Active Now</span>';
                    } else {
                        $onlineStatus = '<span class="status-dot offline" title="Offline"></span>';
                        $lastAccessFormatted = date('d M, h:i A', $lastAccessTime);
                    }
                }

                echo "<tr>
                    <td>#{$row['id']}</td>
                    <td>
                        <div style='display: flex; align-items: center; gap: 10px;'>
                            {$onlineStatus}
                            <strong>{$row['name']}</strong>
                        </div>
                    </td>
                    <td>{$row['email']}</td>
                    <td><span class='badge badge-{$statusColor}'>" . ucfirst($row['status']) . "</span></td>
                    <td><span class='badge badge-warning'>{$row['batch_count']} Batches</span></td>
                    <td><small>{$lastAccessFormatted}</small></td>
                    <td>
                        <div style='display: flex; gap: 8px;'>
                            <a href='edit_students.php?id={$row['id']}' class='btn btn-sm' style='background: var(--dark); color: white;' title='Edit'><i class='fas fa-edit'></i></a>
                        ";

                $token = generateToken();
                if ($row['status'] == 'active') {
                    echo "<a href='?action=block&id={$row['id']}&token={$token}' class='btn btn-sm btn-danger' style='background: #fee2e2; color: #b91c1c;' title='Block User'><i class='fas fa-ban'></i></a> ";
                    echo "<a href='?action=archive&id={$row['id']}&token={$token}' class='btn btn-sm btn-warning' style='background: #fef9c3; color: #a16207;' title='Archive User'><i class='fas fa-folder-minus'></i></a> ";
                } else {
                    echo "<a href='?action=activate&id={$row['id']}&token={$token}' class='btn btn-sm btn-success' style='background: #dcfce7; color: #15803d;' title='Activate User'><i class='fas fa-check'></i></a> ";
                }

                echo "
                            <a href='?action=delete&id={$row['id']}&token={$token}' onclick='return confirm(\"Are you sure you want to permanently delete this student?\")' class='btn btn-sm btn-danger' style='background: #fee2e2; color: #b91c1c;' title='Delete'><i class='fas fa-trash'></i></a>
                        </div>
                    </td>
                </tr>";
            }
            ?>
        </tbody>
    </table>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>