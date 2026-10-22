<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\BackupService;
use OpenLMS\Services\MailService;

trait SystemNotificationsTrait
{
    public static function notifications(): void
    {
        require_feature('notifications');
        Auth::requireAuth();
        $u = Auth::user();
        $pdo = Database::connection();
        $globalPublisher = Auth::hasAnyRole(['super_admin', 'administrator', 'hod']);
        $announcementsEnabled = feature_enabled('announcements');
        $canPublish = $announcementsEnabled && Auth::can('courses.manage');
        $error = $message = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Security token expired.';
            } else {
                try {
                    $action = (string)($_POST['action'] ?? '');
                    if ($action === 'mark_read') {
                        $pdo->prepare('UPDATE notifications SET read_at=? WHERE id=? AND user_id=?')
                            ->execute([gmdate('Y-m-d H:i:s'), (int)($_POST['id'] ?? 0), (int)$u['id']]);
                    } elseif ($action === 'announce') {
                        if (!$announcementsEnabled) throw new \RuntimeException('Announcements module is disabled.');
                        if (!$canPublish) throw new \RuntimeException('Announcement permission denied.');
                        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180);
                        $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 4000);
                        if ($title === '' || $body === '') throw new \RuntimeException('Announcement title and body are required.');

                        $requested = (string)($_POST['audience'] ?? 'course');
                        $audience = $globalPublisher && in_array($requested, ['all','course','batch'], true) ? $requested : 'course';
                        if (!$globalPublisher && $requested !== 'course') throw new \RuntimeException('Teachers can publish only to their own courses.');

                        $courseId = null;
                        $batchId = null;
                        if ($audience === 'course') {
                            $courseId = (int)($_POST['course_id'] ?? 0);
                            $course = self::course($courseId);
                            if (!$course || !CoursePolicy::canManage($course, $u)) throw new \RuntimeException('A manageable course is required.');
                        } elseif ($audience === 'batch') {
                            $batchId = (int)($_POST['batch_id'] ?? 0);
                            $st = $pdo->prepare('SELECT COUNT(*) FROM batches WHERE id=?');
                            $st->execute([$batchId]);
                            if ($batchId < 1 || !(int)$st->fetchColumn()) throw new \RuntimeException('A valid batch is required.');
                        }

                        $expires = utc_datetime_from_input($_POST['expires_at'] ?? null);
                        $now = gmdate('Y-m-d H:i:s');
                        $pdo->prepare('INSERT INTO announcements (title,body,audience,course_id,batch_id,created_by,published_at,expires_at,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
                            ->execute([$title,$body,$audience,$courseId,$batchId,(int)$u['id'],$now,$expires,$now]);
                        $message = 'Announcement published.';
                        if (isset($_POST['send_email']) && MailService::announcementMailEnabled()) {
                            $recipients = self::announcementRecipients($pdo, $audience, $courseId, $batchId);
                            $mail = MailService::sendMany($recipients, '[' . app_name() . '] ' . $title, $body . "\n\nPublished: " . format_app_datetime($now, 'Y-m-d H:i T'));
                            $message .= ' Email sent to ' . $mail['sent'] . ' recipient(s)' . ($mail['failed'] ? '; ' . $mail['failed'] . ' failed.' : '.');
                        }
                    }
                } catch (\Throwable $e) {
                    $error = $e instanceof \RuntimeException ? $e->getMessage() : 'Unable to update notifications.';
                }
            }
        }

        $st = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100');
        $st->execute([(int)$u['id']]);
        $notes = $st->fetchAll();
        $now = gmdate('Y-m-d H:i:s');

        $ann = [];
        if ($announcementsEnabled) {
            if (in_array('student', $u['roles'], true)) {
                $st = $pdo->prepare("SELECT a.* FROM announcements a WHERE a.published_at IS NOT NULL AND (a.expires_at IS NULL OR a.expires_at>?) AND (a.audience='all' OR (a.audience='course' AND EXISTS(SELECT 1 FROM courses c WHERE c.id=a.course_id AND (EXISTS(SELECT 1 FROM enrollments e WHERE e.course_id=c.id AND e.user_id=? AND e.status IN ('active','completed')) OR EXISTS(SELECT 1 FROM course_batches cb JOIN batch_members bm ON bm.batch_id=cb.batch_id WHERE cb.course_id=c.id AND bm.user_id=?))) OR (a.audience='batch' AND EXISTS(SELECT 1 FROM batch_members bm WHERE bm.batch_id=a.batch_id AND bm.user_id=?))) ORDER BY a.published_at DESC LIMIT 100");
                $st->execute([$now,(int)$u['id'],(int)$u['id'],(int)$u['id']]);
                $ann = $st->fetchAll();
            } elseif ($globalPublisher) {
                $st = $pdo->prepare('SELECT * FROM announcements WHERE published_at IS NOT NULL AND (expires_at IS NULL OR expires_at>?) ORDER BY published_at DESC LIMIT 100');
                $st->execute([$now]);
                $ann = $st->fetchAll();
            } elseif (in_array('teacher', $u['roles'], true)) {
                $st = $pdo->prepare("SELECT a.* FROM announcements a WHERE a.published_at IS NOT NULL AND (a.expires_at IS NULL OR a.expires_at>?) AND (a.audience='all' OR (a.audience='course' AND EXISTS(SELECT 1 FROM courses c WHERE c.id=a.course_id AND c.teacher_id=?))) ORDER BY a.published_at DESC LIMIT 100");
                $st->execute([$now,(int)$u['id']]);
                $ann = $st->fetchAll();
            } else {
                $st = $pdo->prepare("SELECT * FROM announcements WHERE audience='all' AND published_at IS NOT NULL AND (expires_at IS NULL OR expires_at>?) ORDER BY published_at DESC LIMIT 100");
                $st->execute([$now]);
                $ann = $st->fetchAll();
            }
        }

        $courses = $canPublish ? self::manageableCourses($u) : [];
        $batches = $globalPublisher ? $pdo->query('SELECT id,name FROM batches ORDER BY name')->fetchAll() : [];

        ob_start(); ?>
        <div class="page-head"><div><h1>Notifications & announcements</h1><p class="muted">Personal alerts and institution/course announcements.</p></div></div>
        <?php if($message):?><div class="alert alert-success"><?=e($message)?></div><?php endif;?>
        <?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
        <?php if($canPublish):?>
        <section class="card"><h2>Publish announcement</h2><form method="post">
          <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="announce">
          <label>Title<input name="title" maxlength="180" required></label><label>Message<textarea name="body" maxlength="4000" required></textarea></label>
          <div class="form-row"><label>Audience<select name="audience"><?php if($globalPublisher):?><option value="all">All users</option><?php endif;?><option value="course">Course</option><?php if($globalPublisher):?><option value="batch">Batch</option><?php endif;?></select></label><label>Expires<input type="datetime-local" name="expires_at"></label></div>
          <div class="form-row"><label>Course<select name="course_id"><option value="">—</option><?php foreach($courses as $c):?><option value="<?=(int)$c['id']?>"><?=e($c['title'])?></option><?php endforeach;?></select></label><?php if($globalPublisher):?><label>Batch<select name="batch_id"><option value="">—</option><?php foreach($batches as $b):?><option value="<?=(int)$b['id']?>"><?=e($b['name'])?></option><?php endforeach;?></select></label><?php endif;?></div>
          <?php if(MailService::announcementMailEnabled()): ?><label class="check-row"><input type="checkbox" name="send_email" value="1"> Also send this announcement by email</label><?php endif; ?>
          <p class="hint">Expiry uses the LMS timezone: <?= e(app_timezone()) ?>.</p><button class="btn btn-primary">Publish</button>
        </form></section><?php endif;?>
        <div class="grid-2"><?php if($announcementsEnabled): ?><section class="card"><h2>Announcements</h2><div class="compact-list">
          <?php foreach($ann as $a):?><div><span><strong><?=e($a['title'])?></strong><small><?=e($a['body'])?></small></span><span class="badge"><?=e($a['audience'])?></span></div><?php endforeach;?>
          <?php if(!$ann):?><p class="muted">No announcements.</p><?php endif;?>
        </div></section><?php endif; ?><section class="card"><h2>My notifications</h2><div class="compact-list">
          <?php foreach($notes as $n):?><div><span><strong><?=e($n['title'])?></strong><small><?=e($n['body'])?></small><?php if(!empty($n['link_url'])):?><a class="text-link" href="<?=e($n['link_url'])?>">Open</a><?php endif;?></span><?php if(!$n['read_at']):?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?=(int)$n['id']?>"><button class="btn btn-small">Mark read</button></form><?php else:?><span class="badge">Read</span><?php endif;?></div><?php endforeach;?>
          <?php if(!$notes):?><p class="muted">No personal notifications.</p><?php endif;?>
        </div></section></div>
        <?php render_page('Notifications', ob_get_clean());
    }

    private static function announcementRecipients(\PDO $pdo, string $audience, ?int $courseId, ?int $batchId): array
    {
        if ($audience === 'all') return $pdo->query("SELECT email FROM users WHERE status='active' AND email<>''")->fetchAll(\PDO::FETCH_COLUMN);
        if ($audience === 'batch' && $batchId) {
            $st=$pdo->prepare("SELECT DISTINCT u.email FROM users u JOIN batch_members bm ON bm.user_id=u.id WHERE bm.batch_id=? AND u.status='active' AND u.email<>''");$st->execute([$batchId]);return $st->fetchAll(\PDO::FETCH_COLUMN);
        }
        if ($audience === 'course' && $courseId) {
            $st=$pdo->prepare("SELECT DISTINCT u.email FROM users u WHERE u.status='active' AND u.email<>'' AND (EXISTS(SELECT 1 FROM enrollments e WHERE e.user_id=u.id AND e.course_id=? AND e.status IN ('active','completed')) OR EXISTS(SELECT 1 FROM batch_members bm JOIN course_batches cb ON cb.batch_id=bm.batch_id WHERE bm.user_id=u.id AND cb.course_id=?) OR EXISTS(SELECT 1 FROM courses c WHERE c.id=? AND c.teacher_id=u.id))");$st->execute([$courseId,$courseId,$courseId]);return $st->fetchAll(\PDO::FETCH_COLUMN);
        }
        return [];
    }
}
