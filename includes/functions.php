<?php
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function app_root(): string { return dirname(__DIR__); }

function app_url(string $path = ''): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = rtrim(dirname($script), '/.');
    if (str_contains($base, '/admin') || str_contains($base, '/teacher') || str_contains($base, '/student')) {
        $base = rtrim(dirname($base), '/.');
    }
    if ($base === '/') $base = '';
    return $base . '/' . ltrim($path, '/');
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function nav_link(string $route, string $label): string
{
    $active = ($_GET['r'] ?? 'dashboard') === $route ? ' active' : '';
    return '<a class="nav-link' . $active . '" href="' . e(app_url('?r=' . $route)) . '">' . e($label) . '</a>';
}

function render_page(string $title, string $content, bool $withShell = true): void
{
    $appName = class_exists('OpenLMS\\Core\\Config') ? (string) \OpenLMS\Core\Config::get('app.name', 'Open LMS') : 'Open LMS';
    $user = is_file(app_root() . '/config/config.php') && class_exists('OpenLMS\\Core\\Auth') ? \OpenLMS\Core\Auth::user() : null;
    ?><!doctype html><html lang="en" data-theme="auto"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title><?= e($title) ?> · <?= e($appName) ?></title><link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>"><link rel="stylesheet" href="<?= e(app_url('assets/css/modules.css')) ?>"></head><body>
    <?php if ($withShell && $user): ?>
    <div class="app-shell"><aside class="sidebar">
      <a class="brand" href="<?= e(app_url('?r=dashboard')) ?>"><span class="brand-mark">OL</span><strong><?= e($appName) ?></strong></a>
      <nav>
        <?= nav_link('dashboard','Dashboard') ?>
        <?= nav_link('subjects','Learning') ?>
        <?php if(feature_enabled('attendance')): ?><?= nav_link('attendance','Attendance') ?><?php endif; ?>
        <?php if(feature_enabled('question_bank') && \OpenLMS\Core\Auth::can('questions.manage')): ?><?= nav_link('questions','Question bank') ?><?php endif; ?>
        <?php if(feature_enabled('quizzes') && \OpenLMS\Core\Auth::can('exams.manage')): ?><?= nav_link('quizzes','Quiz manager') ?><?php endif; ?>
        <?php if(feature_enabled('exams') && \OpenLMS\Core\Auth::can('exams.manage')): ?><?= nav_link('exams','Exam manager') ?><?php endif; ?>
        <?php if(in_array('student', $user['roles'] ?? [], true) && (feature_enabled('quizzes') || feature_enabled('exams'))): ?><?= nav_link('assessments','Assessments') ?><?php endif; ?>
        <?php if(feature_enabled('projects')): ?><?= nav_link('projects','Projects') ?><?php endif; ?>
        <?php if(feature_enabled('gradebook')): ?><?= nav_link('gradebook','Gradebook') ?><?php endif; ?>
        <?php if(feature_enabled('notifications')): ?><?= nav_link('notifications','Notifications') ?><?php endif; ?>
        <?php if(feature_enabled('timetable')): ?><?= nav_link('timetable','Timetable') ?><?php endif; ?>
        <?php if(feature_enabled('certificates')): ?><?= nav_link('certificates','Certificates') ?><?php endif; ?>
        <?php if(feature_enabled('reports') && \OpenLMS\Core\Auth::can('reports.view')): ?><?= nav_link('reports','Reports') ?><?php endif; ?>
        <?php if(feature_enabled('fee_management')): ?><?= nav_link('fees','Fees') ?><?php endif; ?>
        <?php if(feature_enabled('parent_portal') && in_array('parent',$user['roles'],true)): ?><?= nav_link('parent-portal','Parent portal') ?><?php endif; ?>
        <?php if(feature_enabled('search')): ?><?= nav_link('search','Search') ?><?php endif; ?>
        <?= nav_link('mfa-setup','Security / MFA') ?>
        <?php if(\OpenLMS\Core\Auth::can('academics.manage')): ?><?= nav_link('academics','Academic setup') ?><?php endif; ?>
        <?php if(\OpenLMS\Core\Auth::isPrivileged()): ?>
          <?= nav_link('users','Users') ?><?php if(\OpenLMS\Core\Auth::hasAnyRole(['super_admin'])): ?><?= nav_link('roles','Roles') ?><?php endif; ?><?= nav_link('features','Modules') ?><?= nav_link('diagnostics','Diagnostics') ?><?= nav_link('audit','Audit log') ?><?php if(feature_enabled('backup_restore')): ?><?= nav_link('backup','Backup') ?><?php endif; ?>
        <?php endif; ?>
      </nav>
      <div class="sidebar-bottom"><button class="theme-btn" type="button" data-theme-toggle>Theme</button><form method="post" action="<?= e(app_url('?r=logout')) ?>"><input type="hidden" name="_csrf" value="<?= e(\OpenLMS\Core\Csrf::token()) ?>"><button class="nav-link nav-button" type="submit">Sign out</button></form></div>
    </aside><main class="main"><header class="topbar"><button class="menu-btn" data-menu-toggle aria-label="Toggle menu">☰</button><div></div><div class="user-chip"><span><?= e($user['name']) ?></span><small><?= e($user['email']) ?></small></div></header><div class="page-content"><?= $content ?></div></main></div>
    <?php else: ?><main class="standalone"><?= $content ?></main><button class="floating-theme theme-btn" type="button" data-theme-toggle>Theme</button><?php endif; ?>
    <script src="<?= e(app_url('assets/js/vendor/openlms-qrcode-modules-1.js')) ?>" defer></script><script src="<?= e(app_url('assets/js/vendor/openlms-qrcode-modules-2.js')) ?>" defer></script><script src="<?= e(app_url('assets/js/vendor/openlms-qrcode-modules-3.js')) ?>" defer></script><script src="<?= e(app_url('assets/js/vendor/openlms-qrcode-modules-4.js')) ?>" defer></script><script src="<?= e(app_url('assets/js/vendor/openlms-qrcode.js')) ?>" defer></script><script src="<?= e(app_url('assets/js/app.js')) ?>" defer></script></body></html><?php
}

