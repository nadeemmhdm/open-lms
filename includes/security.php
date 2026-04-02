<?php
// Function to check login attempts
function checkLoginCooldown($pdo, $email)
{
    $now = new DateTime();
    $stmt = $pdo->prepare("SELECT login_attempts, last_attempt_time FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        if ($user['login_attempts'] >= 5) {
            $last = new DateTime($user['last_attempt_time']);
            $diff = $now->getTimestamp() - $last->getTimestamp();
            if ($diff < 300) { // 5 minutes cooldown
                return ceil((300 - $diff) / 60);
            } else {
                $pdo->prepare("UPDATE users SET login_attempts = 0 WHERE email = ?")->execute([$email]);
            }
        }
    }
    return 0;
}

function recordLoginAttempt($pdo, $email)
{
    $pdo->prepare("UPDATE users SET login_attempts = login_attempts + 1, last_attempt_time = NOW() WHERE email = ?")->execute([$email]);
}

function resetLoginAttempts($pdo, $email)
{
    $pdo->prepare("UPDATE users SET login_attempts = 0 WHERE email = ?")->execute([$email]);
}

// Maintenance Mode Quick Check
function getMaintenanceStatus($pdo)
{
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'maintenance_mode'");
    $stmt->execute();
    return $stmt->fetchColumn() === '1';
}

// Global Security Presence Check (Call this in header.php)
function enforceSecurity($pdo)
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();

    if (!isset($_SESSION['user_id']))
        return;

    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'];

    // 1. Fetch current status from DB (Real-time check)
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $status = $stmt->fetchColumn();

    // 2. Blocked/Archived -> Auto Logout
    if ($status === 'blocked' || $status === 'archived') {
        session_destroy();
        // Determine path to root
        $path = (isset($GLOBALS['path_to_root'])) ? $GLOBALS['path_to_root'] : '';
        header("Location: " . $path . "index.php?error=account_locked");
        exit;
    }

    // 4. Update Last Access (Once per minute)
    if (!isset($_SESSION['last_access_update']) || (time() - $_SESSION['last_access_update']) > 60) {
        $pdo->prepare("UPDATE users SET last_access = NOW() WHERE id = ?")->execute([$user_id]);
        $_SESSION['last_access_update'] = time();
    }

    // 3. Maintenance Mode -> Student Logout/Redirect
    if ($role === 'student' && getMaintenanceStatus($pdo)) {
        if (basename($_SERVER['PHP_SELF']) !== 'maintenance.php') {
            $path = (isset($GLOBALS['path_to_root'])) ? $GLOBALS['path_to_root'] : '';
            header("Location: " . $path . "maintenance.php");
            exit;
        }
    }

    // 5. Page-level Dashboard Locks (For Students)
    if ($role === 'student') {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'locked_pages'");
        $stmt->execute();
        $locked_json = $stmt->fetchColumn();
        $locked_pages = json_decode($locked_json ?: '[]', true);

        // Check if current relative path is in locked_pages
        $current_path = 'student/' . basename($_SERVER['PHP_SELF']);
        if (in_array($current_path, $locked_pages)) {
            $path = (isset($GLOBALS['path_to_root'])) ? $GLOBALS['path_to_root'] : '';
            header("Location: " . $path . "student/index.php?error=page_locked");
            exit;
        }
    }
}
?>