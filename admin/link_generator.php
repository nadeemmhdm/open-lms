<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

$message = '';
$generated_link = '';

// 1. Auto cleanup: Delete links used more than 7 days ago
$pdo->query("DELETE FROM magic_links WHERE is_used = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");

// 2. Handle Manual Deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $pdo->prepare("DELETE FROM magic_links WHERE id = ?")->execute([$_GET['delete']]);
    $message = "Link deleted successfully.";
}

// Handle Link Generation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_link'])) {
    $action = $_POST['action'];
    $email = trim($_POST['email'] ?? '');
    $name = trim($_POST['name'] ?? 'Student');
    $user_id = null;
    $data = [];

    try {
        $pdo->beginTransaction();

        // 1. Find or Create User
        if (!empty($email)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user_id = $stmt->fetchColumn();

            if (!$user_id) {
                // Create user if not exists
                $temp_pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'student')");
                $stmt->execute([$name, $email, $temp_pass]);
                $user_id = $pdo->lastInsertId();
            }
        }

        // 2. Prepare Action Data
        if ($action == 'reset_password') {
            $new_pass = trim($_POST['new_password']);
            if (empty($new_pass)) throw new Exception("New password is required for reset link.");
            $data['new_password'] = $new_pass;
        } elseif ($action == 'enroll') {
            $course_ids = $_POST['course_ids'] ?? [];
            if (empty($course_ids)) throw new Exception("Select at least one course for enrollment.");
            $data['course_ids'] = $course_ids;
            
            if (!empty($_POST['apply_voucher'])) {
                // Generate a one-time voucher
                $v_code = 'LNK-' . strtoupper(bin2hex(random_bytes(3)));
                $stmt = $pdo->prepare("INSERT INTO vouchers (code, is_used) VALUES (?, 0)");
                $stmt->execute([$v_code]);
                $data['voucher_id'] = $pdo->lastInsertId();
            }
        } elseif ($action == 'exam_access') {
            $exam_id = $_POST['exam_id'] ?? null;
            if (!$exam_id) throw new Exception("Select an exam for access.");
            $data['exam_id'] = $exam_id;
        }

        // 3. Create Magic Link
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+7 days')); // Links valid for 7 days by default
        
        $stmt = $pdo->prepare("INSERT INTO magic_links (token, user_id, action, data, expires_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$token, $user_id, $action, json_encode($data), $expiry]);

        $pdo->commit();
        
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF'], 2);
        $generated_link = $base_url . "/access.php?token=" . $token;
        $message = "Link generated successfully!";

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error: " . $e->getMessage();
    }
}

