<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

// Handle Delete Voucher
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM vouchers WHERE id = ?");
    $stmt->execute([$id]);
    redirect('vouchers.php');
}

// Handle Add Voucher
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_voucher'])) {
    $code = trim($_POST['code']);
    $course_id = !empty($_POST['course_id']) ? $_POST['course_id'] : null;
    $count = isset($_POST['count']) ? (int)$_POST['count'] : 1;

    for ($i = 0; $i < $count; $i++) {
        $final_code = $count > 1 ? $code . '-' . strtoupper(bin2hex(random_bytes(2))) : $code;
        try {
            $stmt = $pdo->prepare("INSERT INTO vouchers (code, course_id) VALUES (?, ?)");
            $stmt->execute([$final_code, $course_id]);
        } catch (PDOException $e) {
            // Skip duplicates
        }
    }
    redirect('vouchers.php');
}

// Handle Voucher Request Status
if (isset($_GET['approve_request']) && is_numeric($_GET['approve_request'])) {
    $id = $_GET['approve_request'];
    
    // Fetch request details
    $stmt = $pdo->prepare("SELECT * FROM voucher_requests WHERE id = ?");
    $stmt->execute([$id]);
    $req = $stmt->fetch();
    
    if ($req && $req['status'] == 'pending') {
        try {
            $pdo->beginTransaction();
            
            // 1. Generate a unique voucher code
            $v_code = 'VOU-' . strtoupper(bin2hex(random_bytes(3)));
            
            // 2. Insert into vouchers table
            $stmt = $pdo->prepare("INSERT INTO vouchers (code, course_id) VALUES (?, ?)");
            $stmt->execute([$v_code, $req['course_id']]);
            $voucher_id = $pdo->lastInsertId();
            
            // 3. Update request status
            $stmt = $pdo->prepare("UPDATE voucher_requests SET status = 'approved' WHERE id = ?");
            $stmt->execute([$id]);
            
            // 4. Create Notification for Student
            $course_name = "Any Course";
            if ($req['course_id']) {
                $c_stmt = $pdo->prepare("SELECT title FROM courses WHERE id = ?");
                $c_stmt->execute([$req['course_id']]);
                $course_name = $c_stmt->fetchColumn() ?: "Any Course";
            }
            
            $notif_title = "Voucher Request Approved! 🎉";
            $notif_msg = "Your request for a voucher code for **$course_name** has been approved.\n\nYour Voucher Code: **$v_code**\n\nYou can use this code in the Explore Courses section to unlock your course.";
            $link = "student/explore_courses.php";
            
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link, target_role) VALUES (?, ?, ?, ?, 'student')");
            $stmt->execute([$req['student_id'], $notif_title, $notif_msg, $link]);
            
            $pdo->commit();
            $message = "Request approved and voucher sent to student!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error during approval: " . $e->getMessage();
        }
    }
    
    // Use message/error in redirect if needed or just redirect
    // redirect('vouchers.php#requests');
}

if (isset($_GET['reject_request']) && is_numeric($_GET['reject_request'])) {
    $id = $_GET['reject_request'];
    $stmt = $pdo->prepare("UPDATE voucher_requests SET status = 'rejected' WHERE id = ?");
    $stmt->execute([$id]);
    
    // Optional: Notify student of rejection
    $stmt = $pdo->prepare("SELECT student_id FROM voucher_requests WHERE id = ?");
    $stmt->execute([$id]);
    $sid = $stmt->fetchColumn();
    
    if ($sid) {
        $notif_title = "Voucher Request Rejected";
        $notif_msg = "Unfortunately, your voucher request has been rejected by the administrator. Please contact support for more details.";
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, target_role) VALUES (?, ?, ?, 'student')");
        $stmt->execute([$sid, $notif_title, $notif_msg]);
    }

    redirect('vouchers.php#requests');
}

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';

