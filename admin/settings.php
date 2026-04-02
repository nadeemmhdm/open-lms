<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

// Check Admin Access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

// Only main admin can manage sub-admins
$is_super_admin = ($_SESSION['role'] === 'admin');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['toggle_maintenance']) && $is_super_admin) {
        $val = $_POST['maintenance_mode'] ? '1' : '0';
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'maintenance_mode'")->execute([$val]);
        $success = "Maintenance mode updated.";
    }

    if (isset($_POST['save_locks']) && $is_super_admin) {
        $locked = isset($_POST['locked_pages']) ? $_POST['locked_pages'] : [];
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'locked_pages'")->execute([json_encode($locked)]);
        $success = "Interface locks updated.";
    }

    if (isset($_POST['add_sub_admin']) && $is_super_admin) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $pass = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'sub_admin')");
            $stmt->execute([$name, $email, $pass]);
            $success = "Sub-admin added successfully.";
        } catch (PDOException $e) {
            $error = "Error adding user (Email likely exists).";
        }
    }
}

// Fetch Settings
$mMode = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'maintenance_mode'")->fetchColumn();

// Fetch Locked Pages
$lockedPagesJson = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'locked_pages'")->fetchColumn();
$lockedPages = json_decode($lockedPagesJson ?: '[]', true);

// Fetch Sub-admins
$subAdmins = [];
if ($is_super_admin) {
    $subAdmins = $pdo->query("SELECT * FROM users WHERE role = 'sub_admin'")->fetchAll();
}

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<h2>System Settings</h2>

<?php if (isset($success)): ?>
    <div class="badge badge-success" style="display: block; padding: 15px; margin: 20px 0;">
        <?= $success ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="badge badge-danger" style="display: block; padding: 15px; margin: 20px 0;">
        <?= $error ?>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Maintenance Mode Card -->
    <div class="col-12 col-md-6" style="margin-bottom: 20px;">
        <div class="white-card"
            style="background: white; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
            <h3><i class="fas fa-tools"></i> Maintenance Mode</h3>
            <p style="color: #666; margin-bottom: 20px;">When enabled, only Admins and Sub-admins can access the system.
                Students will see a maintenance page.</p>

            <?php if ($is_super_admin): ?>
                <form method="POST">
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">
                        <label class="switch">
                            <input type="checkbox" name="maintenance_mode" <?= $mMode == '1' ? 'checked' : '' ?>
                            onchange="this.form.submit()">
                            <span class="slider round"></span>
                        </label>
                        <span style="font-weight: 600; color: <?= $mMode == '1' ? 'var(--danger)' : 'var(--success)' ?>">
                            <?= $mMode == '1' ? 'Enabled (System Locked)' : 'Disabled (System Active)' ?>
                        </span>
                        <input type="hidden" name="toggle_maintenance" value="1">
                    </div>
                </form>
            <?php else: ?>
                <div class="badge badge-warning">Only Main Admin can change this.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Page Locking Card -->
    <div class="col-12 col-md-6" style="margin-bottom: 20px;">
        <div class="white-card"
            style="background: white; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
            <h3><i class="fas fa-lock"></i> Student Interface Locks</h3>
            <p style="color: #666; margin-bottom: 20px;">Prevent students from accessing specific sections of the layout.</p>

            <?php if ($is_super_admin): ?>
                <form method="POST">
                    <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px;">
                        <?php
                        $available_locks = [
                            'student/my_courses.php' => 'My Academy',
                            'student/exams.php' => 'My Exams',
                            'student/projects.php' => 'My Projects',
                            'student/schedules.php' => 'Class Schedule',
                            'student/events.php' => 'Institute Events',
                            'student/explore_courses.php' => 'Explore Courses'
                        ];
                        foreach ($available_locks as $path => $label):
                            $is_locked = in_array($path, $lockedPages);
                        ?>
                            <label style="display: flex; align-items: center; gap: 12px; padding: 10px; border: 1px solid #eee; border-radius: 10px; cursor: pointer; transition: 0.2s; <?= $is_locked ? 'background: #fff5f5; border-color: #feb2b2;' : '' ?>">
                                <input type="checkbox" name="locked_pages[]" value="<?= $path ?>" <?= $is_locked ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                                <span style="font-weight: 600; color: <?= $is_locked ? '#c53030' : '#475569' ?>;"><?= $label ?></span>
                                <?php if ($is_locked): ?>
                                    <i class="fas fa-lock" style="margin-left: auto; color: #c53030;"></i>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="save_locks" class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 12px; font-weight: 700;">Update Interface Locks</button>
                </form>
            <?php else: ?>
                <div class="badge badge-warning">Only Main Admin can change this.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($is_super_admin): ?>
        <div class="col-12 col-md-6">
            <div class="white-card"
                style="background: white; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <h3><i class="fas fa-user-shield"></i> Advanced Sub-Admins</h3>
                <p style="color: #666; margin-bottom: 20px;">Manage sub-admin accounts with page-level permissions and time-based access control.</p>
                
                <a href="sub_admins.php" class="btn btn-primary" style="display: block; width: 100%; text-align: center; padding: 15px; border-radius: 14px; font-weight: 800;">
                    <i class="fas fa-users-cog"></i> Open Sub-Admin Manager (<?= count($subAdmins) ?> Users)
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    /* Switch Toggle CSS */
    .switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 26px;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 34px;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }

    input:checked+.slider {
        background-color: var(--primary);
    }

    input:checked+.slider:before {
        transform: translateX(24px);
    }
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>