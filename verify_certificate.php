<?php
require_once 'config.php';

$cert_id = $_GET['cert_id'] ?? '';
$error = '';
$certificate = null;

if (!empty($cert_id)) {
    // ID Format: LMS-xxxxxx
    $numeric_id = (int)str_replace('LMS-', '', $cert_id);
    
    $stmt = $pdo->prepare("SELECT se.*, e.title as exam_title, u.name as student_name 
                           FROM student_exams se 
                           JOIN exams e ON se.exam_id = e.id 
                           JOIN users u ON se.student_id = u.id 
                           WHERE se.id = ? AND se.is_result_published = 1 AND e.is_certificate_exam = 1");
    $stmt->execute([$numeric_id]);
    $certificate = $stmt->fetch();
    
    if (!$certificate) {
        $error = "This certificate ID is invalid or not yet published.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Certificate - Open LMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Outfit', sans-serif; 
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }
        .portal-bg {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            min-height: 100vh;
            padding: 80px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .container {
            width: 100%;
            max-width: 700px;
        }
        .header {
            text-align: center;
            margin-bottom: 40px;
        }
        .logo-icon {
            width: 80px; height: 80px;
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2.5rem;
            animation: fadeInDown 0.8s ease-out;
        }
        .card {
            background: white;
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.05);
            padding: 50px;
            animation: fadeInUp 0.8s ease-out;
        }
        .success-icon {
            width: 60px; height: 60px;
            background: #dcfce7;
            color: #166534;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 1.5rem;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 25px;
            margin-top: 30px;
            background: #f8fafc;
            padding: 30px;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
            text-align: left;
        }
        .grid-item { padding: 10px; }
        .label {
            font-size: 0.7rem;
            color: #94a3b8;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        .value {
            font-size: 1.1rem;
            font-weight: 800;
            color: #1e293b;
        }
        .status-badge {
            color: #16a34a;
            font-weight: 900;
        }
        .btn {
            background: #4f46e5;
            color: white;
            border: none;
            padding: 18px 30px;
            border-radius: 16px;
            font-weight: 800;
            font-size: 1rem;
            cursor: pointer;
            transition: 0.3s;
        }
        .input {
            width: 100%;
            padding: 18px 25px;
            border-radius: 16px;
            border: 2px solid #e2e8f0;
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 20px;
            outline: none;
        }
        .input:focus { border-color: #4f46e5; }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 600px) {
            .card { padding: 30px 20px; }
            .grid { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="portal-bg">
        <div class="container">
            <div class="header">
                <div class="logo-icon">🎓</div>
                <h1 style="font-weight: 900; font-size: 2.2rem; margin-bottom: 5px;">Verification Portal</h1>
                <p style="color: #64748b; font-weight: 600;">Authentic Credential Validation Service</p>
            </div>

            <div class="card">
                <?php if (!$certificate || $error): ?>
                    <div style="text-align: center;">
                        <h2 style="margin-bottom: 30px; font-weight: 800;">Validate Certificate</h2>
                        <?php if ($error): ?>
                            <div style="background: #fef2f2; color: #991b1b; padding: 15px; border-radius: 12px; margin-bottom: 25px; font-weight: 700; border: 1px solid #fee2e2;">
                                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
                            </div>
                        <?php endif; ?>
                        <form action="verify_certificate.php" method="GET">
                            <input type="text" name="cert_id" class="input" placeholder="Enter Serial Number (e.g. LMS-000001)" value="<?= htmlspecialchars($cert_id) ?>" required>
                            <button type="submit" class="btn" style="width: 100%;">Verify Certificate</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="text-align: center;">
                        <div class="success-icon"><i class="fas fa-check"></i></div>
                        <h2 style="font-weight: 900; font-size: 1.8rem; margin-bottom: 5px;">Validated Authentic</h2>
                        <p style="color: #166534; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; font-size: 0.8rem; margin-bottom: 35px;">Credential Verified by Open LMS Authority</p>

                        <div class="grid">
                            <div style="grid-column: 1 / -1; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">
                                <div class="label">Achiever Name</div>
                                <div class="value" style="font-size: 1.4rem;"><?= htmlspecialchars($certificate['student_name']) ?></div>
                            </div>
                            <div class="grid-item">
                                <div class="label">Academic Program</div>
                                <div class="value"><?= htmlspecialchars($certificate['exam_title']) ?></div>
                            </div>
                            <div class="grid-item">
                                <div class="label">Serial ID</div>
                                <div class="value"><?= $cert_id ?></div>
                            </div>
                            <div class="grid-item">
                                <div class="label">Issue Date</div>
                                <div class="value"><?= date('M d, Y', strtotime($certificate['submit_time'])) ?></div>
                            </div>
                            <div class="grid-item">
                                <div class="label">Status</div>
                                <div class="value status-badge">VERIFIED SUCCESS</div>
                            </div>
                        </div>

                        <a href="verify_certificate.php" style="display: inline-block; margin-top: 35px; color: #64748b; font-weight: 700; text-decoration: none; border-bottom: 2px solid #e2e8f0;">Switch to Another ID</a>
                    </div>
                <?php endif; ?>
            </div>

            <div style="text-align: center; margin-top: 40px; color: #94a3b8; font-size: 0.85rem; font-weight: 600;">
                © <?= date('Y') ?> Open LMS Global Education. All rights reserved.<br>
                For official inquiries: academic@openlms.com
            </div>
        </div>
    </div>
</body>
</html>
