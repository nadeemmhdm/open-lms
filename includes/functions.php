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

function absolute_app_url(string $path = ''): string
{
    $configured = class_exists('OpenLMS\\Core\\Config') ? trim((string)\OpenLMS\Core\Config::get('app.base_url', '')) : '';
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL)) {
        $parts = parse_url($configured);
        if (is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)) {
            return rtrim($configured, '/') . '/' . ltrim($path, '/');
        }
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host)) return app_url($path);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($secure ? 'https://' : 'http://') . $host . app_url($path);
}

function asset_url(string $path): string
{
    $path = ltrim($path, '/');
    $full = app_root() . '/' . $path;
    $version = is_file($full) ? (string)filemtime($full) : '1';
    return app_url($path) . '?v=' . rawurlencode($version);
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function app_name(): string
{
    $fallback = class_exists('OpenLMS\Core\Config') ? (string) \OpenLMS\Core\Config::get('app.name', 'Open LMS') : 'Open LMS';
    try {
        if (class_exists('OpenLMS\Services\SettingsService')) {
            $configured = trim((string) \OpenLMS\Services\SettingsService::get('institution_name', $fallback));
            if ($configured !== '') return $configured;
        }
    } catch (\Throwable) {}
    return $fallback !== '' ? $fallback : 'Open LMS';
}

function app_brand_initials(): string
{
    $name = preg_replace('/\s+/', ' ', trim(app_name())) ?? 'Open LMS';
    $parts = array_values(array_filter(explode(' ', $name)));
    if (!$parts) return 'OL';
    $letters = mb_substr($parts[0], 0, 1);
    if (count($parts) > 1) $letters .= mb_substr($parts[count($parts) - 1], 0, 1);
    return mb_strtoupper($letters ?: 'OL');
}

function nav_link(string $route, string $label): string
{
    $currentRoute = (string)($_GET['r'] ?? 'dashboard');
    $groups = [
        'subjects' => ['subjects','subject','course','lesson','material-download'],
        'doubts' => ['doubts'],
        'projects' => ['projects','project','project-file'],
        'assessments' => ['assessments','quiz-attempt','exam-attempt','attempt-review'],
        'exams' => ['exams','exam-manage','exam-review'],
        'quizzes' => ['quizzes','quiz-manage'],
        'questions' => ['questions','question-bank'],
        'profile' => ['profile'],
        'mfa-setup' => ['mfa-setup'],
        'fees' => ['fees','payment-return','payment-webhook'],
        'support' => ['support','support-ticket'],
        'onboarding' => ['onboarding','join'],
        'backup' => ['backup','backup-download'],
    ];
    $isActive = in_array($currentRoute, $groups[$route] ?? [$route], true);
    $active = $isActive ? ' active' : '';
    $current = $isActive ? ' aria-current="page" data-nav-active="1"' : '';
    return '<a class="nav-link' . $active . '" data-nav-route="' . e($route) . '"' . $current . ' href="' . e(app_url('?r=' . $route)) . '">' . e($label) . '</a>';
}

function render_page(string $title, string $content, bool $withShell = true): void
{
    $appName = app_name();
    $user = is_file(app_root() . '/config/config.php') && class_exists('OpenLMS\\Core\\Auth') ? \OpenLMS\Core\Auth::user() : null;
    $roles = (array)($user['roles'] ?? []);
    $isPrivileged = $user && \OpenLMS\Core\Auth::isPrivileged();
    $isStudent = in_array('student', $roles, true);
    $isParent = in_array('parent', $roles, true);
    $isParentOnly = $isParent && !(bool)array_intersect(['student','teacher','hod','administrator','super_admin','examiner'], $roles);
    $canManageCourses = $user && \OpenLMS\Core\Auth::can('courses.manage');
    $canViewLearning = $isStudent || $canManageCourses;
    ?><!doctype html><html lang="en" data-theme="auto"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title><?= e($title) ?> · <?= e($appName) ?></title><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('assets/css/modules.css')) ?>"></head><body>
    <?php if ($withShell && $user): ?>
    <div class="app-shell"><aside class="sidebar">
      <a class="brand" href="<?= e(app_url('?r=dashboard')) ?>"><span class="brand-mark"><?= e(app_brand_initials()) ?></span><strong><?= e($appName) ?></strong></a>
      <nav>
        <?= nav_link('dashboard','Dashboard') ?>
        <?php if(!$isParentOnly && $canViewLearning): ?><?= nav_link('subjects','Courses') ?><?php endif; ?>
        <?php if(!$isParentOnly && ($isStudent || $canManageCourses || $isPrivileged)): ?><?= nav_link('doubts','Doubts & Q&A') ?><?php endif; ?>
        <?php if(!$isParentOnly && feature_enabled('attendance') && ($isStudent || \OpenLMS\Core\Auth::can('attendance.manage'))): ?><?= nav_link('attendance','Attendance') ?><?php endif; ?>
        <?php if(!$isParentOnly && feature_enabled('question_bank') && \OpenLMS\Core\Auth::can('questions.manage')): ?><?= nav_link('questions','Questions') ?><?php endif; ?>
        <?php if(!$isParentOnly && feature_enabled('quizzes') && \OpenLMS\Core\Auth::can('exams.manage')): ?><?= nav_link('quizzes','Quizzes') ?><?php endif; ?>
        <?php if(!$isParentOnly && feature_enabled('exams') && \OpenLMS\Core\Auth::can('exams.manage')): ?><?= nav_link('exams','Exams') ?><?php endif; ?>
        <?php if(!$isParentOnly && $isStudent && (feature_enabled('quizzes') || feature_enabled('exams'))): ?><?= nav_link('assessments','Assessments') ?><?php endif; ?>
        <?php if(!$isParentOnly && feature_enabled('projects') && ($isStudent || \OpenLMS\Core\Auth::can('projects.manage'))): ?><?= nav_link('projects','Projects') ?><?php endif; ?>
        <?php if(!$isParentOnly && feature_enabled('gradebook') && ($isStudent || \OpenLMS\Core\Auth::can('reports.view'))): ?><?= nav_link('gradebook','Gradebook') ?><?php endif; ?>
        <?php if(feature_enabled('notifications')): ?><?= nav_link('notifications','Notifications') ?><?php endif; ?>
        <?php if(!$isParentOnly && feature_enabled('timetable')): ?><?= nav_link('timetable','Timetable') ?><?php endif; ?>
        <?php if(!$isParentOnly && feature_enabled('certificates') && ($isStudent || \OpenLMS\Core\Auth::can('reports.view'))): ?><?= nav_link('certificates','Certificates') ?><?php endif; ?>
        <?php if(!$isParentOnly && feature_enabled('reports') && \OpenLMS\Core\Auth::can('reports.view')): ?><?= nav_link('reports','Reports') ?><?php endif; ?>
        <?php if(feature_enabled('fee_management') && ($isPrivileged || $isStudent)): ?><?= nav_link('fees','Fees') ?><?php endif; ?>
        <?php if(feature_enabled('parent_portal') && $isParent): ?><?= nav_link('parent-portal','Parent portal') ?><?php endif; ?>
        <?php if(!$isParentOnly && feature_enabled('search')): ?><?= nav_link('search','Search') ?><?php endif; ?>
        <?php if(feature_enabled('support')): ?><?= nav_link('support','Support') ?><?php endif; ?>
        <?= nav_link('profile','My profile') ?>
        <?= nav_link('mfa-setup','Security') ?>
        <?php if(!$isParentOnly && \OpenLMS\Core\Auth::can('academics.manage')): ?><?= nav_link('academics','Academics') ?><?php endif; ?>
        <?php if($isPrivileged): ?>
          <?= nav_link('onboarding','Onboarding') ?><?= nav_link('users','Users') ?><?php if(\OpenLMS\Core\Auth::hasAnyRole(['super_admin'])): ?><?= nav_link('roles','Roles') ?><?php endif; ?><?= nav_link('settings','Settings') ?><?= nav_link('features','Modules') ?><?= nav_link('diagnostics','Diagnostics') ?><?= nav_link('audit','Audit log') ?><?php if(feature_enabled('backup_restore')): ?><?= nav_link('backup','Backup') ?><?php endif; ?>
        <?php endif; ?>
      </nav>
      <div class="sidebar-bottom"><button class="theme-btn" type="button" data-theme-toggle>Theme</button><form method="post" action="<?= e(app_url('?r=logout')) ?>"><input type="hidden" name="_csrf" value="<?= e(\OpenLMS\Core\Csrf::token()) ?>"><button class="nav-link nav-button" type="submit">Sign out</button></form></div>
    </aside><main class="main"><header class="topbar"><button class="menu-btn" data-menu-toggle aria-label="Toggle menu" aria-expanded="true"><span class="menu-icon" aria-hidden="true"><span></span><span></span><span></span></span></button><div></div><div class="user-chip"><span><?= e($user['name']) ?></span><small><?= e($user['email']) ?></small></div></header><div class="page-content"><?= $content ?></div></main></div>
    <?php else: ?><main class="standalone"><?= $content ?></main><button class="floating-theme theme-btn" type="button" data-theme-toggle>Theme</button><?php endif; ?>
    <script src="<?= e(asset_url('assets/js/vendor/openlms-qrcode-modules-1.js')) ?>" defer></script><script src="<?= e(asset_url('assets/js/vendor/openlms-qrcode-modules-2.js')) ?>" defer></script><script src="<?= e(asset_url('assets/js/vendor/openlms-qrcode-modules-3.js')) ?>" defer></script><script src="<?= e(asset_url('assets/js/vendor/openlms-qrcode-modules-4.js')) ?>" defer></script><script src="<?= e(asset_url('assets/js/vendor/openlms-qrcode.js')) ?>" defer></script><script src="<?= e(asset_url('assets/js/app.js')) ?>" defer></script></body></html><?php
}