$courses = $pdo->query("SELECT id, title FROM courses WHERE is_paid = 1")->fetchAll();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
    <div>
        <h2 style="margin: 0;">Voucher Management</h2>
        <p style="color: #666; margin: 5px 0 0 0;">Create and manage access codes for paid courses</p>
    </div>
    <button onclick="document.getElementById('addVoucherModal').style.display='flex'" class="btn btn-primary" style="display: flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 10px;">
        <i class="fas fa-plus"></i> Create Vouchers
    </button>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="white-card" style="padding: 0; overflow: hidden; border-radius: 15px; border: 1px solid #eee;">
            <div style="padding: 20px; border-bottom: 1px solid #eee; background: #fafafa; display: flex; justify-content: space-between; align-items: center;">
                <h4 style="margin: 0;"><i class="fas fa-ticket-alt"></i> Active Vouchers</h4>
            </div>
            <div style="overflow-x: auto;">
                <table class="table" style="margin: 0;">
                    <thead style="background: #f8f9fa;">
                        <tr>
                            <th style="padding: 15px;">Code</th>
                            <th style="padding: 15px;">Course</th>
                            <th style="padding: 15px;">Status</th>
                            <th style="padding: 15px;">Used By</th>
                            <th style="padding: 15px; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $vouchers = $pdo->query("SELECT v.*, c.title as course_title, u.name as student_name 
                                               FROM vouchers v 
                                               LEFT JOIN courses c ON v.course_id = c.id 
                                               LEFT JOIN users u ON v.student_id = u.id 
                                               ORDER BY v.id DESC")->fetchAll();
                        foreach ($vouchers as $v):
                        ?>
                        <tr>
                            <td style="padding: 15px; font-family: monospace; font-weight: bold; color: #4f46e5;"><?= htmlspecialchars($v['code']) ?></td>
                            <td style="padding: 15px;"><?= $v['course_title'] ? htmlspecialchars($v['course_title']) : '<span style="color: #999;">Any Course</span>' ?></td>
                            <td style="padding: 15px;">
                                <?php if ($v['is_used']): ?>
                                    <span class="badge" style="background: #fee2e2; color: #ef4444; border: 1px solid #fecaca;">Used</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #d1fae5; color: #059669; border: 1px solid #a7f3d0;">Active</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 15px;">
                                <?php if ($v['student_name']): ?>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($v['student_name']) ?></div>
                                    <div style="font-size: 11px; color: #999;"><?= date('M d, Y', strtotime($v['used_at'])) ?></div>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td style="padding: 15px; text-align: right;">
                                <a href="vouchers.php?delete=<?= $v['id'] ?>" class="btn btn-sm" style="background: #fef2f2; color: #ef4444; border: none;" onclick="return confirm('Delete this voucher?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($vouchers)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 40px; color: #999;">No vouchers found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4" id="requests">
        <div class="white-card" style="padding: 0; overflow: hidden; border-radius: 15px; border: 1px solid #eee;">
            <div style="padding: 20px; border-bottom: 1px solid #eee; background: #fafafa;">
                <h4 style="margin: 0;"><i class="fas fa-hand-holding-usd"></i> Voucher Requests</h4>
            </div>
            <div style="max-height: 600px; overflow-y: auto; padding: 15px;">
                <?php
                $requests = $pdo->query("SELECT vr.*, u.name, u.email, c.title as requested_course 
                                       FROM voucher_requests vr 
                                       JOIN users u ON vr.student_id = u.id 
                                       LEFT JOIN courses c ON vr.course_id = c.id
                                       ORDER BY vr.status = 'pending' DESC, vr.created_at DESC")->fetchAll();
                foreach ($requests as $req):
                ?>
                <div style="background: #fff; border: 1px solid #eee; border-radius: 12px; padding: 15px; margin-bottom: 15px; position: relative;">
                    <div style="position: absolute; top: 15px; right: 15px;">
                        <?php if ($req['status'] == 'pending'): ?>
                            <span class="badge" style="background: #fef3c7; color: #d97706;">Pending</span>
                        <?php elseif ($req['status'] == 'approved'): ?>
                            <span class="badge" style="background: #d1fae5; color: #059669;">Approved</span>
                        <?php else: ?>
                            <span class="badge" style="background: #fee2e2; color: #ef4444;">Rejected</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-weight: 700; font-size: 1.05rem;"><?= htmlspecialchars($req['name']) ?></div>
                    <div style="color: #666; font-size: 0.9rem; margin-bottom: 5px;"><i class="far fa-envelope"></i> <?= htmlspecialchars($req['email']) ?></div>
                    <div style="color: #666; font-size: 0.9rem; margin-bottom: 5px;"><i class="fas fa-phone"></i> <?= htmlspecialchars($req['phone_number']) ?></div>
                    <div style="color: #4f46e5; font-size: 0.9rem; font-weight: 700; margin-bottom: 5px;"><i class="fas fa-graduation-cap"></i> <?= $req['requested_course'] ? htmlspecialchars($req['requested_course']) : 'Any Course' ?></div>
                    <div style="color: #999; font-size: 0.8rem;"><i class="far fa-clock"></i> <?= date('M d, h:i A', strtotime($req['created_at'])) ?></div>
                    
                    <?php if ($req['status'] == 'pending'): ?>
                    <div style="margin-top: 15px; display: flex; gap: 10px;">
                        <a href="vouchers.php?approve_request=<?= $req['id'] ?>" class="btn btn-sm" style="background: #059669; color: white; flex: 1;">Approve</a>
                        <a href="vouchers.php?reject_request=<?= $req['id'] ?>" class="btn btn-sm" style="background: #dc3545; color: white; flex: 1;">Reject</a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($requests)): ?>
                <div style="text-align: center; padding: 20px; color: #999;">No requests found</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Voucher Modal -->
<div id="addVoucherModal" class="modal-overlay">
    <div class="modal-content fade-in">
        <div class="modal-header">
            <h3>Create Vouchers</h3>
            <span onclick="document.getElementById('addVoucherModal').style.display='none'" class="close-btn">&times;</span>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>Voucher Code Base</label>
                <input type="text" name="code" class="form-control" placeholder="e.g. SUMMER2024" required>
                <small style="color: #999;">If creating multiple, a random suffix will be added.</small>
            </div>
            <div class="form-group">
                <label>Course Requirement</label>
                <select name="course_id" class="form-control">
                    <option value="">Applicable to Any Paid Course</option>
                    <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Number of Vouchers to Generate</label>
                <input type="number" name="count" class="form-control" value="1" min="1" max="100">
            </div>
            <button type="submit" name="add_voucher" class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 8px; font-weight: 600;">Generate Vouchers</button>
        </form>
    </div>
</div>

<style>
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.6);
    z-index: 2000;
    justify-content: center; align-items: center;
    backdrop-filter: blur(5px);
}
.modal-content {
    background: white; padding: 30px; border-radius: 20px;
    width: 400px; max-width: 90%;
}
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
.close-btn { cursor: pointer; font-size: 1.8rem; color: #999; }
.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #444; font-size: 0.9rem; }
.form-control { width: 100%; padding: 12px; border: 2px solid #edeff2; border-radius: 10px; }
.btn-sm { padding: 6px 12px; font-size: 0.85rem; border-radius: 8px; font-weight: 600; text-decoration: none; display: inline-block; }
</style>

<script>
window.onclick = function(event) {
    if (event.target.className === 'modal-overlay') {
        event.target.style.display = 'none';
    }
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
