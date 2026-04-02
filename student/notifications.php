<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = $_SESSION['user_id'];
$active_tab = $_GET['tab'] ?? 'alerts';

if ($active_tab == 'alerts') {
    // Mark individual alerts as read
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
} else {
    // Mark global announcements as read
    $sql = "INSERT IGNORE INTO notification_reads (notification_id, user_id)
            SELECT DISTINCT n.id, ? FROM notifications n 
            LEFT JOIN student_batches sb ON n.batch_id = sb.batch_id
            WHERE n.user_id IS NULL 
            AND (
                n.target_role = 'all' 
                OR (n.target_role = 'student' AND n.batch_id IS NULL) 
                OR (n.target_role = 'student' AND sb.student_id = ?)
            )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $user_id]);
}

// Fetch Alerts
$sql_alerts = "SELECT *, (is_read = 0) as is_new FROM notifications WHERE user_id = ? ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql_alerts);
$stmt->execute([$user_id]);
$alerts = $stmt->fetchAll();

// Fetch Announcements
$sql_ann = "SELECT n.*, b.name as batch_name,
            (SELECT COUNT(*) FROM notification_reads nr WHERE nr.notification_id = n.id AND nr.user_id = ?) = 0 as is_new
            FROM notifications n
            LEFT JOIN batches b ON n.batch_id = b.id
            LEFT JOIN student_batches sb ON n.batch_id = sb.batch_id
            WHERE n.user_id IS NULL AND (
                n.target_role = 'all' 
                OR (n.target_role = 'student' AND n.batch_id IS NULL) 
                OR (n.target_role = 'student' AND sb.student_id = ?)
            )
            GROUP BY n.id
            ORDER BY n.created_at DESC";
$stmt = $pdo->prepare($sql_ann);
$stmt->execute([$user_id, $user_id]);
$announcements = $stmt->fetchAll();

