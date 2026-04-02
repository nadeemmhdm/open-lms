<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    redirect('../index.php');
}

$id = $_GET['id'] ?? null;
if (!$id)
    redirect('students.php');

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student)
    redirect('students.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    // Update with or without password
    if (!empty($password)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?");
        $update->execute([$name, $email, $hash, $id]);
    } else {
        $update = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
        $update->execute([$name, $email, $id]);
    }
    redirect('students.php?updated=1');
}

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 20px;">
    <a href="students.php" style="color: #666;"><i class="fas fa-arrow-left"></i> Back to Students</a>
</div>

<h2>Edit Student: <span style="color: var(--primary);">
        <?= htmlspecialchars($student['name']) ?>
    </span></h2>

<div class="white-card"
    style="background: white; padding: 30px; border-radius: 16px; margin-top: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); max-width: 600px;">
    <form method="POST">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($student['name']) ?>"
                required>
        </div>
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($student['email']) ?>"
                required>
        </div>
        <div class="form-group">
            <label>New Password (leave blank to keep current)</label>
            <input type="password" name="password" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top: 20px;">Update Profile</button>
    </form>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>