<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

$exam_id = $_GET['exam_id'] ?? 0;
if (!$exam_id)
    redirect('exams.php');

$stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

$message = '';

// ADD QUESTION
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_question'])) {
    $q = trim($_POST['question']);
    $type = $_POST['question_type'];
    $marks = (int)($_POST['marks'] ?? 1);
    
    $a = $b = $c = $d = $correct = null;
    if ($type === 'mcq') {
        $a = trim($_POST['a']);
        $b = trim($_POST['b']);
        $c = trim($_POST['c']);
        $d = trim($_POST['d']);
        $correct = $_POST['correct'];
    }

    $stmt = $pdo->prepare("INSERT INTO exam_questions (exam_id, question_text, question_type, marks, option_a, option_b, option_c, option_d, correct_option) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$exam_id, $q, $type, $marks, $a, $b, $c, $d, $correct]);
    $message = "Question added successfully!";
}

// UPDATE QUESTION
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_question'])) {
    $qid = $_POST['question_id'];
    $q = trim($_POST['question']);
    $type = $_POST['question_type'];
    $marks = (int)($_POST['marks'] ?? 1);
    
    $a = $b = $c = $d = $correct = null;
    if ($type === 'mcq') {
        $a = trim($_POST['a']);
        $b = trim($_POST['b']);
        $c = trim($_POST['c']);
        $d = trim($_POST['d']);
        $correct = $_POST['correct'];
    }

    $stmt = $pdo->prepare("UPDATE exam_questions SET question_text = ?, question_type = ?, marks = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_option = ? WHERE id = ?");
    $stmt->execute([$q, $type, $marks, $a, $b, $c, $d, $correct, $qid]);
    $message = "Question updated successfully!";
}

if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM exam_questions WHERE id = ?")->execute([$_GET['delete']]);
    redirect('manage_questions.php?exam_id=' . $exam_id . '&msg=deleted');
}

if(isset($_GET['msg']) && $_GET['msg'] == 'deleted') $message = "Question removed successfully!";

$questions = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY id ASC");
$questions->execute([$exam_id]);
$questions = $questions->fetchAll();

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 20px;">
    <a href="exams.php" style="color: #64748b; font-weight: 600; text-decoration: none;"><i class="fas fa-arrow-left"></i> Back to Exams</a>
</div>

<div style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h2 style="font-weight: 800; color: var(--dark); margin: 0;">Manage Questions</h2>
        <p style="color: #64748b; margin: 5px 0 0 0;"><?= htmlspecialchars($exam['title']) ?></p>
    </div>
    <a href="ai_generate_exam.php?exam_id=<?= $exam_id ?>" class="btn btn-primary" style="padding: 12px 25px; border-radius: 12px; font-weight: 800; background: linear-gradient(135deg, #6366f1, #a855f7); border: none; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);">
        <i class="fas fa-robot"></i> Generate with AI
    </a>
</div>

<?php if ($message): ?>
    <div class="badge badge-success" style="display: block; padding: 15px; margin-bottom: 25px; border-radius: 12px; font-weight: 700;">
        <i class="fas fa-check-circle"></i> <?= $message ?>
    </div>
<?php endif; ?>

