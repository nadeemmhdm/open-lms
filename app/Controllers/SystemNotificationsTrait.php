<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\MailService;
use OpenLMS\Services\UploadService;

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

                        $rawImageUrl = trim((string)($_POST['image_url'] ?? ''));
                        $rawVideoUrl = trim((string)($_POST['video_url'] ?? ''));
                        $imageUrl = $rawImageUrl === '' ? null : safe_external_url($rawImageUrl);
                        $videoUrl = $rawVideoUrl === '' ? null : safe_external_url($rawVideoUrl);
                        if ($rawImageUrl !== '' && !$imageUrl) throw new \RuntimeException('Image link must be a valid HTTP/HTTPS URL.');
                        if ($rawVideoUrl !== '' && !$videoUrl) throw new \RuntimeException('Video link must be a valid HTTP/HTTPS URL.');

                        $expires = utc_datetime_from_input($_POST['expires_at'] ?? null);
                        $now = gmdate('Y-m-d H:i:s');
                        $pdo->prepare('INSERT INTO announcements (title,body,audience,course_id,batch_id,created_by,published_at,expires_at,created_at,image_url,video_url) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                            ->execute([$title,$body,$audience,$courseId,$batchId,(int)$u['id'],$now,$expires,$now,$imageUrl,$videoUrl]);
                        $announcementId = (int)$pdo->lastInsertId();

                        $uploadWarnings = [];
                        foreach (self::uploadedAnnouncementFiles($_FILES['media_files'] ?? null) as $file) {
                            [$ok,$result] = UploadService::storeAnnouncementMedia($announcementId, $file);
                            if (!$ok) $uploadWarnings[] = (string)$result;
                        }

                        $message = 'Announcement published.';
                        if ($uploadWarnings) $message .= ' Some media files were skipped: ' . implode(' ', array_unique($uploadWarnings));
                        if (isset($_POST['send_email']) && MailService::announcementMailEnabled()) {
                            $recipients = self::announcementRecipients($pdo, $audience, $courseId, $batchId);
                            $mailBody = $body;
                            if ($imageUrl) $mailBody .= "\n\nImage: " . $imageUrl;
                            if ($videoUrl) $mailBody .= "\nVideo: " . $videoUrl;
                            $mail = MailService::sendMany($recipients, '[' . app_name() . '] ' . $title, $mailBody . "\n\nPublished: " . format_app_datetime($now, 'Y-m-d H:i T'));
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
                $st = $pdo->prepare("SELECT a.* FROM announcements a WHERE a.published_at IS NOT NULL AND (a.expires_at IS NULL OR a.expires_at>?) AND (a.audience='all' OR (a.audience='course' AND EXISTS(SELECT 1 FROM courses c JOIN class_subjects cs ON cs.subject_id=c.subject_id JOIN class_members cm ON cm.class_id=cs.class_id WHERE c.id=a.course_id AND c.status='published' AND cm.user_id=?)) OR (a.audience='batch' AND EXISTS(SELECT 1 FROM batch_members bm WHERE bm.batch_id=a.batch_id AND bm.user_id=?))) ORDER BY a.published_at DESC LIMIT 100");
                $st->execute([$now,(int)$u['id'],(int)$u['id']]);
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

        $media = self::announcementMediaMap($pdo, $ann);
        $courses = $canPublish ? self::manageableCourses($u) : [];
        $batches = $globalPublisher ? $pdo->query('SELECT id,name FROM batches ORDER BY name')->fetchAll() : [];

        ob_start(); ?>
        <div class="page-head"><div><h1>Notifications & announcements</h1><p class="muted">Personal alerts and institution/course announcements.</p></div></div>
        <?php if($message):?><div class="alert alert-success"><?=e($message)?></div><?php endif;?>
        <?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
        <?php if($canPublish):?>
        <section class="card"><h2>Publish announcement</h2><form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="announce">
          <label>Title<input name="title" maxlength="180" required></label><label>Message<textarea name="body" maxlength="4000" rows="5" required></textarea></label>
          <div class="form-row"><label>Audience<select name="audience"><?php if($globalPublisher):?><option value="all">All users</option><?php endif;?><option value="course">Course</option><?php if($globalPublisher):?><option value="batch">Batch</option><?php endif;?></select></label><label>Expires<input type="datetime-local" name="expires_at"></label></div>
          <div class="form-row"><label>Course<select name="course_id"><option value="">—</option><?php foreach($courses as $c):?><option value="<?=(int)$c['id']?>"><?=e($c['title'])?></option><?php endforeach;?></select></label><?php if($globalPublisher):?><label>Batch<select name="batch_id"><option value="">—</option><?php foreach($batches as $b):?><option value="<?=(int)$b['id']?>"><?=e($b['name'])?></option><?php endforeach;?></select></label><?php endif;?></div>
          <div class="form-row"><label>Image link <small>Optional HTTPS/HTTP image URL</small><input type="url" name="image_url" placeholder="https://..."></label><label>Video link <small>YouTube, Vimeo or direct MP4</small><input type="url" name="video_url" placeholder="https://..."></label></div>
          <label>Upload images/videos <small>Optional. Up to 4 JPG, PNG, WebP or MP4 files; each file uses the configured upload limit.</small><input type="file" name="media_files[]" accept="image/jpeg,image/png,image/webp,video/mp4" multiple></label>
          <?php if(MailService::announcementMailEnabled()): ?><label class="check-row"><input type="checkbox" name="send_email" value="1"> Also send this announcement by email</label><?php endif; ?>
          <p class="hint">Expiry uses the LMS timezone: <?= e(app_timezone()) ?>.</p><button class="btn btn-primary">Publish</button>
        </form></section><?php endif;?>
        <div class="grid-2"><?php if($announcementsEnabled): ?><section class="card"><h2>Announcements</h2><div class="announcement-list">
          <?php foreach($ann as $a):?><article class="announcement-item"><div class="announcement-head"><div><strong><?=e($a['title'])?></strong><small><?=e(format_app_datetime((string)$a['published_at']))?></small></div><span class="badge"><?=e($a['audience'])?></span></div><p><?=nl2br(e($a['body']))?></p>
          <?php if(!empty($a['image_url'])):?><img class="announcement-image" src="<?=e($a['image_url'])?>" alt="<?=e($a['title'])?>" loading="lazy" referrerpolicy="no-referrer"><?php endif;?>
          <?php if(!empty($a['video_url'])):?><?php $embed=lesson_embed_url((string)$a['video_url']); ?><?php if($embed):?><div class="video-frame"><iframe src="<?=e($embed)?>" title="<?=e($a['title'])?>" loading="lazy" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div><?php elseif(preg_match('/\.mp4(?:\?.*)?$/i',(string)$a['video_url'])):?><video class="announcement-video" controls preload="metadata" src="<?=e($a['video_url'])?>"></video><?php else:?><a class="text-link" href="<?=e($a['video_url'])?>" target="_blank" rel="noopener noreferrer">Open video</a><?php endif;?><?php endif;?>
          <?php foreach($media[(int)$a['id']]??[] as $m):?><?php if(str_starts_with((string)$m['mime_type'],'image/')):?><img class="announcement-image" src="<?=e(app_url('?r=announcement-media&id='.(int)$m['id']))?>" alt="<?=e($m['original_name'])?>" loading="lazy"><?php elseif(str_starts_with((string)$m['mime_type'],'video/')):?><video class="announcement-video" controls preload="metadata" src="<?=e(app_url('?r=announcement-media&id='.(int)$m['id']))?>"></video><?php endif;?><?php endforeach;?>
          </article><?php endforeach;?>
          <?php if(!$ann):?><p class="muted">No announcements.</p><?php endif;?>
        </div></section><?php endif; ?><section class="card"><h2>My notifications</h2><div class="compact-list">
          <?php foreach($notes as $n):?><div><span><strong><?=e($n['title'])?></strong><small><?=e($n['body'])?></small><?php if(!empty($n['link_url'])):?><a class="text-link" href="<?=e($n['link_url'])?>">Open</a><?php endif;?></span><?php if(!$n['read_at']):?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?=(int)$n['id']?>"><button class="btn btn-small">Mark read</button></form><?php else:?><span class="badge">Read</span><?php endif;?></div><?php endforeach;?>
          <?php if(!$notes):?><p class="muted">No personal notifications.</p><?php endif;?>
        </div></section></div>
        <?php render_page('Notifications', ob_get_clean());
    }

    public static function announcementMedia(): void
    {
        require_feature('announcements');
        Auth::requireAuth();
        $pdo=Database::connection();$u=Auth::user();$id=(int)($_GET['id']??0);
        $st=$pdo->prepare('SELECT m.*,a.audience,a.course_id,a.batch_id,a.published_at,a.expires_at,a.created_by FROM announcement_media m JOIN announcements a ON a.id=m.announcement_id WHERE m.id=? LIMIT 1');
        $st->execute([$id]);$m=$st->fetch();
        if(!$m||!self::userCanViewAnnouncement($pdo,$m,$u)){render_status_page(404,'Media not found','This announcement media is unavailable.');return;}
        $path=UploadService::resolveStoredPath((string)$m['stored_name']);
        if(!$path||!is_file($path)||!(str_starts_with((string)$m['mime_type'],'image/')||str_starts_with((string)$m['mime_type'],'video/'))){render_status_page(404,'Media not found','This announcement media is unavailable.');return;}
        $name=preg_replace('/[\r\n"]+/','_', (string)$m['original_name']);
        header('Content-Type: '.(string)$m['mime_type']);header('X-Content-Type-Options: nosniff');header('Content-Disposition: inline; filename="'.addcslashes($name,'"\\').'"');header('Content-Length: '.filesize($path));header('Cache-Control: private, no-store');readfile($path);exit;
    }

    private static function uploadedAnnouncementFiles(mixed $files): array
    {
        if(!is_array($files)||!isset($files['name'])) return [];
        $names=is_array($files['name'])?$files['name']:[$files['name']];$out=[];
        $count=min(4,count($names));
        for($i=0;$i<$count;$i++){
            $file=['name'=>$names[$i]??'','type'=>(is_array($files['type']??null)?($files['type'][$i]??''):($files['type']??'')),'tmp_name'=>(is_array($files['tmp_name']??null)?($files['tmp_name'][$i]??''):($files['tmp_name']??'')),'error'=>(int)(is_array($files['error']??null)?($files['error'][$i]??UPLOAD_ERR_NO_FILE):($files['error']??UPLOAD_ERR_NO_FILE)),'size'=>(int)(is_array($files['size']??null)?($files['size'][$i]??0):($files['size']??0))];
            if($file['error']!==UPLOAD_ERR_NO_FILE)$out[]=$file;
        }
        return $out;
    }

    private static function announcementMediaMap(\PDO $pdo, array $announcements): array
    {
        $ids=array_values(array_filter(array_map(fn($a)=>(int)($a['id']??0),$announcements)));
        if(!$ids)return [];$marks=implode(',',array_fill(0,count($ids),'?'));
        $st=$pdo->prepare("SELECT * FROM announcement_media WHERE announcement_id IN ($marks) ORDER BY id");$st->execute($ids);$map=[];
        foreach($st->fetchAll() as $row)$map[(int)$row['announcement_id']][]=$row;
        return $map;
    }

    private static function userCanViewAnnouncement(\PDO $pdo, array $a, array $u): bool
    {
        if(empty($a['published_at'])||(!empty($a['expires_at'])&&(string)$a['expires_at']<=gmdate('Y-m-d H:i:s')))return false;
        if((bool)array_intersect(['super_admin','administrator','hod'],$u['roles']))return true;
        if((int)($a['created_by']??0)===(int)$u['id'])return true;
        if(($a['audience']??'')==='all')return true;
        if(($a['audience']??'')==='batch'&&!empty($a['batch_id'])){$st=$pdo->prepare('SELECT COUNT(*) FROM batch_members WHERE batch_id=? AND user_id=?');$st->execute([(int)$a['batch_id'],(int)$u['id']]);return (int)$st->fetchColumn()>0;}
        if(($a['audience']??'')==='course'&&!empty($a['course_id'])){
            $course=self::course((int)$a['course_id']);if(!$course)return false;
            return CoursePolicy::canViewCourse($course,$u);
        }
        return false;
    }

    private static function announcementRecipients(\PDO $pdo, string $audience, ?int $courseId, ?int $batchId): array
    {
        if ($audience === 'all') return $pdo->query("SELECT email FROM users WHERE status='active' AND email<>''")->fetchAll(\PDO::FETCH_COLUMN);
        if ($audience === 'batch' && $batchId) {
            $st=$pdo->prepare("SELECT DISTINCT u.email FROM users u JOIN batch_members bm ON bm.user_id=u.id WHERE bm.batch_id=? AND u.status='active' AND u.email<>''");$st->execute([$batchId]);return $st->fetchAll(\PDO::FETCH_COLUMN);
        }
        if ($audience === 'course' && $courseId) {
            $st=$pdo->prepare("SELECT DISTINCT u.email FROM users u WHERE u.status='active' AND u.email<>'' AND (EXISTS(SELECT 1 FROM courses c JOIN class_subjects cs ON cs.subject_id=c.subject_id JOIN class_members cm ON cm.class_id=cs.class_id WHERE c.id=? AND cm.user_id=u.id) OR EXISTS(SELECT 1 FROM courses c WHERE c.id=? AND c.teacher_id=u.id))");$st->execute([$courseId,$courseId]);return $st->fetchAll(\PDO::FETCH_COLUMN);
        }
        return [];
    }
}
