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

        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $subjectCount = (int) $pdo->query("SELECT COUNT(*) FROM subjects WHERE status='active'")->fetchColumn();
        $courseCount = (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE status<>'archived'")->fetchColumn();
        $lessonCount = (int) $pdo->query("SELECT COUNT(*) FROM lessons WHERE status<>'archived'")->fetchColumn();
        $features = (int) $pdo->query('SELECT COUNT(*) FROM feature_settings WHERE enabled = 1')->fetchColumn();
        $recent = $pdo->query('SELECT action, description, created_at FROM audit_logs ORDER BY id DESC LIMIT 8')->fetchAll();

        $myCourses = 0;
        $myProgress = 0;
        if (in_array('student', $user['roles'], true)) {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM courses c WHERE c.status='published' AND (EXISTS (SELECT 1 FROM enrollments e WHERE e.course_id=c.id AND e.user_id=? AND e.status IN ('active','completed')) OR EXISTS (SELECT 1 FROM course_batches cb INNER JOIN batch_members bm ON bm.batch_id=cb.batch_id WHERE cb.course_id=c.id AND bm.user_id=?))");
            $stmt->execute([(int)$user['id'], (int)$user['id']]);
            $myCourses = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_progress WHERE user_id=? AND progress_percent=100");
            $stmt->execute([(int)$user['id']]);
            $myProgress = (int)$stmt->fetchColumn();
        } elseif (in_array('teacher', $user['roles'], true) && !Auth::hasAnyRole(['super_admin','administrator','hod'])) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE teacher_id=? AND status<>'archived'");
            $stmt->execute([(int)$user['id']]);
            $myCourses = (int)$stmt->fetchColumn();
        }

        ob_start(); ?>
        <div class="page-head"><div><h1>Dashboard</h1><p class="muted">Welcome, <?= e($user['name']) ?>.</p></div><span class="badge"><?= e(implode(', ', $user['roles'])) ?></span></div>
        <div class="stats-grid">
            <?php if(in_array('student',$user['roles'],true)): ?>
              <div class="stat card"><span>My courses</span><strong><?= $myCourses ?></strong></div>
              <div class="stat card"><span>Lessons completed</span><strong><?= $myProgress ?></strong></div>
              <div class="stat card"><span>Subjects</span><strong><?= $subjectCount ?></strong></div>
              <div class="stat card"><span>Enabled modules</span><strong><?= $features ?></strong></div>
            <?php elseif(in_array('teacher',$user['roles'],true) && !Auth::hasAnyRole(['super_admin','administrator','hod'])): ?>
              <div class="stat card"><span>My courses</span><strong><?= $myCourses ?></strong></div>
              <div class="stat card"><span>Subjects</span><strong><?= $subjectCount ?></strong></div>
              <div class="stat card"><span>Lessons</span><strong><?= $lessonCount ?></strong></div>
              <div class="stat card"><span>Enabled modules</span><strong><?= $features ?></strong></div>
            <?php else: ?>
              <div class="stat card"><span>Users</span><strong><?= $userCount ?></strong></div>
              <div class="stat card"><span>Subjects</span><strong><?= $subjectCount ?></strong></div>
              <div class="stat card"><span>Courses</span><strong><?= $courseCount ?></strong></div>
              <div class="stat card"><span>Lessons</span><strong><?= $lessonCount ?></strong></div>
            <?php endif; ?>
        </div>
        <div class="grid-2">
            <section class="card"><h2>Learning platform</h2><ul class="status-list"><li><span class="dot ok"></span> Subject and course hierarchy active</li><li><span class="dot ok"></span> Private learning-material downloads active</li><li><span class="dot ok"></span> Batch and enrollment access controls active</li><li><span class="dot ok"></span> Lesson progress tracking active</li></ul><p style="margin-top:18px"><a class="btn btn-primary" href="<?= e(app_url('?r=subjects')) ?>">Open learning</a></p></section>
            <section class="card"><h2>Recent activity</h2><?php if (!$recent): ?><p class="muted">No activity yet.</p><?php else: ?><div class="activity-list"><?php foreach ($recent as $item): ?><div><strong><?= e($item['action']) ?></strong><span><?= e($item['description']) ?></span><small><?= e($item['created_at']) ?> UTC</small></div><?php endforeach; ?></div><?php endif; ?></section>
        </div>
        <?php render_page('Dashboard', ob_get_clean());
    }
}
