<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Handle Voucher Entry
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['apply_voucher'])) {
    $code = trim($_POST['voucher_code']);
    $course_id = $_POST['course_id'];

    // Check if voucher exists and is active
    $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE code = ? AND is_used = 0");
    $stmt->execute([$code]);
    $voucher = $stmt->fetch();

    if (!$voucher) {
        $error = "Invalid or already used voucher code.";
    } elseif ($voucher['course_id'] !== null && $voucher['course_id'] != $course_id) {
        $error = "This voucher is not applicable to this course.";
    } else {
        // Apply Voucher
        try {
            $pdo->beginTransaction();
            
            // Mark voucher as used
            $stmt = $pdo->prepare("UPDATE vouchers SET is_used = 1, student_id = ?, used_at = NOW() WHERE id = ?");
            $stmt->execute([$user_id, $voucher['id']]);

            // Give access to course
            $stmt = $pdo->prepare("INSERT INTO student_courses (student_id, course_id, access_type, voucher_id) VALUES (?, ?, 'voucher', ?)");
            $stmt->execute([$user_id, $course_id, $voucher['id']]);

            $pdo->commit();
            $success = "Voucher applied successfully! You now have access to this course.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() == 23000) {
                $error = "You already have access to this course.";
            } else {
                $error = "An error occurred. Please try again.";
            }
        }
    }
}

// Handle Free Enrollment
if (isset($_GET['enroll_free']) && is_numeric($_GET['enroll_free'])) {
    $course_id = $_GET['enroll_free'];
    
    // Check if course is free
    $stmt = $pdo->prepare("SELECT is_paid FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();
    
    if ($course && !$course['is_paid']) {
        try {
            $stmt = $pdo->prepare("INSERT INTO student_courses (student_id, course_id, access_type) VALUES (?, ?, 'free')");
            $stmt->execute([$user_id, $course_id]);
            $success = "Successfully enrolled in the course!";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $success = "You are already enrolled in this course.";
            } else {
                $error = "Enrollment failed.";
            }
        }
    }
}

// Fetch all courses and student access (including batch-level enrollment)
$sql = "SELECT c.*, 
               (SELECT enrolled_at FROM student_courses WHERE student_id = ? AND course_id = c.id LIMIT 1) as direct_enrolled_at,
               (SELECT sb.batch_id FROM student_batches sb 
                JOIN batch_courses bc ON sb.batch_id = bc.batch_id 
                WHERE sb.student_id = ? AND bc.course_id = c.id LIMIT 1) as batch_enrolled_id
        FROM courses c 
        WHERE c.status = 1
        ORDER BY c.is_paid ASC, c.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id, $user_id]);
$all_courses = $stmt->fetchAll();

