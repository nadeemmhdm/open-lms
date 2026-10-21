<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Config;
use OpenLMS\Core\Database;
use PDO;

final class DiagnosticsController
{
    public static function show(): void
    {
        Auth::requireAdmin(); $drivers=PDO::getAvailableDrivers();
        $checks=[['PHP version',version_compare(PHP_VERSION,'8.1.0','>='),PHP_VERSION],['PDO MySQL',in_array('mysql',$drivers,true),in_array('mysql',$drivers,true)?'Available':'Unavailable'],['PDO SQLite',in_array('sqlite',$drivers,true),in_array('sqlite',$drivers,true)?'Available':'Unavailable / optional'],['Session support',function_exists('session_start'),function_exists('session_start')?'Available':'Missing'],['OpenSSL',function_exists('openssl_encrypt'),function_exists('openssl_encrypt')?'Available':'Missing'],['Storage writable',is_writable(dirname(__DIR__,2).'/storage'),is_writable(dirname(__DIR__,2).'/storage')?'Writable':'Not writable'],['Uploads writable',is_writable(dirname(__DIR__,2).'/storage/uploads'),is_writable(dirname(__DIR__,2).'/storage/uploads')?'Writable':'Not writable'],['Database connection',self::dbOk(),Config::get('database.driver','unknown')],['Install lock',is_file(dirname(__DIR__,2).'/storage/install.lock'),is_file(dirname(__DIR__,2).'/storage/install.lock')?'Present':'Missing']];
        ob_start(); ?><div class="page-head"><div><h1>Diagnostics</h1><p class="muted">Safe environment checks; secrets and filesystem paths are intentionally hidden.</p></div></div><section class="card"><div class="diagnostic-list"><?php foreach($checks as [$label,$ok,$detail]): ?><div><span class="dot <?= $ok?'ok':'bad' ?>"></span><strong><?= e($label) ?></strong><span><?= e($detail) ?></span></div><?php endforeach; ?></div></section><section class="card"><h2>Runtime limits</h2><div class="kv"><span>upload_max_filesize</span><strong><?= e(ini_get('upload_max_filesize')) ?></strong><span>post_max_size</span><strong><?= e(ini_get('post_max_size')) ?></strong><span>max_execution_time</span><strong><?= e(ini_get('max_execution_time')) ?>s</strong></div></section><?php render_page('Diagnostics',ob_get_clean());
    }
    private static function dbOk(): bool { try { Database::connection()->query('SELECT 1'); return true; } catch(\Throwable) { return false; } }
}
