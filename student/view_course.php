<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    redirect('../index.php');
}

$user_id = $_SESSION['user_id'];
$batch_id = isset($_GET['batch_id']) ? (int) $_GET['batch_id'] : 0;
$course_id = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
$lesson_id = isset($_GET['lesson_id']) ? (int) $_GET['lesson_id'] : 0;

// Handle Mark as Completed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_completion'])) {
    $target_lesson = (int)$_POST['lesson_id'];
    $check = $pdo->prepare("SELECT 1 FROM lesson_status WHERE student_id = ? AND lesson_id = ?");
    $check->execute([$user_id, $target_lesson]);
    if ($check->fetch()) {
        $pdo->prepare("DELETE FROM lesson_status WHERE student_id = ? AND lesson_id = ?")->execute([$user_id, $target_lesson]);
    } else {
        $pdo->prepare("INSERT INTO lesson_status (student_id, lesson_id) VALUES (?, ?)")->execute([$user_id, $target_lesson]);
    }
    // Stay on current lesson
    redirect("view_course.php?batch_id=$batch_id&course_id=$course_id&lesson_id=$lesson_id");
}

// Verify Enrollment (either via batch or direct course access)
$is_enrolled = false;
if ($batch_id > 0) {
    $stmt = $pdo->prepare("SELECT 1 FROM student_batches WHERE student_id = ? AND batch_id = ?");
    $stmt->execute([$user_id, $batch_id]);
    if ($stmt->fetch()) $is_enrolled = true;
}

if (!$is_enrolled) {
    $stmt = $pdo->prepare("SELECT 1 FROM student_courses WHERE student_id = ? AND course_id = ?");
    $stmt->execute([$user_id, $course_id]);
    if ($stmt->fetch()) $is_enrolled = true;
}

if (!$is_enrolled) {
    redirect('index.php');
}

// If batch_id is 0 or invalid, try to find an active batch for this course
if ($batch_id <= 0 && $course_id > 0) {
    $stmt = $pdo->prepare("SELECT batch_id FROM batch_courses bc JOIN batches b ON bc.batch_id = b.id WHERE bc.course_id = ? AND b.status = 'active' LIMIT 1");
    $stmt->execute([$course_id]);
    $batch_id = $stmt->fetchColumn() ?: 0;
}

// 1. Fetch Batch and Course Info
$sql = "SELECT b.*, c.title as course_title, c.description as course_desc 
        FROM batches b 
        JOIN batch_courses bc ON b.id = bc.batch_id
        JOIN courses c ON bc.course_id = c.id
        WHERE b.id = ? AND c.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$batch_id, $course_id]);
$batch = $stmt->fetch();

