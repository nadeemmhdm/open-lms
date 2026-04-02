<?php
// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$role = $_SESSION['role'] ?? 'guest';
$name = $_SESSION['name'] ?? 'User';

// Role Helpers
$is_admin = ($role === 'admin');
$is_sub_admin = ($role === 'sub_admin');
$is_staff = ($is_admin || $is_sub_admin);
$is_student = ($role === 'student');

$current_page = basename($_SERVER['PHP_SELF']);
$current_full_path = 'student/' . $current_page; // For student pages

// Fetch locked pages from settings
$lockedPages = [];
try {
    $lockedStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'locked_pages'");
    $lockedData = $lockedStmt->fetch();
    if ($lockedData) {
        $lockedPages = json_decode($lockedData['setting_value'], true) ?: [];
    }
} catch (PDOException $e) {}

// Notification Count for Student
$unreadCount = 0;
if ($is_student) {
    try {
        // Count individual unread notifications
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$_SESSION['user_id']]);
        $unreadCount += $stmt->fetchColumn();

        // Count global unread notifications (announcements)
        $sql = "SELECT COUNT(*) FROM notifications n 
                LEFT JOIN student_batches sb ON n.batch_id = sb.batch_id 
                WHERE n.user_id IS NULL 
                AND (
                    (n.target_role = 'all') 
                    OR (n.target_role = 'student' AND n.batch_id IS NULL) 
                    OR (n.target_role = 'student' AND sb.student_id = ?)
                )
                AND n.id NOT IN (SELECT notification_id FROM notification_reads WHERE user_id = ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        $unreadCount += $stmt->fetchColumn();
    } catch (PDOException $e) {}
}
?>

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <i class="fas fa-graduation-cap"></i> Open Lms
        </div>
        <!-- Close button for mobile -->
        <div class="close-sidebar" id="closeSidebar" style="display: none;">
            <i class="fas fa-times"></i>
        </div>
    </div>

    <div class="user-profile" style="padding: 0 10px 25px; border-bottom: 1px solid #f1f5f9; margin-bottom: 25px;">
        <div style="font-weight: 700; color: var(--dark); font-size: 1.1rem; margin-bottom: 4px;">
            <?php echo htmlspecialchars($name); ?>
        </div>
        <div
            style="font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">
            <?php echo str_replace('_', ' ', $role); ?>
        </div>
    </div>

    <nav aria-label="Main Navigation">
        <ul style="display: flex; flex-direction: column; gap: 4px;">
            <?php if ($is_staff): ?>
                <?php if (hasPermission('dashboard')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/index.php"
                        class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>"><i
                            class="fas fa-table-columns"></i> Dashboard</a></li>
                <?php endif; ?>
                
                <?php if (hasPermission('students')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/students.php"
                        class="nav-link <?php echo $current_page == 'students.php' ? 'active' : ''; ?>"><i
                            class="fas fa-users-gear"></i> Students</a></li>
                <?php endif; ?>

                <?php if (hasPermission('courses')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/courses.php"
                        class="nav-link <?php echo $current_page == 'courses.php' ? 'active' : ''; ?>"><i
                            class="fas fa-graduation-cap"></i> Courses</a></li>
                <?php endif; ?>

                <?php if (hasPermission('batches')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/batches.php"
                        class="nav-link <?php echo $current_page == 'batches.php' ? 'active' : ''; ?>"><i
                            class="fas fa-chalkboard"></i> Batches</a></li>
                <?php endif; ?>

                <?php if (hasPermission('exams')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/exams.php"
                        class="nav-link <?php echo ($current_page == 'exams.php' || $current_page == 'manage_questions.php') ? 'active' : ''; ?>"><i
                            class="fas fa-file-signature"></i> Exams</a></li>
                <?php endif; ?>

                <?php if (hasPermission('projects')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/projects.php"
                        class="nav-link <?php echo $current_page == 'projects.php' ? 'active' : ''; ?>"><i
                            class="fas fa-folder-tree"></i> Projects</a></li>
                <?php endif; ?>

                <?php if (hasPermission('schedules')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/schedules.php"
                        class="nav-link <?php echo $current_page == 'schedules.php' ? 'active' : ''; ?>"><i
                            class="fas fa-calendar-day"></i> Schedules</a></li>
                <?php endif; ?>

                <?php if (hasPermission('events')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/events.php"
                        class="nav-link <?php echo $current_page == 'events.php' ? 'active' : ''; ?>"><i
                            class="fas fa-calendar-check"></i> Events</a></li>
                <?php endif; ?>

                <?php if (hasPermission('announcements')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/notifications.php"
                        class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>"><i
                            class="fas fa-bell"></i> Announcements</a></li>
                <?php endif; ?>

                <?php if (hasPermission('vouchers')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/vouchers.php"
                        class="nav-link <?php echo $current_page == 'vouchers.php' ? 'active' : ''; ?>"><i
                            class="fas fa-ticket-alt"></i> Vouchers & Requests</a></li>
                <?php endif; ?>

                <?php if ($is_admin): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/link_generator.php"
                        class="nav-link <?php echo $current_page == 'link_generator.php' ? 'active' : ''; ?>"><i
                            class="fas fa-magic"></i> Magic Link Generator</a></li>
                <?php endif; ?>

                <?php if (hasPermission('tickets')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/tickets.php"
                        class="nav-link <?php echo $current_page == 'tickets.php' ? 'active' : ''; ?>"><i
                            class="fas fa-headset"></i> Support Tickets</a></li>
                <?php endif; ?>

                <?php if (hasPermission('settings')): ?>
                <li><a href="<?php echo $path_to_root; ?>admin/reports.php"
                        class="nav-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>"><i
                            class="fas fa-chart-line"></i> Learning Reports</a></li>
                <li><a href="<?php echo $path_to_root; ?>admin/settings.php"
                        class="nav-link <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>"><i
                            class="fas fa-gear"></i> System Settings</a></li>
                <?php endif; ?>

            <?php elseif ($is_student): ?>
                <li><a href="<?php echo $path_to_root; ?>student/index.php"
                        class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>"><i
                            class="fas fa-house"></i> Dashboard</a></li>
                <li><a href="<?php echo $path_to_root; ?>student/explore_courses.php"
                        class="nav-link <?php echo $current_page == 'explore_courses.php' ? 'active' : ''; ?> <?php echo in_array('student/explore_courses.php', $lockedPages) ? 'locked-link' : ''; ?>" <?php echo in_array('student/explore_courses.php', $lockedPages) ? 'onclick="showLockModal(event)"' : ''; ?>><i
                            class="fas fa-search-plus"></i> Explore Courses</a></li>
                <li><a href="<?php echo $path_to_root; ?>student/my_courses.php"
                        class="nav-link <?php echo ($current_page == 'my_courses.php' || $current_page == 'view_course.php') ? 'active' : ''; ?> <?php echo in_array('student/my_courses.php', $lockedPages) ? 'locked-link' : ''; ?>" <?php echo in_array('student/my_courses.php', $lockedPages) ? 'onclick="showLockModal(event)"' : ''; ?>><i
                            class="fas fa-book-open-reader"></i> My Academy</a></li>
                <li><a href="<?php echo $path_to_root; ?>student/exams.php"
                        class="nav-link <?php echo ($current_page == 'exams.php' || $current_page == 'take_exam.php') ? 'active' : ''; ?> <?php echo in_array('student/exams.php', $lockedPages) ? 'locked-link' : ''; ?>" <?php echo in_array('student/exams.php', $lockedPages) ? 'onclick="showLockModal(event)"' : ''; ?>><i
                            class="fas fa-file-signature"></i> My Exams</a></li>
                <li><a href="<?php echo $path_to_root; ?>student/projects.php"
                        class="nav-link <?php echo ($current_page == 'projects.php' || $current_page == 'project_details.php') ? 'active' : ''; ?> <?php echo in_array('student/projects.php', $lockedPages) ? 'locked-link' : ''; ?>" <?php echo in_array('student/projects.php', $lockedPages) ? 'onclick="showLockModal(event)"' : ''; ?>><i
                            class="fas fa-folder-tree"></i> My Projects</a></li>
                <li><a href="<?php echo $path_to_root; ?>student/schedules.php"
                        class="nav-link <?php echo $current_page == 'schedules.php' ? 'active' : ''; ?> <?php echo in_array('student/schedules.php', $lockedPages) ? 'locked-link' : ''; ?>" <?php echo in_array('student/schedules.php', $lockedPages) ? 'onclick="showLockModal(event)"' : ''; ?>><i
                            class="fas fa-calendar-alt"></i> Class Schedule</a></li>
                <li><a href="<?php echo $path_to_root; ?>student/events.php"
                        class="nav-link <?php echo $current_page == 'events.php' ? 'active' : ''; ?> <?php echo in_array('student/events.php', $lockedPages) ? 'locked-link' : ''; ?>" <?php echo in_array('student/events.php', $lockedPages) ? 'onclick="showLockModal(event)"' : ''; ?>><i
                            class="fas fa-calendar-days"></i> Institute Events</a></li>
                <li><a href="<?php echo $path_to_root; ?>student/notifications.php"
                        class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
                        <i class="fas fa-bell"></i> Notifications
                        <?php if ($unreadCount > 0): ?>
                            <span class="badge" style="background: #ef4444; color: white; margin-left: auto; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem;"><?php echo $unreadCount; ?></span>
                        <?php endif; ?>
                    </a></li>
                <li><a href="<?php echo $path_to_root; ?>student/raise_ticket.php"
                        class="nav-link <?php echo $current_page == 'raise_ticket.php' ? 'active' : ''; ?>">
                        <i class="fas fa-headset"></i> Raise Support Ticket
                    </a></li>
                <li><a href="<?php echo $path_to_root; ?>student/profile.php"
                        class="nav-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>"><i
                            class="fas fa-user-circle"></i> Personal Profile</a></li>
            <?php endif; ?>

            <li style="margin-top: 30px; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                <a href="<?php echo $path_to_root; ?>logout.php" class="nav-link"
                    style="color: #ef4444; background: #fef2f2;"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </li>
        </ul>
    </nav>
</aside>

<!-- Mobile Menu Toggle Button -->
<div class="menu-toggle" id="menuToggle">
    <i class="fas fa-bars"></i>
</div>

<!-- Page Lock Modal -->
<div id="pageLockModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: white; padding: 40px; border-radius: 24px; text-align: center; max-width: 400px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <div style="width: 80px; height: 80px; background: #fff1f2; color: #e11d48; border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
            <i class="fas fa-lock fa-3x"></i>
        </div>
        <h3 style="color: #1e293b; font-weight: 800; margin-bottom: 10px;">Feature Locked</h3>
        <p style="color: #64748b; line-height: 1.6; margin-bottom: 25px;">This section is temporarily disabled by the administrator. Please contact support for more information.</p>
        <button onclick="document.getElementById('pageLockModal').style.display='none'" class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 12px; font-weight: 700;">Understand</button>
    </div>
</div>

<script>
function showLockModal(e) {
    e.preventDefault();
    document.getElementById('pageLockModal').style.display = 'flex';
}
</script>

<style>
.locked-link {
    opacity: 0.6;
    cursor: not-allowed;
}
.locked-link:hover {
    background: transparent !important;
    color: #94a3b8 !important;
}
.locked-link i {
    color: #cbd5e1 !important;
}
</style>

<main class="main-content fade-in">
<?php
// Enforce page lock if direct access tried
if ($is_student && in_array('student/' . $current_page, $lockedPages)) {
    echo "<div style='padding: 50px; text-align: center;'>
            <div style='background: #fff1f2; color: #e11d48; border: 1px solid #fda4af; padding: 30px; border-radius: 20px; display: inline-block; max-width: 500px;'>
                <i class='fas fa-lock' style='font-size: 3rem; margin-bottom: 20px;'></i>
                <h2>Access Forbidden</h2>
                <p>This page has been locked by the administrator. Please return to the dashboard.</p>
                <a href='index.php' class='btn btn-primary' style='margin-top: 20px;'>Back to Dashboard</a>
            </div>
          </div>";
    include 'footer.php';
    exit;
}
?>