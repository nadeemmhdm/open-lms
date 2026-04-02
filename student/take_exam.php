<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$exam_id = $_GET['id'] ?? 0;
if (!$exam_id) redirect('exams.php');

$user_id = $_SESSION['user_id'];

// 1. Fetch Exam Data
$stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if (!$exam) redirect('exams.php');

// 2. Check Enrollment & Time
$now = time();
$start = strtotime($exam['start_time']);
$end = $start + ($exam['duration_minutes'] * 60);

if ($now < $start || $now > $end) redirect('exams.php');

// 3. Check/Initialize Attempt
// Find an ongoing attempt first
$stmt = $pdo->prepare("SELECT * FROM student_exams WHERE student_id = ? AND exam_id = ? AND status = 'ongoing' ORDER BY id DESC LIMIT 1");
$stmt->execute([$user_id, $exam_id]);
$attempt = $stmt->fetch();

$fingerprint = md5($_SERVER['HTTP_USER_AGENT'] . $_SESSION['user_id']); 

if (!$attempt) {
    // No ongoing attempt - check if we can start a new one based on max_attempts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM student_exams WHERE student_id = ? AND exam_id = ? AND status = 'submitted'");
    $stmt->execute([$user_id, $exam_id]);
    $submitted_count = $stmt->fetchColumn();

    if ($submitted_count >= ($exam['max_attempts'] ?: 1)) {
        redirect('exams.php?error=max_attempts_reached');
    }

    // Initialize new attempt
    $stmt = $pdo->prepare("INSERT INTO student_exams (student_id, exam_id, start_time, status, device_fingerprint) VALUES (?, ?, NOW(), 'ongoing', ?)");
    $stmt->execute([$user_id, $exam_id, $fingerprint]);
    $attempt_id = $pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT * FROM student_exams WHERE id = ?");
    $stmt->execute([$attempt_id]);
    $attempt = $stmt->fetch();
} else {
    // Validating ongoing attempt
    if ($attempt['status'] == 'blocked') redirect('exams.php?error=blocked');
    if ($attempt['device_fingerprint'] !== $fingerprint) {
        die("Security Alert: Session active on another device.");
    }
    $attempt_id = $attempt['id'];
}

// 4. Fetch Questions & Shuffle
$stmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ?");
$stmt->execute([$exam_id]);
$questions = $stmt->fetchAll();

srand($attempt_id); 
shuffle($questions);

// Fetch saved answers
$stmt = $pdo->prepare("SELECT question_id, selected_option, answer_text FROM student_answers WHERE student_exam_id = ?");
$stmt->execute([$attempt_id]);
$saved_answers_raw = $stmt->fetchAll();
$saved_data = [];
foreach ($saved_answers_raw as $sa) {
    $saved_data[$sa['question_id']] = ['opt' => $sa['selected_option'], 'text' => $sa['answer_text']];
}

include $path_to_root . 'includes/header.php';
?>