if (!$batch) {
    // Fallback: Just get course info at least
    $stmt = $pdo->prepare("SELECT title as course_title, description as course_desc FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $batch = $stmt->fetch();
}

// 2. Check if batch is closed (only for batch-only students)
$stmt = $pdo->prepare("SELECT 1 FROM student_courses WHERE student_id = ? AND course_id = ?");
$stmt->execute([$user_id, $course_id]);
$has_direct_access = (bool)$stmt->fetch();

if (!$has_direct_access && isset($batch['status']) && $batch['status'] == 'closed') {
    include $path_to_root . 'includes/header.php';
    include $path_to_root . 'includes/sidebar.php';
    ?>
    <div class="white-card fade-in" style="text-align: center; padding: 100px 20px; border-radius: 24px; background: white;">
        <div style="font-size: 5rem; color: #ff6b6b; margin-bottom: 30px;">
            <i class="fas fa-lock"></i>
        </div>
        <h1 style="font-weight: 800; color: #2d3436; margin-bottom: 20px;">Batch Support Period Ended</h1>
        <p style="color: #636e72; font-size: 1.2rem; max-width: 600px; margin: 0 auto 40px;">
            <?= htmlspecialchars($batch['close_message'] ?: "The active support for this batch has concluded. Lessons and resources are no longer accessible.") ?>
        </p>
        <a href="index.php" class="btn btn-primary" style="padding: 15px 40px; border-radius: 12px; font-weight: 600;">Return to Dashboard</a>
    </div>
    <?php
    include $path_to_root . 'includes/footer.php';
    exit;
}

// Fetch Lessons for this batch AND specific course
$sql = "SELECT * FROM lessons WHERE batch_id = ? AND (course_id = ? OR course_id IS NULL) AND (publish_date IS NULL OR publish_date <= NOW()) AND is_hidden = 0 ORDER BY id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$batch_id, $course_id]);
$lessons = $stmt->fetchAll();

// Find requested or first lesson
$current_lesson = null;
$lesson_to_view = $lesson_id;

foreach ($lessons as $index => $l) {
    if ($l['id'] == $lesson_to_view || ($lesson_to_view == 0 && $index == 0)) {
        // Strict Progression Check: Verify all previous lessons are completed
        for ($i = 0; $i < $index; $i++) {
            if (!in_array($lessons[$i]['id'], $completed_lessons)) {
                // Not completed! Force redirect to the missing lesson
                $missing_id = $lessons[$i]['id'];
                header("Location: view_course.php?batch_id=$batch_id&course_id=$course_id&lesson_id=$missing_id&error=complete_previous");
                exit;
            }
        }
        $current_lesson = $l;
        $lesson_id = $l['id'];
        break;
    }
}

if (!$current_lesson && count($lessons) > 0) {
    $current_lesson = $lessons[0];
    $lesson_id = $current_lesson['id'];
}

// Fetch Completed Lessons for Progress Bar
$completed_lessons = [];
if (count($lessons) > 0) {
    $stmt = $pdo->prepare("SELECT lesson_id FROM lesson_status WHERE student_id = ? AND lesson_id IN (" . implode(',', array_column($lessons, 'id')) . ")");
    $stmt->execute([$user_id]);
    $completed_lessons = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
$progress_percent = count($lessons) > 0 ? round((count($completed_lessons) / count($lessons)) * 100) : 0;

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';
?>

<div style="margin-bottom: 20px;" class="breadcrumb-mobile-fix">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
        <div style="flex: 1;">
            <a href="my_courses.php" style="color: #666; text-decoration: none;"><i class="fas fa-arrow-left"></i> All Programs</a>
            <span style="margin: 0 10px; color: #ccc;">/</span>
            <span style="color: var(--primary); font-weight: 600;">
                <?= htmlspecialchars($batch['course_title'] ?? $batch['name']) ?>
            </span>
        </div>
        
        <!-- Progress Bar -->
        <div style="min-width: 200px; background: #e2e8f0; height: 10px; border-radius: 5px; overflow: hidden; position: relative;">
            <div style="width: <?= $progress_percent ?>%; background: #22c55e; height: 100%; transition: width 0.5s;"></div>
            <div style="position: absolute; right: 0; top: -20px; font-size: 0.75rem; font-weight: 800; color: #15803d;"><?= $progress_percent ?>% Completed</div>
        </div>
    </div>
</div>

<div class="row" style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px;">

    <!-- Video Player / Content Area -->
    <div class="content-area fade-in">
        <?php if ($current_lesson): ?>

            <!-- Exam Gateway Logic -->
            <?php if ($current_lesson['exam_id']): ?>
                <div class="white-card" style="background: linear-gradient(135deg, #fffcf0 0%, #fff7d1 100%); border: 2px solid #fde68a; padding: 40px; border-radius: 24px; text-align: center; margin-bottom: 25px;">
                    <div style="font-size: 3.5rem; color: #fbbf24; margin-bottom: 15px;">
                        <i class="fas fa-file-signature"></i>
                    </div>
                    <h3 style="color: #92400e; font-weight: 800; margin-bottom: 10px;">Module Certification Exam</h3>
                    <p style="color: #b45309; margin-bottom: 25px; font-weight: 600;">This module requires an official assessment to track your proficiency.</p>
                    <a href="take_exam.php?id=<?= $current_lesson['exam_id'] ?>" class="btn" style="background: #fbbf24; color: #78350f; padding: 15px 40px; border-radius: 12px; font-weight: 800; font-size: 1.1rem;">
                        Start Certification Assessment
                    </a>
                </div>
            <?php endif; ?>

            <!-- Video Logic -->
            <?php if (!empty($current_lesson['video_file'])): ?>
                <div class="video-container"
                    style="background: black; border-radius: 16px; overflow: hidden; margin-bottom: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); position: relative; padding-top: 56.25%;">
                    <video id="lessonVideo" controls controlsList="nodownload"
                        oncontextmenu="return false;" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; outline: none;">
                        <source src="<?= htmlspecialchars($path_to_root . $current_lesson['video_file']) ?>" type="video/mp4">
                        Your browser does not support HTML5 video.
                    </video>
                </div>
            <?php elseif (!empty($current_lesson['video_url'])): ?>
                <div class="video-container"
                    style="background: black; border-radius: 16px; overflow: hidden; margin-bottom: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); position: relative; padding-top: 56.25%;">
                    <iframe style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"
                        src="<?= str_replace('watch?v=', 'embed/', htmlspecialchars($current_lesson['video_url'])) ?>"
                        frameborder="0" allowfullscreen></iframe>
                </div>
            <?php endif; ?>

            <?php if (!empty($current_lesson['image_url'])): ?>
                <div class="image-container" style="margin-bottom: 20px;">
                    <img src="<?= htmlspecialchars($current_lesson['image_url']) ?>" alt="Lesson Content"
                        style="width: 100%; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
                </div>
            <?php endif; ?>

            <div class="white-card"
                style="background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <h2 style="margin-bottom: 20px; color: #2d3436;"><?= htmlspecialchars($current_lesson['title']) ?></h2>
                <div class="lesson-content-rendered" style="line-height: 1.8; color: #4a5568; font-size: 1.05rem;" id="lessonContent">
                    <?= $current_lesson['content'] ?>
                </div>

                <!-- AI Summary Feature -->
                <style>
                    .ai-summary-card {
                        margin-top: 40px; 
                        padding: 30px; 
                        background: #f8fafc; 
                        border-radius: 24px; 
                        border: 1px solid #e2e8f0;
                        box-shadow: 0 10px 25px rgba(0,0,0,0.02);
                        transition: 0.3s;
                    }
                    .ai-summary-card:hover { border-color: #cbd5e1; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
                    .ai-title { color: #1e293b; font-weight: 800; display: flex; align-items: center; gap: 10px; margin-bottom: 20px; font-size: 1.1rem; }
                    .ai-badge { background: #eff6ff; color: #3b82f6; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
                    
                    /* Loader Animation */
                    .ai-pulse {
                        width: 12px; height: 12px; background: #3b82f6; border-radius: 50%;
                        display: inline-block; animation: pulse 1.5s infinite;
                    }
                    @keyframes pulse { 0% { transform: scale(0.8); opacity: 0.5; } 50% { transform: scale(1.2); opacity: 1; } 100% { transform: scale(0.8); opacity: 0.5; } }
                    
                    #summaryResult table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 0.9rem; }
                    #summaryResult th, #summaryResult td { padding: 12px; border: 1px solid #e2e8f0; text-align: left; }
                    #summaryResult th { background: #f1f5f9; color: #475569; font-weight: 700; }
                    #summaryResult p { margin-bottom: 15px; color: #475569; font-size: 0.95rem; }
                    #summaryResult ul, #summaryResult ol { margin-bottom: 15px; padding-left: 20px; }
                    #summaryResult li { margin-bottom: 8px; color: #475569; }
                </style>

                <div class="ai-summary-card fade-in">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <div class="ai-title">
                            <i class="fas fa-robot" style="color: #3b82f6;"></i> Smart Lesson Summary 
                            <span class="ai-badge">Beginner Friendly</span>
                        </div>
                        <div id="summaryControls" style="display: none; gap: 8px;">
                            <button onclick="copySummary()" class="btn-action" style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; width:auto; padding:0 12px; font-size:0.8rem; height:32px;" title="Copy to clipboard">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                            <button onclick="generateSummary(<?= $current_lesson['id'] ?>)" class="btn-action" style="background:#3b82f6; color:white; width:auto; padding:0 12px; font-size:0.8rem; height:32px;" title="Regenerate">
                                <i class="fas fa-redo"></i> Regenerate
                            </button>
                        </div>
                        <button id="initialSumBtn" onclick="generateSummary(<?= $current_lesson['id'] ?>)" class="btn btn-primary" style="padding: 10px 20px; border-radius: 12px; font-weight: 700;">
                            <i class="fas fa-magic"></i> Summarize with AI
                        </button>
                    </div>

                    <div id="summaryResult" style="display: none;">
                        <div style="text-align: center; padding: 40px; background: white; border-radius: 16px; border: 1px dashed #e2e8f0;">
                            <div style="margin-bottom: 15px;">
                                <div class="ai-pulse" style="animation-delay: 0s;"></div>
                                <div class="ai-pulse" style="animation-delay: 0.2s; margin: 0 8px;"></div>
                                <div class="ai-pulse" style="animation-delay: 0.4s;"></div>
                            </div>
                            <div style="color: #64748b; font-weight: 600; font-size: 0.95rem;">AI is analyzing this lesson for you...</div>
                            <div style="color: #94a3b8; font-size: 0.8rem; margin-top: 5px;">Building beginner-friendly concepts</div>
                        </div>
                    </div>
                </div>

                <!-- Marked for Markdown Support -->
                <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

                <script>
                let currentRawSummary = '';

                async function generateSummary(lessonId) {
                    const initialBtn = document.getElementById('initialSumBtn');
                    const controls = document.getElementById('summaryControls');
                    const result = document.getElementById('summaryResult');
                    
                    initialBtn.style.display = 'none';
                    controls.style.display = 'none';
                    result.style.display = 'block';
                    result.innerHTML = `
                        <div style="text-align: center; padding: 40px; background: white; border-radius: 16px; border: 1px dashed #e2e8f0;">
                            <div style="margin-bottom: 15px;">
                                <div class="ai-pulse" style="animation-delay: 0s;"></div>
                                <div class="ai-pulse" style="animation-delay: 0.2s; margin: 0 8px;"></div>
                                <div class="ai-pulse" style="animation-delay: 0.4s;"></div>
                            </div>
                            <div style="color: #64748b; font-weight: 600; font-size: 0.95rem;">AI is analyzing this lesson for you...</div>
                            <div style="color: #94a3b8; font-size: 0.8rem; margin-top: 5px;">Building beginner-friendly concepts</div>
                        </div>
                    `;

                    try {
                        const response = await fetch('summarize_lesson.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `lesson_id=${lessonId}`
                        });
                        const data = await response.json();
                        
                        if (data.success) {
                            currentRawSummary = data.summary;
                            // Inject markdown rendering
                            result.innerHTML = `
                                <div class="fade-in" style="background: white; padding: 25px; border-radius: 16px; border: 1px solid #f1f5f9; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                                    ${marked.parse(data.summary)}
                                </div>
                            `;
                            controls.style.display = 'flex';
                        } else {
                            result.innerHTML = '<div style="color: #ef4444; padding: 20px; text-align: center; font-weight: 600;"><i class="fas fa-exclamation-circle"></i> Error: ' + data.message + '</div>';
                            initialBtn.style.display = 'block';
                        }
                    } catch (error) {
                        result.innerHTML = '<div style="color: #ef4444; padding: 20px; text-align: center; font-weight: 600;"><i class="fas fa-wifi"></i> Connection failed. Please try again.</div>';
                        initialBtn.style.display = 'block';
                    }
                }

                function copySummary() {
                    if (!currentRawSummary) return;
                    navigator.clipboard.writeText(currentRawSummary).then(() => {
                        const copyBtn = document.querySelector('[title="Copy to clipboard"]');
                        const originalText = copyBtn.innerHTML;
                        copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                        copyBtn.style.background = '#dcfce7'; 
                        copyBtn.style.color = '#166534';
                        setTimeout(() => {
                            copyBtn.innerHTML = originalText;
                            copyBtn.style.background = '#f1f5f9';
                            copyBtn.style.color = '#475569';
                        }, 2000);
                    });
                }
                </script>

                <!-- Prism.js for Code Highlighting -->
                <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet" />
                <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-css.min.js"></script>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup.min.js"></script>
                
                <style>
                    /* Force main sidebar fix for this page */
                    .sidebar { background: white !important; }
                    .sidebar nav, .sidebar ul { background: transparent !important; box-shadow: none !important; margin: 0; padding: 0; }
                    .sidebar .nav-link { text-decoration: none !important; }
                    .main-content { background: var(--slate-50) !important; padding: 30px !important; }
                    
                    .lesson-content-rendered pre {
                        border-radius: 12px;
                        margin: 25px 0;
                        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
                        background: #1e1e1e !important;
                    }
                    .lesson-content-rendered code {
                        font-family: 'Fira Code', 'Consolas', monospace;
                        font-size: 0.95rem;
                    }
                    .lesson-content-rendered h1, .lesson-content-rendered h2, .lesson-content-rendered h3 {
                        color: #1a202c;
                        margin-top: 30px;
                        margin-bottom: 15px;
                    }
                    .lesson-content-rendered p { margin-bottom: 20px; }
                    .lesson-content-rendered table { width: 100%; border-collapse: collapse; margin-bottom: 25px; background: white; }
                    .lesson-content-rendered th, .lesson-content-rendered td { padding: 15px; border: 1px solid #edf2f7; text-align: left; }
                    .lesson-content-rendered th { background: #f8fafc; font-weight: 700; color: #4a5568; }
                    .lesson-content-rendered img { max-width: 100%; height: auto; border-radius: 12px; display: block; margin: 25px auto; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
                </style>

                <!-- Resources Section -->
                <?php
                $rStmt = $pdo->prepare("SELECT * FROM resources WHERE lesson_id = ?");
                $rStmt->execute([$current_lesson['id']]);
                $resources = $rStmt->fetchAll();
                ?>

                <?php if (count($resources) > 0): ?>
                    <div style="margin-top: 40px; border-top: 2px solid #f7fafc; padding-top: 25px;">
                        <h4 style="margin-bottom: 20px; color: #2d3748;"><i class="fas fa-paperclip" style="color: var(--primary);"></i> Downloads & Reference Links</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;">
                            <?php foreach ($resources as $r): ?>
                                <?php if ($r['type'] == 'link'): ?>
                                    <a href="<?= htmlspecialchars($r['file_url']) ?>" target="_blank" class="resource-btn" style="text-decoration: none; display: flex; align-items: center; gap: 10px; background: #ebf4ff; color: #2b6cb0; padding: 12px; border-radius: 10px; font-weight: 500;">
                                        <i class="fas fa-external-link-alt"></i> 
                                        <span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($r['title']) ?></span>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars($path_to_root . $r['file_url']) ?>" download class="resource-btn" style="text-decoration: none; display: flex; align-items: center; gap: 10px; background: #f7fafc; color: #4a5568; padding: 12px; border-radius: 10px; font-weight: 500; border: 1px solid #e2e8f0;">
                                        <i class="fas fa-file-download"></i>
                                        <span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($r['title']) ?></span>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Mark as Completed Button -->
                <div style="margin-top: 40px; padding-top: 30px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end;">
                    <form method="POST">
                        <input type="hidden" name="lesson_id" value="<?= $current_lesson['id'] ?>">
                        <?php if (in_array($current_lesson['id'], $completed_lessons)): ?>
                            <button type="submit" name="toggle_completion" class="btn btn-success" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 12px 25px; border-radius: 12px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-check-circle"></i> Lesson Completed
                            </button>
                        <?php else: ?>
                            <button type="submit" name="toggle_completion" class="btn btn-primary" style="padding: 12px 30px; border-radius: 12px; font-weight: 700;">
                                Mark as Completed
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="white-card" style="padding: 100px 40px; text-align: center; border-radius: 20px; background: white;">
                <div style="font-size: 4rem; color: #edf2f7; margin-bottom: 20px;">
                    <i class="fas fa-video-slash"></i>
                </div>
                <h3 style="color: #4a5568;">Curriculum Coming Soon</h3>
                <p style="color: #a0aec0;">The instructor hasn't uploaded lessons for this course yet. Stay tuned!</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Syllabus Toggle for Mobile -->
    <div id="syllabusToggle" class="syllabus-toggle">
        <i class="fas fa-chevron-right" id="toggleIcon"></i>
    </div>

    <!-- Lesson Sidebar (Program Syllabus) -->
    <div class="lesson-sidebar" id="syllabusDrawer">
        <?php 
        // Fetch associated certificate exam for THIS specific course
        $stmt = $pdo->prepare("SELECT id, title FROM exams WHERE course_id = ? AND is_certificate_exam = 1 AND is_published = 1 AND (publish_date IS NULL OR publish_date <= NOW()) LIMIT 1");
        $stmt->execute([$course_id]);
        $cert_exam = $stmt->fetch();
        ?>
        <div style="background: white; width: 100%; height: 100%; box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden;">
            <div style="padding: 24px; border-bottom: 1px solid #f1f5f9;">
                <h3 style="font-weight: 800; color: #1a202c; font-size: 1.15rem; margin-bottom: 5px;">Program Syllabus</h3>
                <p style="color: #94a3b8; font-size: 0.85rem; font-weight: 600;"><?= count($lessons) + ($cert_exam ? 1 : 0) ?> Milestones available</p>
            </div>
            
            <div class="syllabus-list" style="max-height: calc(100vh - 120px); overflow-y: auto;">
                <?php foreach ($lessons as $index => $l): 
                    $isActive = ($l['id'] == $lesson_id);
                    $itemStyle = $isActive ? 'background: #ebf4ff; border-left: 4px solid #3182ce;' : 'border-left: 4px solid transparent;';
                ?>
                    <a href="?batch_id=<?= $batch_id ?>&course_id=<?= $course_id ?>&lesson_id=<?= $l['id'] ?>" 
                       style="display: flex; gap: 15px; padding: 20px 24px; text-decoration: none; border-bottom: 1px solid #f8fafc; transition: all 0.2s; <?= $itemStyle ?>">
                        <div style="font-weight: 800; color: <?= $isActive ? '#3182ce' : '#cbd5e1' ?>; font-size: 0.9rem;">
                            <?= sprintf('%02d', $index + 1) ?>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-weight: 700; color: <?= $isActive ? '#2c5282' : '#4a5568' ?>; font-size: 0.95rem; margin-bottom: 4px;">
                                <?= htmlspecialchars($l['title']) ?>
                                <?php if ($l['is_optional']): ?>
                                    <span style="font-size: 0.7rem; color: #94a3b8; font-weight: 400; margin-left: 5px;">(Optional)</span>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; align-items: center; gap: 6px; font-size: 0.75rem; color: #94a3b8; font-weight: 600;">
                                <?php if ($l['exam_id']): ?>
                                    <i class="fas fa-file-signature" style="color: #f59e0b;"></i> Certification Quiz
                                <?php else: ?>
                                    <i class="fas fa-circle-play"></i> Video Lesson
                                <?php endif; ?>
                            <?php if (in_array($l['id'], $completed_lessons)): ?>
                                <span style="margin-left: 8px; color: #22c55e;"><i class="fas fa-check-circle"></i> Done</span>
                            <?php endif; ?>
                        </div>
                        </div>
                    </a>
                <?php endforeach; ?>

                <?php if ($cert_exam):
                    $isCertActive = (isset($_GET['is_cert']) && $_GET['is_cert'] == 1);
                    $certStyle = $isCertActive ? 'background: #fffcf0; border-left: 4px solid #d97706;' : 'border-left: 4px solid #e2e8f0; background: #fffdf5;';
                ?>
                    <div style="padding: 10px 24px 5px; background: #f8fafc; font-size: 0.7rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px;">Final Requirement</div>
                    <a href="take_exam.php?id=<?= $cert_exam['id'] ?>" 
                       style="display: flex; gap: 15px; padding: 25px 24px; text-decoration: none; border-bottom: 1px solid #fef3c7; transition: all 0.2s; <?= $certStyle ?>">
                        <div style="font-weight: 800; color: #d97706; font-size: 1rem; width: 24px; text-align: center;">
                            <i class="fas fa-award"></i>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-weight: 800; color: #92400e; font-size: 1rem; margin-bottom: 4px;">
                                Certification Assessment
                            </div>
                            <div style="display: flex; align-items: center; gap: 6px; font-size: 0.75rem; color: #b45309; font-weight: 700;">
                                <i class="fas fa-file-alt"></i> <?= htmlspecialchars($cert_exam['title']) ?>
                            </div>
                        </div>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    // Syllabus Drawer Toggle Logic for Mobile
    const syllabusToggle = document.getElementById('syllabusToggle');
    if (syllabusToggle) {
        syllabusToggle.addEventListener('click', function() {
            document.body.classList.toggle('syllabus-open');
            const icon = document.getElementById('toggleIcon');
            
            if (document.body.classList.contains('syllabus-open')) {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
            } else {
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
            }
        });
    }
</script>

<style>
    .syllabus-toggle { display: none; } /* Hidden on desktop */
    .resource-btn:hover { background: #e2e8f0 !important; }

    @media (max-width: 992px) {
        .row { grid-template-columns: 1fr !important; gap: 20px !important; }
        
        /* Syllabus Drawer Styling - MOVED TO LEFT */
        .syllabus-toggle {
            display: flex;
            position: fixed !important; /* Extremely robust fixed positioning */
            left: 0;
            top: 120px; /* Permanently fixed distance from top */
            background: var(--primary);
            color: white;
            width: 45px;
            height: 55px;
            border-radius: 0 16px 16px 0;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 5px 0 15px rgba(0,0,0,0.1);
            z-index: 20000; /* Higher than drawer to stay visible */
            font-size: 1rem;
            border: 2px solid white;
            border-left: none;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .lesson-sidebar {
            position: fixed !important;
            left: -320px; /* Hidden off-screen to the left */
            top: 0;
            width: 310px !important;
            height: 100dvh;
            z-index: 10000;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            padding: 0;
            background: white;
            border-right: 1px solid #e2e8f0;
        }
        
        body.syllabus-open .lesson-sidebar {
            left: 0px;
            box-shadow: 10px 0 40px rgba(0,0,0,0.15);
        }

        body.syllabus-open .syllabus-toggle {
            left: 310px; /* Move toggle with drawer */
        }
...

        .main-content { padding: 80px 15px 15px !important; } 
        
        .lesson-title-mobile-fix { font-size: 1.5rem !important; margin-bottom: 20px !important; line-height: 1.3 !important; }
        .breadcrumb-mobile-fix { padding-right: 60px; margin-bottom: 30px !important; }
        
        .lesson-content-rendered { font-size: 1rem !important; }
        .lesson-content-rendered h1 { font-size: 1.6rem !important; }
        .lesson-content-rendered h2 { font-size: 1.4rem !important; }
        .lesson-content-rendered h3 { font-size: 1.2rem !important; }
        
        .white-card { padding: 20px !important; border-radius: 20px !important; }
    }
</style>

    <!-- AI Voice Tutor FAB -->
    <div id="aiTutorBtn" class="ai-fab" onclick="toggleTutor()">
        <div class="ai-fab-aura"></div>
        <i class="fas fa-robot"></i>
        <span>AI TUTOR</span>
    </div>

    <!-- AI Voice Tutor Overlay -->
    <div id="tutorOverlay" class="tutor-overlay">
        <div class="tutor-modal fade-in">
            <div class="tutor-header">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="tutor-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; color: #1e293b; font-size: 1.1rem;">AI Tutor Live</h3>
                        <p id="tutorStatus" style="margin: 0; font-size: 0.75rem; color: #10b981; font-weight: 700;">READY TO HELP</p>
                    </div>
                </div>
                <button onclick="toggleTutor()" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer;">&times;</button>
            </div>

            <div class="tutor-body" id="tutorBody">
                <div class="tutor-bubble ai-msg">
                    Hello! I'm your AI Tutor. You can ask me anything about "<?= htmlspecialchars($current_lesson['title'] ?? 'this lesson') ?>". I'm listening!
                </div>
            </div>

            <div class="tutor-footer">
                <div id="visualizer" class="visualizer">
                    <div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div><div class="bar"></div>
                </div>
                <div style="display: flex; justify-items: center; gap: 20px; align-items: center;">
                    <button id="muteBtn" class="tutor-tool-btn" onclick="toggleMute()" title="Mute Mic">
                        <i class="fas fa-microphone"></i>
                    </button>
                    <button id="micCircle" class="mic-main-btn" onclick="startRecognition()">
                        <i class="fas fa-microphone"></i>
                    </button>
                    <button id="ttsBtn" class="tutor-tool-btn active" onclick="toggleTTS()" title="Mute AI Voice">
                        <i class="fas fa-volume-up"></i>
                    </button>
                </div>
                <p id="transcript" style="margin-top: 15px; font-size: 0.85rem; color: #94a3b8; text-align: center; min-height: 20px; font-style: italic;"></p>
            </div>
        </div>
    </div>

    <style>
        .ai-fab {
            position: fixed; bottom: 30px; left: 30px; background: #3b82f6; color: white;
            padding: 15px 25px; border-radius: 50px; cursor: pointer; display: flex; align-items: center; gap: 10px;
            font-weight: 800; font-size: 0.9rem; box-shadow: 0 10px 30px rgba(59, 130, 246, 0.4); z-index: 9999;
            transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .ai-fab:hover { transform: translateY(-5px) scale(1.05); }
        .ai-fab-aura { position: absolute; inset: 0; border-radius: 50px; background: #3b82f6; opacity: 0.3; animation: aura 2s infinite; }
        @keyframes aura { 0% { transform: scale(1); opacity: 0.3; } 100% { transform: scale(1.4); opacity: 0; } }

        .tutor-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(8px); z-index: 10000; display: none; align-items: center; justify-content: center; padding: 20px; }
        .tutor-modal { background: white; width: 600px; max-width: 100%; border-radius: 32px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 25px 50px rgba(0,0,0,0.3); max-height: 90vh; }
        .tutor-header { padding: 25px; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; }
        .tutor-avatar { width: 45px; height: 45px; background: #3b82f6; color: white; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        
        .tutor-body { padding: 25px; height: 450px; overflow-y: auto; background: #ffffff; display: flex; flex-direction: column; gap: 15px; }
        .tutor-bubble { padding: 15px 20px; border-radius: 20px; font-size: 0.95rem; line-height: 1.5; max-width: 90%; }
        .tutor-bubble table { width: 100%; border-collapse: collapse; margin: 10px 0; border: 1px solid #e2e8f0; }
        .tutor-bubble th, .tutor-bubble td { padding: 8px; border: 1px solid #e2e8f0; font-size: 0.85rem; }
        .tutor-bubble th { background: #f1f5f9; }
        
        .ai-msg { background: #f1f5f9; color: #334155; border-bottom-left-radius: 4px; align-self: flex-start; }
        .user-msg { background: #3b82f6; color: white; border-bottom-right-radius: 4px; align-self: flex-end; }


        .tutor-footer { padding: 30px; background: #f8fafc; border-top: 1px solid #f1f5f9; display: flex; flex-direction: column; align-items: center; }
        .mic-main-btn { width: 70px; height: 70px; background: #3b82f6; color: white; border: none; border-radius: 50%; font-size: 1.5rem; cursor: pointer; box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3); transition: 0.3s; }
        .mic-main-btn.listening { background: #ef4444; animation: ripple 1s infinite alternate; }
        @keyframes ripple { from { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); } to { box-shadow: 0 0 0 20px rgba(239, 68, 68, 0); } }
        
        .tutor-tool-btn { background: #f1f5f9; border: none; width: 40px; height: 40px; border-radius: 50%; color: #64748b; cursor: pointer; transition: 0.2s; }
        .tutor-tool-btn.active { color: #3b82f6; background: #eff6ff; }
        .tutor-tool-btn.muted { color: #ef4444; background: #fef2f2; }

        .visualizer { display: flex; align-items: center; gap: 4px; height: 30px; margin-bottom: 20px; display: none; }
        .visualizer.active { display: flex; }
        .bar { width: 4px; height: 10px; background: #3b82f6; border-radius: 2px; }
        .visualizer.active .bar { animation: barRise 0.5s infinite alternate; }
        .bar:nth-child(2) { animation-delay: 0.1s; } .bar:nth-child(3) { animation-delay: 0.2s; } .bar:nth-child(4) { animation-delay: 0.3s; }
        @keyframes barRise { from { height: 5px; } to { height: 25px; } }
    </style>

    <script>
        let isMuted = false;
        let ttsEnabled = true;
        let recognition = null;
        let synth = window.speechSynthesis;

        if ('webkitSpeechRecognition' in window) {
            recognition = new webkitSpeechRecognition();
            recognition.continuous = true; 
            recognition.interimResults = true;
            recognition.lang = 'en-US';

            recognition.onstart = () => {
                document.getElementById('tutorStatus').innerText = 'LISTENING...';
                document.getElementById('tutorStatus').style.color = '#ef4444';
                document.getElementById('micCircle').classList.add('listening');
                document.getElementById('visualizer').classList.add('active');
            };

            recognition.onresult = (event) => {
                let inter = '';
                for (let i = event.resultIndex; i < event.results.length; ++i) {
                    if (event.results[i].isFinal) {
                        handleVoiceCommand(event.results[i][0].transcript);
                    } else {
                        inter += event.results[i][0].transcript;
                    }
                }
                document.getElementById('transcript').innerText = inter;
            };

            recognition.onend = () => {
                document.getElementById('tutorStatus').innerText = 'READY';
                document.getElementById('tutorStatus').style.color = '#10b981';
                document.getElementById('micCircle').classList.remove('listening');
                document.getElementById('visualizer').classList.remove('active');
            };
        }

        function toggleTutor() {
            const overlay = document.getElementById('tutorOverlay');
            const isOpening = overlay.style.display !== 'flex';
            overlay.style.display = isOpening ? 'flex' : 'none';
            if (isOpening) {
                if(recognition && !isMuted) recognition.start();
            } else {
                if(recognition) recognition.stop();
                if(synth.speaking) synth.cancel();
            }
        }

        function toggleMute() {
            isMuted = !isMuted;
            const btn = document.getElementById('muteBtn');
            btn.classList.toggle('muted', isMuted);
            btn.innerHTML = isMuted ? '<i class="fas fa-microphone-slash"></i>' : '<i class="fas fa-microphone"></i>';
            if (isMuted) {
                if(recognition) recognition.stop();
            } else {
                if(recognition) recognition.start();
            }
        }

        function toggleTTS() {
            ttsEnabled = !ttsEnabled;
            const btn = document.getElementById('ttsBtn');
            btn.classList.toggle('active', ttsEnabled);
            btn.innerHTML = ttsEnabled ? '<i class="fas fa-volume-up"></i>' : '<i class="fas fa-volume-mute"></i>';
            if(!ttsEnabled && synth.speaking) synth.cancel();
        }

        function startRecognition() {
            if (isMuted) return toggleMute();
            if (!recognition) return alert("Speech recognition not supported.");
            recognition.start();
        }

        function sendQuickTask(task) {
            handleVoiceCommand(task);
            // Hide quick tasks after first use to clean up UI
            document.getElementById('aiQuickTasks').style.display = 'none';
        }

        async function handleVoiceCommand(text) {
            if (!text.trim()) return;
            
            // STOP MIC while processing/answering
            if(recognition) recognition.stop();
            
            appendMessage('user', text);
            document.getElementById('transcript').innerText = '';

            try {
                const response = await fetch('ai_tutor_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `message=${encodeURIComponent(text)}&lesson_id=<?= $current_lesson['id'] ?>`
                });
                const data = await response.json();
                
                if (data.success) {
                    appendMessage('ai', data.reply);
                    if (ttsEnabled) {
                        speak(data.reply);
                    } else {
                        // If TTS disabled, manually restart mic after delay
                        setTimeout(() => {
                            if (!isMuted && document.getElementById('tutorOverlay').style.display === 'flex') {
                                recognition.start();
                            }
                        }, 1000);
                    }
                }
            } catch (e) {
                console.error(e);
            }
        }

        function appendMessage(role, text) {
            const body = document.getElementById('tutorBody');
            const div = document.createElement('div');
            div.className = `tutor-bubble ${role}-msg`;
            
            if (role === 'ai') {
                div.innerHTML = marked.parse(text);
            } else {
                div.innerText = text;
            }
            
            body.appendChild(div);
            // Enhanced scroll logic
            setTimeout(() => {
                body.scrollTop = body.scrollHeight;
            }, 50);
        }

        function speak(text) {
            if (!ttsEnabled) return;
            if (synth.speaking) synth.cancel();

            let cleanText = text
                .replace(/\*/g, '')
                .replace(/#/g, '')
                .replace(/_/g, '')
                .replace(/\|/g, ' ')
                .replace(/-{2,}/g, ' ')
                .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
                .replace(/:/g, '. ')
                .replace(/\s+/g, ' ')
                .trim();

            const utterance = new SpeechSynthesisUtterance(cleanText);
            utterance.rate = 1;
            utterance.pitch = 1;

            // ON END: Re-activate microphone automatically
            utterance.onend = () => {
                if (!isMuted && document.getElementById('tutorOverlay').style.display === 'flex') {
                    recognition.start();
                }
            };

            synth.speak(utterance);
        }
    </script>
<?php include $path_to_root . 'includes/footer.php'; ?>