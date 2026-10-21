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
    ?><!doctype html><html lang="en" data-theme="auto"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title><?= e($title) ?> · <?= e($appName) ?></title><link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>"></head><body>
    <?php if ($withShell && $user): ?><div class="app-shell"><aside class="sidebar"><a class="brand" href="<?= e(app_url('?r=dashboard')) ?>"><span class="brand-mark">OL</span><strong><?= e($appName) ?></strong></a><nav><?= nav_link('dashboard','Dashboard') ?><?= nav_link('subjects','Learning') ?><?php if(\OpenLMS\Core\Auth::can('academics.manage')): ?><?= nav_link('academics','Academic setup') ?><?php endif; ?><?php if(\OpenLMS\Core\Auth::isPrivileged()): ?><?= nav_link('users','Users') ?><?= nav_link('features','Modules') ?><?= nav_link('diagnostics','Diagnostics') ?><?= nav_link('audit','Audit log') ?><?php endif; ?></nav><div class="sidebar-bottom"><button class="theme-btn" type="button" data-theme-toggle>Theme</button><a class="nav-link" href="<?= e(app_url('?r=logout')) ?>">Sign out</a></div></aside><main class="main"><header class="topbar"><button class="menu-btn" data-menu-toggle aria-label="Toggle menu">☰</button><div></div><div class="user-chip"><span><?= e($user['name']) ?></span><small><?= e($user['email']) ?></small></div></header><div class="page-content"><?= $content ?></div></main></div><?php else: ?><main class="standalone"><?= $content ?></main><button class="floating-theme theme-btn" type="button" data-theme-toggle>Theme</button><?php endif; ?>
    <script src="<?= e(app_url('assets/js/app.js')) ?>" defer></script></body></html><?php
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