function render_status_page(int $status, string $title, string $message, ?string $actionLabel = null, ?string $actionUrl = null): void
{
    http_response_code($status);
    $code = in_array($status, [403,404,410,500,503], true) ? (string)$status : 'Error';
    ob_start(); ?>
    <div class="status-page">
      <section class="card status-card">
        <span class="status-code"><?= e($code) ?></span>
        <h1><?= e($title) ?></h1>
        <p class="muted"><?= e($message) ?></p>
        <?php if($actionLabel && $actionUrl): ?><a class="btn btn-primary" href="<?= e($actionUrl) ?>"><?= e($actionLabel) ?></a><?php endif; ?>
      </section>
    </div>
    <?php render_page($title, ob_get_clean(), false);
}

function sanitize_rich_text(string $html): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed|svg|math)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><blockquote><code><pre><a><hr>');
    $html = preg_replace_callback('/<a\b[^>]*>/i', static function(array $m): string {
        $tag = $m[0];
        if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $hrefMatch)) return '<a>';
        $url = html_entity_decode((string)$hrefMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safe = safe_external_url($url);
        if (!$safe) return '<a>';
        return '<a href="' . e($safe) . '" target="_blank" rel="noopener noreferrer">';
    }, $html) ?? '';
    $html = preg_replace('/<(p|strong|b|em|i|u|ul|ol|li|h2|h3|blockquote|code|pre|hr)\b[^>]*>/i', '<$1>', $html) ?? '';
    $html = preg_replace('/<br\b[^>]*>/i', '<br>', $html) ?? '';
    $html = preg_replace('/<b>/i', '<strong>', $html) ?? '';
    $html = preg_replace('/<\/b>/i', '</strong>', $html) ?? '';
    $html = preg_replace('/<i>/i', '<em>', $html) ?? '';
    $html = preg_replace('/<\/i>/i', '</em>', $html) ?? '';
    return trim($html);
}