function sanitize_rich_text(string $html): string
{
    $allowed = '<p><br><strong><em><ul><ol><li><h2><h3><blockquote><code><pre>';
    $clean = strip_tags($html, $allowed);
    $clean = preg_replace('/<(p|strong|em|ul|ol|li|h2|h3|blockquote|code|pre)\b[^>]*>/i', '<$1>', $clean) ?? '';
    $clean = preg_replace('/<br\b[^>]*>/i', '<br>', $clean) ?? '';
    return trim($clean);
}

function safe_external_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)) return null;
    return $url;
}

function lesson_embed_url(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts)) return null;
    $host = strtolower((string)($parts['host'] ?? ''));
    if (in_array($host, ['youtube.com','www.youtube.com','m.youtube.com'], true) && ($parts['path'] ?? '') === '/watch') {
        parse_str((string)($parts['query'] ?? ''), $q);
        if (!empty($q['v']) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', (string)$q['v'])) {
            return 'https://www.youtube-nocookie.com/embed/' . rawurlencode((string)$q['v']);
        }
    }
    if ($host === 'youtu.be') {
        $id = trim((string)($parts['path'] ?? ''), '/');
        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id);
    }
    if (in_array($host, ['vimeo.com','www.vimeo.com'], true)) {
        $id = trim((string)($parts['path'] ?? ''), '/');
        if (preg_match('/^\d+$/', $id)) return 'https://player.vimeo.com/video/' . $id;
    }
    return null;
}

function utc_datetime_from_input(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $value, new \DateTimeZone('UTC'));
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

function datetime_input_value(?string $value): string
{
    if (!$value) return '';
    try { return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\\TH:i'); }
    catch (\Throwable) { return ''; }
}

function feature_enabled(string $key): bool
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = \OpenLMS\Core\Database::connection()->prepare('SELECT enabled FROM feature_settings WHERE feature_key=? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $cache[$key] = ($value === false ? false : (bool)$value);
    } catch (\Throwable) {
        return false;
    }
}

function require_feature(string $key): void
{
    if (!feature_enabled($key)) {
        http_response_code(404);
        render_page('Module disabled', '<div class="card empty-state"><h2>Module unavailable</h2><p>This LMS module is currently disabled by an administrator.</p></div>');
        exit;
    }
}