// Fetch data for forms
$students = $pdo->query("SELECT id, name, email FROM users WHERE role = 'student' ORDER BY name ASC")->fetchAll();
$courses = $pdo->query("SELECT id, title FROM courses WHERE status = 1 ORDER BY title ASC")->fetchAll();
$exams = $pdo->query("SELECT id, title FROM exams WHERE is_private = 1 ORDER BY title ASC")->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="fade-in">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <div>
            <h2 style="font-weight: 800; color: var(--dark); margin: 0;">Magic Link Generator</h2>
            <p style="color: #64748b; margin-top: 5px;">Create one-time access links for students.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="badge badge-<?= strpos($message, 'Error') === false ? 'success' : 'danger' ?>" style="display: block; padding: 15px; margin-bottom: 25px; border-radius: 12px;">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <?php if ($generated_link): ?>
        <div class="white-card" style="padding: 25px; border: 2px solid var(--primary); margin-bottom: 30px; background: #f5f3ff; border-radius: 20px;">
            <h4 style="margin-top: 0; color: var(--primary);"><i class="fas fa-link"></i> Your Generated Link</h4>
            <div style="display: flex; gap: 10px; margin-top: 15px;">
                <input type="text" id="copyLink" value="<?= $generated_link ?>" class="form-control" readonly style="background: white; font-family: monospace; font-weight: bold;">
                <button onclick="copyToClipboard()" class="btn btn-primary"><i class="fas fa-copy"></i> Copy</button>
            </div>
            <p style="margin-top: 10px; font-size: 0.85rem; color: #64748b;">This link will expire on <?= date('d M, Y h:i A', strtotime('+7 days')) ?> and is for one-time use.</p>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-6">
            <div class="white-card" style="padding: 30px; border-radius: 24px;">
                <h3 style="margin-bottom: 25px; font-weight: 800;">Create New Link</h3>
                <form method="POST">
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label>Student (Search or Add New)</label>
                        <input type="text" list="studentList" id="student_id_search" class="form-control" placeholder="Type Email or select existing..." onchange="updateStudentInfo(this.value)">
                        <datalist id="studentList">
                            <?php foreach ($students as $s): ?>
                                <option value="<?= htmlspecialchars($s['email']) ?>" data-name="<?= htmlspecialchars($s['name']) ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </datalist>
                        <input type="hidden" name="email" id="final_email">
                    </div>

                    <div id="newStudentInfo" style="display: none; background: #f8fafc; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label>Student Name (For new user)</label>
                            <input type="text" name="name" id="new_name" class="form-control">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <label>Action Type</label>
                        <select name="action" class="form-control" onchange="toggleActionFields(this.value)" required>
                            <option value="login">Auto-Login only</option>
                            <option value="reset_password">Reset Password & Login</option>
                            <option value="enroll">Auto-Enroll & Login</option>
                            <option value="exam_access">Grant Exam Access</option>
                        </select>
                    </div>

                    <!-- Action Specific Fields -->
                    <div id="fields_reset_password" class="action-fields" style="display: none;">
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="text" name="new_password" class="form-control" placeholder="Set temporary password">
                        </div>
                    </div>

                    <div id="fields_enroll" class="action-fields" style="display: none;">
                        <div class="form-group">
                            <label>Select Courses</label>
                            <div style="max-height: 150px; overflow-y: auto; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                                <?php foreach ($courses as $c): ?>
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox" name="course_ids[]" value="<?= $c['id'] ?>"> <?= htmlspecialchars($c['title']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group" style="margin-top: 10px;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" name="apply_voucher" value="1"> Auto-apply Voucher (for paid courses)
                            </label>
                        </div>
                    </div>

                    <div id="fields_exam_access" class="action-fields" style="display: none;">
                        <div class="form-group">
                            <label>Select Private Exam</label>
                            <select name="exam_id" class="form-control">
                                <option value="">-- Choose Exam --</option>
                                <?php foreach ($exams as $e): ?>
                                    <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top: 30px;">
                        <button type="submit" name="generate_link" class="btn btn-primary" style="width: 100%; padding: 15px; border-radius: 12px; font-weight: 700;">
                            <i class="fas fa-magic"></i> Generate Magic Link
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-6">
            <div class="white-card" style="padding: 30px; border-radius: 24px;">
                <h3 style="margin-bottom: 25px; font-weight: 800;">Recent Links</h3>
                <div style="overflow-x: auto;">
                    <table style="width: 100%;">
                        <thead>
                            <tr style="border-bottom: 1px solid #eee;">
                                <th style="padding: 10px 0; text-align: left;">Student</th>
                                <th style="padding: 10px 0; text-align: left;">Action</th>
                                <th style="padding: 10px 0; text-align: left;">Status</th>
                                <th style="padding: 10px 0; text-align: left;">Expires</th>
                                <th style="padding: 10px 0; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $links = $pdo->query("SELECT l.*, u.name as student_name, u.email as student_email 
                                                FROM magic_links l 
                                                LEFT JOIN users u ON l.user_id = u.id 
                                                ORDER BY l.created_at DESC LIMIT 20")->fetchAll();
                            foreach ($links as $l):
                                $is_expired = strtotime($l['expires_at']) < time();
                            ?>
                            <tr style="border-bottom: 1px solid #f8fafc;">
                                <td style="padding: 12px 0;">
                                    <div style="font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($l['student_name'] ?: 'Guest') ?></div>
                                    <small style="color: #94a3b8;"><?= htmlspecialchars($l['student_email'] ?: '-') ?></small>
                                </td>
                                <td style="padding: 12px 0;">
                                    <span class="badge" style="background: #f1f5f9; color: #475569; font-size: 0.65rem; border: 1px solid #e2e8f0;"><?= strtoupper($l['action']) ?></span>
                                </td>
                                <td style="padding: 12px 0;">
                                    <?php if ($l['is_used']): ?>
                                        <span class="badge" style="background: #dcfce7; color: #15803d; font-size: 0.65rem;">USED</span>
                                    <?php elseif ($is_expired): ?>
                                        <span class="badge" style="background: #fee2e2; color: #b91c1c; font-size: 0.65rem;">EXPIRED</span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #fef9c3; color: #a16207; font-size: 0.65rem;">ACTIVE</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 0;">
                                    <small style="font-weight: 600; color: #64748b;"><?= date('d M', strtotime($l['expires_at'])) ?></small>
                                </td>
                                <td style="padding: 12px 0; text-align: right;">
                                    <a href="?delete=<?= $l['id'] ?>" onclick="return confirm('Delete this link?')" class="btn btn-sm" style="background: #fff1f2; color: #e11d48; padding: 6px 10px; border-radius: 8px;">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleActionFields(action) {
    document.querySelectorAll('.action-fields').forEach(el => el.style.display = 'none');
    const target = document.getElementById('fields_' + action);
    if (target) target.style.display = 'block';
}

function updateStudentInfo(email) {
    const list = document.getElementById('studentList');
    const options = list.options;
    let found = false;
    for (let i = 0; i < options.length; i++) {
        if (options[i].value === email) {
            found = true;
            document.getElementById('newStudentInfo').style.display = 'none';
            break;
        }
    }
    
    if (!found && email.includes('@')) {
        document.getElementById('newStudentInfo').style.display = 'block';
    } else {
        document.getElementById('newStudentInfo').style.display = 'none';
    }
    document.getElementById('final_email').value = email;
}

function copyToClipboard() {
    const copyText = document.getElementById("copyLink");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    alert("Link copied to clipboard!");
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
