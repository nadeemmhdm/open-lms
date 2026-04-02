<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

// Sub-admin permission check
if ($_SESSION['role'] === 'sub_admin' && !hasPermission('courses')) {
    redirect('index.php');
}

// Handle Delete Course
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
    $stmt->execute([$id]);
    redirect('courses.php');
}

// Handle Toggle Status (Hide/Show)
if (isset($_GET['toggle_status']) && is_numeric($_GET['toggle_status'])) {
    $id = $_GET['toggle_status'];
    $stmt = $pdo->prepare("UPDATE courses SET status = 1 - status WHERE id = ?");
    $stmt->execute([$id]);
    redirect('courses.php');
}

// Handle Add/Edit Course
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim($_POST['title']);
    $desc = trim($_POST['description']);
    $image_url = trim($_POST['image_url']);
    $is_paid = isset($_POST['is_paid']) ? 1 : 0;
    $price = isset($_POST['price']) ? (float)$_POST['price'] : 0.00;
    
    // Handle Image Upload
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] == 0) {
        $target_dir = $path_to_root . "uploads/images/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $file_ext = pathinfo($_FILES["cover_image"]["name"], PATHINFO_EXTENSION);
        $file_name = time() . '_' . uniqid() . '.' . $file_ext;
        $target_file = $target_dir . $file_name;
        
        if (move_uploaded_file($_FILES["cover_image"]["tmp_name"], $target_file)) {
            $image_url = "uploads/images/" . $file_name;
        }
    }

    if (isset($_POST['add_course'])) {
        $stmt = $pdo->prepare("INSERT INTO courses (title, description, image_url, status, is_paid, price) VALUES (?, ?, ?, 1, ?, ?)");
        $stmt->execute([$title, $desc, $image_url, $is_paid, $price]);
        redirect('courses.php');
    } elseif (isset($_POST['edit_course'])) {
        $id = $_POST['course_id'];
        // If image_url is empty and no new upload, we might want to keep the old one. 
        // But for simplicity, we'll just update with what's provided.
        if (empty($image_url) && (!isset($_FILES['cover_image']) || $_FILES['cover_image']['error'] != 0)) {
            $stmt = $pdo->prepare("UPDATE courses SET title = ?, description = ?, is_paid = ?, price = ? WHERE id = ?");
            $stmt->execute([$title, $desc, $is_paid, $price, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE courses SET title = ?, description = ?, image_url = ?, is_paid = ?, price = ? WHERE id = ?");
            $stmt->execute([$title, $desc, $image_url, $is_paid, $price, $id]);
        }
        redirect('courses.php');
    }
}

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
    <div>
        <h2 style="margin: 0;">Manage Courses</h2>
        <p style="color: #666; margin: 5px 0 0 0;">Create, edit, and organize your learning modules</p>
    </div>
    <button onclick="document.getElementById('addCourseModal').style.display='flex'" class="btn btn-primary" style="display: flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 10px;">
        <i class="fas fa-plus"></i> Add New Course
    </button>
</div>

<!-- Add Course Modal -->
<div id="addCourseModal" class="modal-overlay">
    <div class="modal-content fade-in">
        <div class="modal-header">
            <h3>Create New Course</h3>
            <span onclick="document.getElementById('addCourseModal').style.display='none'" class="close-btn">&times;</span>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Course Title</label>
                <input type="text" name="title" class="form-control" placeholder="e.g. Master Web Development" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Briefly describe what students will learn..."></textarea>
            </div>
            <div class="form-group">
                <label>Cover Image</label>
                <div style="border: 2px dashed #ddd; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 10px;">
                    <input type="file" name="cover_image" id="add_img_file" style="display: none;" onchange="updateFileName(this, 'add_file_name')">
                    <label for="add_img_file" style="cursor: pointer; color: var(--primary-color);">
                        <i class="fas fa-cloud-upload-alt" style="font-size: 24px; margin-bottom: 8px;"></i><br>
                        Click to Upload Image
                    </label>
                    <div id="add_file_name" style="font-size: 12px; color: #666; margin-top: 5px;"></div>
                </div>
                <div style="text-align: center; margin-bottom: 10px; font-weight: bold; color: #999;">- OR -</div>
                <input type="text" name="image_url" class="form-control" placeholder="Paste Image URL (e.g. https://...)">
            </div>
            <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-top: 10px;">
                <label style="margin: 0;">Paid Course?</label>
                <input type="checkbox" name="is_paid" id="is_paid_add" onchange="togglePriceInput(this, 'price_group_add')">
            </div>
            <div id="price_group_add" class="form-group" style="display: none;">
                <label>Course Price</label>
                <div style="position: relative;">
                    <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #666;">$</span>
                    <input type="number" name="price" class="form-control" step="0.01" style="padding-left: 25px;" placeholder="0.00">
                </div>
            </div>
            <button type="submit" name="add_course" class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 8px; font-weight: 600;">Create Course</button>
        </form>
    </div>
</div>

<!-- Edit Course Modal -->
<div id="editCourseModal" class="modal-overlay">
    <div class="modal-content fade-in">
        <div class="modal-header">
            <h3>Edit Course</h3>
            <span onclick="document.getElementById('editCourseModal').style.display='none'" class="close-btn">&times;</span>
        </div>
        <form method="POST" enctype="multipart/form-data" id="editForm">
            <input type="hidden" name="course_id" id="edit_course_id">
            <div class="form-group">
                <label>Course Title</label>
                <input type="text" name="title" id="edit_title" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label>Cover Image</label>
                <div style="border: 2px dashed #ddd; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 10px;">
                    <input type="file" name="cover_image" id="edit_img_file" style="display: none;" onchange="updateFileName(this, 'edit_file_name')">
                    <label for="edit_img_file" style="cursor: pointer; color: var(--primary-color);">
                        <i class="fas fa-cloud-upload-alt" style="font-size: 24px; margin-bottom: 8px;"></i><br>
                        Change Cover Image
                    </label>
                    <div id="edit_file_name" style="font-size: 12px; color: #666; margin-top: 5px;"></div>
                </div>
                <div style="text-align: center; margin-bottom: 10px; font-weight: bold; color: #999;">- OR -</div>
                <input type="text" name="image_url" id="edit_image_url" class="form-control" placeholder="Paste Image URL">
            </div>
            <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-top: 10px;">
                <label style="margin: 0;">Paid Course?</label>
                <input type="checkbox" name="is_paid" id="edit_is_paid" onchange="togglePriceInput(this, 'price_group_edit')">
            </div>
            <div id="price_group_edit" class="form-group" style="display: none;">
                <label>Course Price</label>
                <div style="position: relative;">
                    <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #666;">$</span>
                    <input type="number" name="price" id="edit_price" class="form-control" step="0.01" style="padding-left: 25px;" placeholder="0.00">
                </div>
            </div>
            <button type="submit" name="edit_course" class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 8px; font-weight: 600;">Save Changes</button>
        </form>
    </div>
</div>

<div class="course-grid">
    <?php
    $stmt = $pdo->query("SELECT * FROM courses ORDER BY id DESC");
    while ($course = $stmt->fetch()) {
        $img = $course['image_url'];
        if (empty($img)) {
            $img = 'https://via.placeholder.com/400x250?text=No+Image';
        } elseif (!filter_var($img, FILTER_VALIDATE_URL)) {
            $img = $path_to_root . $img;
        }
        
        $status_label = $course['status'] == 1 ? 'Published' : 'Hidden';
        $status_color = $course['status'] == 1 ? '#28a745' : '#dc3545';
        $status_icon = $course['status'] == 1 ? 'fa-eye' : 'fa-eye-slash';
        $status_btn_text = $course['status'] == 1 ? 'Hide' : 'Show';
        $opacity = $course['status'] == 1 ? '1' : '0.7';
        ?>
        <div class='course-card' style="opacity: <?php echo $opacity; ?>; position: relative; transition: all 0.3s ease;">
            <div style="position: absolute; top: 10px; left: 10px; z-index: 10; display: flex; gap: 5px;">
                <div style="background: <?php echo $status_color; ?>; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    <?php echo $status_label; ?>
                </div>
                <?php if ($course['is_paid']): ?>
                <div style="background: #4f46e5; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    $<?php echo number_format($course['price'], 2); ?>
                </div>
                <?php else: ?>
                <div style="background: #059669; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    FREE
                </div>
                <?php endif; ?>
            </div>
            <div class='course-img' style="height: 180px; overflow: hidden; border-radius: 16px 16px 0 0;">
                <img src='<?php echo $img; ?>' alt='<?php echo htmlspecialchars($course['title']); ?>' style="width: 100%; height: 100%; object-fit: cover;">
            </div>
            <div class='course-content' style="padding: 20px;">
                <div class='course-title' style="font-size: 1.1rem; font-weight: 700; margin-bottom: 8px; color: #333; height: 1.4em; overflow: hidden;"><?php echo htmlspecialchars($course['title']); ?></div>
                <div class='course-desc' style="font-size: 0.9rem; color: #666; height: 3em; overflow: hidden; margin-bottom: 15px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;"><?php echo htmlspecialchars($course['description']); ?></div>
                
                <div style='display: flex; flex-wrap: wrap; gap: 8px;'>
                    <a href='batches.php?course_id=<?php echo $course['id']; ?>' class='btn btn-sm btn-primary' style='flex: 1; min-width: 100px;'>Batches</a>
                    
                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($course)); ?>)" class='btn btn-sm' style='background: #eef2ff; color: #4f46e5; border: none;'>
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    
                    <a href='courses.php?toggle_status=<?php echo $course['id']; ?>' class='btn btn-sm' style='background: #fff8e1; color: #f59e0b; border: none;'>
                        <i class="fas <?php echo $status_icon; ?>"></i> <?php echo $status_btn_text; ?>
                    </a>
                    
                    <a href='courses.php?delete=<?php echo $course['id']; ?>' class='btn btn-sm' style='background: #fef2f2; color: #ef4444; border: none;' onclick="return confirm('Are you sure you want to delete this course?')">
                        <i class="fas fa-trash"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    ?>
