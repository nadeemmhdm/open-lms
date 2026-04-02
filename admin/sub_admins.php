<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    redirect('../index.php');
}

$message = '';
$pages_list = [
    'dashboard' => 'Dashboard Overview',
    'courses' => 'Course Management',
    'batches' => 'Batch Management',
    'exams' => 'Exams & Quizzes',
    'projects' => 'Projects & Submissions',
    'students' => 'Student Management',
    'vouchers' => 'Voucher Requests',
    'tickets' => 'Support Tickets',
    'announcements' => 'Announcements',
    'events' => 'Events Management',
    'schedules' => 'Live Class Schedules',
    'settings' => 'System Settings'
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $start_time = $_POST['access_start_time'] ?: null;
    $end_time = $_POST['access_end_time'] ?: null;
    $full_access = isset($_POST['full_access']);
    $selected_pages = $_POST['pages'] ?? [];
    $permissions = $full_access ? 'all' : implode(',', $selected_pages);
    
    if (!checkToken($_POST['csrf_token'] ?? '')) {
        $message = "<div class='badge badge-danger' style='display:block; padding: 12px; margin-bottom: 20px;'>Security error: CSRF token invalid.</div>";
    } else {
        if (isset($_POST['add_subadmin'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, permissions, access_start_time, access_end_time) VALUES (?, ?, ?, 'sub_admin', ?, ?, ?)");
                $stmt->execute([$name, $email, $password, $permissions, $start_time, $end_time]);
                $message = "<div class='badge badge-success' style='display:block; padding: 12px; margin-bottom: 20px;'>Sub-Admin created successfully!</div>";
            } catch (PDOException $e) {
                $message = "<div class='badge badge-danger' style='display:block; padding: 12px; margin-bottom: 20px;'>Error: Email already exists or DB error.</div>";
            }
        } elseif (isset($_POST['edit_subadmin'])) {
            $id = $_POST['user_id'];
            $sql = "UPDATE users SET name = ?, email = ?, permissions = ?, access_start_time = ?, access_end_time = ? ";
            $params = [$name, $email, $permissions, $start_time, $end_time];
            
            if (!empty($_POST['password'])) {
                $sql .= ", password = ? ";
                $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            
            $sql .= " WHERE id = ? AND role = 'sub_admin'";
            $params[] = $id;
            
            $pdo->prepare($sql)->execute($params);
            $message = "<div class='badge badge-success' style='display:block; padding: 12px; margin-bottom: 20px;'>Sub-Admin updated!</div>";
        }
    }
}

if (isset($_GET['delete'])) {
    if (isset($_GET['token']) && checkToken($_GET['token'])) {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'sub_admin'")->execute([$_GET['delete']]);
        redirect('sub_admins.php');
    } else {
        $message = "<div class='badge badge-danger' style='display:block; padding: 12px; margin-bottom: 20px;'>Security error: Delete unauthorized.</div>";
    }
}

$sub_admins = $pdo->query("SELECT * FROM users WHERE role = 'sub_admin' ORDER BY created_at DESC")->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
    <h2>Manage Sub-Admins</h2>
    <button onclick="openModal()" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Sub-Admin</button>
</div>

<?php echo $message; ?>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Name & Email</th>
                <th>Access Type</th>
                <th>Active Time</th>
                <th>Permissions</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sub_admins as $sa): ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($sa['name']) ?></strong><br>
                    <small style="color: #64748b;"><?= htmlspecialchars($sa['email']) ?></small>
                </td>
                <td>
                    <?php if ($sa['permissions'] === 'all'): ?>
                        <span class="badge badge-success">Full Access</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Restricted Access</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($sa['access_start_time']): ?>
                        <i class="fas fa-clock"></i> <?= date('h:i A', strtotime($sa['access_start_time'])) ?> - <?= date('h:i A', strtotime($sa['access_end_time'])) ?>
                    <?php else: ?>
                        <span class="text-success">24/7 Access</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display: flex; flex-wrap: wrap; gap: 4px; max-width: 300px;">
                        <?php 
                        if ($sa['permissions'] === 'all') {
                            echo "<span class='badge' style='background:#f0fdf4; color:#166534; font-size: 0.65rem;'>Everything</span>";
                        } else {
                            $ps = explode(',', $sa['permissions']);
                            foreach ($ps as $p) {
                                if (isset($pages_list[$p])) echo "<span class='badge' style='background:#f1f5f9; color:#475569; font-size: 0.65rem;'>".$pages_list[$p]."</span>";
                            }
                        }
                        ?>
                    </div>
                </td>
                <td>
                    <div style="display: flex; gap: 6px;">
                        <button onclick='editSubAdmin(<?= json_encode($sa) ?>)' class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></button>
                        <a href="?delete=<?= $sa['id'] ?>&token=<?= generateToken() ?>" onclick="return confirm('Delete this sub-admin?')" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal -->
