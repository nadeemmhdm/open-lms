<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

$attempt_id = $_GET['attempt_id'] ?? 0;
if (!$attempt_id) redirect('exams.php');

// Fetch Attempt Info with LEFT JOIN for resilience
$stmt = $pdo->prepare("SELECT se.*, u.name as student_name, e.title as exam_title, e.id as exam_id, b.name as batch_name 
                       FROM student_exams se 
                       LEFT JOIN users u ON se.student_id = u.id 
                       LEFT JOIN exams e ON se.exam_id = e.id 
                       LEFT JOIN batches b ON e.batch_id = b.id 
                       WHERE se.id = ?");
$stmt->execute([$attempt_id]);
$attempt = $stmt->fetch();

if (!$attempt) {
    // Redirect back but with an error if possible (fallback to simple redirect)
    redirect('exams.php?error=Attempt data not found or database link broken.');
}

// Handle Manual Grading
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_grading'])) {
    $marks = $_POST['marks_awarded']; // Associative: [question_id => mark]
    $total_score = 0;
    $total_possible = 0;

    foreach ($marks as $q_id => $m) {
        $m = (float)$m;
        $stmt = $pdo->prepare("UPDATE student_answers SET marks_awarded = ? WHERE student_exam_id = ? AND question_id = ?");
        $stmt->execute([$m, $attempt_id, $q_id]);
        $total_score += $m;
        
        // Get question value for total mapping
        $stmt = $pdo->prepare("SELECT marks FROM exam_questions WHERE id = ?");
        $stmt->execute([$q_id]);
        $total_possible += (int)$stmt->fetchColumn();
    }

    // Update overall score
    $stmt = $pdo->prepare("UPDATE student_exams SET score = ?, total_marks = ? WHERE id = ?");
    $stmt->execute([$total_score, $total_possible, $attempt_id]);
    
    $attempt['score'] = $total_score;
    $attempt['total_marks'] = $total_possible;
    $message = "Marks and feedback updated successfully!";
}

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
    <a href="exam_submissions.php?exam_id=<?= $attempt['exam_id'] ?>" style="color: #64748b; font-weight: 600;"><i class="fas fa-arrow-left"></i> Back to Submissions</a>
</div>

<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; flex-wrap: wrap; gap: 20px;">
    <div>
        <h2 style="font-weight: 800; color: var(--dark); margin: 0;">Evaluation Script</h2>
        <div style="margin-top: 10px; color: #64748b; font-weight: 600;">Candidate: <span style="color: var(--primary);"><?= htmlspecialchars($attempt['student_name']) ?></span></div>
        <div style="color: #64748b;"><i class="fas fa-file-alt"></i> <?= htmlspecialchars($attempt['exam_title']) ?> - <?= htmlspecialchars($attempt['batch_name']) ?></div>
    </div>
    
    <div class="white-card" style="padding: 20px 40px; border-radius: 20px; background: #f8fafc; border: 1px solid #e1e7ef; text-align: center;">
        <div style="font-size: 0.8rem; font-weight: 800; color: #94a3b8; text-transform: uppercase;">Overall Score</div>
        <div style="font-size: 2.2rem; font-weight: 900; color: var(--primary);">
            <?= $attempt['score'] !== null ? (float)$attempt['score'] : '--' ?>
            <span style="font-size: 1.2rem; color: #cbd5e1; font-weight: 800;">/ <?= $attempt['total_marks'] ?: array_sum(array_column($questions, 'marks')) ?></span>
        </div>
    </div>
</div>

<?php if (isset($message)): ?>
    <div class="badge badge-success" style="display: block; padding: 15px; margin-bottom: 25px; border-radius: 12px; font-weight: 700;">
        <i class="fas fa-check-circle"></i> <?= $message ?>
    </div>
<?php endif; ?>