function markdown_inline_html(string $text): string
{
    $escaped = e($text);
    $placeholders = [];
    $escaped = preg_replace_callback('/`([^`\n]+)`/', static function(array $m) use (&$placeholders): string {
        $key = '@@CODE' . count($placeholders) . '@@';
        $placeholders[$key] = '<code>' . $m[1] . '</code>';
        return $key;
    }, $escaped) ?? $escaped;

    $escaped = preg_replace_callback('/!\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/i', static function(array $m): string {
        $url = safe_external_url(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!$url) return $m[0];
        return '<img class="markdown-image" src="' . e($url) . '" alt="' . $m[1] . '" loading="lazy" referrerpolicy="no-referrer">';
    }, $escaped) ?? $escaped;
    $escaped = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/i', static function(array $m): string {
        $url = safe_external_url(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!$url) return $m[0];
        return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
    }, $escaped) ?? $escaped;
    $escaped = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/__([^_\n]+)__/', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $escaped) ?? $escaped;
    $escaped = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/', '<em>$1</em>', $escaped) ?? $escaped;
    $escaped = preg_replace('/~~([^~\n]+)~~/', '<del>$1</del>', $escaped) ?? $escaped;
    foreach ($placeholders as $key => $html) $escaped = str_replace($key, $html, $escaped);
    return $escaped;
}