<div class="row" style="display: grid; grid-template-columns: 450px 1fr; gap: 30px;">
    <!-- Add Question -->
    <div class="white-card" style="background: white; padding: 30px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); height: fit-content; border: 1px solid #f1f5f9;">
        <h4 style="font-weight: 800; color: var(--dark); margin-bottom: 25px;">Add New Question</h4>
        <form method="POST">
            <div class="form-group">
                <label>Question Type</label>
                <select name="question_type" id="q_type" class="form-control" onchange="toggleType(this.value, 'mcq_options_area')" style="background: #f8fafc; font-weight: 600;">
                    <option value="mcq">Multiple Choice (MCQ)</option>
                    <option value="descriptive">Descriptive (Written)</option>
                </select>
            </div>

            <div class="form-group">
                <label>Question Points/Marks</label>
                <input type="number" name="marks" class="form-control" value="1" min="1" required style="background: #f8fafc;">
            </div>

            <div class="form-group">
                <label>Question Text</label>
                <textarea name="question" class="form-control" rows="4" placeholder="Type the question content here..." required style="background: #f8fafc;"></textarea>
            </div>

            <div id="mcq_options_area">
                <div style="margin: 20px 0 10px; font-size: 0.8rem; font-weight: 800; color: #94a3b8; text-transform: uppercase;">MCQ Options</div>
                <div class="form-group"><input type="text" name="a" class="form-control" placeholder="Option A" style="background: #f8fafc;"></div>
                <div class="form-group"><input type="text" name="b" class="form-control" placeholder="Option B" style="background: #f8fafc;"></div>
                <div class="form-group"><input type="text" name="c" class="form-control" placeholder="Option C" style="background: #f8fafc;"></div>
                <div class="form-group"><input type="text" name="d" class="form-control" placeholder="Option D" style="background: #f8fafc;"></div>
                
                <div class="form-group">
                    <label>Correct Answer</label>
                    <select name="correct" class="form-control" style="background: #f1f5ff; border-color: #cbd5e1; font-weight: 800;">
                        <option value="a">A</option>
                        <option value="b">B</option>
                        <option value="c">C</option>
                        <option value="d">D</option>
                    </select>
                </div>
            </div>

            <button type="submit" name="add_question" class="btn btn-primary" style="width: 100%; padding: 15px; border-radius: 12px; font-weight: 800; margin-top: 10px; box-shadow: 0 4px 12px rgba(78, 84, 200, 0.2);">
                <i class="fas fa-plus"></i> Add to Exam
            </button>
        </form>
    </div>

    <!-- Question List -->
    <div style="max-height: 85vh; overflow-y: auto; padding-right: 10px;" class="pretty-scroll">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <h4 style="font-weight: 800; color: var(--dark); margin: 0;">Exam Blueprint <span style="color: #94a3b8; font-weight: 600;">(<?= count($questions) ?>)</span></h4>
            <div style="padding: 5px 15px; background: #f1f5f9; border-radius: 50px; font-size: 0.85rem; font-weight: 800; color: #475569;">
                Total Marks: <?= array_sum(array_column($questions, 'marks')) ?>
            </div>
        </div>

        <?php if (empty($questions)): ?>
            <div style="text-align: center; padding: 50px; background: #f8fafc; border-radius: 20px; border: 2px dashed #e2e8f0;">
                <i class="fas fa-file-signature" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 20px;"></i>
                <h3 style="color: #94a3b8; font-weight: 700;">No questions yet</h3>
                <p style="color: #64748b;">Start building your exam by adding questions from the left panel.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($questions as $index => $q): ?>
            <div class="white-card" style="background: white; padding: 25px; border-radius: 20px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; position: relative; transition: 0.2s;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px;">
                    <div>
                        <span style="display: inline-block; padding: 4px 10px; border-radius: 6px; background: <?= $q['question_type'] == 'mcq' ? '#f0fdf4; color:#166534;' : '#fefce8; color:#854d0e;' ?>; font-size: 0.7rem; font-weight: 800; text-transform: uppercase;">
                            <?= $q['question_type'] ?> Question
                        </span>
                        <div style="margin-top: 10px; font-weight: 700; color: var(--dark); font-size: 1.1rem;">
                            Q<?= $index + 1 ?>: <?= htmlspecialchars($q['question_text']) ?>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-weight: 800; color: #94a3b8; font-size: 0.9rem; margin-right:5px;"><?= $q['marks'] ?> Pts</span>
                        <button onclick='editQuestion(<?= json_encode($q) ?>)' style="width: 35px; height: 35px; background: #f1f5ff; border: none; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--primary); cursor: pointer;" title="Edit Question"><i class="fas fa-edit"></i></button>
                        <a href="?exam_id=<?= $exam_id ?>&delete=<?= $q['id'] ?>" onclick="return confirm('Remove this question?')"
                            style="width: 35px; height: 35px; background: #fff1f2; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #e11d48; transition: 0.2s; text-decoration: none;" title="Delete"><i class="fas fa-trash"></i></a>
                    </div>
                </div>

                <?php if ($q['question_type'] == 'mcq'): ?>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-left: 10px;">
                        <div style="padding: 10px; border-radius: 8px; border: 1px solid #f1f5f9; background: <?= $q['correct_option'] == 'a' ? '#eff6ff; border-color:#bfdbfe;' : '#fcfdfe' ?>; font-size: 0.9rem; color: #1e293b;">
                            <strong style="margin-right: 5px;">A)</strong> <?= htmlspecialchars($q['option_a']) ?>
                        </div>
                        <div style="padding: 10px; border-radius: 8px; border: 1px solid #f1f5f9; background: <?= $q['correct_option'] == 'b' ? '#eff6ff; border-color:#bfdbfe;' : '#fcfdfe' ?>; font-size: 0.9rem; color: #1e293b;">
                            <strong style="margin-right: 5px;">B)</strong> <?= htmlspecialchars($q['option_b']) ?>
                        </div>
                        <div style="padding: 10px; border-radius: 8px; border: 1px solid #f1f5f9; background: <?= $q['correct_option'] == 'c' ? '#eff6ff; border-color:#bfdbfe;' : '#fcfdfe' ?>; font-size: 0.9rem; color: #1e293b;">
                            <strong style="margin-right: 5px;">C)</strong> <?= htmlspecialchars($q['option_c']) ?>
                        </div>
                        <div style="padding: 10px; border-radius: 8px; border: 1px solid #f1f5f9; background: <?= $q['correct_option'] == 'd' ? '#eff6ff; border-color:#bfdbfe;' : '#fcfdfe' ?>; font-size: 0.9rem; color: #1e293b;">
                            <strong style="margin-right: 5px;">D)</strong> <?= htmlspecialchars($q['option_d']) ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="padding: 15px; background: #f8fafc; border-radius: 12px; border: 1px dashed #e2e8f0; font-size: 0.85rem; color: #64748b;">
                        <i class="fas fa-pen-nib"></i> Students will provide an essay-style or descriptive answer for this question.
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- EDIT MODAL -->
<div id="editModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(10px); z-index: 2000; align-items: center; justify-content: center; padding: 20px;">
    <div class="white-card" style="width: 100%; max-width: 500px; padding: 35px; border-radius: 25px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);">
        <h3 style="margin-bottom: 25px; font-weight: 800; display: flex; justify-content: space-between;">
            Modify Question <i class="fas fa-times" style="cursor: pointer; color: #94a3b8;" onclick="closeModal()"></i>
        </h3>
        <form method="POST">
            <input type="hidden" name="question_id" id="edit_qid">
            <div class="form-group">
                <label>Question Type</label>
                <select name="question_type" id="edit_q_type" class="form-control" onchange="toggleType(this.value, 'edit_mcq_options')" style="background: #f8fafc; font-weight: 700;">
                    <option value="mcq">Multiple Choice</option>
                    <option value="descriptive">Descriptive</option>
                </select>
            </div>
            <div class="form-group">
                <label>Marks</label>
                <input type="number" name="marks" id="edit_marks" class="form-control" required style="background: #f8fafc;">
            </div>
            <div class="form-group">
                <label>Question Content</label>
                <textarea name="question" id="edit_q_text" class="form-control" rows="3" required style="background: #f8fafc;"></textarea>
            </div>

            <div id="edit_mcq_options">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 15px;">
                    <div><label>Option A</label><input type="text" name="a" id="edit_a" class="form-control"></div>
                    <div><label>Option B</label><input type="text" name="b" id="edit_b" class="form-control"></div>
                    <div><label>Option C</label><input type="text" name="c" id="edit_c" class="form-control"></div>
                    <div><label>Option D</label><input type="text" name="d" id="edit_d" class="form-control"></div>
                </div>
                <div class="form-group">
                    <label>Correct Answer</label>
                    <select name="correct" id="edit_correct" class="form-control" style="background: #f1f5ff; font-weight: 800;">
                        <option value="a">A</option>
                        <option value="b">B</option>
                        <option value="c">C</option>
                        <option value="d">D</option>
                    </select>
                </div>
            </div>

            <button type="submit" name="update_question" class="btn btn-primary" style="width: 100%; padding: 16px; border-radius: 12px; font-weight: 800; margin-top: 15px;">
                Save Modifications
            </button>
        </form>
    </div>
