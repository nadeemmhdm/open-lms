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
    <?php if ($withShell && $user): ?><div class="app-shell"><aside class="sidebar"><a class="brand" href="<?= e(app_url('?r=dashboard')) ?>"><span class="brand-mark">OL</span><strong><?= e($appName) ?></strong></a><nav><?= nav_link('dashboard','Dashboard') ?><?php if(\OpenLMS\Core\Auth::isPrivileged()): ?><?= nav_link('users','Users') ?><?= nav_link('features','Modules') ?><?= nav_link('diagnostics','Diagnostics') ?><?= nav_link('audit','Audit log') ?><?php endif; ?></nav><div class="sidebar-bottom"><button class="theme-btn" type="button" data-theme-toggle>Theme</button><a class="nav-link" href="<?= e(app_url('?r=logout')) ?>">Sign out</a></div></aside><main class="main"><header class="topbar"><button class="menu-btn" data-menu-toggle aria-label="Toggle menu">☰</button><div></div><div class="user-chip"><span><?= e($user['name']) ?></span><small><?= e($user['email']) ?></small></div></header><div class="page-content"><?= $content ?></div></main></div><?php else: ?><main class="standalone"><?= $content ?></main><button class="floating-theme theme-btn" type="button" data-theme-toggle>Theme</button><?php endif; ?>
    <script src="<?= e(app_url('assets/js/app.js')) ?>" defer></script></body></html><?php
}
