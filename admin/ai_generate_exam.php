<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

$exam_id = $_GET['exam_id'] ?? 0;
if (!$exam_id) redirect('exams.php');

// Fetch courses
$courses = $pdo->query("SELECT * FROM courses ORDER BY title ASC")->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 20px;">
    <a href="manage_questions.php?exam_id=<?= $exam_id ?>" style="color: #64748b; font-weight: 600;"><i class="fas fa-arrow-left"></i> Back to Manual Management</a>
</div>

<div style="background: linear-gradient(135deg, #4f46e5, #9333ea); padding: 30px; border-radius: 24px; color: white; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(79, 70, 229, 0.2);">
    <div style="display: flex; align-items: center; gap: 20px;">
        <div style="background: rgba(255,255,255,0.2); width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
            <i class="fas fa-robot"></i>
        </div>
        <div>
            <h2 style="margin: 0; font-weight: 800;">AI Exam Generator</h2>
            <p style="margin: 5px 0 0 0; opacity: 0.9;">Create professional exam papers in seconds using AI analysis of your lesson content.</p>
        </div>
    </div>
</div>

<div class="row" style="display: grid; grid-template-columns: 400px 1fr; gap: 30px;">
    <!-- Generator Interface -->
    <div class="white-card" style="background: white; padding: 30px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); height: fit-content; border: 1px solid #f1f5f9;">
        <h4 style="font-weight: 800; color: var(--dark); margin-bottom: 25px;">Configuration</h4>
        
        <div class="form-group">
            <label>Select Course</label>
            <select id="courseSelect" class="form-control" onchange="loadLessons(this.value)" style="background: #f8fafc; font-weight: 600;">
                <option value="">-- Choose Course --</option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Source Lesson</label>
            <select id="lessonSelect" class="form-control" style="background: #f8fafc; font-weight: 600;" disabled>
                <option value="">-- Select Course First --</option>
            </select>
            <small style="color: #64748b;">AI will analyze the text content of this lesson.</small>
        </div>

        <hr style="border: 0; border-top: 1px solid #f1f5f9; margin: 25px 0;">

        <div class="form-group">
            <label>Number of Questions</label>
            <input type="number" id="qCount" class="form-control" value="5" min="1" max="20" style="background: #f8fafc; font-weight: 700;">
        </div>

        <div class="form-group" style="background: #f1f5ff; padding: 15px; border-radius: 12px; margin-top: 15px;">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin: 0; color: #4f46e5;">
                <input type="checkbox" id="isDescriptive" style="width: 18px; height: 18px;">
                <strong>Create Descriptive Questions?</strong>
            </label>
            <small style="display: block; margin-top: 5px; color: #64748b;">If unchecked, standard MCQ with options will be generated.</small>
        </div>

        <button id="generateBtn" class="btn btn-primary" onclick="generateAI()" style="width: 100%; padding: 18px; border-radius: 16px; font-weight: 800; margin-top: 25px; background: linear-gradient(135deg, #4f46e5, #7c3aed); border: none; box-shadow: 0 10px 20px rgba(79, 70, 229, 0.2);">
            <i class="fas fa-sparkles"></i> Generate Questions
        </button>
    </div>

    <!-- Preview Area -->
    <div>
        <div id="statusArea" style="display: none; text-align: center; padding: 100px 50px; background: white; border-radius: 20px; border: 1px solid #f1f5f9;">
            <div class="spinner-border" style="width: 3rem; height: 3rem; color: #4f46e5; margin-bottom: 20px;"></div>
            <h3 style="font-weight: 800; color: #1e293b;">AI is analyzing content...</h3>
            <p style="color: #64748b;">This may take 15-30 seconds depending on the lesson volume.</p>
        </div>

        <div id="resultsContainer">
            <div style="text-align: center; padding: 100px 50px; background: #f8fafc; border-radius: 20px; border: 2px dashed #e2e8f0;">
                <i class="fas fa-brain" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 20px;"></i>
                <h3 style="color: #94a3b8; font-weight: 700;">Ready to Generate</h3>
                <p style="color: #64748b;">Configure the source on the left to begin generation.</p>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Saving -->
<div id="successModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(5px);">
    <div style="background: white; padding: 40px; border-radius: 24px; text-align: center; width: 400px; box-shadow: 0 25px 50px rgba(0,0,0,0.2);">
        <div style="background: #f0fdf4; color: #16a34a; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 20px;">
            <i class="fas fa-check"></i>
        </div>
        <h2 style="font-weight: 800; color: #1e293b;">Exam Updated!</h2>
        <p style="color: #64748b; margin-bottom: 25px;">Selected questions have been added to the paper blueprint.</p>
        <button onclick="location.href='manage_questions.php?exam_id=<?= $exam_id ?>'" class="btn btn-primary" style="width: 100%; padding: 15px; border-radius: 12px; font-weight: 700;">View Paper Blueprint</button>
    </div>
</div>

<script>
async function loadLessons(courseId) {
    const lessonSelect = document.getElementById('lessonSelect');
    if (!courseId) {
        lessonSelect.disabled = true;
        lessonSelect.innerHTML = '<option value="">-- Select Course First --</option>';
        return;
    }

    lessonSelect.innerHTML = '<option value="">Loading lessons...</option>';
    lessonSelect.disabled = true;

    try {
        const res = await fetch('get_lessons.php?course_id=' + courseId);
        const lessons = await res.json();
        
        lessonSelect.innerHTML = '<option value="">-- Choose Lesson --</option>';
        lessons.forEach(l => {
            lessonSelect.innerHTML += `<option value="${l.id}">${l.title}</option>`;
        });
        lessonSelect.disabled = false;
    } catch (e) {
        alert('Failed to load lessons');
    }
}

