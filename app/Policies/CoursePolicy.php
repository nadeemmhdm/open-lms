<?php
namespace OpenLMS\Policies;

use OpenLMS\Core\Database;

final class CoursePolicy
{
    public static function canManage(array $course, array $user): bool
    {
        if ((bool) array_intersect(['super_admin','administrator','hod'], $user['roles'] ?? [])) return true;
        return in_array('teacher', $user['roles'] ?? [], true) && (int)($course['teacher_id'] ?? 0) === (int)$user['id'];
    }

    public static function canViewCourse(array $course, array $user): bool
    {
        if (self::canManage($course, $user)) return true;
        if (!in_array('student', $user['roles'] ?? [], true) || ($course['status'] ?? '') !== 'published' || (($course['subject_status'] ?? 'active') !== 'active')) return false;
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM class_subjects cs INNER JOIN class_members cm ON cm.class_id=cs.class_id WHERE cs.subject_id=? AND cm.user_id=?');
        $stmt->execute([(int)$course['subject_id'], (int)$user['id']]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function lessonAvailableForStudent(array $lesson, int $userId): bool
    {
        if (($lesson['status'] ?? '') !== 'published') return false;
        if (!empty($lesson['release_at']) && strtotime((string)$lesson['release_at'] . ' UTC') > time()) return false;

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT prerequisite_lesson_id FROM lesson_prerequisites WHERE lesson_id=?');
        $stmt->execute([(int)$lesson['id']]);
        $required = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if (!$required) return true;
        $marks = implode(',', array_fill(0, count($required), '?'));
        $args = array_merge([$userId], $required);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lesson_progress WHERE user_id=? AND progress_percent=100 AND lesson_id IN ($marks)");
        $stmt->execute($args);
        return (int)$stmt->fetchColumn() === count($required);
    }
}