</div>

<script>
function toggleType(val, targetId) {
    const area = document.getElementById(targetId);
    if (val === 'descriptive') {
        area.style.display = 'none';
        area.querySelectorAll('input, select').forEach(i => i.required = false);
    } else {
        area.style.display = 'block';
        area.querySelectorAll('input, select').forEach(i => i.required = true);
    }
}

function editQuestion(q) {
    document.getElementById('edit_qid').value = q.id;
    document.getElementById('edit_q_text').value = q.question_text;
    document.getElementById('edit_q_type').value = q.question_type;
    document.getElementById('edit_marks').value = q.marks;
    
    if (q.question_type === 'mcq') {
        document.getElementById('edit_a').value = q.option_a;
        document.getElementById('edit_b').value = q.option_b;
        document.getElementById('edit_c').value = q.option_c;
        document.getElementById('edit_d').value = q.option_d;
        document.getElementById('edit_correct').value = q.correct_option;
    }
    
    toggleType(q.question_type, 'edit_mcq_options');
    document.getElementById('editModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == document.getElementById('editModal')) closeModal();
}

window.onload = () => { toggleType(document.getElementById('q_type').value, 'mcq_options_area'); };
</script>

<style>
.pretty-scroll::-webkit-scrollbar { width: 6px; }
.pretty-scroll::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
.pretty-scroll:hover::-webkit-scrollbar-thumb { background: #cbd5e1; }
</style>

<?php include $path_to_root . 'includes/footer.php'; ?>