// Map enrolled_at for display logic
foreach ($all_courses as &$course) {
    if ($course['direct_enrolled_at']) {
        $course['enrolled_at'] = $course['direct_enrolled_at'];
    } elseif ($course['batch_enrolled_id']) {
        $course['enrolled_at'] = date('Y-m-d H:i:s'); // Fallback for batch enrollment
    } else {
        $course['enrolled_at'] = null;
    }
}
unset($course);

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="row fade-in">
    <div class="col-12" style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h2 style="font-weight: 800; color: #1e293b; letter-spacing: -0.5px; margin: 0;">Explore Courses</h2>
            <p style="color: #64748b; margin: 5px 0 0 0;">Discover new learning opportunities and unlock premium content.</p>
        </div>
        <a href="request_voucher.php" class="btn btn-outline-primary" style="display: flex; align-items: center; gap: 8px; border-radius: 12px; font-weight: 700; padding: 10px 20px;">
            <i class="fas fa-paper-plane"></i> Request Voucher
        </a>
    </div>

    <?php if ($error): ?>
        <div class="col-12">
            <div style="background: #fff1f2; color: #be123c; padding: 15px; border-radius: 12px; margin-bottom: 25px; border: 1px solid #fecdd3;">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="col-12">
            <div style="background: #f0fdf4; color: #15803d; padding: 15px; border-radius: 12px; margin-bottom: 25px; border: 1px solid #bbf7d0;">
                <i class="fas fa-check-circle"></i> <?= $success ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="col-12">
        <div class="course-grid">
            <?php foreach ($all_courses as $c): 
                $img = $c['image_url'];
                if ($img && !filter_var($img, FILTER_VALIDATE_URL)) {
                    $img = $path_to_root . $img;
                }
                $is_enrolled = !empty($c['enrolled_at']);
                ?>
                <div class="course-card" style="display: flex; flex-direction: column;">
                    <div class="course-img">
                        <img src="<?= $img ?: 'https://via.placeholder.com/400x200?text=No+Image' ?>" alt="<?= htmlspecialchars($c['title']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        <div style="position: absolute; top: 15px; right: 15px; z-index: 5;">
                            <?php if ($c['is_paid']): ?>
                                <span class="badge" style="background: rgba(255,255,255,0.9); color: #4f46e5; padding: 6px 14px; border-radius: 12px; font-weight: 800; backdrop-filter: blur(8px); box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                                    $<?= number_format($c['price'], 2) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background: rgba(255,255,255,0.9); color: #10b981; padding: 6px 14px; border-radius: 12px; font-weight: 800; backdrop-filter: blur(8px); box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                                    FREE
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($is_enrolled): ?>
                            <div class="enrolled-overlay" style="opacity: 1;">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
                                    <div style="width: 50px; height: 50px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 20px rgba(16, 185, 129, 0.4);">
                                        <i class="fas fa-check"></i>
                                    </div>
                                    <span style="font-weight: 800; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px;">Enrolled</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="course-content" style="flex: 1; display: flex; flex-direction: column;">
                        <h3 class="course-title" style="font-size: 1.25rem; font-weight: 800; color: #1e293b; margin-bottom: 10px;"><?= htmlspecialchars($c['title']) ?></h3>
                        <p class="course-desc" style="color: #64748b; font-size: 0.95rem; margin-bottom: 20px; flex: 1;"><?= htmlspecialchars($c['description']) ?></p>
                        
                        <div style="margin-top: auto;">
                            <?php if ($is_enrolled): ?>
                                <div style="display: flex; gap: 10px;">
                                    <a href="my_courses.php" class="btn btn-primary" style="flex: 1; padding: 12px; border-radius: 12px; font-weight: 800; background: #4f46e5; border: none; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3); text-transform: uppercase; letter-spacing: 0.5px;">Open Course</a>
                                    <button onclick="showEnrolledInfo('<?= addslashes($c['title']) ?>', '<?= date('F j, Y', strtotime($c['enrolled_at'])) ?>')" class="btn" style="background: #f1f5f9; color: #475569; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center;" title="Enrollment Info">
                                        <i class="fas fa-info-circle"></i>
                                    </button>
                                </div>
                            <?php elseif (!$c['is_paid']): ?>
                                <a href="explore_courses.php?enroll_free=<?= $c['id'] ?>" class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 12px; font-weight: 800; background: #10b981; border: none; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3); text-transform: uppercase;">Enroll Now (Free)</a>
                            <?php else: ?>
                                <button onclick="openVoucherModal(<?= $c['id'] ?>, '<?= addslashes($c['title']) ?>')" class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 12px; font-weight: 800; background: #4f46e5; border: none; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3); text-transform: uppercase;">Unlock with Voucher</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Voucher Modal -->
