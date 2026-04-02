<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = $_SESSION['user_id'];

// Handle Unenrollment
if (isset($_GET['unenroll']) && is_numeric($_GET['unenroll'])) {
    $c_id = (int)$_GET['unenroll'];
    $source = $_GET['source'] ?? 'direct';
    
    if ($source === 'direct') {
        $pdo->prepare("DELETE FROM student_courses WHERE student_id = ? AND course_id = ?")->execute([$user_id, $c_id]);
    } else {
        // If enrolled via batch, unregister from that batch
        $b_id = (int)($_GET['batch_id'] ?? 0);
        if ($b_id > 0) {
            $pdo->prepare("DELETE FROM student_batches WHERE student_id = ? AND batch_id = ?")->execute([$user_id, $b_id]);
        }
    }
    header("Location: my_courses.php?unenroll_success=1");
    exit;
}

// Fetch courses from batches
$sql = "(SELECT c.*, b.name as batch_name, b.id as batch_id, b.status as batch_status, 'batch' as enrollment_source
        FROM student_batches sb 
        JOIN batches b ON sb.batch_id = b.id 
        JOIN batch_courses bc ON b.id = bc.batch_id
        JOIN courses c ON bc.course_id = c.id
        WHERE sb.student_id = ? AND c.status = 1
        GROUP BY c.id)
        
        UNION
        
        (SELECT c.*, b.name as batch_name, b.id as batch_id, b.status as batch_status, 'direct' as enrollment_source
        FROM student_courses sc
        JOIN courses c ON sc.course_id = c.id
        LEFT JOIN batch_courses bc ON c.id = bc.course_id
        LEFT JOIN batches b ON bc.batch_id = b.id AND b.status = 'active'
        WHERE sc.student_id = ? AND c.status = 1
        AND c.id NOT IN (SELECT bc2.course_id FROM student_batches sb2 JOIN batch_courses bc2 ON sb2.batch_id = bc2.batch_id WHERE sb2.student_id = ?)
        GROUP BY c.id)
        
        ORDER BY batch_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id, $user_id, $user_id]);
$courses = $stmt->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="row fade-in">
    <?php if (isset($_GET['unenroll_success'])): ?>
        <div class="col-12">
            <div style="background: #f0fdf4; color: #15803d; padding: 15px; border-radius: 12px; margin-bottom: 25px; border: 1px solid #bbf7d0;">
                <i class="fas fa-check-circle"></i> Course unenrolled successfully.
            </div>
        </div>
    <?php endif; ?>

    <div class="col-12" style="margin-bottom: 30px;">
        <h2 style="font-weight: 700; color: var(--dark);">Academic Programs</h2>
        <p style="color: #666;">Explore your active courses and tracked progress across all batches.</p>
    </div>

    <div class="col-12">
        <div class="course-grid">
            <?php foreach ($courses as $c): 
                $img = $c['image_url'];
                if ($img && !filter_var($img, FILTER_VALIDATE_URL)) {
                    $img = $path_to_root . $img;
                }
                ?>
                <div class="course-card">
                    <div class="course-img">
                        <img src="<?= $img ?: 'https://via.placeholder.com/400x200?text=No+Image' ?>" alt="<?= htmlspecialchars($c['title']) ?>">
                        <div style="position: absolute; top: 15px; left: 15px; display: flex; flex-direction: column; gap: 5px;">
                            <span class="badge" style="background: rgba(255,255,255,0.9); color: var(--primary); backdrop-filter: blur(5px);">
                                <?= htmlspecialchars($c['batch_name'] ?: 'No Active Batch') ?>
                            </span>
                            <?php if ($c['enrollment_source'] == 'direct'): ?>
                                <span class="badge" style="background: #4f46e5; color: white; font-size: 0.65rem;">VOUCHER ACCESS</span>
                            <?php endif; ?>
                        </div>
                        <?php if($c['batch_status'] == 'closed'): ?>
                        <div style="position: absolute; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center;">
                            <span class="badge badge-danger">BATCH LOCKED</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="course-content">
                        <h3 class="course-title" style="min-height: 2.6rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;"><?= htmlspecialchars($c['title']) ?></h3>
                        
                        <?php 
                        $desc = $c['description'];
                        $is_long = strlen($desc) > 120;
                        $display_desc = $is_long ? substr($desc, 0, 117) . '...' : $desc;
                        ?>
                        
                        <p class="course-desc" style="min-height: 4.5rem;">
                            <?= htmlspecialchars($display_desc) ?>
                            <?php if ($is_long): ?>
                                <button type="button" 
                                        onclick="showFullDesc(<?= htmlspecialchars(json_encode($c['title'])) ?>, <?= htmlspecialchars(json_encode($desc)) ?>)"
                                        style="background: none; border: none; color: var(--primary); font-weight: 700; cursor: pointer; padding: 0; font-size: 0.85rem; margin-left: 5px;">
                                    Read More
                                </button>
                            <?php endif; ?>
                        </p>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: auto; padding-top: 20px; border-top: 1px solid #f1f5f9;">
                            <div style="font-size: 0.8rem; color: #888;">
                                <i class="fas fa-book-reader" style="margin-right: 5px; color: var(--primary);"></i> Enrolled
                            </div>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <?php if($c['enrollment_source'] == 'direct' || $c['batch_status'] == 'active'): ?>
                                    <a href="view_course.php?batch_id=<?= $c['batch_id'] ?: 0 ?>&course_id=<?= $c['id'] ?>" class="btn btn-primary btn-sm" style="border-radius: 8px;">
                                        Continue <i class="fas fa-arrow-right" style="margin-left: 5px; font-size: 0.8rem;"></i>
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-sm" disabled style="background: #fef2f2; color: #ef4444; border-radius: 8px; border: 1px solid #fee2e2;">Batch Locked</button>
                                <?php endif; ?>
                                
                                <a href="?unenroll=<?= $c['id'] ?>&source=<?= $c['enrollment_source'] ?>&batch_id=<?= $c['batch_id'] ?>" 
                                   class="btn btn-sm" 
                                   style="background: #f8fafc; color: #94a3b8; border-radius: 8px; padding: 8px 10px;"
                                   onclick="return confirm('Are you sure you want to unenroll? All progress for this course will be lost.')"
                                   title="Unenroll">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($courses)): ?>
                <div class="white-card" style="grid-column: 1 / -1; text-align: center; padding: 100px; border-radius: 24px; box-shadow: none; border: 2px dashed #e2e8f0;">
                    <div style="font-size: 4rem; color: #e2e8f0; margin-bottom: 25px;">
                        <i class="fas fa-search"></i>
                    </div>
                    <h3 style="color: #4a5568;">No Enrolled Batches</h3>
                    <p style="color: #a0aec0;">Your courses will appear here once you are assigned to a student batch.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Course Description Modal -->