<style>
    body { background: #f8fafc; user-select: none; -webkit-user-select: none; overflow: hidden; }
    .exam-header {
        position: fixed; top: 0; left: 0; right: 0;
        background: white; padding: 15px 30px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        z-index: 9999 !important; display: flex; justify-content: space-between; align-items: center;
        border-bottom: 2px solid var(--primary-light);
    }
    .exam-layout {
        display: grid; grid-template-columns: 280px 1fr; gap: 30px;
        max-width: 1400px; margin: 100px auto 0; height: calc(100vh - 120px); padding: 0 20px;
    }
    .question-navigator {
        background: white; padding: 25px; border-radius: 20px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow-y: auto; height: 100%;
    }
    .nav-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-top: 20px; }
    .nav-btn {
        aspect-ratio: 1; display: flex; align-items: center; justify-content: center;
        border-radius: 10px; background: #f1f5f9; color: #64748b; font-weight: 700;
        cursor: pointer; transition: 0.2s; border: 2px solid transparent;
    }
    .nav-btn.active { background: var(--primary); color: white; }
    .nav-btn.answered { border-color: var(--success); background: #f0fff4; color: var(--success); }
    .question-area {
        background: white; border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.04);
        display: flex; flex-direction: column; overflow: hidden; height: 100%;
    }
    .question-content { flex: 1; padding: 40px; overflow-y: auto; }
    .question-footer {
        padding: 25px 40px; background: #f8fafc; border-top: 1px solid #f1f5f9;
        display: flex; justify-content: space-between; align-items: center;
    }
    .question-card { display: none; width: 100%; }
    .question-card.active { display: block; animation: fadeIn 0.4s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .option-label {
        display: block; padding: 20px; margin-bottom: 12px; border: 2px solid #f1f5f9;
        border-radius: 16px; cursor: pointer; transition: 0.2s; font-weight: 600; background: #fcfdfe;
    }
    .option-label:hover { border-color: var(--primary-light); background: white; }
    input[type="radio"]:checked + .option-label {
        background: #f1f5ff; border-color: var(--primary); color: var(--primary);
        box-shadow: 0 4px 12px rgba(78, 84, 200, 0.1);
    }
    input[type="radio"] { display: none; }
    .timer-card {
        background: #0f172a; color: white; padding: 10px 20px; border-radius: 14px;
        font-family: monospace; letter-spacing: 2px; font-size: 1.4rem; font-weight: 800;
        display: flex; align-items: center; gap: 12px;
    }
    #fullscreen-warning {
        position: fixed; inset: 0; background: rgba(15, 23, 42, 0.98);
        backdrop-filter: blur(8px); color: white; display: none;
        flex-direction: column; align-items: center; justify-content: center; z-index: 9999;
    }
    .descriptive-input {
        width: 100%; padding: 20px; border: 2px solid #edeff2; border-radius: 16px;
        font-family: inherit; font-size: 1.1rem; line-height: 1.6; background: #f8fafc;
        resize: vertical; min-height: 200px; transition: 0.3s;
    }
    .descriptive-input:focus { outline: none; border-color: var(--primary); background: white; }

    @media (max-width: 991px) {
        body { overflow: auto !important; }
        .exam-header { 
            padding: 0 15px; 
            height: 70px; 
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
        }
        .exam-layout { 
            display: block !important;
            margin-top: 70px; 
            height: auto !important;
            min-height: calc(100vh - 150px);
            padding-bottom: 90px; /* Space for sticky footer */
        }
        .question-navigator { 
            display: none; 
        }
        .question-area { 
            display: block !important;
            height: auto !important;
            box-shadow: none !important;
            border-radius: 0 !important;
        }
        .question-content { 
            padding: 20px;
            overflow: visible !important;
        }
        .question-footer { 
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 12px 20px; 
            background: white;
            border-top: 2px solid #edf2f7;
            display: flex;
            gap: 12px;
            justify-content: stretch;
            height: 85px;
            padding-bottom: env(safe-area-inset-bottom, 20px);
            z-index: 10000;
            box-shadow: 0 -5px 15px rgba(0,0,0,0.05);
        }
        #prevBtn, #nextBtn, #submitBtn { 
            flex: 1;
            padding: 15px !important; 
            font-size: 1rem; 
            border-radius: 16px;
            margin: 0 !important;
            font-weight: 800;
            height: 55px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #submitBtn:disabled { background: #94a3b8 !important; opacity: 0.6; cursor: not-allowed; }
        .question-card h2 { 
            font-size: 1.1rem; 
            margin-bottom: 15px;
        }
        .question-card p { 
            font-size: 1rem; 
            margin-bottom: 20px; 
        }
        .option-label { 
            padding: 14px; 
            margin-bottom: 10px; 
            font-size: 0.9rem; 
            border-radius: 12px;
        }
        #questionCount, #navToggle, #examTitle, .step-indicator, .header-left { 
            display: none !important; 
        }
    }
</style>

<div id="fullscreen-warning">
    <div style="background: rgba(255,255,255,0.1); padding: 30px; border-radius: 30px; text-align: center; margin: 20px;">
        <i class="fas fa-shield-alt" style="font-size: 4rem; color: #818cf8; margin-bottom: 25px;"></i>
        <h1 style="font-size: 1.5rem; margin-bottom: 20px;">Secure Exam Mode</h1>
        <button onclick="enterFullscreen()" class="btn btn-primary" style="width: 100%; padding: 15px; border-radius: 15px; font-weight: 700;">Enter Session</button>
    </div>
</div>

<div class="navigator-overlay" id="navOverlay" onclick="toggleNavigator()"></div>

<div class="exam-header">
    <div class="header-left" style="display: flex; align-items: center; gap: 10px;">
        <button onclick="toggleNavigator()" class="btn" style="background: var(--primary-light); color: white; padding: 8px; border-radius: 8px; display: none;" id="navToggle">
            <i class="fas fa-th-large"></i>
        </button>
        <div>
            <div style="font-weight: 800; font-size: 0.9rem;" id="examTitle"><?= htmlspecialchars($exam['title']) ?></div>
            <div id="questionCount">
                <span id="solved-count">0</span> / <?= count($questions) ?> SOLVED
            </div>
        </div>
    </div>
    <div class="timer-card"><i class="fas fa-clock"></i><span id="timer">00:00:00</span></div>
    <div class="header-right" style="display: flex; gap: 8px;">
        <button onclick="toggleInstructions()" class="btn" style="background: #f1f5f9; color: #64748b; padding: 8px 12px; border-radius: 10px; font-weight: 800; font-size: 0.7rem;">
            <i class="fas fa-info-circle"></i> INFO
        </button>
        <div style="background: #fff1f2; color: #e11d48; padding: 8px 10px; border-radius: 10px; font-weight: 800; font-size: 0.7rem;">
            W: <span id="violation-count"><?= $attempt['violations_count'] ?></span>/3
        </div>
    </div>