function mfa_required_roles(): array
{
    static $roles = null;
    if ($roles !== null) return $roles;
    $roles = ['super_admin'];
    try {
        $st=\OpenLMS\Core\Database::connection()->prepare("SELECT setting_value FROM app_settings WHERE setting_key='mfa_required_roles' LIMIT 1");
        $st->execute();$raw=$st->fetchColumn();$decoded=$raw!==false?json_decode((string)$raw,true):null;
        if(is_array($decoded))$roles=array_values(array_unique(array_filter(array_map('strval',$decoded))));
    } catch (\Throwable) {}
    if(!in_array('super_admin',$roles,true))$roles[]='super_admin';
    return $roles;
}

function current_user_mfa_required(): bool
{
    $user=\OpenLMS\Core\Auth::user();
    return $user && (bool)array_intersect((array)$user['roles'],mfa_required_roles());
}

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type'=>$type,'message'=>$message];
}

function flash_pull(): ?array
{
    $flash = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return is_array($flash) ? $flash : null;
}

function current_url_route(string $route, array $params = []): string
{
    return app_url('?' . http_build_query(['r'=>$route] + $params));
}

function parse_csv_list(string $value): array
{
    $parts = array_map('trim', explode(',', $value));
    $parts = array_values(array_filter($parts, fn($v) => $v !== ''));
    return array_values(array_unique($parts));
}

function bool_post(string $key): int
{
    return isset($_POST[$key]) ? 1 : 0;
}

function notify_user(int $userId, string $title, string $body, string $type = 'general', ?string $linkUrl = null): void
{
    if ($userId < 1 || !feature_enabled('notifications')) return;
    $title = mb_substr(trim($title), 0, 180);
    $body = mb_substr(trim($body), 0, 4000);
    $type = preg_match('/^[a-z0-9_.-]{1,50}$/i', $type) ? $type : 'general';
    $linkUrl = $linkUrl !== null ? mb_substr(trim($linkUrl), 0, 500) : null;
    if ($title === '' || $body === '') return;
    try {
        \OpenLMS\Core\Database::connection()
            ->prepare('INSERT INTO notifications (user_id,title,body,type,link_url,created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $title, $body, $type, $linkUrl ?: null, gmdate('Y-m-d H:i:s')]);
    } catch (\Throwable) {}
}

function notify_course_students(int $courseId, string $title, string $body, string $type = 'course', ?string $linkUrl = null): void
{
    if ($courseId < 1 || !feature_enabled('notifications')) return;
    try {
        $pdo = \OpenLMS\Core\Database::connection();
        $st = $pdo->prepare("SELECT DISTINCT u.id FROM users u WHERE u.status='active' AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.name='student') AND (EXISTS(SELECT 1 FROM enrollments e WHERE e.user_id=u.id AND e.course_id=? AND e.status IN ('active','completed')) OR EXISTS(SELECT 1 FROM batch_members bm JOIN course_batches cb ON cb.batch_id=bm.batch_id WHERE bm.user_id=u.id AND cb.course_id=?))");
        $st->execute([$courseId, $courseId]);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $uid) notify_user((int)$uid, $title, $body, $type, $linkUrl);
    } catch (\Throwable) {}
}

function sync_course_completion(int $userId, int $courseId): void
{
    try {
        $pdo=\OpenLMS\Core\Database::connection();
        $st=$pdo->prepare("SELECT COUNT(*) FROM lessons l JOIN course_sections cs ON cs.id=l.course_section_id WHERE cs.course_id=? AND l.status='published' AND (l.release_at IS NULL OR l.release_at<=?)");$st->execute([$courseId,gmdate('Y-m-d H:i:s')]);$total=(int)$st->fetchColumn();if($total<1)return;
        $st=$pdo->prepare("SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id JOIN course_sections cs ON cs.id=l.course_section_id WHERE lp.user_id=? AND cs.course_id=? AND lp.progress_percent=100 AND l.status='published' AND (l.release_at IS NULL OR l.release_at<=?)");$st->execute([$userId,$courseId,gmdate('Y-m-d H:i:s')]);$done=(int)$st->fetchColumn();
        if($done>=$total){$st=$pdo->prepare('SELECT COUNT(*) FROM course_completions WHERE user_id=? AND course_id=?');$st->execute([$userId,$courseId]);if(!(int)$st->fetchColumn())$pdo->prepare('INSERT INTO course_completions (user_id,course_id,completed_at) VALUES (?,?,?)')->execute([$userId,$courseId,gmdate('Y-m-d H:i:s')]);}
        else{$pdo->prepare('DELETE FROM course_completions WHERE user_id=? AND course_id=?')->execute([$userId,$courseId]);}
    } catch (\Throwable) {}
}