function markdown_to_safe_html(string $markdown): string
{
    $markdown = str_replace(["\r\n", "\r"], "\n", str_replace("\0", '', $markdown));
    $lines = explode("\n", $markdown);
    $out = [];
    $list = null;
    $inCode = false;
    $code = [];
    $closeList = static function() use (&$out, &$list): void {
        if ($list !== null) { $out[] = '</' . $list . '>'; $list = null; }
    };

    foreach ($lines as $line) {
        if (preg_match('/^```/', $line)) {
            $closeList();
            if ($inCode) { $out[] = '<pre><code>' . e(implode("\n", $code)) . '</code></pre>'; $code=[]; $inCode=false; }
            else { $inCode=true; $code=[]; }
            continue;
        }
        if ($inCode) { $code[] = $line; continue; }
        if (trim($line) === '') { $closeList(); continue; }
        if (preg_match('/^\s*---+\s*$/', $line)) { $closeList(); $out[]='<hr>'; continue; }
        if (preg_match('/^###\s+(.+)$/', $line, $m)) { $closeList(); $out[]='<h3>'.markdown_inline_html($m[1]).'</h3>'; continue; }
        if (preg_match('/^#{1,2}\s+(.+)$/', $line, $m)) { $closeList(); $out[]='<h2>'.markdown_inline_html($m[1]).'</h2>'; continue; }
        if (preg_match('/^>\s?(.*)$/', $line, $m)) { $closeList(); $out[]='<blockquote>'.markdown_inline_html($m[1]).'</blockquote>'; continue; }
        if (preg_match('/^\s*[-*+]\s+(.+)$/', $line, $m)) {
            if ($list !== 'ul') { $closeList(); $list='ul'; $out[]='<ul>'; }
            $out[]='<li>'.markdown_inline_html($m[1]).'</li>'; continue;
        }
        if (preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $m)) {
            if ($list !== 'ol') { $closeList(); $list='ol'; $out[]='<ol>'; }
            $out[]='<li>'.markdown_inline_html($m[1]).'</li>'; continue;
        }
        $closeList();
        $out[]='<p>'.markdown_inline_html($line).'</p>';
    }
    if ($inCode) $out[]='<pre><code>'.e(implode("\n", $code)).'</code></pre>';
    $closeList();
    return implode("\n", $out);
}

function lesson_content_html(?string $content, ?string $format = 'rich'): string
{
    $content = (string)$content;
    if (trim($content) === '') return '<p class="muted">No lesson content yet.</p>';
    return $format === 'markdown' ? markdown_to_safe_html($content) : sanitize_rich_text($content);
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

function lesson_resource_type(string $url, string $requested = 'auto'): string
{
    $allowed = ['link','video','image','pdf','document'];
    if (in_array($requested, $allowed, true)) return $requested;
    if (lesson_embed_url($url) !== null) return 'video';
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = strtolower((string)($parts['path'] ?? ''));
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['mp4','webm','mov','m4v'], true)) return 'video';
    if (in_array($ext, ['jpg','jpeg','png','webp','gif','avif'], true)) return 'image';
    if ($ext === 'pdf') return 'pdf';
    if (in_array($ext, ['doc','docx','ppt','pptx','xls','xlsx','odt','ods','odp','txt','rtf'], true)) return 'document';
    if (in_array($host, ['docs.google.com','drive.google.com','www.dropbox.com','dropbox.com','onedrive.live.com','1drv.ms'], true)) return 'document';
    return 'link';
}

function lesson_resource_preview_url(string $url, string $type): ?string
{
    if ($type === 'video') {
        $embed = lesson_embed_url($url);
        if ($embed) return $embed;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) return null;
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    if ($host === 'drive.google.com') {
        if (preg_match('~/file/d/([A-Za-z0-9_-]+)~', $path, $m)) return 'https://drive.google.com/file/d/'.rawurlencode($m[1]).'/preview';
        parse_str((string)($parts['query'] ?? ''), $q);
        if (!empty($q['id']) && preg_match('/^[A-Za-z0-9_-]+$/', (string)$q['id'])) return 'https://drive.google.com/file/d/'.rawurlencode((string)$q['id']).'/preview';
    }
    if ($host === 'docs.google.com' && preg_match('~/(document|spreadsheets|presentation)/d/([A-Za-z0-9_-]+)~', $path, $m)) {
        return 'https://docs.google.com/'.$m[1].'/d/'.rawurlencode($m[2]).'/preview';
    }
    if ($type === 'pdf' && preg_match('/\.pdf(?:$|\?)/i', $url)) return $url;
    return null;
}