</div>

<!-- Instructions Modal -->
<div id="instructionsModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 7000; align-items: center; justify-content: center; backdrop-filter: blur(5px);">
    <div style="background: white; padding: 30px; border-radius: 24px; width: 500px; max-width: 90%;" class="fade-in">
        <h3 style="margin-bottom: 20px; font-weight: 800;">Exam Guidelines</h3>
        <div style="background: #f8fafc; padding: 20px; border-radius: 16px; font-size: 0.95rem; line-height: 1.6; color: #475569; white-space: pre-wrap; margin-bottom: 25px; max-height: 300px; overflow-y: auto;"><?= htmlspecialchars($exam['instructions'] ?: 'No specific instructions provided.') ?></div>
        <button onclick="toggleInstructions()" class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 12px; font-weight: 700;">Continue Exam</button>
    </div>
</div>

<div class="exam-layout fade-in">
    <aside class="question-navigator">
        <h4 style="font-weight: 800; margin-bottom: 20px;">Progress Map</h4>
        <div class="nav-grid">
            <?php foreach ($questions as $index => $q): ?>
                <div class="nav-btn <?= isset($saved_data[$q['id']]) ? 'answered' : '' ?>" id="nav-<?= $index ?>" onclick="showQuestion(<?= $index ?>)">
                    <?= $index + 1 ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top: 30px;">
            <div style="display:flex; justify-content:space-between; font-size:0.75rem; margin-bottom:5px;">
                <span>Completion</span><span id="progress-text">0%</span>
            </div>
            <div style="height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden;">
                <div id="progress-bar" style="width:0%; height:100%; background:var(--primary); transition:0.3s;"></div>
            </div>
        </div>
    </aside>

    <main class="question-area">
        <div class="question-content">
            <form id="examForm" action="submit_exam.php" method="POST">
                <input type="hidden" name="attempt_id" value="<?= $attempt_id ?>">
                <input type="hidden" name="exam_id" value="<?= $exam_id ?>">

                <?php foreach ($questions as $index => $q): 
                    $data = $saved_data[$q['id']] ?? ['opt' => null, 'text' => null];
                ?>
                    <div class="question-card <?= $index === 0 ? 'active' : '' ?>" id="q-container-<?= $index ?>">
                        <div style="display:flex; justify-content:space-between; margin-bottom:25px;">
                            <h2 style="font-weight: 800; color: var(--dark);">Question <?= $index + 1 ?></h2>
                            <span style="font-weight:700; color:#94a3b8;"><?= $q['marks'] ?> Pts</span>
                        </div>
                        <p style="font-size: 1.3rem; font-weight: 600; line-height: 1.6; margin-bottom: 40px; color: #1e293b;">
                            <?= htmlspecialchars($q['question_text']) ?>
                        </p>
                        
                        <?php if ($q['question_type'] == 'mcq'): 
                            $options = ['a' => $q['option_a'], 'b' => $q['option_b'], 'c' => $q['option_c'], 'd' => $q['option_d']];
                            $opt_keys = array_keys($options);
                            srand($q['id'] + $attempt_id); shuffle($opt_keys);
                        ?>
                            <div class="options-group">
                                <?php foreach ($opt_keys as $key): ?>
                                    <label class="option-label">
                                        <input type="radio" name="answers[<?= $q['id'] ?>]" value="<?= $key ?>" <?= $data['opt'] == $key ? 'checked' : '' ?> onchange="handleSave(<?= $index ?>, <?= $q['id'] ?>, '<?= $key ?>', null)">
                                        <?= htmlspecialchars($options[$key]) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <textarea class="descriptive-input" placeholder="Enter your detailed answer here..." onblur="handleSave(<?= $index ?>, <?= $q['id'] ?>, null, this.value)"><?= htmlspecialchars($data['text']) ?></textarea>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </form>
        </div>

        <footer class="question-footer">
            <button type="button" onclick="prevQuestion()" id="prevBtn" class="btn" style="background:#f1f5f9; color:#64748b; font-weight:700;">Previous</button>
            <div class="step-indicator" style="font-weight:700; color:#94a3b8;">Question <span id="current-step">1</span> of <?= count($questions) ?></div>
            <button type="button" onclick="nextQuestion()" id="nextBtn" class="btn btn-primary">Next</button>
            <button type="button" onclick="confirmSubmit()" id="submitBtn" class="btn btn-success" disabled>Finish Exam</button>
        </footer>
    </main>