<div id="voucherModal" class="modal-overlay">
    <div class="modal-content fade-in" style="width: 400px; padding: 0; overflow: hidden; border: none;">
        <div style="background: linear-gradient(135deg, #4f46e5, #4338ca); padding: 30px; color: white; text-align: center;">
            <i class="fas fa-ticket-alt fa-3x" style="margin-bottom: 15px; opacity: 0.9;"></i>
            <h3 style="margin: 0; font-weight: 800;">Unlock Course</h3>
        </div>
        <div style="padding: 30px;">
            <form method="POST">
                <input type="hidden" name="course_id" id="modal_course_id">
                <p id="modal_course_title" style="font-weight: 800; color: #1e293b; margin-bottom: 20px; font-size: 1.1rem; text-align: center;"></p>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="color: #64748b; font-weight: 700; font-size: 0.85rem; text-transform: uppercase;">Voucher Code</label>
                    <input type="text" name="voucher_code" class="form-control" placeholder="e.g. SUMMER-ABCD" required style="text-transform: uppercase; font-weight: 700; letter-spacing: 1px; border-color: #e2e8f0;">
                    <small style="color: #94a3b8; display: block; margin-top: 10px;">Don't have a voucher? <a href="request_voucher.php" style="color: #4f46e5; font-weight: 800; text-decoration: none;">Request one</a></small>
                </div>
                <button type="submit" name="apply_voucher" class="btn btn-primary" style="width: 100%; padding: 14px; border-radius: 12px; font-weight: 800; background: #4f46e5; border: none; box-shadow: 0 10px 15px rgba(79, 70, 229, 0.2);">REDEEM & ENROLL</button>
                <button type="button" onclick="document.getElementById('voucherModal').style.display='none'" style="width: 100%; margin-top: 10px; background: transparent; border: none; color: #94a3b8; font-weight: 700; cursor: pointer;">Cancel</button>
            </form>
        </div>
    </div>
</div>

<!-- Enrolled Info Modal -->
<div id="enrolledInfoModal" class="modal-overlay">
    <div class="modal-content fade-in" style="width: 400px; padding: 0; overflow: hidden; border: none;">
        <div style="background: #10b981; padding: 30px; color: white; text-align: center;">
            <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                <i class="fas fa-check fa-lg"></i>
            </div>
            <h3 style="margin: 0; font-weight: 800;">Already Enrolled</h3>
        </div>
        <div style="padding: 30px; text-align: center;">
            <h4 id="info_course_title" style="color: #1e293b; font-weight: 800; margin-bottom: 10px;"></h4>
            <p style="color: #64748b; margin-bottom: 25px;">You enrolled in this course on <span id="info_enroll_date" style="color: #1e293b; font-weight: 700;"></span>.</p>
            
            <a href="my_courses.php" class="btn btn-primary" style="width: 100%; padding: 14px; border-radius: 12px; font-weight: 800; background: #10b981; border: none; text-decoration: none; display: block; box-shadow: 0 10px 15px rgba(16, 185, 129, 0.2);">GO TO COURSE</a>
            <button onclick="document.getElementById('enrolledInfoModal').style.display='none'" style="width: 100%; margin-top: 15px; background: transparent; border: none; color: #94a3b8; font-weight: 700; cursor: pointer;">Close</button>
        </div>
    </div>
</div>

<style>
.course-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 30px;
}
.course-card {
    background: white;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0,0,0,0.04);
    border: 1px solid #f1f5f9;
    transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
    height: 100%;
}
.course-card:hover {
    transform: translateY(-10px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.12);
    border-color: #e2e8f0;
}
.course-img {
    height: 220px;
    position: relative;
    overflow: hidden;
}
.course-img img {
    transition: transform 0.6s ease;
}
.course-card:hover .course-img img {
    transform: scale(1.1);
}
.enrolled-overlay {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(4px);
    color: white;
    opacity: 0;
    transition: 0.3s opacity;
}
.course-card:hover .enrolled-overlay {
    opacity: 1;
}
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
}
.close-btn { cursor: pointer; font-size: 1.5rem; color: #999; }
.form-control { width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 10px; outline: none; }
.form-control:focus { border-color: #4f46e5; }
</style>

<script>
function openVoucherModal(id, title) {
    document.getElementById('modal_course_id').value = id;
    document.getElementById('modal_course_title').innerText = title;
    document.getElementById('voucherModal').style.display = 'flex';
}
function showEnrolledInfo(title, date) {
    document.getElementById('info_course_title').innerText = title;
    document.getElementById('info_enroll_date').innerText = date;
    document.getElementById('enrolledInfoModal').style.display = 'flex';
}
window.onclick = function(event) {
    if (event.target.className === 'modal-overlay') {
        event.target.style.display = 'none';
    }
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