function lesson_resource_title(string $url, string $type): string
{
    $parts = parse_url($url);
    $path = rawurldecode((string)($parts['path'] ?? ''));
    $base = trim(basename($path), '/');
    if ($base !== '' && $base !== '.') return mb_substr($base, 0, 180);
    $host = preg_replace('/^www\./i', '', (string)($parts['host'] ?? 'resource'));
    $label = match ($type) { 'video'=>'Video', 'image'=>'Image', 'pdf'=>'PDF', 'document'=>'Document', default=>'Resource' };
    return mb_substr($label.' · '.$host, 0, 180);
}

function lesson_resource_provider(string $url): string
{
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    $host = preg_replace('/^www\./', '', $host) ?: 'external';
    return match (true) {
        str_contains($host, 'youtube.com') || $host === 'youtu.be' => 'YouTube',
        str_contains($host, 'vimeo.com') => 'Vimeo',
        str_contains($host, 'drive.google.com') || str_contains($host, 'docs.google.com') => 'Google Drive',
        str_contains($host, 'dropbox.com') => 'Dropbox',
        $host === '1drv.ms' || str_contains($host, 'onedrive.live.com') => 'OneDrive',
        default => $host,
    };
}

function app_timezone(): string
{
    $fallback = class_exists('OpenLMS\\Core\\Config') ? (string)\OpenLMS\Core\Config::get('app.timezone', 'UTC') : 'UTC';
    $timezone = $fallback !== '' ? $fallback : 'UTC';
    try {
        if (class_exists('OpenLMS\\Services\\SettingsService')) {
            $timezone = (string)\OpenLMS\Services\SettingsService::get('app_timezone', $timezone);
        }
        new \DateTimeZone($timezone);
        return $timezone;
    } catch (\Throwable) {
        return 'UTC';
    }
}

function format_app_datetime(?string $utcValue, string $format = 'Y-m-d H:i'): string
{
    if (!$utcValue) return '—';
    try {
        $dt = new \DateTimeImmutable((string)$utcValue, new \DateTimeZone('UTC'));
        return $dt->setTimezone(new \DateTimeZone(app_timezone()))->format($format);
    } catch (\Throwable) {
        return (string)$utcValue;
    }
}

function utc_datetime_from_input(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    try {
        $tz = new \DateTimeZone(app_timezone());
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $tz);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$dt || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) return null;
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (\Throwable) { return null; }
}

function datetime_input_value(?string $value): string
{
    if (!$value) return '';
    try {
        return (new \DateTimeImmutable((string)$value, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(app_timezone()))
            ->format('Y-m-d\\TH:i');
    } catch (\Throwable) { return ''; }
}

function maintenance_mode_enabled(): bool
{
    try { return class_exists('OpenLMS\\Services\\SettingsService') && \OpenLMS\Services\SettingsService::bool('maintenance_enabled', false); }
    catch (\Throwable) { return false; }
}

function maintenance_message(): string
{
    try {
        $fallback = app_name() . ' is temporarily unavailable for maintenance.';
        $message = class_exists('OpenLMS\\Services\\SettingsService') ? trim((string)\OpenLMS\Services\SettingsService::get('maintenance_message', $fallback)) : '';
        return $message !== '' ? $message : $fallback;
    } catch (\Throwable) { return 'The LMS is temporarily unavailable for maintenance.'; }
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
        render_status_page(404, 'Module unavailable', 'This LMS module is currently disabled or is not available for this account.', 'Back to dashboard', app_url('?r=dashboard'));
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
        $st = $pdo->prepare("SELECT DISTINCT u.id FROM courses c JOIN class_subjects cs ON cs.subject_id=c.subject_id JOIN class_members cm ON cm.class_id=cs.class_id JOIN users u ON u.id=cm.user_id WHERE c.id=? AND c.status='published' AND u.status='active' AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.name='student')");
        $st->execute([$courseId]);
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
