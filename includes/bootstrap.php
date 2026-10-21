<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';

spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'OpenLMS\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root . '/app/' . $relative . '.php';
    if (is_file($file)) require_once $file;
});

$configFile = $root . '/config/config.php';
if (is_file($configFile)) {
    \OpenLMS\Core\Config::load($configFile);
    date_default_timezone_set((string) \OpenLMS\Core\Config::get('app.timezone', 'UTC'));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name((string) \OpenLMS\Core\Config::get('security.session_name', 'open_lms_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} else {
    session_name('open_lms_install');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
}
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
