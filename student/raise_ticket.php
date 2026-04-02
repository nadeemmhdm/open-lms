<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Fetch Student Info
$stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$student = $stmt->fetch();

// Raise Ticket
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ticket'])) {
    $category = $_POST['category'];
    $description = trim($_POST['description']);
    $file_url = null;

    // Handle Image Upload
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['attachment']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $new_name = 'ticket_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            $dest = $path_to_root . 'uploads/tickets/' . $new_name;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
                $file_url = 'uploads/tickets/' . $new_name;
            }
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO tickets (student_id, category, description, attachment_url) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $category, $description, $file_url]);
        $success = "Your ticket has been submitted successfully. Our team will resolve it within 48 hours.";
    } catch (PDOException $e) {
        $error = "Failed to submit ticket: " . $e->getMessage();
    }
}

// Fetch Previous Tickets
$tickets = $pdo->prepare("SELECT * FROM tickets WHERE student_id = ? ORDER BY created_at DESC");
$tickets->execute([$user_id]);
$all_tickets = $tickets->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="row fade-in">
    <div class="col-12" style="margin-bottom: 30px;">
        <h2 style="font-weight: 800; color: #1e293b; margin: 0;">Raise Support Ticket</h2>
        <p style="color: #64748b; margin: 5px 0 0 0;">Have an issue? We're here to help. Typical resolution time is under 48 hours.</p>
    </div>

    <div class="col-lg-5">
        <div class="white-card" style="border-radius: 24px; padding: 35px; border: 1px solid #edf2f7; background: #fff;">
            <div style="background: #f0f7ff; color: #4f46e5; width: 60px; height: 60px; border-radius: 18px; display: flex; align-items: center; justify-content: center; margin-bottom: 25px;">
                <i class="fas fa-headset fa-2x"></i>
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="color: #64748b; font-weight: 700; font-size: 0.85rem; margin-bottom: 10px; display: block;">STUDENT NAME</label>
                    <input type="text" value="<?= htmlspecialchars($student['name']) ?>" class="form-control" disabled style="background: #f8fafc; border: 2px solid #e2e8f0; color: #94a3b8; font-weight: 700;">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="color: #64748b; font-weight: 700; font-size: 0.85rem; margin-bottom: 10px; display: block;">EMAIL ADDRESS</label>
                    <input type="text" value="<?= htmlspecialchars($student['email']) ?>" class="form-control" disabled style="background: #f8fafc; border: 2px solid #e2e8f0; color: #94a3b8; font-weight: 700;">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="color: #64748b; font-weight: 700; font-size: 0.85rem; margin-bottom: 10px; display: block;">ISSUE CATEGORY</label>
                    <select name="category" class="form-control" required style="border: 2px solid #e2e8f0; border-radius: 12px; height: 50px; font-weight: 600;">
                        <option value="LMS Issue">LMS Issue (Bugs, Login, Slow Page)</option>
                        <option value="Student Issue">Student Account Issue</option>
                        <option value="Course Material">Course Material / Lessons</option>
                        <option value="Exam/Result">Exam or Result Issues</option>
                        <option value="Other">Other Issues</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 25px;">
                    <label style="color: #64748b; font-weight: 700; font-size: 0.85rem; margin-bottom: 10px; display: block;">DESCRIBE YOUR ISSUE</label>
                    <textarea name="description" class="form-control" rows="5" placeholder="Explain the problem in detail..." required style="border: 2px solid #e2e8f0; border-radius: 14px; padding: 15px;"></textarea>
                </div>

                <div class="form-group" style="margin-bottom: 30px;">
                    <label style="color: #64748b; font-weight: 700; font-size: 0.85rem; margin-bottom: 10px; display: block;">ATTACH SCREENSHOT (OPTIONAL)</label>
                    <input type="file" name="attachment" accept="image/*" class="form-control" style="border: 2px dashed #cbd5e1; padding: 20px; text-align: center; border-radius: 14px; background: #fafafa;">
                    <small style="color: #94a3b8; display: block; margin-top: 8px;">Allowed: JPG, PNG, GIF. Max file size: 5MB.</small>
                </div>

                <button type="submit" name="submit_ticket" class="btn btn-primary" style="width: 100%; border-radius: 16px; padding: 16px; font-weight: 800; font-size: 1.1rem; box-shadow: 0 10px 20px rgba(79, 70, 229, 0.2);">SUBMIT TICKET</button>
            </form>

            <?php if ($success): ?>
                <div class="fade-in" style="margin-top: 25px; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 15px; border-radius: 14px; display: flex; align-items: center; gap: 10px; font-weight: 600;">
                    <i class="fas fa-check-circle"></i> <?= $success ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="white-card" style="border-radius: 24px; padding: 35px; border: 1px solid #edf2f7; background: #fff; height: 100%;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <h3 style="font-weight: 800; color: #1e293b; margin: 0;">Ticket History</h3>
                <div style="background: #f1f5f9; padding: 5px 15px; border-radius: 20px; font-weight: 700; color: #475569; font-size: 0.8rem;">TOTAL: <?= count($all_tickets) ?></div>
            </div>

            <?php if (empty($all_tickets)): ?>
                <div style="text-align: center; padding: 80px 0; color: #94a3b8;">
                    <i class="fas fa-folder-open fa-4x" style="opacity: 0.2; margin-bottom: 20px;"></i>
                    <p style="font-weight: 700;">No support tickets raised yet.</p>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <?php foreach ($all_tickets as $t): 
                        $status_clrs = [
                            'open' => ['#f8fafc', '#64748b'],
                            'in_progress' => ['#eff6ff', '#2563eb'],
                            'solved' => ['#f0fdf4', '#10b981'],
                            'closed' => ['#fef2f2', '#ef4444']
                        ];
                        $st = $status_clrs[$t['status']];
                        ?>
                        <div style="border: 1px solid #f1f5f9; border-radius: 20px; padding: 25px; transition: 0.3s;" class="ticket-card">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span class="badge" style="background: <?= $st[0] ?>; color: <?= $st[1] ?>; font-weight: 800; text-transform: uppercase; font-size: 0.7rem; border: 1px solid <?= $st[1] ?>20;"><?= str_replace('_', ' ', $t['status']) ?></span>
                                    <span style="color: #94a3b8; font-size: 0.8rem; font-weight: 600;">ID #<?= $t['id'] ?></span>
                                </div>
                                <span style="color: #94a3b8; font-size: 0.8rem; font-weight: 600;"><?= date('M d, Y', strtotime($t['created_at'])) ?></span>
                            </div>
                            
                            <h4 style="font-weight: 800; color: #1e293b; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                <span style="width: 8px; height: 8px; background: #6366f1; border-radius: 50%;"></span>
                                <?= $t['category'] ?>
                            </h4>
                            <p style="color: #475569; font-size: 0.95rem; line-height: 1.6;"><?= nl2br(htmlspecialchars($t['description'])) ?></p>

                            <?php if ($t['attachment_url']): ?>
                                <a href="<?= $path_to_root . $t['attachment_url'] ?>" target="_blank" style="display: flex; align-items: center; gap: 10px; color: #6366f1; font-weight: 700; font-size: 0.85rem; margin-top: 15px; text-decoration: none;">
                                    <i class="fas fa-image"></i> View Attachment
                                </a>
                            <?php endif; ?>

                            <?php if ($t['admin_reply']): ?>
                                <div style="margin-top: 20px; background: #fdf2f8; border-radius: 16px; padding: 20px; border-left: 5px solid #ec4899;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                        <span style="color: #ec4899; font-weight: 800; font-size: 0.75rem; text-transform: uppercase;">ADMIN RESPONSE</span>
                                        <span style="color: #94a3b8; font-size: 0.75rem;"><?= date('M d, h:i A', strtotime($t['replied_at'])) ?></span>
                                    </div>
                                    <p style="color: #1e293b; font-weight: 600; margin: 0; line-height: 1.6;"><?= nl2br(htmlspecialchars($t['admin_reply'])) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.ticket-card:hover { transform: translateX(5px); box-shadow: 0 10px 20px rgba(0,0,0,0.02); }
.ticket-card.active { border-color: #6366f1; }
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>