</div>

<script>
    let currentIdx = 0;
    const totalQuestions = <?= count($questions) ?>;
    const attemptId = <?= $attempt_id ?>;
    <?php 
        $rem = (strtotime($attempt['start_time']) + ($exam['duration_minutes'] * 60)) - time();
    ?>
    let remainingTime = <?= $rem ?>;
    let violationCount = <?= $attempt['violations_count'] ?>;

    function showQuestion(idx) {
        document.querySelectorAll('.question-card').forEach(c => c.classList.remove('active'));
        document.getElementById(`q-container-${idx}`).classList.add('active');
        currentIdx = idx;
        document.getElementById('current-step').innerText = idx + 1;
        
        // Navigation Button Logic
        const prev = document.getElementById('prevBtn');
        const next = document.getElementById('nextBtn');
        const submit = document.getElementById('submitBtn');

        // Initial state
        prev.style.display = (idx === 0) ? 'none' : 'block';
        next.style.display = (idx === totalQuestions - 1) ? 'none' : 'block';
        submit.style.display = (idx === totalQuestions - 1) ? 'block' : 'none';

        document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
        document.getElementById(`nav-${idx}`).classList.add('active');
        calculateProgress();
    }

    function toggleNavigator() {
        document.querySelector('.question-navigator').classList.toggle('active');
        document.getElementById('navOverlay').classList.toggle('active');
    }

    function nextQuestion() { if(currentIdx < totalQuestions -1) showQuestion(currentIdx + 1); }
    function prevQuestion() { if(currentIdx > 0) showQuestion(currentIdx - 1); }

    function handleSave(idx, qId, opt, text) {
        let fd = new FormData();
        fd.append('attempt_id', attemptId); fd.append('question_id', qId);
        if(opt) fd.append('option', opt);
        if(text !== null) fd.append('answer_text', text);
        
        fetch('save_answer.php', { method: 'POST', body: fd });
        if(opt || (text && text.trim().length > 0)) {
            document.getElementById(`nav-${idx}`).classList.add('answered');
        } else {
            document.getElementById(`nav-${idx}`).classList.remove('answered');
        }
        calculateProgress();
    }

    function calculateProgress() {
        const answered = document.querySelectorAll('.nav-btn.answered').length;
        const percent = Math.round((answered / totalQuestions) * 100);
        
        const progressBar = document.getElementById('progress-bar');
        const progressText = document.getElementById('progress-text');
        const solvedCount = document.getElementById('solved-count');
        const submitBtn = document.getElementById('submitBtn');

        if(progressBar) progressBar.style.width = percent + '%';
        if(progressText) progressText.innerText = percent + '%';
        if(solvedCount) solvedCount.innerText = answered;

        // Enforce Submission Rule: All questions must be answered
        if (answered === totalQuestions) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.innerText = 'Finish Exam';
        } else {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
            submitBtn.innerText = 'Answer All (' + answered + '/' + totalQuestions + ')';
        }
    }

    function toggleInstructions() {
        const m = document.getElementById('instructionsModal');
        m.style.display = (m.style.display === 'none') ? 'flex' : 'none';
    }

    function updateTimer() {
        if(remainingTime <= 0) { document.getElementById("examForm").submit(); return; }
        let h = Math.floor(remainingTime / 3600), m = Math.floor((remainingTime % 3600) / 60), s = remainingTime % 60;
        document.getElementById("timer").innerText = (h<10?'0':'')+h+':'+(m<10?'0':'')+m+':'+(s<10?'0':'')+s;
        remainingTime--;
    }
    setInterval(updateTimer, 1000); updateTimer();

    function confirmSubmit() { if(confirm("Submit your examination?")) document.getElementById("examForm").submit(); }

    function enterFullscreen() {
        let e = document.documentElement;
        if(e.requestFullscreen) e.requestFullscreen(); else if(e.webkitRequestFullscreen) e.webkitRequestFullscreen();
        document.getElementById('fullscreen-warning').style.display = 'none';
    }
    document.addEventListener('fullscreenchange', () => {
        if(!document.fullscreenElement) { document.getElementById('fullscreen-warning').style.display = 'flex'; handleViolation("FS Exit"); }
    });
    window.onblur = () => handleViolation("Tab Switch");

    function handleViolation(t) {
        violationCount++; document.getElementById('violation-count').innerText = violationCount;
        let fd = new FormData(); fd.append('attempt_id', attemptId); fd.append('type', t);
        fetch('record_violation.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => { if(d.status === 'blocked') window.location.href = 'exams.php'; });
    }

    window.onload = () => { 
        document.getElementById('fullscreen-warning').style.display='flex'; 
        showQuestion(0);
        calculateProgress(); 
    };
</script>
</body></html>