<div id="saModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center; padding: 20px;">
    <div class="white-card" style="width: 100%; max-width: 800px; max-height: 90vh; overflow-y: auto; border-radius: 24px; padding: 40px;">
        <h3 id="modalTitle" style="font-weight: 800; color: #1e293b;">Add Sub-Admin</h3>
        <form method="POST" id="saForm" style="margin-top: 25px;">
            <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">
            <input type="hidden" name="user_id" id="user_id">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label style="font-weight: 700; color: #475569; font-size: 0.85rem;">Full Name</label>
                    <input type="text" name="name" id="name" class="form-control" required style="height: 45px; border-radius: 12px; border: 2px solid #f1f5f9;">
                </div>
                <div class="form-group">
                    <label style="font-weight: 700; color: #475569; font-size: 0.85rem;">Email Address</label>
                    <input type="email" name="email" id="email" class="form-control" required style="height: 45px; border-radius: 12px; border: 2px solid #f1f5f9;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="font-weight: 700; color: #475569; font-size: 0.85rem;">Password <small id="passLabel">(Leave blank to keep current)</small></label>
                <input type="password" name="password" id="password" class="form-control" style="height: 45px; border-radius: 12px; border: 2px solid #f1f5f9;">
            </div>

            <!-- Access Settings -->
            <div style="background: #f8fafc; padding: 25px; border-radius: 20px; margin-bottom: 25px; border: 1px solid #f1f5f9;">
                <h4 style="margin-bottom: 15px; font-size: 0.85rem; text-transform: uppercase; color: #64748b; font-weight: 800;">Permissions & Time Access</h4>
                
                <div style="display: flex; gap: 30px; margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 700;">
                        <input type="checkbox" name="full_access" id="full_access" onchange="togglePages(this.checked)"> Full Access (All Pages)
                    </label>
                </div>

                <div id="pages_grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 25px;">
                    <?php foreach ($pages_list as $key => $label): ?>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; cursor: pointer;">
                        <input type="checkbox" name="pages[]" value="<?= $key ?>" class="page-checkbox"> <?= $label ?>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label style="font-weight: 700; color: #475569; font-size: 0.8rem;">Access Start Time</label>
                        <input type="time" name="access_start_time" id="access_start_time" class="form-control">
                    </div>
                    <div class="form-group">
                        <label style="font-weight: 700; color: #475569; font-size: 0.8rem;">Access End Time</label>
                        <input type="time" name="access_end_time" id="access_end_time" class="form-control">
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 15px; margin-top: 30px;">
                <button type="submit" name="add_subadmin" id="submitBtn" class="btn btn-primary" style="padding: 12px 30px; border-radius: 14px; font-weight: 800;">Create Sub-Admin</button>
                <button type="button" onclick="closeModal()" class="btn" style="background: #f1f5f9; color: #64748b; padding: 12px 25px; border-radius: 14px; font-weight: 700;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePages(full) {
    const boxes = document.querySelectorAll('.page-checkbox');
    boxes.forEach(b => {
        b.disabled = full;
        if (full) b.checked = true;
    });
}

function openModal() {
    document.getElementById('modalTitle').innerText = 'Add Sub-Admin';
    document.getElementById('submitBtn').name = 'add_subadmin';
    document.getElementById('submitBtn').innerText = 'Create Sub-Admin';
    document.getElementById('user_id').value = '';
    document.getElementById('passLabel').style.display = 'none';
    document.getElementById('password').required = true;
    document.getElementById('saForm').reset();
    togglePages(false);
    document.getElementById('saModal').style.display = 'flex';
}

function editSubAdmin(sa) {
    document.getElementById('modalTitle').innerText = 'Edit Sub-Admin';
    document.getElementById('submitBtn').name = 'edit_subadmin';
    document.getElementById('submitBtn').innerText = 'Save Changes';
    document.getElementById('user_id').value = sa.id;
    document.getElementById('name').value = sa.name;
    document.getElementById('email').value = sa.email;
    document.getElementById('passLabel').style.display = 'inline';
    document.getElementById('password').required = false;
    document.getElementById('password').value = '';
    
    if (sa.access_start_time) {
        document.getElementById('access_start_time').value = sa.access_start_time.substring(0, 5);
    } else {
        document.getElementById('access_start_time').value = '';
    }
    
    if (sa.access_end_time) {
        document.getElementById('access_end_time').value = sa.access_end_time.substring(0, 5);
    } else {
        document.getElementById('access_end_time').value = '';
    }
    
    const full = sa.permissions === 'all';
    document.getElementById('full_access').checked = full;
    togglePages(full);
    
    if (!full) {
        const perms = sa.permissions ? sa.permissions.split(',') : [];
        const boxes = document.querySelectorAll('.page-checkbox');
        boxes.forEach(b => {
            b.checked = perms.includes(b.value);
        });
    }
    
    document.getElementById('saModal').style.display = 'flex';
}

function closeModal() { document.getElementById('saModal').style.display = 'none'; }
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