</div>

<style>
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    z-index: 2000;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(5px);
}

.modal-content {
    background: white;
    padding: 30px;
    border-radius: 20px;
    width: 450px;
    max-width: 90%;
    box-shadow: 0 20px 50px rgba(0,0,0,0.2);
    position: relative;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.4rem;
    color: #333;
}

.close-btn {
    cursor: pointer;
    font-size: 1.8rem;
    color: #999;
    transition: color 0.2s;
}

.close-btn:hover {
    color: #333;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #444;
    font-size: 0.9rem;
}

.form-control {
    width: 100%;
    padding: 12px;
    border: 2px solid #edeff2;
    border-radius: 10px;
    font-size: 0.95rem;
    transition: border-color 0.2s;
}

.form-control:focus {
    border-color: var(--primary-color);
    outline: none;
}

.btn-sm {
    padding: 8px 12px;
    font-size: 0.85rem;
    border-radius: 8px;
    font-weight: 500;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
}

/* Base styles for the grid if not already in global CSS */
.course-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 25px;
}

.course-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    overflow: hidden;
    border: 1px solid #f0f0f0;
}

.course-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}
</style>

<script>
function openEditModal(course) {
    document.getElementById('edit_course_id').value = course.id;
    document.getElementById('edit_title').value = course.title;
    document.getElementById('edit_description').value = course.description;
    document.getElementById('edit_image_url').value = course.image_url;
    document.getElementById('edit_file_name').innerText = "";
    
    const isPaid = parseInt(course.is_paid) === 1;
    document.getElementById('edit_is_paid').checked = isPaid;
    document.getElementById('price_group_edit').style.display = isPaid ? 'block' : 'none';
    document.getElementById('edit_price').value = course.price;
    
    document.getElementById('editCourseModal').style.display = 'flex';
}

function togglePriceInput(checkbox, targetId) {
    document.getElementById(targetId).style.display = checkbox.checked ? 'block' : 'none';
}

function updateFileName(input, targetId) {
    const fileName = input.files[0] ? input.files[0].name : "";
    document.getElementById(targetId).innerText = fileName;
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.className === 'modal-overlay') {
        event.target.style.display = 'none';
    }
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>