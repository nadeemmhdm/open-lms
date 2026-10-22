<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use PDO;

final class DoubtController
{
    public static function index(): void
    {
        Auth::requireAuth();
        $pdo = Database::connection();
        $user = Auth::user();
        $userId = (int)($user['id'] ?? 0);
        $roles = (array)($user['roles'] ?? []);
        $isStudent = in_array('student', $roles, true);
        $isPrivileged = Auth::isPrivileged();
        $canTeach = $isPrivileged || in_array('teacher', $roles, true) || in_array('hod', $roles, true) || Auth::can('courses.manage');
        if (!$isStudent && !$canTeach) {
            render_status_page(403, 'Doubts unavailable', 'This section is available to students, teachers, HODs and administrators.', 'Back to dashboard', app_url('?r=dashboard'));
            return;
        }

        $courses = self::visibleCourses($pdo, $userId, $isStudent, $isPrivileged, $roles);
        $courseIds = array_map('intval', array_column($courses, 'id'));
        $message = $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['_csrf'] ?? null)) {
                $error = 'Security token expired. Refresh and try again.';
            } else {
                $action = (string)($_POST['action'] ?? '');
                try {
                    if ($action === 'ask') {
                        if (!$isStudent) throw new \RuntimeException('Only students can create a new doubt.');
                        $courseId = (int)($_POST['course_id'] ?? 0);
                        $title = trim((string)($_POST['title'] ?? ''));
                        $body = trim((string)($_POST['body'] ?? ''));
                        if (!in_array($courseId, $courseIds, true)) throw new \RuntimeException('Choose one of your available class courses.');
                        if (mb_strlen($title) < 3 || mb_strlen($title) > 180) throw new \RuntimeException('Title must be between 3 and 180 characters.');
                        if (mb_strlen($body) < 5 || mb_strlen($body) > 5000) throw new \RuntimeException('Question must be between 5 and 5000 characters.');
                        $publicId = self::generatePublicId($pdo);
                        $now = gmdate('Y-m-d H:i:s');
                        $pdo->prepare('INSERT INTO doubts (public_id,course_id,student_id,title,body,status,created_at,updated_at) VALUES (?,?,?,?,?,\'open\',?,?)')
                            ->execute([$publicId,$courseId,$userId,$title,$body,$now,$now]);
                        $teacher = $pdo->prepare('SELECT teacher_id FROM courses WHERE id=?');
                        $teacher->execute([$courseId]);
                        $teacherId = (int)$teacher->fetchColumn();
                        if ($teacherId > 0) notify_user($teacherId, 'New student doubt', $title, 'doubt', app_url('?r=doubts'));
                        Auth::audit('doubts.ask', 'Student asked a course doubt', $userId, 'doubt', $publicId);
                        $message = 'Your doubt was posted.';
                    } elseif ($action === 'reply') {
                        $publicId = (int)($_POST['doubt_id'] ?? 0);
                        $body = trim((string)($_POST['body'] ?? ''));
                        $doubt = self::findDoubt($pdo, $publicId);
                        if (!$doubt || !self::canViewDoubt($doubt, $userId, $isStudent, $canTeach, $isPrivileged, $courseIds)) throw new \RuntimeException('Doubt not found or unavailable.');
                        if (mb_strlen($body) < 2 || mb_strlen($body) > 5000) throw new \RuntimeException('Reply must be between 2 and 5000 characters.');
                        $pdo->prepare('INSERT INTO doubt_replies (doubt_id,user_id,body,created_at) VALUES (?,?,?,?)')
                            ->execute([(int)$doubt['id'],$userId,$body,gmdate('Y-m-d H:i:s')]);
                        $pdo->prepare('UPDATE doubts SET updated_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s'),(int)$doubt['id']]);
                        if ($userId === (int)$doubt['student_id']) {
                            $teacher = $pdo->prepare('SELECT teacher_id FROM courses WHERE id=?');
                            $teacher->execute([(int)$doubt['course_id']]);
                            $teacherId = (int)$teacher->fetchColumn();
                            if ($teacherId > 0) notify_user($teacherId, 'Student replied to a doubt', (string)$doubt['title'], 'doubt', app_url('?r=doubts'));
                        } else {
                            notify_user((int)$doubt['student_id'], 'Teacher replied to your doubt', (string)$doubt['title'], 'doubt', app_url('?r=doubts'));
                        }
                        Auth::audit('doubts.reply', 'Added a reply to a course doubt', $userId, 'doubt', $publicId);
                        $message = 'Reply added.';
                    } elseif ($action === 'edit_doubt') {
                        if (!$isStudent) throw new \RuntimeException('Only the student who asked the doubt can edit it.');
                        $publicId=(int)($_POST['doubt_id']??0);$doubt=self::findDoubt($pdo,$publicId);
                        if(!$doubt||(int)$doubt['student_id']!==$userId||$doubt['status']!=='open')throw new \RuntimeException('This doubt can no longer be edited.');
                        $title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));
                        if(mb_strlen($title)<3||mb_strlen($title)>180)throw new \RuntimeException('Title must be between 3 and 180 characters.');
                        if(mb_strlen($body)<5||mb_strlen($body)>5000)throw new \RuntimeException('Question must be between 5 and 5000 characters.');
                        $pdo->prepare('UPDATE doubts SET title=?,body=?,updated_at=? WHERE id=?')->execute([$title,$body,gmdate('Y-m-d H:i:s'),(int)$doubt['id']]);
                        $teacher=$pdo->prepare('SELECT teacher_id FROM courses WHERE id=?');$teacher->execute([(int)$doubt['course_id']]);$teacherId=(int)$teacher->fetchColumn();if($teacherId>0)notify_user($teacherId,'Student updated a doubt',$title,'doubt',app_url('?r=doubts'));
                        Auth::audit('doubts.edit','Edited and resent a doubt',$userId,'doubt',$publicId);$message='Doubt updated and resent.';
                    } elseif ($action === 'edit_reply') {
                        $replyId=(int)($_POST['reply_id']??0);$body=trim((string)($_POST['body']??''));
                        $st=$pdo->prepare('SELECT dr.*,d.public_id,d.student_id,d.course_id,d.status FROM doubt_replies dr JOIN doubts d ON d.id=dr.doubt_id WHERE dr.id=? LIMIT 1');$st->execute([$replyId]);$reply=$st->fetch();
                        if(!$reply||(int)$reply['user_id']!==$userId||$reply['status']!=='open'||!self::canViewDoubt($reply,$userId,$isStudent,$canTeach,$isPrivileged,$courseIds))throw new \RuntimeException('This message cannot be edited.');
                        if(mb_strlen($body)<2||mb_strlen($body)>5000)throw new \RuntimeException('Reply must be between 2 and 5000 characters.');
                        $now=gmdate('Y-m-d H:i:s');$pdo->prepare('UPDATE doubt_replies SET body=?,updated_at=? WHERE id=?')->execute([$body,$now,$replyId]);$pdo->prepare('UPDATE doubts SET updated_at=? WHERE id=?')->execute([$now,(int)$reply['doubt_id']]);
                        if($userId===(int)$reply['student_id']){$teacher=$pdo->prepare('SELECT teacher_id FROM courses WHERE id=?');$teacher->execute([(int)$reply['course_id']]);$teacherId=(int)$teacher->fetchColumn();if($teacherId>0)notify_user($teacherId,'Student updated a doubt reply','A private doubt message was edited and resent.','doubt',app_url('?r=doubts'));}else{notify_user((int)$reply['student_id'],'Teacher updated a doubt reply','A private doubt message was edited and resent.','doubt',app_url('?r=doubts'));}
                        Auth::audit('doubts.reply_edit','Edited and resent a doubt reply',$userId,'doubt_reply',$replyId);$message='Message edited and resent.';
                    } elseif ($action === 'react') {
                        $replyId=(int)($_POST['reply_id']??0);$reaction=(string)($_POST['reaction']??'');if(!in_array($reaction,['like','dislike'],true))throw new \RuntimeException('Invalid reaction.');
                        $st=$pdo->prepare('SELECT dr.id reply_id,d.* FROM doubt_replies dr JOIN doubts d ON d.id=dr.doubt_id WHERE dr.id=? LIMIT 1');$st->execute([$replyId]);$doubt=$st->fetch();
                        if(!$doubt||!self::canViewDoubt($doubt,$userId,$isStudent,$canTeach,$isPrivileged,$courseIds))throw new \RuntimeException('Message unavailable.');
                        $now=gmdate('Y-m-d H:i:s');$st=$pdo->prepare('SELECT reaction FROM doubt_reply_reactions WHERE reply_id=? AND user_id=?');$st->execute([$replyId,$userId]);$current=$st->fetchColumn();
                        if($current===$reaction)$pdo->prepare('DELETE FROM doubt_reply_reactions WHERE reply_id=? AND user_id=?')->execute([$replyId,$userId]);
                        elseif($current)$pdo->prepare('UPDATE doubt_reply_reactions SET reaction=?,updated_at=? WHERE reply_id=? AND user_id=?')->execute([$reaction,$now,$replyId,$userId]);
                        else $pdo->prepare('INSERT INTO doubt_reply_reactions (reply_id,user_id,reaction,created_at,updated_at) VALUES (?,?,?,?,?)')->execute([$replyId,$userId,$reaction,$now,$now]);
                        $message='Reaction updated.';
                    } elseif ($action === 'resolve') {
                        if (!$canTeach) throw new \RuntimeException('Only teaching staff or administrators can mark a doubt cleared.');
                        $publicId = (int)($_POST['doubt_id'] ?? 0);
                        $doubt = self::findDoubt($pdo, $publicId);
                        if (!$doubt || !self::canViewDoubt($doubt, $userId, false, $canTeach, $isPrivileged, $courseIds)) throw new \RuntimeException('Doubt not found or unavailable.');
                        $now = gmdate('Y-m-d H:i:s');
                        $pdo->prepare("UPDATE doubts SET status='resolved',resolved_by=?,resolved_at=?,updated_at=? WHERE id=?")
                            ->execute([$userId,$now,$now,(int)$doubt['id']]);
                        notify_user((int)$doubt['student_id'], 'Your doubt was cleared', (string)$doubt['title'], 'doubt', app_url('?r=doubts'));
                        Auth::audit('doubts.resolve', 'Marked a course doubt cleared', $userId, 'doubt', $publicId);
                        $message = 'Doubt marked cleared.';
                    } elseif ($action === 'reopen') {
                        $publicId = (int)($_POST['doubt_id'] ?? 0);
                        $doubt = self::findDoubt($pdo, $publicId);
                        if (!$doubt || (int)$doubt['student_id'] !== $userId) throw new \RuntimeException('Only the student who asked this doubt can reopen it.');
                        $pdo->prepare("UPDATE doubts SET status='open',resolved_by=NULL,resolved_at=NULL,updated_at=? WHERE id=?")
                            ->execute([gmdate('Y-m-d H:i:s'),(int)$doubt['id']]);
                        $message = 'Doubt reopened.';
                    }
                } catch (\RuntimeException $e) {
                    $error = $e->getMessage();
                } catch (\Throwable $e) {
                    error_log('[OpenLMS doubts] ' . $e->getMessage());
                    $error = 'Unable to complete the doubt action.';
                }
            }
        }

        $status = (string)($_GET['status'] ?? 'open');
        if (!in_array($status, ['open','resolved','all'], true)) $status = 'open';
        $selectedCourse = (int)($_GET['course'] ?? 0);
        if ($selectedCourse > 0 && !in_array($selectedCourse, $courseIds, true) && !$isPrivileged) $selectedCourse = 0;
        $doubts = self::loadDoubts($pdo, $userId, $isStudent, $isPrivileged, $courseIds, $status, $selectedCourse);
        foreach ($doubts as &$doubt) {
            $st = $pdo->prepare('SELECT dr.id,dr.body,dr.created_at,dr.updated_at,dr.user_id,u.name,(SELECT COUNT(*) FROM doubt_reply_reactions rr WHERE rr.reply_id=dr.id AND rr.reaction=\'like\') like_count,(SELECT COUNT(*) FROM doubt_reply_reactions rr WHERE rr.reply_id=dr.id AND rr.reaction=\'dislike\') dislike_count,(SELECT rr.reaction FROM doubt_reply_reactions rr WHERE rr.reply_id=dr.id AND rr.user_id=? LIMIT 1) my_reaction FROM doubt_replies dr JOIN users u ON u.id=dr.user_id WHERE dr.doubt_id=? ORDER BY dr.id');
            $st->execute([$userId,(int)$doubt['id']]);
            $doubt['replies'] = $st->fetchAll();
        }
        unset($doubt);

        ob_start(); ?>
        <div class="page-head"><div><span class="eyebrow">Support</span><h1>Doubts &amp; Q&amp;A</h1><p class="muted"><?= $isStudent ? 'Ask a private question from one of your class subjects. Only you, the assigned teacher and administrators can see the thread.' : 'Answer student questions from your courses and move them to Cleared when the issue is resolved.' ?></p></div></div>
        <?php if($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

        <?php if($isStudent): ?>
        <section class="card doubt-ask-card"><div class="section-title"><div><h2>Ask a doubt</h2><p class="muted">Choose the course, write a short title, and explain the question clearly.</p></div></div>
          <?php if($courses): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="ask"><label>Course<select name="course_id" required><option value="">Select course</option><?php foreach($courses as $course): ?><option value="<?= (int)$course['id'] ?>"><?= e((string)$course['title']) ?></option><?php endforeach; ?></select></label><label>Title<input name="title" maxlength="180" placeholder="What do you need help with?" required></label><label>Your question<textarea name="body" rows="5" maxlength="5000" placeholder="Explain where you are stuck" required></textarea></label><button class="btn btn-primary" type="submit">Post doubt</button></form><?php else: ?><div class="empty-state"><h3>No class subjects available</h3><p class="muted">You can ask course doubts after a subject is assigned to your class.</p></div><?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="card doubt-filter-card"><form method="get" class="doubt-filters"><input type="hidden" name="r" value="doubts"><label>Status<select name="status"><option value="open" <?= $status==='open'?'selected':'' ?>>Open</option><option value="resolved" <?= $status==='resolved'?'selected':'' ?>>Cleared</option><option value="all" <?= $status==='all'?'selected':'' ?>>All</option></select></label><label>Course<select name="course"><option value="0">All courses</option><?php foreach($courses as $course): ?><option value="<?= (int)$course['id'] ?>" <?= $selectedCourse===(int)$course['id']?'selected':'' ?>><?= e((string)$course['title']) ?></option><?php endforeach; ?></select></label><button class="btn" type="submit">Apply</button></form></section>

        <section class="doubt-list"><?php foreach($doubts as $doubt): ?>
          <article class="card doubt-card"><div class="doubt-card-head"><div><span class="eyebrow"><?= e((string)$doubt['course_title']) ?></span><h2><?= e((string)$doubt['title']) ?></h2><p class="muted">Asked by <?= e((string)$doubt['student_name']) ?> · <?= e(format_app_datetime((string)$doubt['created_at'])) ?></p></div><span class="badge <?= $doubt['status']==='open'?'badge-warn':'badge-ok' ?>"><?= $doubt['status']==='resolved'?'Cleared':'Open' ?></span></div><p class="doubt-body"><?= nl2br(e((string)$doubt['body'])) ?></p>
            <?php if($isStudent && (int)$doubt['student_id']===$userId && $doubt['status']==='open'): ?><details class="message-edit"><summary>Edit &amp; resend question</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="edit_doubt"><input type="hidden" name="doubt_id" value="<?= e((string)$doubt['public_id']) ?>"><label>Title<input name="title" maxlength="180" value="<?= e((string)$doubt['title']) ?>" required></label><label>Question<textarea name="body" rows="4" maxlength="5000" required><?= e((string)$doubt['body']) ?></textarea></label><button class="btn btn-small">Save &amp; resend</button></form></details><?php endif; ?>
            <div class="doubt-thread"><?php foreach((array)$doubt['replies'] as $reply): $fromStudent=(int)$reply['user_id']===(int)$doubt['student_id']; ?><div class="doubt-reply <?= $fromStudent?'is-student':'is-staff' ?>"><div><strong><?= e((string)$reply['name']) ?></strong><span><?= $fromStudent?'Student':'Teacher / staff' ?></span></div><p><?= nl2br(e((string)$reply['body'])) ?></p><small><?= e(format_app_datetime((string)$reply['created_at'])) ?><?= !empty($reply['updated_at'])?' · edited '.e(format_app_datetime((string)$reply['updated_at'])):'' ?></small><div class="message-actions"><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="react"><input type="hidden" name="reply_id" value="<?= (int)$reply['id'] ?>"><button class="reaction-btn <?= ($reply['my_reaction']??'')==='like'?'active':'' ?>" name="reaction" value="like" type="submit">Like <?= (int)$reply['like_count'] ?></button><button class="reaction-btn <?= ($reply['my_reaction']??'')==='dislike'?'active':'' ?>" name="reaction" value="dislike" type="submit">Dislike <?= (int)$reply['dislike_count'] ?></button></form><?php if((int)$reply['user_id']===$userId && $doubt['status']==='open'): ?><details class="message-edit"><summary>Edit</summary><form method="post"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="edit_reply"><input type="hidden" name="reply_id" value="<?= (int)$reply['id'] ?>"><textarea name="body" rows="3" maxlength="5000" required><?= e((string)$reply['body']) ?></textarea><button class="btn btn-small">Edit &amp; resend</button></form></details><?php endif; ?></div></div><?php endforeach; ?><?php if(!$doubt['replies']): ?><p class="muted">No replies yet.</p><?php endif; ?></div>
            <?php if($doubt['status']==='open'): ?><form method="post" class="doubt-reply-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="doubt_id" value="<?= e((string)$doubt['public_id']) ?>"><label><?= $isStudent?'Add more details':'Reply' ?><textarea name="body" rows="3" maxlength="5000" required></textarea></label><button class="btn" type="submit">Send reply</button></form><?php endif; ?>
            <div class="action-row"><?php if($canTeach && $doubt['status']==='open'): ?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="resolve"><input type="hidden" name="doubt_id" value="<?= e((string)$doubt['public_id']) ?>"><button class="btn btn-primary btn-small" type="submit">Mark clear</button></form><?php endif; ?><?php if($isStudent && (int)$doubt['student_id']===$userId && $doubt['status']==='resolved'): ?><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="action" value="reopen"><input type="hidden" name="doubt_id" value="<?= e((string)$doubt['public_id']) ?>"><button class="btn btn-small" type="submit">Reopen</button></form><?php endif; ?></div>
          </article>
        <?php endforeach; ?><?php if(!$doubts): ?><div class="card empty-state"><h2>No doubts here</h2><p class="muted">There are no questions in this section.</p></div><?php endif; ?></section>
        <?php render_page('Doubts & Q&A', ob_get_clean());
    }

    private static function visibleCourses(PDO $pdo, int $userId, bool $isStudent, bool $isPrivileged, array $roles): array
    {
        if ($isStudent) {
            $st = $pdo->prepare("SELECT DISTINCT c.id,c.title FROM courses c JOIN class_subjects cs ON cs.subject_id=c.subject_id JOIN class_members cm ON cm.class_id=cs.class_id WHERE c.status='published' AND cm.user_id=? ORDER BY c.title");
            $st->execute([$userId]);
            return $st->fetchAll();
        }
        if ($isPrivileged || in_array('hod', $roles, true)) return $pdo->query("SELECT id,title FROM courses WHERE status='published' ORDER BY title")->fetchAll();
        $st = $pdo->prepare("SELECT id,title FROM courses WHERE status='published' AND teacher_id=? ORDER BY title");
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    private static function loadDoubts(PDO $pdo, int $userId, bool $isStudent, bool $isPrivileged, array $courseIds, string $status, int $selectedCourse): array
    {
        $where = [];
        $params = [];
        if ($isStudent) {
            $where[] = 'd.student_id=?';
            $params[] = $userId;
        } elseif (!$isPrivileged) {
            if (!$courseIds) return [];
            $where[] = 'd.course_id IN (' . implode(',', array_fill(0, count($courseIds), '?')) . ')';
            array_push($params, ...$courseIds);
        }
        if ($status !== 'all') { $where[] = 'd.status=?'; $params[] = $status; }
        if ($selectedCourse > 0) { $where[] = 'd.course_id=?'; $params[] = $selectedCourse; }
        $sql = 'SELECT d.*,c.title course_title,u.name student_name FROM doubts d JOIN courses c ON c.id=d.course_id JOIN users u ON u.id=d.student_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= " ORDER BY CASE WHEN d.status='open' THEN 0 ELSE 1 END,d.updated_at DESC LIMIT 100";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    private static function findDoubt(PDO $pdo, int $publicId): ?array
    {
        if ($publicId < 100000 || $publicId > 99999999) return null;
        $st = $pdo->prepare('SELECT * FROM doubts WHERE public_id=? LIMIT 1');
        $st->execute([$publicId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private static function canViewDoubt(array $doubt, int $userId, bool $isStudent, bool $canTeach, bool $isPrivileged, array $courseIds): bool
    {
        if ($isStudent) return (int)$doubt['student_id'] === $userId;
        if ($isPrivileged) return true;
        return $canTeach && in_array((int)$doubt['course_id'], $courseIds, true);
    }

    private static function generatePublicId(PDO $pdo): int
    {
        for ($i=0; $i<50; $i++) {
            $id = random_int(100000, 99999999);
            $st = $pdo->prepare('SELECT COUNT(*) FROM doubts WHERE public_id=?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() === 0) return $id;
        }
        throw new \RuntimeException('Unable to allocate a doubt reference.');
    }
}
