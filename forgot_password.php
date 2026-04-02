<?php
require_once 'config.php';
require_once 'includes/mailer.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['forgot_password'])) {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $error = 'Email address is required.';
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate a secure token
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            // Clear old tokens for this email
            $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);

            // Save new token
            $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$email, $token, $expires_at]);

            // Create Reset Link (using base URL)
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $dir = dirname($_SERVER['PHP_SELF']);
            $reset_link = "{$protocol}://{$host}{$dir}/reset_password.php?token={$token}";

            // Email Content
            $subject = "🔒 Password Reset Request - Open Lms";
            $mail_body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; padding: 30px; border-radius: 15px;'>
                    <div style='text-align: center; margin-bottom: 30px;'>
                        <h2 style='color: #4f46e5; margin: 0;'>Open Lms Security</h2>
                    </div>
                    <p style='color: #333;'>Hello <strong>{$user['name']}</strong>,</p>
                    <p style='color: #555; line-height: 1.6;'>A request was made to reset your password. If you didn't do this, you can safely ignore this email.</p>
                    <p style='color: #555;'>This link is valid for **10 minutes only**.</p>
                    <div style='text-align: center; margin: 35px 0;'>
                        <a href='{$reset_link}' style='background: #4f46e5; color: white; padding: 15px 30px; text-decoration: none; border-radius: 10px; font-weight: bold; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);'>Reset My Password</a>
                    </div>
                    <p style='color: #888; font-size: 0.8rem;'>If the button above doesn't work, copy and paste this link into your browser:<br>{$reset_link}</p>
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 30px 0;'>
                    <p style='color: #999; font-size: 0.75rem; text-align: center;'>&copy; " . date('Y') . " Open Lms. All rights reserved.</p>
                </div>
            ";

            if (sendLMSMail($email, $subject, $mail_body)) {
                $message = 'A secure reset link has been sent to your email. Check your inbox (and spam).';
            } else {
                $error = 'Email sending failed. Please contact your administrator.';
            }
        } else {
            // Secretly say it's sent to prevent email enumeration, or show error if preferred.
            // User requested check email available, so I'll show error if not found.
            $error = 'This email address is not registered in our system.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Open Lms</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --dark: #1e293b;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: #6366f1;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }

        .box {
            background: white;
            padding: 50px;
            border-radius: 30px;
            width: 100%;
            max-width: 450px;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .logo {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 20px;
        }

        h2 {
            margin-bottom: 15px;
            font-weight: 800;
        }

        p {
            color: #64748b;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .form-control {
            width: 100%;
            padding: 16px;
            border-radius: 12px;
            border: 2px solid #f1f5f9;
            background: #f8fafc;
            font-family: inherit;
            margin-bottom: 20px;
            transition: 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: #fff;
        }

        .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            background: var(--primary);
            color: white;
            font-weight: 800;
            cursor: pointer;
            transition: 0.3s;
        }

        .btn:hover {
            filter: brightness(1.1);
            transform: translateY(-2px);
        }

        .msg {
            background: #dcfce7;
            color: #166534;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .err {
            background: #fee2e2;
            color: #ef4444;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .back {
            display: block;
            margin-top: 25px;
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .back:hover {
            color: var(--primary);
        }
    </style>
</head>

<body>
    <div class="box">
        <div class="logo"><i class="fas fa-key"></i></div>
        <h2>Forgot Password?</h2>
        <p>Enter your email address and we'll send you a link to reset your password safely.</p>

        <?php if ($message): ?>
            <div class="msg"><?= $message ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="err"><?= $error ?></div>
        <?php endif; ?>

        <?php if (!$message): ?>
            <form method="POST">
                <input type="email" name="email" class="form-control" placeholder="your@email.com" required autofocus>
                <button type="submit" name="forgot_password" class="btn">Send Reset Link</button>
            </form>
        <?php endif; ?>

        <a href="index.php" class="back"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
</body>

</html>