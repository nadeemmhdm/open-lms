<?php
require_once 'config.php';

$token = $_GET['token'] ?? '';
$error = '';
$message = '';
$email = '';

if (empty($token)) {
    redirect('index.php');
}

// Verify Token
$stmt = $pdo->prepare("SELECT email, expires_at FROM password_resets WHERE token = ?");
$stmt->execute([$token]);
$reset_req = $stmt->fetch();

if (!$reset_req) {
    $error = 'This reset link is invalid or has already been used.';
} else {
    $expires = strtotime($reset_req['expires_at']);
    if (time() > $expires) {
        $error = 'This reset link has expired (it was only valid for 10 minutes).';
    } else {
        $email = $reset_req['email'];

        // Handle Form Submission
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if (strlen($new_password) < 6) {
                $error = 'Password must be at least 6 characters long.';
            } elseif ($new_password !== $confirm_password) {
                $error = 'Passwords do not match.';
            } else {
                // Update User Password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
                $stmt->execute([$hashed_password, $email]);

                // Delete Token
                $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);

                $message = 'Your password has been successfully reset! You can now login with your new password.';
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
    <title>Set New Password - Open Lms</title>
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

        h2 {
            margin-bottom: 25px;
            font-weight: 800;
            font-size: 1.8rem;
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
            font-size: 1rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: #fff;
        }

        .btn {
            width: 100%;
            padding: 18px;
            border: none;
            border-radius: 16px;
            background: var(--primary);
            color: white;
            font-weight: 800;
            cursor: pointer;
            transition: 0.3s;
            font-size: 1.1rem;
        }

        .btn:hover {
            filter: brightness(1.1);
            transform: translateY(-2px);
        }

        .msg {
            background: #dcfce7;
            color: #166534;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            line-height: 1.4;
            font-weight: 600;
        }

        .err {
            background: #fee2e2;
            color: #ef4444;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
    </style>
</head>

<body>
    <div class="box">
        <h2>Secure Your Account</h2>

        <?php if ($message): ?>
            <div class="msg">
                <i class="fas fa-check-circle" style="font-size: 2rem; display: block; margin-bottom: 15px;"></i>
                <?= $message ?>
            </div>
            <a href="index.php" class="btn" style="display: block; text-decoration: none; margin-top: 20px;">Proceed to
                Login</a>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="err"><?= $error ?></div>
                <a href="forgot_password.php" class="btn" style="display: block; text-decoration: none;">Request New Link</a>
            <?php else: ?>
                <p style="color: #64748b; margin-bottom: 30px;">Setting up a new password for
                    <br><strong><?= htmlspecialchars($email) ?></strong></p>
                <form method="POST">
                    <input type="password" name="new_password" class="form-control" placeholder="New Secret Password" required
                        autofocus>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Confirm Secret Password"
                        required>
                    <button type="submit" name="reset_password" class="btn">Update & Login</button>
                </form>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</body>

</html>