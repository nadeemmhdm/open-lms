<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'sub_admin') {
        redirect('admin/index.php');
    } else {
        redirect('student/index.php');
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $token = $_POST['csrf_token'] ?? '';

    if (!checkToken($token)) {
        $error = 'Security session expired. Please refresh.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        // Check Login Cooldown
        $cooldown = checkLoginCooldown($pdo, $email);
        if ($cooldown > 0) {
            $error = "Too many failed attempts. Please wait $cooldown minutes.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Reset cooldown
                resetLoginAttempts($pdo, $email);
                
                // Restart session to get clean ID
                session_regenerate_id(true);
                $new_sid = session_id();
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['name'];

                // Device Detection
                $isMobile = preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $_SERVER["HTTP_USER_AGENT"]);
                $device_col = $isMobile ? 'mobile_session_id' : 'desktop_session_id';
                $_SESSION['device_type'] = $isMobile ? 'mobile' : 'desktop';

                // First Login Welcome notification
                if ($user['role'] == 'student' && $user['login_count'] == 0) {
                    $welcome_msg = "Welcome to the LMS, " . htmlspecialchars($user['name']) . "! We're glad to have you here. Explore your courses and start learning today.";
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, target_role) VALUES (?, '🎉 Welcome to LMS!', ?, 'student')")->execute([$user['id'], $welcome_msg]);
                }

                // Update user tracking
                $pdo->prepare("UPDATE users SET $device_col = ?, login_count = login_count + 1, last_access = NOW() WHERE id = ?")->execute([$new_sid, $user['id']]);

                if ($user['role'] == 'admin' || $user['role'] == 'sub_admin') {
                    redirect('admin/index.php');
                } else {
                    redirect('student/index.php');
                }
            } else {
                recordLoginAttempt($pdo, $email);
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Open Lms</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --secondary: #818cf8;
            --dark: #1e293b;
            --light: #f8fafc;
            --accent: #34d399;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 50px;
            border-radius: 30px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            width: 100%;
            max-width: 450px;
            text-align: center;
            position: relative;
            transform: translateY(0);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 35px 60px -15px rgba(0, 0, 0, 0.3);
        }

        .logo {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .logo i {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        h2 {
            color: var(--dark);
            margin-bottom: 10px;
            font-weight: 800;
            font-size: 1.8rem;
        }

        p {
            color: #64748b;
            margin-bottom: 35px;
            font-size: 0.95rem;
        }

        .form-group {
            margin-bottom: 25px;
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
            color: #475569;
            font-size: 0.85rem;
            padding-left: 5px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 20px;
            color: #94a3b8;
            font-size: 1.1rem;
            transition: 0.3s;
        }

        .form-control {
            width: 100%;
            padding: 16px 20px 16px 55px;
            border-radius: 16px;
            border: 2px solid #f1f5f9;
            background: #f8fafc;
            font-family: inherit;
            font-size: 1rem;
            transition: all 0.3s ease;
            color: var(--dark);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .form-control:focus+i {
            color: var(--primary);
        }

        .btn-login {
            width: 100%;
            padding: 18px;
            border: none;
            border-radius: 18px;
            background: linear-gradient(to right, #4f46e5, #7c3aed);
            color: white;
            font-size: 1.1rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3);
            margin-top: 10px;
        }

        .btn-login:hover {
            transform: scale(1.02);
            box-shadow: 0 20px 25px -5px rgba(79, 70, 229, 0.4);
            filter: brightness(1.1);
        }

        .error-msg {
            background: #fee2e2;
            color: #ef4444;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-5px);
            }

            75% {
                transform: translateX(5px);
            }
        }

        .forgot-link {
            display: block;
            margin-top: 25px;
            color: #64748b;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: 0.3s;
        }

        .forgot-link:hover {
            color: var(--primary);
        }

        .footer-text {
            margin-top: 30px;
            font-size: 0.85rem;
            color: #94a3b8;
        }

        .footer-text a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
        }

        /* Background Shapes */
        .shape {
            position: absolute;
            z-index: -1;
            filter: blur(80px);
            opacity: 0.4;
            border-radius: 50%;
        }

        .shape-1 {
            width: 400px;
            height: 400px;
            background: #818cf8;
            top: -100px;
            left: -100px;
        }

        .shape-2 {
            width: 300px;
            height: 300px;
            background: #c084fc;
            bottom: -50px;
            right: -50px;
        }
    </style>
</head>

<body>
    <div class="shape shape-1"></div>
    <div class="shape shape-2"></div>

    <div class="login-container">
        <div class="logo">
            <i class="fas fa-graduation-cap"></i>
            <span>Open LMs</span>
        </div>

        <h2>Welcome Back</h2>
        <p>Login to access your high-speed learning portal</p>

        <?php if ($error): ?>
            <div class="error-msg">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">
            <div class="form-group">
                <label>Email Address</label>
                <div class="input-wrapper">
                    <input type="email" name="email" class="form-control" placeholder="name@example.com" required
                        autofocus>
                    <i class="fas fa-envelope"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Secured Password</label>
                <div class="input-wrapper">
                    <input type="password" name="password" class="form-control" placeholder="Your secret password"
                        required>
                    <i class="fas fa-shield-alt"></i>
                </div>
            </div>

            <button type="submit" name="login" class="btn-login">
                Access Dashboard <i class="fas fa-arrow-right" style="margin-left: 10px;"></i>
            </button>
        </form>

        <div style="margin-top: 25px; display: flex; justify-content: center; gap: 20px;">
            <a href="forgot_password.php" class="forgot-link" style="margin-top: 0;">Forgot Password?</a>
            <span style="color: #cbd5e1;">|</span>
            <a href="verify_certificate.php" class="forgot-link" style="margin-top: 0; color: var(--primary);">Verify Certificate</a>
        </div>

        <div class="footer-text">
            Don't have an account? <a href="#">Contact Support</a>
        </div>
    </div>
</body>

</html>