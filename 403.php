<?php
$path_to_root = './';
require_once 'config.php';
include 'includes/header.php';
?>

<div style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 70vh; text-align: center; padding: 20px;">
    <div style="font-size: 8rem; font-weight: 800; color: var(--danger); line-height: 1; margin-bottom: 20px; opacity: 0.2;">403</div>
    <h2 style="font-size: 2.5rem; margin-bottom: 15px;">Access Denied</h2>
    <p style="color: #64748b; max-width: 500px; margin-bottom: 30px; font-size: 1.1rem;">You don't have permission to access this resource. If you believe this is an error, please contact the administrator.</p>
    <div style="display: flex; gap: 15px;">
        <a href="index.php" class="btn btn-primary" style="padding: 15px 40px; border-radius: 50px;">Go Home</a>
        <button onclick="history.back()" class="btn" style="background: #f1f5f9; color: #64748b; padding: 15px 40px; border-radius: 50px;">Go Back</button>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
