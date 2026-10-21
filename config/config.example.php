<?php
return [
    'app' => ['name'=>'Open LMS','env'=>'production','debug'=>false,'timezone'=>'UTC','base_url'=>'','key'=>'generate-a-random-64-hex-character-key'],
    'database' => ['driver'=>'mysql','host'=>'sqlXXX.infinityfree.com','port'=>3306,'name'=>'if0_xxxxx_openlms','user'=>'if0_xxxxx','pass'=>'','charset'=>'utf8mb4','sqlite_path'=>__DIR__ . '/../storage/open-lms.sqlite'],
    'security' => ['session_name'=>'open_lms_session','login_max_attempts'=>5,'login_window_minutes'=>15,'require_mfa_for_super_admin'=>false],
];
