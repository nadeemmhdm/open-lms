<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = (int)$_SESSION['user_id'];
$attempt_id = $_GET['attempt_id'] ?? 0;
$exam_id = $_GET['exam_id'] ?? 0;

if (!$attempt_id && !$exam_id) redirect('exams.php');

// Fetch Attempt Info (and verify ownership)
if ($attempt_id) {
    $stmt = $pdo->prepare("SELECT se.*, e.title as exam_title, e.id as exam_id, e.is_certificate_exam, b.name as batch_name 
                           FROM student_exams se 
                           JOIN exams e ON se.exam_id = e.id 
                           LEFT JOIN batches b ON e.batch_id = b.id 
                           WHERE se.id = ? AND se.student_id = ? AND se.is_result_published = 1");
    $stmt->execute([$attempt_id, $user_id]);
} else {
    // Fetch latest published attempt for this exam
    $stmt = $pdo->prepare("SELECT se.*, e.title as exam_title, e.id as exam_id, e.is_certificate_exam, b.name as batch_name 
                           FROM student_exams se 
                           JOIN exams e ON se.exam_id = e.id 
                           LEFT JOIN batches b ON e.batch_id = b.id 
                           WHERE se.exam_id = ? AND se.student_id = ? AND se.is_result_published = 1 
                           ORDER BY se.id DESC LIMIT 1");
    $stmt->execute([$exam_id, $user_id]);
}
$attempt = $stmt->fetch();

if (!$attempt) {
    // Either not yours or not published yet
    redirect('exams.php');
}

$attempt_id = $attempt['id']; // Important for fetching questions

// Fetch Questions and Answers
$stmt = $pdo->prepare("SELECT eq.*, sa.selected_option, sa.answer_text, sa.marks_awarded 
                       FROM exam_questions eq 
                       LEFT JOIN student_answers sa ON eq.id = sa.question_id AND sa.student_exam_id = ? 
                       WHERE eq.exam_id = ? ORDER BY eq.id ASC");
$stmt->execute([$attempt_id, $attempt['exam_id']]);
$questions = $stmt->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 25px;">
    <a href="exams.php" style="color: #64748b; font-weight: 600;"><i class="fas fa-arrow-left"></i> Back to My Exams</a>
</div>

<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; flex-wrap: wrap; gap: 20px;">
    <div>
        <h2 style="font-weight: 800; color: var(--dark); margin: 0;">Exam Transcript</h2>
        <div style="margin-top: 10px; color: #64748b; font-weight: 600;"><i class="fas fa-check-circle" style="color: var(--success);"></i> Verified Evaluation</div>
        <div style="color: #64748b;"><i class="fas fa-file-alt"></i> <?= htmlspecialchars($attempt['exam_title']) ?> - <?= htmlspecialchars($attempt['batch_name']) ?></div>
    </div>
    
    <div class="white-card" style="padding: 20px 40px; border-radius: 20px; background: #f0f9ff; border: 1px solid #bae6fd; text-align: center;">
        <div style="font-size: 0.8rem; font-weight: 800; color: #0369a1; text-transform: uppercase;">Final Score</div>
        <div style="font-size: 2.2rem; font-weight: 900; color: #0284c7;">
            <?= (float)$attempt['score'] ?>
            <span style="font-size: 1.2rem; color: #7dd3fc; font-weight: 800;">/ <?= $attempt['total_marks'] ?></span>
        </div>
    </div>
</div>

<!-- Download/Share Helper -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<?php if ($attempt['is_certificate_exam']): ?>
<?php 
$cert_id = "LMS-" . str_pad($attempt['id'], 6, '0', STR_PAD_LEFT);
$verify_url = "https://" . $_SERVER['HTTP_HOST'] . str_replace('student/view_result.php', 'verify_certificate.php', $_SERVER['PHP_SELF']) . "?cert_id=" . $cert_id;
$share_text = "I am proud to share that I've successfully completed the assessment for " . $attempt['exam_title'] . " on Open LMS! %0A%0AVerify my achievement here: " . $verify_url;
$linkedin_url = "https://www.linkedin.com/sharing/share-offsite/?url=" . urlencode($verify_url);
?>

<div style="display: flex; justify-content: flex-end; gap: 15px; margin-bottom: 20px;" class="no-print">
    <button onclick="downloadCertificate()" class="btn" style="background: #0f172a; color: white; border-radius: 12px; font-weight: 700; padding: 10px 20px;">
        <i class="fas fa-file-image"></i> Download Image
    </button>
    <a href="<?= $linkedin_url ?>" target="_blank" class="btn" style="background: #0077b5; color: white; border-radius: 12px; font-weight: 700; padding: 10px 20px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
        <i class="fab fa-linkedin"></i> Share on LinkedIn
    </a>
    <button onclick="window.print()" class="btn" style="background: #fbbf24; color: #78350f; border-radius: 12px; font-weight: 700; padding: 10px 20px;">
        <i class="fas fa-print"></i> Print Certificate
    </button>
</div>

<div id="certificate-wrapper" style="padding: 10px; background: white; border-radius: 20px; box-shadow: 0 15px 40px rgba(0,0,0,0.08); margin-bottom: 50px;">
    <div id="certificate-area" style="background: white; border: 15px solid #fafaf9; outline: 3px double #e7e5e4; padding: 60px; min-height: 600px; position: relative; overflow: hidden; display: flex; flex-direction: column; align-items: center; text-align: center;">
        
        <!-- Decorative Elements -->
        <div style="position: absolute; top: -50px; left: -50px; width: 200px; height: 200px; background: radial-gradient(circle, #fef3c7 0%, transparent 70%); opacity: 0.5;"></div>
        <div style="position: absolute; right: 40px; bottom: 40px; width: 120px; height: 120px; opacity: 0.1; background: url('https://www.transparenttextures.com/patterns/natural-paper.png');"></div>

        <!-- System Branding -->
        <div style="margin-bottom: 40px;">
            <div style="font-size: 1.8rem; font-weight: 900; color: #1e293b; letter-spacing: -1px;">
                <i class="fas fa-graduation-cap" style="color: #4f46e5;"></i> Open LMs
            </div>
            <div style="width: 40px; height: 3px; background: #fbbf24; margin: 10px auto;"></div>
        </div>

        <h1 style="font-family: 'Georgia', serif; font-size: 2.8rem; font-style: italic; color: #92400e; margin-bottom: 10px; font-weight: 400;">Certificate of Achievement</h1>
        <p style="color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 5px; font-size: 0.8rem; margin-bottom: 40px;">This acknowledges that</p>

        <h2 style="font-size: 3.2rem; font-weight: 800; color: #1e293b; margin-bottom: 15px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; min-width: 400px;">
            <?= htmlspecialchars($_SESSION['name']) ?>
        </h2>

        <p style="color: #475569; font-size: 1.1rem; line-height: 1.8; max-width: 600px; margin-bottom: 40px;">
            has demonstrated exceptional proficiency and successfully completed the required assessment for the academic program:
            <br><strong style="color: #1e293b; font-size: 1.3rem;"><?= htmlspecialchars($attempt['exam_title']) ?></strong>
        </p>

        <!-- Seal & Info Grid -->
        <div style="width: 100%; display: grid; grid-template-columns: 1fr 1fr; align-items: flex-end; margin-top: auto; padding-top: 50px; border-top: 1px solid #f1f5f9;">
            <div style="text-align: left; padding-left: 20px;">
                <div style="font-weight: 800; color: #1e293b; font-size: 1.1rem;"><?= date('F d, Y', strtotime($attempt['submit_time'])) ?></div>
                <div style="font-size: 0.7rem; color: #94a3b8; font-weight: 800; text-transform: uppercase; margin-top: 5px; letter-spacing: 2px;">Date of Issuance</div>
            </div>

            <div style="text-align: right; padding-right: 20px;">
                <a href="<?= $verify_url ?>" target="_blank" style="text-decoration: none;">
                    <div style="font-weight: 800; color: #1e293b; font-size: 1.1rem;"><?= $cert_id ?></div>
                    <div style="font-size: 0.7rem; color: #94a3b8; font-weight: 800; text-transform: uppercase; margin-top: 5px; letter-spacing: 2px;">Verification Serial <i class="fas fa-external-link-alt" style="font-size: 0.6rem; margin-left: 5px;"></i></div>
                </a>
            </div>
        </div>

        <div style="margin-top: 30px; font-size: 0.65rem; color: #cbd5e1; text-transform: uppercase; font-weight: 700; letter-spacing: 3px;">
            Authentic Digital Credential • Open LMS Academic Board
        </div>
    </div>
</div>

<script>
function downloadCertificate() {
    const btn = event.currentTarget;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    btn.disabled = true;

    html2canvas(document.querySelector("#certificate-area"), {
        scale: 2,
        useCORS: true,
        logging: false,
        backgroundColor: "#ffffff"
    }).then(canvas => {
        const link = document.createElement('a');
        link.download = 'Certificate-<?= $cert_id ?>.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
        
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}
</script>
<?php endif; ?>

<div class="questions-transcript">
    <?php foreach ($questions as $index => $q): 
        $is_mcq = ($q['question_type'] == 'mcq');
        $is_correct = ($is_mcq && $q['selected_option'] == $q['correct_option']);
        $status_bg = $is_mcq ? ($is_correct ? '#f0fdf4' : '#fef2f2') : '#f8fafc';
        $status_border = $is_mcq ? ($is_correct ? '#bbf7d0' : '#fecaca') : '#f1f5f9';
    ?>
        <div class="white-card" style="background: white; padding: 25px; border-radius: 24px; margin-bottom: 30px; border: 1px solid <?= $status_border ?>; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div style="max-width: 80%;">
                    <h3 style="font-weight: 800; color: #1e293b; font-size: 1.1rem; line-height: 1.5; margin-bottom: 15px;">
                        Q<?= $index + 1 ?>: <?= htmlspecialchars($q['question_text']) ?>
                    </h3>
                </div>
                
                <div style="text-align: right; background: <?= $status_bg ?>; padding: 10px 15px; border-radius: 12px; border: 1px solid <?= $status_border ?>;">
                    <span style="font-size: 0.75rem; font-weight: 800; color: #64748b; display: block;">POINTS</span>
                    <strong style="font-size: 1.2rem; font-weight: 900; color: var(--primary);">
                        <?php 
                        if (!$is_mcq && $q['marks_awarded'] === null) echo "<span style='font-size:0.8rem; color:#94a3b8;'>GRADED PENDING</span>";
                        else echo (float)($q['marks_awarded'] ?? 0); 
                        ?>
                        / <?= $q['marks'] ?>
                    </strong>
                </div>
            </div>

            <div style="background: #f8fafc; border-radius: 15px; padding: 20px;">
                <?php if ($is_mcq): ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
                        <?php 
                        $opts = ['a' => $q['option_a'], 'b' => $q['option_b'], 'c' => $q['option_c'], 'd' => $q['option_d']];
                        foreach ($opts as $key => $val):
                            $selected = ($q['selected_option'] == $key);
                            $correct = ($q['correct_option'] == $key);
                            $bg = 'white'; $border = '#e2e8f0'; $txt = '#475569';
                            if ($correct) { $bg = '#dcfce7'; $border = '#86efac'; $txt = '#166534'; }
                            if ($selected && !$correct) { $bg = '#fee2e2'; $border = '#fca5a5'; $txt = '#991b1b'; }
                        ?>
                            <div style="padding: 15px; border-radius: 12px; background: <?= $bg ?>; border: 1px solid <?= $border ?>; color: <?= $txt ?>; font-weight: <?= ($selected || $correct) ? '700' : '500' ?>; display: flex; align-items: center; gap: 10px;">
                                <div style="width: 25px; height: 25px; border-radius: 50%; border: 2px solid currentColor; display: flex; align-items: center; justify-content: center; font-size: 0.75rem;">
                                    <?= strtoupper($key) ?>
                                </div>
                                <span><?= htmlspecialchars($val) ?></span>
                                <?php if($selected): ?> <i class="fas <?= $is_correct ? 'fa-check-circle' : 'fa-times-circle' ?>" style="margin-left: auto;"></i> <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="margin-top: 5px;">
                        <div style="font-size: 0.75rem; font-weight: 800; color: #94a3b8; margin-bottom: 12px; text-transform: uppercase;">Your Written Response:</div>
                        <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; font-size: 1rem; line-height: 1.7; color: #334155; white-space: pre-wrap;"><?= htmlspecialchars($q['answer_text'] ?: 'No response provided.') ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<style>
@media print {
    /* Hide everything by default */
    body * {
        visibility: hidden;
    }
    /* Show ONLY the certificate and its content */
    #certificate-wrapper, #certificate-wrapper * {
        visibility: visible;
    }
    #certificate-wrapper {
        position: absolute;
        left: 0;
        top: 0;
        width: 100% !important;
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
    }
    #certificate-area {
        width: 100% !important;
        min-height: auto !important;
        background: white !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    /* Hide utility elements during print */
    .no-print, #print-btn {
        display: none !important;
    }
    .sidebar, .header, .footer, nav, aside, header, footer, .questions-transcript, a[href="exams.php"] {
        display: none !important;
    }
}

@media (max-width: 768px) {
    .questions-transcript .white-card { padding: 15px; }
}
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>
