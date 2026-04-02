<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

// Sub-admin permission check
if ($_SESSION['role'] === 'sub_admin' && !hasPermission('tickets')) {
    redirect('index.php');
}

$error = '';
$success = '';

// Handle Admin Response
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_ticket'])) {
    $ticket_id = $_POST['ticket_id'];
    $status = $_POST['status'];
    $reply = trim($_POST['admin_reply']);

    try {
        $stmt = $pdo->prepare("UPDATE tickets SET status = ?, admin_reply = ?, replied_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $reply, $ticket_id]);
        
        // Notify student (Individual Notification)
        $stmt = $pdo->prepare("SELECT student_id FROM tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $sid = $stmt->fetchColumn();

        if ($sid) {
            $msg = "Administrator has responded to your ticket #$ticket_id. Status: ".ucfirst($status);
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link) VALUES (?, 'Support Ticket Update', ?, 'student/raise_ticket.php')");
            $stmt->execute([$sid, $msg]);
        }

        $success = "Ticket #$ticket_id has been successfully updated.";
    } catch (PDOException $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

// Fetch Tickets
$status_filter = $_GET['status'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';

$sql = "SELECT t.*, u.name as student_name, u.email as student_email 
        FROM tickets t 
        JOIN users u ON t.student_id = u.id ";
$params = [];

if ($status_filter !== 'all') {
    $sql .= "WHERE t.status = ? ";
    $params[] = $status_filter;
}

if ($category_filter !== 'all') {
    $sql .= ($status_filter !== 'all' ? "AND " : "WHERE ") . "t.category = ? ";
    $params[] = $category_filter;
}

$sql .= "ORDER BY (t.status = 'open' OR t.status = 'in_progress') DESC, t.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; gap: 20px; flex-wrap: wrap;">
    <div>
        <h2 style="font-weight: 800; color: #1e293b; margin: 0;">Support Ticket Management</h2>
        <p style="color: #64748b; margin: 5px 0 0 0;">Review, track, and resolve student-submitted issues.</p>
    </div>
    
    <div style="display: flex; gap: 10px; background: white; padding: 10px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 10px rgba(0,0,0,0.03);">
        <select onchange="window.location.href='?status='+this.value+'&category=<?= $category_filter ?>'" style="padding: 10px; border: none; font-weight: 700; color: #475569; background: #f8fafc; border-radius: 8px; cursor: pointer;">
            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Statuses</option>
            <option value="open" <?= $status_filter == 'open' ? 'selected' : '' ?>>Open</option>
            <option value="in_progress" <?= $status_filter == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
            <option value="solved" <?= $status_filter == 'solved' ? 'selected' : '' ?>>Solved</option>
            <option value="closed" <?= $status_filter == 'closed' ? 'selected' : '' ?>>Closed</option>
        </select>
        <select onchange="window.location.href='?category='+this.value+'&status=<?= $status_filter ?>'" style="padding: 10px; border: none; font-weight: 700; color: #475569; background: #f8fafc; border-radius: 8px; cursor: pointer;">
            <option value="all" <?= $category_filter == 'all' ? 'selected' : '' ?>>All Categories</option>
            <option value="LMS Issue" <?= $category_filter == 'LMS Issue' ? 'selected' : '' ?>>LMS Issues</option>
            <option value="Student Issue" <?= $category_filter == 'Student Issue' ? 'selected' : '' ?>>Student Account</option>
            <option value="Course Material" <?= $category_filter == 'Course Material' ? 'selected' : '' ?>>Course Material</option>
            <option value="Exam/Result" <?= $category_filter == 'Exam/Result' ? 'selected' : '' ?>>Exam/Results</option>
            <option value="Other" <?= $category_filter == 'Other' ? 'selected' : '' ?>>Other</option>
        </select>
    </div>
</div>

<?php if ($success): ?>
    <div style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 15px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; font-weight: 600;">
        <i class="fas fa-check-circle"></i> <?= $success ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-12">
        <div class="white-card" style="border-radius: 20px; padding: 0; overflow: hidden; background: white; border: 1px solid #f1f5f9;">
            <table class="table" style="margin: 0; width: 100%; border-collapse: collapse;">
                <thead style="background: #f8fafc; border-bottom: 2px solid #f1f5f9; text-align: left;">
                    <tr>
                        <th style="padding: 20px; color: #64748b; font-weight: 800; font-size: 0.75rem; text-transform: uppercase;">Ticket Identity</th>
                        <th style="padding: 20px; color: #64748b; font-weight: 800; font-size: 0.75rem; text-transform: uppercase;">Student Details</th>
                        <th style="padding: 20px; color: #64748b; font-weight: 800; font-size: 0.75rem; text-transform: uppercase;">Issue Description</th>
                        <th style="padding: 20px; color: #64748b; font-weight: 800; font-size: 0.75rem; text-transform: uppercase;">Current Status</th>
                        <th style="padding: 20px; color: #64748b; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="5" style="padding: 60px; text-align: center; color: #94a3b8;">
                                <div style="font-size: 3rem; margin-bottom: 15px; opacity: 0.2;"><i class="fas fa-search"></i></div>
                                <h4 style="margin: 0; font-weight: 700;">No support tickets match the filters.</h4>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $t): 
                            $status_clrs = [
                                'open' => ['#f1f5f9', '#475569'],
                                'in_progress' => ['#eff6ff', '#2563eb'],
                                'solved' => ['#f0fdf4', '#10b981'],
                                'closed' => ['#fef2f2', '#ef4444']
                            ];
                            $st = $status_clrs[$t['status']];
                            ?>
                            <tr style="border-bottom: 1px solid #f8fafc; transition: 0.2s;">
                                <td style="padding: 20px;">
                                    <div style="font-weight: 800; color: #1e293b; font-size: 1.05rem;">ID #<?= $t['id'] ?></div>
                                    <span class="badge" style="background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; font-size: 0.7rem; font-weight: 700; border-radius: 6px; padding: 2px 8px; margin-top: 5px; display: inline-block;">
                                        <?= strtoupper($t['category']) ?>
                                    </span>
                                </td>
                                <td style="padding: 20px;">
                                    <div style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($t['student_name']) ?></div>
                                    <div style="color: #94a3b8; font-size: 0.85rem; font-weight: 500;"><?= htmlspecialchars($t['student_email']) ?></div>
                                    <div style="color: #94a3b8; font-size: 0.75rem; margin-top: 5px;"><?= date('M d, h:i A', strtotime($t['created_at'])) ?></div>
                                </td>
                                <td style="padding: 20px; max-width: 350px;">
                                    <div style="color: #475569; font-size: 0.9rem; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars(substr($t['description'], 0, 100)) ?>...
                                    </div>
                                    <?php if ($t['attachment_url']): ?>
                                        <a href="<?= $path_to_root . $t['attachment_url'] ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 5px; color: #4f46e5; font-size: 0.75rem; font-weight: 800; margin-top: 5px; text-decoration: none;">
                                            <i class="fas fa-image"></i> VIEW ATTACHMENT
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 20px;">
                                    <span class="badge" style="background: <?= $st[0] ?>; color: <?= $st[1] ?>; font-weight: 800; text-transform: uppercase; font-size: 0.7rem; border: 1px solid <?= $st[1] ?>30; padding: 5px 12px; border-radius: 10px;">
                                        <?= str_replace('_', ' ', $t['status']) ?>
                                    </span>
                                </td>
                                <td style="padding: 20px; text-align: right;">
                                    <button onclick='openResponseModal(<?= json_encode($t) ?>)' class="btn btn-primary btn-sm" style="background: #1e293b; border: none; border-radius: 8px; font-weight: 700; padding: 8px 15px;">Review & Respond</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Ticket Response Modal -->
<div id="responseModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); z-index: 10000; align-items: center; justify-content: center; padding: 20px;">
    <div class="modal-content fade-in" style="background: white; border-radius: 24px; max-width: 650px; width: 100%; box-shadow: 0 25px 50px rgba(0,0,0,0.2); overflow: hidden;">
        <div style="background: #1e293b; padding: 25px 30px; color: white; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-weight: 800; font-size: 1.25rem;">Resolve Ticket <span id="m_tid" style="opacity: 0.5; font-weight: 400; margin-left: 10px;">#123</span></h3>
            <span onclick="document.getElementById('responseModal').style.display='none'" style="cursor: pointer; font-size: 1.5rem; opacity: 0.6;"><i class="fas fa-times"></i></span>
        </div>
        
        <form method="POST" style="padding: 30px;">
            <input type="hidden" name="ticket_id" id="m_ticket_id">
            
            <div style="background: #f8fafc; padding: 20px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #f1f5f9;">
                <div style="font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; font-weight: 800; margin-bottom: 12px; letter-spacing: 0.5px;">Student Message</div>
                <p id="m_description" style="color: #475569; font-weight: 600; line-height: 1.6; margin: 0; font-size: 0.95rem;"></p>
                <div id="m_attachment_link" style="margin-top: 15px;"></div>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="color: #64748b; font-weight: 700; font-size: 0.85rem; margin-bottom: 10px; display: block;">UDPATE STATUS</label>
                <select name="status" id="m_status" class="form-control" style="border: 2px solid #e2e8f0; border-radius: 12px; font-weight: 700; height: 50px;">
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="solved">Solved</option>
                    <option value="closed">Closed / Discarded</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 30px;">
                <label style="color: #64748b; font-weight: 700; font-size: 0.85rem; margin-bottom: 10px; display: block;">OFFICIAL RESPONSE / NOTE</label>
                <textarea name="admin_reply" id="m_reply" class="form-control" rows="5" placeholder="Explain steps taken or instructions for user..." required style="border: 2px solid #e2e8f0; border-radius: 16px; padding: 15px; font-weight: 600;"></textarea>
            </div>

            <button type="submit" name="update_ticket" class="btn btn-primary" style="width: 100%; border-radius: 16px; padding: 16px; font-weight: 800; font-size: 1.1rem; background: #1e293b; border: none; box-shadow: 0 10px 20px rgba(30, 41, 59, 0.2);">UPDATE RESOLUTION</button>
        </form>
    </div>
</div>

<script>
function openResponseModal(ticket) {
    document.getElementById('m_tid').innerText = '#' + ticket.id;
    document.getElementById('m_ticket_id').value = ticket.id;
    document.getElementById('m_description').innerText = ticket.description;
    document.getElementById('m_status').value = ticket.status;
    document.getElementById('m_reply').value = ticket.admin_reply || '';
    
    const attLink = document.getElementById('m_attachment_link');
    if (ticket.attachment_url) {
        attLink.innerHTML = '<a href="<?= $path_to_root ?>' + ticket.attachment_url + '" target="_blank" style="color: #4f46e5; font-weight: 800; font-size: 0.85rem;"><i class="fas fa-image"></i> VIEW ATTACHED IMAGE</a>';
    } else {
        attLink.innerHTML = '';
    }
    
    document.getElementById('responseModal').style.display = 'flex';
}

window.onclick = function(e) {
    if (e.target.id === 'responseModal') {
        e.target.style.display = 'none';
    }
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