$display_notifs = ($active_tab == 'alerts') ? $alerts : $announcements;

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="row fade-in">
    <div class="col-12" style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h2 style="font-weight: 800; color: #1e293b; letter-spacing: -0.5px; margin: 0;">Communications</h2>
            <p style="color: #64748b; margin: 5px 0 0 0;">Stay informed with personal alerts and global broadcasts.</p>
        </div>
        <div style="display: flex; background: #f1f5f9; padding: 6px; border-radius: 14px; gap: 4px;">
            <a href="?tab=alerts" class="btn btn-sm" style="background: <?= $active_tab == 'alerts' ? 'white' : 'transparent' ?>; color: <?= $active_tab == 'alerts' ? 'var(--primary)' : '#64748b' ?>; border-radius: 10px; padding: 10px 20px; font-weight: 800; border: none; box-shadow: <?= $active_tab == 'alerts' ? '0 4px 6px -1px rgba(0,0,0,0.1)' : 'none' ?>;">
                My Alerts 
                <?php 
                $alert_count = 0;
                foreach($alerts as $a) { if($a['is_new']) $alert_count++; }
                if ($alert_count > 0) echo "<span style='background:#ef4444; color:white; padding:2px 6px; border-radius:10px; font-size:0.6rem; margin-left:5px;'>".$alert_count."</span>";
                ?>
            </a>
            <a href="?tab=announcements" class="btn btn-sm" style="background: <?= $active_tab == 'announcements' ? 'white' : 'transparent' ?>; color: <?= $active_tab == 'announcements' ? 'var(--primary)' : '#64748b' ?>; border-radius: 10px; padding: 10px 20px; font-weight: 800; border: none; box-shadow: <?= $active_tab == 'announcements' ? '0 4px 6px -1px rgba(0,0,0,0.1)' : 'none' ?>;">
                Announcements
                <?php 
                $ann_count = 0;
                foreach($announcements as $an) { if($an['is_new']) $ann_count++; }
                if ($ann_count > 0) echo "<span style='background:#ef4444; color:white; padding:2px 6px; border-radius:10px; font-size:0.6rem; margin-left:5px;'>".$ann_count."</span>";
                ?>
            </a>
        </div>
    </div>

    <div class="col-lg-8">
        <?php if (empty($display_notifs)): ?>
            <div class="white-card" style="text-align: center; padding: 80px 20px; border-radius: 24px; border: 2px dashed #e2e8f0; background: white;">
                <div style="font-size: 4rem; color: #e2e8f0; margin-bottom: 25px;">
                    <i class="fas <?= $active_tab == 'alerts' ? 'fa-bell-slash' : 'fa-bullhorn' ?>"></i>
                </div>
                <h3 style="color: #4a5568;">No <?= $active_tab ?> found</h3>
                <p style="color: #a0aec0;">We'll notify you when something important happens.</p>
            </div>
        <?php else: ?>
            <div class="notifications-list">
                <?php foreach ($display_notifs as $n): 
                    $is_new = (bool)$n['is_new']; 
                ?>
                <div class="notif-card <?= $is_new ? 'is-new' : '' ?>" style="background: white; border-radius: 20px; margin-bottom: 20px; border: 1px solid <?= $is_new ? '#4f46e520' : '#f1f5f9' ?>; overflow: hidden; transition: 0.3s; position: relative;">
                    <?php if ($is_new): ?>
                        <div style="position: absolute; top: 0; left: 0; bottom: 0; width: 6px; background: #4f46e5;"></div>
                    <?php endif; ?>
                    
                    <div style="padding: 25px; display: flex; gap: 20px;">
                        <div class="notif-icon" style="flex-shrink: 0; width: 50px; height: 50px; border-radius: 15px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; background: <?= $n['user_id'] ? '#f0f4ff' : '#fef2f2' ?>; color: <?= $n['user_id'] ? '#4f46e5' : '#ef4444' ?>;">
                            <i class="fas <?= $n['user_id'] ? 'fa-circle-check' : 'fa-bullhorn' ?>"></i>
                        </div>
                        
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                <h4 style="font-weight: 800; color: #1e293b; margin: 0; font-size: 1.1rem;"><?= htmlspecialchars($n['title']) ?></h4>
                                <span style="font-size: 0.8rem; color: #94a3b8; font-weight: 600;"><?= date('M d, h:i A', strtotime($n['created_at'])) ?></span>
                            </div>
                            
                            <div style="color: #475569; line-height: 1.6; white-space: pre-wrap; font-size: 1rem;"><?= htmlspecialchars($n['message']) ?></div>
                            
                            <?php if ($n['link']): ?>
                                <div style="margin-top: 20px;">
                                    <a href="<?= $path_to_root . $n['link'] ?>" class="btn btn-primary" style="padding: 8px 20px; border-radius: 10px; font-size: 0.85rem; font-weight: 700;">View Details <i class="fas fa-chevron-right" style="margin-left: 5px; font-size: 0.7rem;"></i></a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($n['attachment_url']): ?>
                                <div style="margin-top: 20px; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                                    <?php if ($n['attachment_type'] == 'image'): ?>
                                        <img src="<?= $path_to_root . $n['attachment_url'] ?>" style="max-width: 100%; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.05);">
                                    <?php elseif ($n['attachment_type'] == 'video'): ?>
                                        <video src="<?= $path_to_root . $n['attachment_url'] ?>" controls style="max-width: 100%; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.05);"></video>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="white-card" style="border-radius: 20px; background: #fafafa; border: 1px solid #eee; padding: 25px;">
            <h4 style="font-weight: 800; margin-bottom: 20px;">Overview</h4>
            <div style="display: flex; flex-direction: column; gap: 15px;">
                <div style="display: flex; gap: 12px; color: #64748b; font-size: 0.9rem;">
                    <i class="fas fa-check-circle" style="color: #4f46e5; margin-top: 3px;"></i>
                    <span><strong>My Alerts:</strong> Private notifications for voucher approvals, ticket replies, and personal tasks.</span>
                </div>
                <div style="display: flex; gap: 12px; color: #64748b; font-size: 0.9rem;">
                    <i class="fas fa-check-circle" style="color: #ef4444; margin-top: 3px;"></i>
                    <span><strong>Announcements:</strong> Global or batch-specific updates from the administration.</span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.notif-card:hover {
    transform: translateX(5px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.05);
    border-color: #e2e8f0;
}
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>