async function generateAI() {
    const lessonId = document.getElementById('lessonSelect').value;
    const qCount = document.getElementById('qCount').value;
    const isDescriptive = document.getElementById('isDescriptive').checked;
    const generateBtn = document.getElementById('generateBtn');

    if (!lessonId) return alert('Please select a source lesson.');

    generateBtn.disabled = true;
    document.getElementById('statusArea').style.display = 'block';
    document.getElementById('resultsContainer').style.display = 'none';

    try {
        const response = await fetch('ai_generate_process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `lesson_id=${lessonId}&count=${qCount}&type=${isDescriptive ? 'descriptive' : 'mcq'}`
        });

        const data = await response.json();
        if (data.error) throw new Error(data.error);

        renderResults(data.questions);
    } catch (e) {
        alert('Generation failed: ' + e.message);
        document.getElementById('resultsContainer').style.display = 'block';
    } finally {
        generateBtn.disabled = false;
        document.getElementById('statusArea').style.display = 'none';
    }
}

function renderResults(questions) {
    const container = document.getElementById('resultsContainer');
    container.style.display = 'block';
    
    if (!questions || questions.length === 0) {
        container.innerHTML = `<div style="text-align: center; padding: 100px 50px; background: #fff1f2; border-radius: 20px; border: 2px dashed #f43f5e;">
            <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: #f43f5e; margin-bottom: 20px;"></i>
            <h3 style="color: #9f1239; font-weight: 700;">AI failed to generate results</h3>
            <p style="color: #64748b;">The AI service returned no questions. Please try refining your request or shortening the lesson content.</p>
        </div>`;
        return;
    }
    
    let html = `<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: white; padding: 15px 25px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 10px rgba(0,0,0,0.02);">
        <h4 style="font-weight: 800; color: var(--dark); margin: 0;">AI Draft Preview 
            <span style="background: #4f46e5; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem;">${questions.length} Questions</span>
        </h4>
        <button onclick="saveAll()" class="btn btn-success" style="background: #10b981; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 700; color: white; cursor: pointer;">
            <i class="fas fa-file-import"></i> Save All to Exam
        </button>
    </div>`;

    questions.forEach((q, idx) => {
        // Securely serialize question for saving
        const qSafe = b64EncodeUnicode(JSON.stringify(q));

        html += `<div class="white-card" style="background: white; padding: 25px; border-radius: 20px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; position: relative;">
            <div style="font-weight: 800; font-size: 1.15rem; margin-bottom: 12px; color: #1e293b; line-height: 1.4;">${idx + 1}. ${q.question}</div>`;
        
        if (q.type === 'mcq') {
            html += `<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-left: 10px;">
                <div style="padding: 12px; background: #f8fafc; border-radius: 10px; font-size: 0.95rem; ${q.answer === 'a' ? 'border: 2px solid #10b981; background: #ecfdf5; font-weight: 700;' : 'border: 1px solid #f1f5f9;'}"><strong>A)</strong> ${q.a}</div>
                <div style="padding: 12px; background: #f8fafc; border-radius: 10px; font-size: 0.95rem; ${q.answer === 'b' ? 'border: 2px solid #10b981; background: #ecfdf5; font-weight: 700;' : 'border: 1px solid #f1f5f9;'}"><strong>B)</strong> ${q.b}</div>
                <div style="padding: 12px; background: #f8fafc; border-radius: 10px; font-size: 0.95rem; ${q.answer === 'c' ? 'border: 2px solid #10b981; background: #ecfdf5; font-weight: 700;' : 'border: 1px solid #f1f5f9;'}"><strong>C)</strong> ${q.c}</div>
                <div style="padding: 12px; background: #f8fafc; border-radius: 10px; font-size: 0.95rem; ${q.answer === 'd' ? 'border: 2px solid #10b981; background: #ecfdf5; font-weight: 700;' : 'border: 1px solid #f1f5f9;'}"><strong>D)</strong> ${q.d}</div>
            </div>`;
        } else {
            html += `<div style="padding: 15px; background: #fffcf0; border-radius: 12px; font-size: 0.9rem; color: #854d0e; border: 1px dashed #fcd34d; margin-top: 10px;">
                <i class="fas fa-lightbulb"></i> <strong style="margin-left: 5px;">Key Concept:</strong> ${q.key_topics || 'General Topic Analysis'}
            </div>`;
        }
        
        html += `<input type="hidden" class="q-data" value="${qSafe}"></div>`;
    });

    container.innerHTML = html;
}

// B64 helpers to prevent character breakage
function b64EncodeUnicode(str) {
    return btoa(encodeURIComponent(str).replace(/%([0-9A-F]{2})/g,
        function(match, p1) {
            return String.fromCharCode('0x' + p1);
    }));
}

function b64DecodeUnicode(str) {
    return decodeURIComponent(atob(str).split('').map(function(c) {
        return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
    }).join(''));
}

async function saveAll() {
    const qCards = document.querySelectorAll('.q-data');
    const questions = Array.from(qCards).map(card => JSON.parse(b64DecodeUnicode(card.value)));
    
    try {
        const res = await fetch('ai_generate_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ exam_id: '<?= $exam_id ?>', questions: questions })
        });
        
        const data = await res.json();
        if (data.success) {
            document.getElementById('successModal').style.display = 'flex';
        }
    } catch (e) {
        alert('Failed to save to database.');
    }
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
