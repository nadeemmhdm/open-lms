<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

// Check if admin or sub_admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

// Get Stats
$total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$total_courses = $pdo->query("SELECT COUNT(*) FROM courses WHERE status = 1")->fetchColumn();
$hidden_courses = $pdo->query("SELECT COUNT(*) FROM courses WHERE status = 0")->fetchColumn();
$total_batches = $pdo->query("SELECT COUNT(*) FROM batches")->fetchColumn();
$total_lessons = $pdo->query("SELECT COUNT(*) FROM lessons")->fetchColumn();
$online_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND last_access > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="row">
    <div class="col-12">
        <h2 style="margin-bottom: 30px;">Admin Dashboard</h2>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-info">
                    <h3>
                        <?php echo $total_students; ?>
                    </h3>
                    <p>Total Students</p>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>
                        <?php echo $total_courses; ?> <span style="font-size: 0.9rem; color: #888; font-weight: 400;">(+<?php echo $hidden_courses; ?> Hidden)</span>
                    </h3>
                    <p>Courses</p>
                </div>
                <div class="stat-icon" style="background: rgba(46, 204, 113, 0.1); color: var(--success);">
                    <i class="fas fa-book"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>
                        <?php echo $total_batches; ?>
                    </h3>
                    <p>Active Batches</p>
                </div>
                <div class="stat-icon" style="background: rgba(241, 196, 15, 0.1); color: var(--warning);">
                    <i class="fas fa-users"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>
                        <?php echo $total_lessons; ?>
                    </h3>
                    <p>Lessons Uploaded</p>
                </div>
                <div class="stat-icon" style="background: rgba(231, 76, 60, 0.1); color: var(--danger);">
                    <i class="fas fa-video"></i>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-info">
                    <h3>
                        <?php echo $online_students; ?>
                    </h3>
                    <p>Students Online</p>
                </div>
                <div class="stat-icon" style="background: rgba(46, 204, 113, 0.1); color: var(--success);">
                    <i class="fas fa-circle" style="font-size: 0.8rem;"></i>
                </div>
            </div>
        </div>

        <div class="card-glass"
            style="background: white; padding: 25px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05);">
            <h3>Last 5 Registrations</h3>
            <div class="table-container" style="margin-top: 20px;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Joined</th>
                            <th>Last Access</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $pdo->query("SELECT * FROM users WHERE role = 'student' ORDER BY id DESC LIMIT 5");
                        while ($row = $stmt->fetch()) {
                            $last_access_text = 'Never';
                            $is_online = false;
                            
                            if ($row['last_access']) {
                                $last_time = strtotime($row['last_access']);
                                $is_online = (time() - $last_time) < 300;
                                if ($is_online) {
                                    $last_access_text = '<span style="color: var(--success); font-weight: 700;">Active Now</span>';
                                } else {
                                    $last_access_text = date('d M, h:i A', $last_time);
                                }
                            }
                            
                            $dot_class = $is_online ? 'online' : 'offline';

                            echo "<tr>
                                <td>#{$row['id']}</td>
                                <td><span class='status-dot {$dot_class}'></span> <strong>{$row['name']}</strong></td>
                                <td>{$row['email']}</td>
                                <td>" . date('Y-m-d', strtotime($row['created_at'])) . "</td>
                                <td><small>{$last_access_text}</small></td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>