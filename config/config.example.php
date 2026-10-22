<?php
return [
    'app' => [
        'name' => 'Open LMS',
        'env' => 'production',
        'debug' => false,
        'timezone' => 'UTC',
        'base_url' => '',
        'key' => 'generate-a-random-64-hex-character-key',
    ],
    'database' => [
        'driver' => 'mysql', // mysql or sqlite
        'host' => 'sqlXXX.infinityfree.com',
        'port' => 3306,
        'name' => 'if0_xxxxx_openlms',
        'user' => 'if0_xxxxx',
        'pass' => '',
        'charset' => 'utf8mb4',
        'sqlite_path' => __DIR__ . '/../storage/open-lms.sqlite',
    ],
    'uploads' => [
        'max_bytes' => 10 * 1024 * 1024,
    ],
    'security' => [
        'session_name' => 'open_lms_session',
        'login_max_attempts' => 5,
        'login_window_minutes' => 15,
        'login_cooldown_minutes' => 15,
        'login_ip_max_attempts' => 20,
        'login_ip_window_minutes' => 15,
        'require_mfa_for_super_admin' => true,
    ],
];
