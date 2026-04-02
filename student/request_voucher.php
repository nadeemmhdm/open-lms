<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = $_SESSION['user_id'];
$course_id = isset($_GET['course_id']) ? (int) $_GET['course_id'] : null;

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$requested_course_title = null;
if ($course_id) {
    $stmt = $pdo->prepare("SELECT title FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $requested_course_title = $stmt->fetchColumn();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $phone = trim($_POST['phone_number']);
    $password = $_POST['password'];

    // Verify Password
    if (!password_verify($password, $user['password'])) {
        $error = "Incorrect password. Verification failed.";
    } else {
        // Create Request
        $stmt = $pdo->prepare("INSERT INTO voucher_requests (student_id, phone_number, course_id) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $phone, $course_id]);
        $success = "Your voucher request has been sent successfully. Admin will process it soon and notify you.";
    }
}

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="white-card fade-in"
            style="border-radius: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); padding: 40px; border: 1px solid #edf2f7; position: relative; overflow: hidden; background: #fff;">
            <div
                style="position: absolute; top: 0; left: 0; width: 100%; height: 6px; background: linear-gradient(90deg, #4f46e5, #0ea5e9);">
            </div>

            <div style="text-align: center; margin-bottom: 30px;">
                <div
                    style="width: 70px; height: 70px; background: #f0f7ff; border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: #4f46e5; border: 1px solid #e0efff;">
                    <i class="fas fa-ticket-alt fa-2x"></i>
                </div>
                <h2 style="font-weight: 800; color: #1e293b; margin-bottom: 8px;">Request Voucher</h2>
                <?php if ($requested_course_title): ?>
                    <p style="color: #4f46e5; font-weight: 700; font-size: 1.1rem; margin-bottom: 5px;">For:
                        <?= htmlspecialchars($requested_course_title) ?></p>
                <?php endif; ?>
                <p style="color: #64748b; font-size: 1rem;">Unlock premium paid courses with a voucher code.</p>
            </div>

            <?php if ($error): ?>
                <div
                    style="background: #fff1f2; color: #be123c; padding: 15px; border-radius: 12px; margin-bottom: 25px; border: 1px solid #fecdd3; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-exclamation-circle"></i> <span><?= $error ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div
                    style="background: #f0fdf4; color: #15803d; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #bbf7d0; text-align: center;">
                    <div style="font-size: 3rem; margin-bottom: 15px;"><i class="fas fa-check-circle"></i></div>
                    <h4 style="margin-bottom: 10px;">Request Sent!</h4>
                    <p style="margin-bottom: 20px;"><?= $success ?></p>
                    <a href="explore_courses.php" class="btn btn-primary"
                        style="padding: 10px 25px; border-radius: 10px;">Back to Courses</a>
                </div>
            <?php else: ?>
                <form method="POST">
                    <div
                        style="background: #f8fafc; border-radius: 16px; padding: 20px; border: 1px solid #f1f5f9; margin-bottom: 25px;">
                        <div
                            style="font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; font-weight: 800; margin-bottom: 15px; letter-spacing: 0.5px;">
                            Account Information</div>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #64748b;">Name:</span>
                                <span
                                    style="font-weight: 700; color: #334155;"><?= htmlspecialchars($user['name']) ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #64748b;">Email:</span>
                                <span
                                    style="font-weight: 700; color: #334155;"><?= htmlspecialchars($user['email']) ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #64748b;">Request Date:</span>
                                <span style="font-weight: 700; color: #334155;"><?= date('M d, Y h:i A') ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <label
                            style="display: block; font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 0.95rem;">Contact
                            Number</label>
                        <div style="position: relative;">
                            <span
                                style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8;"><i
                                    class="fas fa-phone"></i></span>
                            <input type="text" name="phone_number" class="form-control"
                                style="width: 100%; padding: 12px 12px 12px 45px; border: 2px solid #e2e8f0; border-radius: 14px; outline: none; transition: 0.2s;"
                                placeholder="Enter your phone number" required>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 30px;">
                        <label
                            style="display: block; font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 0.95rem;">Verify
                            Password</label>
                        <div style="position: relative;">
                            <span
                                style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8;"><i
                                    class="fas fa-lock"></i></span>
                            <input type="password" name="password" class="form-control"
                                style="width: 100%; padding: 12px 12px 12px 45px; border: 2px solid #e2e8f0; border-radius: 14px; outline: none; transition: 0.2s;"
                                placeholder="Enter account password to verify" required>
                        </div>
                        <small style="color: #94a3b8; font-size: 0.8rem; margin-top: 5px; display: block;">This is required
                            to verify your identity before sending the request.</small>
                    </div>

                    <button type="submit" class="btn btn-primary"
                        style="width: 100%; padding: 16px; border-radius: 16px; font-weight: 700; font-size: 1.1rem; box-shadow: 0 10px 15px rgba(79, 70, 229, 0.2); border: none; display: flex; align-items: center; justify-content: center; gap: 10px;">
                        Send Request <i class="fas fa-paper-plane"></i>
                    </button>

                    <a href="courses.php"
                        style="display: block; text-align: center; margin-top: 20px; color: #64748b; font-weight: 600; text-decoration: none;">Cancel
                        and Go Back</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .form-control:focus {
        border-color: #4f46e5 !important;
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1) !important;
    }
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>