<form method="POST">
    <?php foreach ($questions as $index => $q): 
        $auto_mark = 0;
        $is_mcq_correct = false;
        if($q['question_type'] == 'mcq') {
            $is_mcq_correct = ($q['selected_option'] == $q['correct_option']);
            $auto_mark = $is_mcq_correct ? $q['marks'] : 0;
        }
        $curr_mark = $q['marks_awarded'] ?? ($q['question_type'] == 'mcq' ? $auto_mark : 0);
    ?>
        <div class="white-card" style="background: white; padding: 25px; border-radius: 24px; margin-bottom: 30px; border: 1px solid <?= $q['question_type'] == 'mcq' ? ($is_mcq_correct ? '#dcfce7' : '#fee2e2') : '#f1f5f9' ?>; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div style="max-width: 80%;">
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 6px; background: #f1f5f9; font-size: 0.75rem; font-weight: 800; color: #64748b; margin-bottom: 15px;">
                        <?= strtoupper($q['question_type']) ?> QUESTION (<?= $q['marks'] ?> PTS)
                    </span>
                    <h3 style="font-weight: 800; color: #1e293b; font-size: 1.2rem; line-height: 1.5;">
                        Q<?= $index + 1 ?>: <?= htmlspecialchars($q['question_text']) ?>
                    </h3>
                </div>
                
                <div style="text-align: right;">
                    <label style="font-size: 0.75rem; font-weight: 800; color: #94a3b8; display: block; margin-bottom: 8px;">AWARDED MARKS</label>
                    <input type="number" step="0.5" max="<?= $q['marks'] ?>" min="0" 
                           name="marks_awarded[<?= $q['id'] ?>]" 
                           value="<?= (float)$curr_mark ?>" 
                           style="width: 80px; text-align: center; padding: 10px; border: 2px solid var(--primary); border-radius: 12px; font-weight: 900; font-size: 1.1rem; color: var(--primary);">
                </div>
            </div>

            <div style="background: #f8fafc; border-radius: 15px; padding: 20px;">
                <?php if ($q['question_type'] == 'mcq'): ?>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <?php 
                        $opts = ['a' => $q['option_a'], 'b' => $q['option_b'], 'c' => $q['option_c'], 'd' => $q['option_d']];
                        foreach ($opts as $key => $val):
                            $is_selected = ($q['selected_option'] == $key);
                            $is_correct = ($q['correct_option'] == $key);
                            $clr = '#f1f5f9'; $txt_clr = '#475569';
                            if ($is_correct) { $clr = '#dcfce7'; $txt_clr = '#166534'; }
                            if ($is_selected && !$is_correct) { $clr = '#fee2e2'; $txt_clr = '#991b1b'; }
                        ?>
                            <div style="padding: 12px 20px; border-radius: 10px; background: <?= $clr ?>; color: <?= $txt_clr ?>; font-weight: <?= ($is_selected || $is_correct) ? '700' : '500' ?>; display: flex; justify-content: space-between;">
                                <span><?= strtoupper($key) ?>) <?= htmlspecialchars($val) ?></span>
                                <?php if ($is_selected): ?>
                                    <i class="fas <?= $is_mcq_correct ? 'fa-check' : 'fa-times' ?>" style="margin-left: 10px;"></i>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="margin-top: 5px;">
                        <div style="font-size: 0.75rem; font-weight: 800; color: #94a3b8; margin-bottom: 10px; text-transform: uppercase;">Student's Response:</div>
                        <div id="ans_<?= $q['id'] ?>" style="background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; font-size: 1.1rem; line-height: 1.7; min-height: 100px; white-space: pre-wrap; transition: 0.3s;"><?= htmlspecialchars($q['answer_text'] ?: 'No response provided.') ?></div>
                        
                        <div id="ai_res_<?= $q['id'] ?>" style="display: none; margin-top: 15px; padding: 15px; border-radius: 12px; background: #fdf4ff; border: 1px solid #fae8ff; color: #701a75;">
                             <i class="fas fa-robot"></i> <span class="ai-txt">AI Reviewing...</span>
                        </div>

                        <div style="margin-top: 15px; display: flex; align-items: center; gap: 15px;">
                            <button type="button" 
                                    onclick="evaluateWithAI('<?= $q['id'] ?>', `<?= addslashes($q['question_text']) ?>`)" 
                                    class="btn-ai-eval" 
                                    style="padding: 8px 20px; border-radius: 10px; border: none; background: linear-gradient(135deg, #a855f7, #6366f1); color: white; font-weight: 800; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.2s;">
                                <i class="fas fa-sparkles"></i> Evaluate with AI
                            </button>
                            <small id="ai_tip_<?= $q['id'] ?>" style="color: #94a3b8; display: none;">AI suggested score will appear above.</small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div style="position: sticky; bottom: 30px; display: flex; justify-content: center; margin-top: 40px; border-top: 1px solid #f1f5f9; padding-top: 25px;">
        <button type="submit" name="save_grading" class="btn btn-primary" style="padding: 18px 60px; border-radius: 50px; font-weight: 800; box-shadow: 0 10px 30px rgba(78, 84, 100, 0.4); font-size: 1.2rem;">
            <i class="fas fa-save" style="margin-right: 10px;"></i> Finalize Grading & Save Score
        </button>
    </div>
</form>

<script>
async function evaluateWithAI(qId, qText) {
    const resDiv = document.getElementById('ai_res_' + qId);
    const tip = document.getElementById('ai_tip_' + qId);
    const studentAns = document.getElementById('ans_' + qId).innerText;
    const scoreInput = document.querySelector(`input[name="marks_awarded[${qId}]"]`);
    const maxMarks = scoreInput.max;

    resDiv.style.display = 'block';
    resDiv.innerHTML = '<i class="fas fa-robot fa-spin"></i> <span class="ai-txt">Analyzing response quality...</span>';
    
    try {
        const response = await fetch('ai_evaluate_process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                question: qText,
                answer: studentAns,
                max_marks: maxMarks
            })
        });

        const data = await response.json();
        if (data.error) throw new Error(data.error);

        resDiv.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>AI Suggestion:</strong> <span style="font-size: 1.1rem; color: #701a75;">${data.score} / ${maxMarks}</span>
                    <p style="margin: 5px 0 0 0; font-size: 0.85rem; font-style: italic;">"${data.feedback}"</p>
                </div>
                <button type="button" onclick="document.querySelector('input[name=\"marks_awarded[${qId}]\"]').value = ${data.score}" 
                        style="background: #701a75; color: white; border: none; padding: 5px 12px; border-radius: 8px; font-size: 0.75rem; font-weight: 800; cursor: pointer;">
                    Apply Score
                </button>
            </div>
        `;
    } catch (e) {
        resDiv.style.background = '#fef2f2';
        resDiv.style.color = '#991b1b';
        resDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Error: ' + e.message;
    }
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
