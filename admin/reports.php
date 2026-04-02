<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    redirect('../index.php');
}

// Sub-admin permission check
if ($_SESSION['role'] === 'sub_admin' && !hasPermission('settings')) { // Using settings permission for reports
    redirect('index.php');
}

include $path_to_root . 'includes/header.php';
include $path_to_root . 'includes/sidebar.php';

// 1. Overall Completion Analytics
$stats = [
    'total_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
    'avg_exam_score' => $pdo->query("SELECT AVG(score) FROM student_exams WHERE score IS NOT NULL")->fetchColumn() ?: 0,
    'total_completions' => $pdo->query("SELECT COUNT(*) FROM lesson_status")->fetchColumn(),
    'total_projects' => $pdo->query("SELECT COUNT(*) FROM project_submissions WHERE score IS NOT NULL")->fetchColumn()
];

// 2. Course-wise Progress
$course_progress = $pdo->query("
    SELECT c.title, 
           COUNT(DISTINCT sc.student_id) as enrolled,
           (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) as total_lessons,
           (SELECT COUNT(*) FROM lesson_status ls JOIN lessons l ON ls.lesson_id = l.id WHERE l.course_id = c.id) as completed_lessons
    FROM courses c
    LEFT JOIN student_courses sc ON c.id = sc.course_id
    GROUP BY c.id
")->fetchAll();

// 3. Top Students (Combined Exam + Project Scores)
$top_students = $pdo->query("
    SELECT u.name, u.email,
           (SELECT AVG(score) FROM student_exams se WHERE se.student_id = u.id) as avg_exam,
           (SELECT AVG(score) FROM project_submissions ps WHERE ps.student_id = u.id) as avg_proj,
           (SELECT COUNT(*) FROM lesson_status ls WHERE ls.student_id = u.id) as lessons_done
    FROM users u
    WHERE u.role = 'student'
    ORDER BY avg_exam DESC, avg_proj DESC
    LIMIT 10
")->fetchAll();

?>

<div style="margin-bottom: 30px;">
    <h2>Learning Analytics & Performance Reports 📊</h2>
    <p style="color: #64748b;">Monitor student progress and platform engagement</p>
</div>

<!-- Stats Row -->
<div class="stat-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px;">
    <div class="white-card" style="padding: 25px; border-radius: 20px; border-left: 5px solid #3b82f6;">
        <div style="font-size: 0.85rem; font-weight: 700; color: #64748b; margin-bottom: 10px; text-transform: uppercase;">Total Enrollment</div>
        <div style="font-size: 1.8rem; font-weight: 800; color: #1e293b;"><?= $stats['total_students'] ?></div>
    </div>
    <div class="white-card" style="padding: 25px; border-radius: 20px; border-left: 5px solid #10b981;">
        <div style="font-size: 0.85rem; font-weight: 700; color: #64748b; margin-bottom: 10px; text-transform: uppercase;">Avg Exam Score</div>
        <div style="font-size: 1.8rem; font-weight: 800; color: #1e293b;"><?= number_format($stats['avg_exam_score'], 1) ?>%</div>
    </div>
    <div class="white-card" style="padding: 25px; border-radius: 20px; border-left: 5px solid #f59e0b;">
        <div style="font-size: 0.85rem; font-weight: 700; color: #64748b; margin-bottom: 10px; text-transform: uppercase;">Lesson Completions</div>
        <div style="font-size: 1.8rem; font-weight: 800; color: #1e293b;"><?= $stats['total_completions'] ?></div>
    </div>
    <div class="white-card" style="padding: 25px; border-radius: 20px; border-left: 5px solid #8b5cf6;">
        <div style="font-size: 0.85rem; font-weight: 700; color: #64748b; margin-bottom: 10px; text-transform: uppercase;">Graded Projects</div>
        <div style="font-size: 1.8rem; font-weight: 800; color: #1e293b;"><?= $stats['total_projects'] ?></div>
    </div>
</div>

<div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
    <!-- Course Progress Table -->
    <div class="white-card" style="padding: 30px; border-radius: 24px;">
        <h3 style="margin-bottom: 20px;">Course Progress Summary</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Students</th>
                        <th>Progress</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($course_progress as $cp): 
                        $overall_progress = ($cp['enrolled'] > 0 && $cp['total_lessons'] > 0) ? round(($cp['completed_lessons'] / ($cp['enrolled'] * $cp['total_lessons'])) * 100) : 0;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($cp['title']) ?></strong></td>
                        <td><?= $cp['enrolled'] ?> Students</td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="flex: 1; height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden;">
                                    <div style="width: <?= $overall_progress ?>%; height: 100%; background: #3b82f6;"></div>
                                </div>
                                <span style="font-size: 0.75rem; font-weight: 700; color: #3b82f6;"><?= $overall_progress ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Top Students -->
    <div class="white-card" style="padding: 30px; border-radius: 24px; background: #1e293b; color: white;">
        <h3 style="margin-bottom: 20px; color: white;">Student Performance Leaderboard 🏆</h3>
        <div class="table-container">
            <table style="color: white; border-color: rgba(255,255,255,0.1);">
                <thead>
                    <tr style="background: rgba(255,255,255,0.05); color: #94a3b8;">
                        <th style="border-bottom: 1px solid rgba(255,255,255,0.1);">Student</th>
                        <th style="border-bottom: 1px solid rgba(255,255,255,0.1);">Exam Avg</th>
                        <th style="border-bottom: 1px solid rgba(255,255,255,0.1);">Project Avg</th>
                        <th style="border-bottom: 1px solid rgba(255,255,255,0.1);">Lessons</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_students as $ts): ?>
                    <tr>
                        <td style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <strong><?= htmlspecialchars($ts['name']) ?></strong><br>
                            <small style="color: #94a3b8;"><?= htmlspecialchars($ts['email']) ?></small>
                        </td>
                        <td style="border-bottom: 1px solid rgba(255,255,255,0.05); color: #fbbf24; font-weight: 700;">
                            <?= $ts['avg_exam'] ? number_format($ts['avg_exam'], 1) . '%' : '-' ?>
                        </td>
                        <td style="border-bottom: 1px solid rgba(255,255,255,0.05); color: #38bdf8; font-weight: 700;">
                            <?= $ts['avg_proj'] ? number_format($ts['avg_proj'], 1) . '%' : '-' ?>
                        </td>
                        <td style="border-bottom: 1px solid rgba(255,255,255,0.05); color: #4ade80; font-weight: 700;">
                            <?= $ts['lessons_done'] ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>
