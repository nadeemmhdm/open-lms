<?php
// Set Timezone to Indian Standard Time (IST) globally for PHP
date_default_timezone_set('Asia/Kolkata');

// Database Configuration
define('DB_HOST', 'YOUR_DB_HOST');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');
define('DB_NAME', 'YOUR_DB_NAME');
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 465); // For SSL
define('SMTP_USER', 'YOUR_EMAIL@gmail.com');
define('SMTP_PASS', 'YOUR_APP_PASSWORD');
define('SMTP_FROM', 'YOUR_EMAIL@gmail.com');
define('SMTP_FROM_NAME', 'Open LMS');

// Ollama AI Configuration
define('OLLAMA_API_KEY', 'YOUR_OLLAMA_API_KEY');
define('OLLAMA_MODEL', 'gpt-oss:120b');
define('OLLAMA_URL', 'https://ollama.com/api/chat');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Sync MySQL Timezone with India (+05:30)
    $pdo->exec("SET time_zone = '+05:30'");
} catch (PDOException $e) {
    die("ERROR: Could not connect. " . $e->getMessage());
}

// Redirect Helper
function redirect($url)
{
    header("Location: $url");
    exit();
}

// Session
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    session_start();
}

// Device-based Session Invalidation & Sub-admin Access Control
if (isset($_SESSION['user_id'])) {
    try {
        $uid = $_SESSION['user_id'];
        $dtype = $_SESSION['device_type'] ?? 'desktop';
        $col = ($dtype == 'mobile' ? 'mobile_session_id' : 'desktop_session_id');
        
        $sess_check = $pdo->prepare("SELECT role, permissions, access_start_time, access_end_time, $col as valid_sid FROM users WHERE id = ?");
        $sess_check->execute([$uid]);
        $user_meta = $sess_check->fetch();
        
        // 1. Session Validaton
        if ($user_meta && $user_meta['valid_sid'] && $user_meta['valid_sid'] !== session_id() && basename($_SERVER['PHP_SELF']) !== 'setup.php') {
            session_destroy();
            $redir = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false || strpos($_SERVER['PHP_SELF'], '/student/') !== false) ? '../index.php' : 'index.php';
            header("Location: $redir?error=logged_out_new_device");
            exit();
        }

        // 2. Sub-admin Time Restiction
        if ($user_meta && $user_meta['role'] === 'sub_admin' && $user_meta['access_start_time'] && $user_meta['access_end_time'] && basename($_SERVER['PHP_SELF']) !== 'setup.php') {
            $current_time = date('H:i:s');
            if ($current_time < $user_meta['access_start_time'] || $current_time > $user_meta['access_end_time']) {
                session_destroy();
                $redir = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false || strpos($_SERVER['PHP_SELF'], '/student/') !== false) ? '../index.php' : 'index.php';
                header("Location: $redir?error=access_time_expired");
                exit();
            }
        }
    } catch (PDOException $e) {
        // Table or columns might not exist yet during setup
    }
}

// Permission Helper
function hasPermission($page) {
    global $pdo;
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) return false;
    if ($_SESSION['role'] === 'admin') return true;
    if ($_SESSION['role'] !== 'sub_admin') return false;

    $stmt = $pdo->prepare("SELECT permissions FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $perms = $stmt->fetchColumn();
    
    if (!$perms) return false;
    if ($perms === 'all') return true;
    
    $allowed_pages = explode(',', $perms);
    return in_array($page, $allowed_pages);
}

// CSRF Protection
function generateToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkToken($token)
{
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Security Presence
$current_page = basename($_SERVER['PHP_SELF']);
if (file_exists(__DIR__ . '/includes/security.php')) {
    require_once __DIR__ . '/includes/security.php';
    if ($current_page !== 'setup.php' && $current_page !== 'maintenance.php' && $current_page !== 'check_maintenance.php') {
        enforceSecurity($pdo);
    }
}
?>