<div id="descModal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); backdrop-filter: blur(8px); z-index: 10000; align-items: center; justify-content: center; padding: 20px;">
    <div class="white-card" style="max-width: 600px; width: 100%; padding: 40px; border-radius: 28px; position: relative; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);">
        <button onclick="closeDescModal()" style="position: absolute; top: 20px; right: 20px; background: #f1f5f9; border: none; width: 40px; height: 40px; border-radius: 12px; cursor: pointer; color: #64748b; transition: all 0.2s;">
            <i class="fas fa-times"></i>
        </button>
        <div style="width: 60px; height: 60px; background: #f0f7ff; color: var(--primary); border-radius: 18px; display: flex; align-items: center; justify-content: center; margin-bottom: 25px; font-size: 1.5rem;">
            <i class="fas fa-book-open"></i>
        </div>
        <h3 id="modalTitle" style="font-weight: 800; color: #1e293b; margin-bottom: 15px; font-size: 1.5rem;">Course Title</h3>
        <div id="modalDesc" style="color: #475569; line-height: 1.8; font-size: 1.05rem; max-height: 400px; overflow-y: auto; padding-right: 10px;">
            Course description...
        </div>
        <div style="margin-top: 30px; border-top: 1px solid #f1f5f9; padding-top: 25px; text-align: right;">
            <button onclick="closeDescModal()" class="btn btn-primary" style="padding: 12px 30px; border-radius: 12px;">Got it</button>
        </div>
    </div>
</div>

<script>
function showFullDesc(title, desc) {
    document.getElementById('modalTitle').innerText = title;
    document.getElementById('modalDesc').innerText = desc;
    document.getElementById('descModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeDescModal() {
    document.getElementById('descModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Close on outside click
window.onclick = function(event) {
    const modal = document.getElementById('descModal');
    if (event.target == modal) {
        closeDescModal();
    }
}
</script>

<style>
/* Enforce fixed aspect ratios and alignment */
.course-card {
    height: 100%;
}
.course-title {
    margin-bottom: 15px !important;
}
.course-desc {
    margin-bottom: 25px !important;
}
#modalDesc::-webkit-scrollbar {
    width: 6px;
}
#modalDesc::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}
#modalDesc::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>