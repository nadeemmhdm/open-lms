<?php
require_once 'config.php';

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die("Invalid access link.");
}

// Silent cleanup of used links older than 7 days
try {
    $pdo->query("DELETE FROM magic_links WHERE is_used = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("SELECT * FROM magic_links WHERE token = ? AND is_used = 0 AND expires_at > NOW()");
    $stmt->execute([$token]);
    $link = $stmt->fetch();

    if (!$link) {
        die("This link is invalid, expired, or has already been used.");
    }

    $action = $link['action'];
    $data = !empty($link['data']) ? json_decode($link['data'], true) : [];
    $user_id = $link['user_id'];

    // Mark link as used for one-time access links
    $pdo->prepare("UPDATE magic_links SET is_used = 1 WHERE id = ?")->execute([$link['id']]);

    // If user_id is provided, log them in automatically
    if ($user_id) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['device_type'] = 'desktop'; // Default

            // Update session ID in DB to support device-based session management
            $col = 'desktop_session_id';
            $pdo->prepare("UPDATE users SET $col = ? WHERE id = ?")->execute([session_id(), $user['id']]);
        } else {
            die("User account is inactive or not found.");
        }
    }

    // Handle Actions
    switch ($action) {
        case 'login':
            redirect('student/index.php');
            break;

        case 'reset_password':
            if (isset($data['new_password'])) {
                $hashed = password_hash($data['new_password'], PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user_id]);
                $_SESSION['success_message'] = "Password reset successful. You are now logged in.";
                redirect('student/index.php');
            } else {
                redirect('reset_password.php?token=' . $token); // Fallback to standard reset if data missing
            }
            break;

        case 'enroll':
            if (isset($data['course_ids'])) {
                $course_ids = $data['course_ids'];
                $voucher_id = $data['voucher_id'] ?? null;
                foreach ($course_ids as $cid) {
                    $pdo->prepare("INSERT IGNORE INTO student_courses (student_id, course_id, access_type, voucher_id) VALUES (?, ?, ?, ?)")
                        ->execute([$user_id, $cid, $voucher_id ? 'voucher' : 'free', $voucher_id]);
                    
                    if ($voucher_id) {
                        $pdo->prepare("UPDATE vouchers SET is_used = 1, student_id = ?, used_at = NOW() WHERE id = ?")
                            ->execute([$user_id, $voucher_id]);
                    }
                }
                $_SESSION['success_message'] = "You have been successfully enrolled in the course(s).";
            }
            redirect('student/index.php');
            break;

        case 'exam_access':
            if (isset($data['exam_id'])) {
                $pdo->prepare("INSERT IGNORE INTO student_private_exams (student_id, exam_id) VALUES (?, ?)")
                    ->execute([$user_id, $data['exam_id']]);
                $_SESSION['success_message'] = "Private exam access granted.";
            }
            redirect('student/my_exams.php');
            break;

        default:
            die("Unknown action.");
    }

} catch (PDOException $e) {
    die("Error processing access link: " . $e->getMessage());
}
