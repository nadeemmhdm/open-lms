<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Database;

final class DashboardController
{
    public static function show(): void
    {
        Auth::requireAuth();
        $pdo = Database::connection();
        $user = Auth::user();
        $userId = (int)$user['id'];
        $isStudent = in_array('student', $user['roles'], true);
        $isTeacherOnly = in_array('teacher', $user['roles'], true) && !Auth::hasAnyRole(['super_admin','administrator','hod']);
        $isPrivileged = Auth::isPrivileged();

        $subjectCount = (int)$pdo->query("SELECT COUNT(*) FROM subjects WHERE status='active'")->fetchColumn();
        $features = (int)$pdo->query('SELECT COUNT(*) FROM feature_settings WHERE enabled = 1')->fetchColumn();
        $myCourses = 0;
        $myProgress = 0;
        $lessonCount = 0;
        $userCount = 0;
        $courseCount = 0;

        if ($isStudent) {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM courses c WHERE c.status='published' AND (EXISTS (SELECT 1 FROM enrollments e WHERE e.course_id=c.id AND e.user_id=? AND e.status IN ('active','completed')) OR EXISTS (SELECT 1 FROM course_batches cb INNER JOIN batch_members bm ON bm.batch_id=cb.batch_id WHERE cb.course_id=c.id AND bm.user_id=?))");
            $stmt->execute([$userId, $userId]);
            $myCourses = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM lesson_progress WHERE user_id=? AND progress_percent=100');
            $stmt->execute([$userId]);
            $myProgress = (int)$stmt->fetchColumn();
        } elseif ($isTeacherOnly) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE teacher_id=? AND status<>'archived'");
            $stmt->execute([$userId]);
            $myCourses = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lessons l INNER JOIN course_sections cs ON cs.id=l.course_section_id INNER JOIN courses c ON c.id=cs.course_id WHERE c.teacher_id=? AND l.status<>'archived'");
            $stmt->execute([$userId]);
            $lessonCount = (int)$stmt->fetchColumn();
        } else {
            $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $courseCount = (int)$pdo->query("SELECT COUNT(*) FROM courses WHERE status<>'archived'")->fetchColumn();
            $lessonCount = (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE status<>'archived'")->fetchColumn();
        }

        if ($isPrivileged) {
            $recent = $pdo->query('SELECT action, description, created_at FROM audit_logs ORDER BY id DESC LIMIT 8')->fetchAll();
            $activityTitle = 'Recent system activity';
        } else {
            $stmt = $pdo->prepare('SELECT action, description, created_at FROM audit_logs WHERE actor_user_id=? ORDER BY id DESC LIMIT 8');
            $stmt->execute([$userId]);
            $recent = $stmt->fetchAll();
            $activityTitle = 'Your recent activity';
        }

        ob_start(); ?>
        <div class="dashboard-hero card"><div><span class="eyebrow">Overview</span><h1>Welcome back, <?= e($user['name']) ?></h1><p class="muted"><?= $isStudent ? 'Continue your courses and keep your learning progress moving.' : ($isTeacherOnly ? 'Manage your teaching work from one focused workspace.' : 'Monitor the LMS and jump into the areas that need attention.') ?></p></div><div class="dashboard-actions"><a class="btn btn-primary" href="<?= e(app_url('?r=subjects')) ?>">Open learning</a><a class="btn" href="<?= e(app_url('?r=profile')) ?>">My profile</a></div></div>
        <div class="stats-grid dashboard-stats">
            <?php if($isStudent): ?>
              <div class="stat card"><span>My courses</span><strong><?= $myCourses ?></strong><small>Available learning spaces</small></div>
              <div class="stat card"><span>Lessons completed</span><strong><?= $myProgress ?></strong><small>Your completed lessons</small></div>
              <div class="stat card"><span>Active subjects</span><strong><?= $subjectCount ?></strong><small>Across the LMS</small></div>
              <div class="stat card"><span>Enabled tools</span><strong><?= $features ?></strong><small>Learning modules available</small></div>
            <?php elseif($isTeacherOnly): ?>
              <div class="stat card"><span>My courses</span><strong><?= $myCourses ?></strong><small>Courses assigned to you</small></div>
              <div class="stat card"><span>My lessons</span><strong><?= $lessonCount ?></strong><small>Lessons in your courses</small></div>
              <div class="stat card"><span>Active subjects</span><strong><?= $subjectCount ?></strong><small>Available subject areas</small></div>
              <div class="stat card"><span>Enabled tools</span><strong><?= $features ?></strong><small>Teaching modules available</small></div>
            <?php else: ?>
              <div class="stat card"><span>Users</span><strong><?= $userCount ?></strong><small>Accounts in the platform</small></div>
              <div class="stat card"><span>Subjects</span><strong><?= $subjectCount ?></strong><small>Active subject areas</small></div>
              <div class="stat card"><span>Courses</span><strong><?= $courseCount ?></strong><small>Non-archived courses</small></div>
              <div class="stat card"><span>Lessons</span><strong><?= $lessonCount ?></strong><small>Available lesson records</small></div>
            <?php endif; ?>
        </div>
        <div class="grid-2 dashboard-grid">
            <section class="card quick-panel"><div class="section-title"><div><span class="eyebrow">Quick access</span><h2>What do you want to do?</h2></div></div><div class="quick-links"><a href="<?= e(app_url('?r=subjects')) ?>"><strong>Learning</strong><span>Browse subjects and courses</span></a><?php if(feature_enabled('attendance')): ?><a href="<?= e(app_url('?r=attendance')) ?>"><strong>Attendance</strong><span>Open attendance tools</span></a><?php endif; ?><?php if($isStudent && (feature_enabled('quizzes') || feature_enabled('exams'))): ?><a href="<?= e(app_url('?r=assessments')) ?>"><strong>Assessments</strong><span>View quizzes and exams</span></a><?php endif; ?><?php if($isPrivileged): ?><a href="<?= e(app_url('?r=users')) ?>"><strong>Users</strong><span>Manage LMS accounts</span></a><?php endif; ?><a href="<?= e(app_url('?r=profile')) ?>"><strong>Profile</strong><span>Account and password settings</span></a></div></section>
            <section class="card"><div class="section-title"><div><span class="eyebrow">Activity</span><h2><?= e($activityTitle) ?></h2></div><?php if($isPrivileged): ?><a class="text-link" href="<?= e(app_url('?r=audit')) ?>">View audit log</a><?php endif; ?></div><?php if (!$recent): ?><div class="empty-inline"><p class="muted">No activity yet.</p></div><?php else: ?><div class="activity-list"><?php foreach ($recent as $item): ?><div><strong><?= e($item['action']) ?></strong><span><?= e($item['description']) ?></span><small><?= e($item['created_at']) ?> UTC</small></div><?php endforeach; ?></div><?php endif; ?></section>
        </div>
        <?php render_page('Dashboard', ob_get_clean());
    }
}
