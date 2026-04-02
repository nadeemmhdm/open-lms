<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id'])) {
    redirect('../index.php');
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    // Check if email taken by another user
    $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $check->execute([$email, $user_id]);
    if ($check->rowCount() > 0) {
        $error = "Email is already in use.";
    } else {
        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?");
            $update->execute([$name, $email, $hash, $user_id]);
        } else {
            $update = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $update->execute([$name, $email, $user_id]);
        }
        $_SESSION['name'] = $name; // Update session name
        $success = "Profile updated successfully!";
    }
}

// Fetch User Data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div class="row">
    <div class="col-md-8 mx-auto" style="max-width: 600px; margin: 0 auto;">
        <h2 style="margin-bottom: 30px;">My Profile</h2>

        <?php if (isset($success)): ?>
            <div class="badge badge-success" style="display: block; padding: 15px; margin-bottom: 20px;">
                <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="badge badge-danger" style="display: block; padding: 15px; margin-bottom: 20px;">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <div class="white-card"
            style="background: white; padding: 40px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); text-align: center;">
            <div
                style="width: 100px; height: 100px; background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 50%; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: white;">
                <?= strtoupper(substr($user['name'], 0, 1)) ?>
            </div>
            <h3 style="margin-bottom: 5px;">
                <?= htmlspecialchars($user['name']) ?>
            </h3>
            <p style="color: #888; margin-bottom: 30px;">
                <?= ucfirst($role) ?> Account
            </p>

            <form method="POST" style="text-align: left;">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>"
                        required>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" class="form-control"
                        value="<?= htmlspecialchars($user['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label>New Password (Optional)</label>
                    <input type="password" name="password" class="form-control"
                        placeholder="Leave blank to keep current">
                </div>
                <button type="submit" name="update_profile" class="btn btn-primary"
                    style="width: 100%; margin-top: 10px;">Save Changes</button>
            </form>
        </div>
    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>