<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

use OpenLMS\Core\Auth;
use OpenLMS\Core\Config;
use OpenLMS\Core\Csrf;
use OpenLMS\Services\Installer;
use OpenLMS\Services\Migrator;
use OpenLMS\Controllers\AuthController;
use OpenLMS\Controllers\MfaController;
use OpenLMS\Controllers\DashboardController;
use OpenLMS\Controllers\AdminController;
use OpenLMS\Controllers\DiagnosticsController;
use OpenLMS\Controllers\AcademicController;
use OpenLMS\Controllers\LearningController;
use OpenLMS\Controllers\AttendanceController;
use OpenLMS\Controllers\AssessmentController;
use OpenLMS\Controllers\ExamController;
use OpenLMS\Controllers\ProjectController;
use OpenLMS\Controllers\SystemController;

$configFile = __DIR__ . '/config/config.php';
$lockFile = __DIR__ . '/storage/install.lock';
$installed = is_file($configFile) && is_file($lockFile);
$route = trim((string) ($_GET['r'] ?? ($installed ? 'dashboard' : 'install')), '/');

if (!$installed) {
    if ($route !== 'install') {
        header('Location: ' . app_url('?r=install')); exit;
    }
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $error = 'Security token expired. Refresh and retry.';
        } else {
            [$ok, $error] = Installer::install($_POST);
            if ($ok) {
                header('Location: ' . app_url('?r=login&installed=1')); exit;
            }
        }
    }
    $checks = Installer::diagnostics();
    ob_start(); ?>
    <div class="installer-wrap">
      <section class="installer-hero"><div class="brand-mark">OL</div><div><h1>Open LMS setup</h1><p>First-run installer for InfinityFree and standard PHP hosting.</p></div></section>
      <?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <div class="installer-grid">
        <section class="card"><h2>1. Environment check</h2><div class="diagnostic-list"><?php foreach($checks as [$label,$ok,$detail]): ?><div><span class="dot <?= $ok?'ok':'bad' ?>"></span><strong><?= e($label) ?></strong><span><?= e($detail) ?></span></div><?php endforeach; ?></div><p class="hint">InfinityFree: choose MySQL/MariaDB using the exact database host, name and username shown in your control panel.</p></section>
        <section class="card"><h2>2. Database + first Super Admin</h2><form method="post" id="install-form"><input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>"><div class="form-row"><label>Database driver<select name="db_driver" id="db-driver"><option value="mysql" selected>MySQL / MariaDB (InfinityFree)</option><option value="sqlite">SQLite (only if PDO_SQLITE exists)</option></select></label><label>Institution name<input name="institution_name" value="Open LMS" required></label></div><div id="mysql-fields"><div class="form-row"><label>DB host<input name="db_host" placeholder="sqlXXX.infinityfree.com"></label><label>DB port<input name="db_port" type="number" value="3306"></label></div><label>Database name<input name="db_name" placeholder="if0_xxxxx_openlms"></label><div class="form-row"><label>DB username<input name="db_user" placeholder="if0_xxxxx"></label><label>DB password<input name="db_pass" type="password" autocomplete="new-password"></label></div></div><hr><div class="form-row"><label>Admin name<input name="admin_name" required></label><label>Admin email<input name="admin_email" type="email" required></label></div><label>Admin password<input name="admin_password" type="password" minlength="12" required autocomplete="new-password"><small>Minimum 12 characters.</small></label><button class="btn btn-primary btn-block" type="submit">Install Open LMS</button></form></section>
      </div>
    </div>
    <?php render_page('Install', ob_get_clean(), false); exit;
}

if ($route === 'install') {
    http_response_code(404);
    render_page('Not found','<div class="card"><h2>Installer locked</h2><p>Open LMS is already installed.</p></div>');
    exit;
}

try {
    Migrator::migrateConfigured();

    // Routes which are intentionally available before an authenticated application session.
    if ($route === 'login') { AuthController::login(); exit; }
    if ($route === 'logout') { AuthController::logout(); exit; }
    if ($route === 'mfa-challenge') { MfaController::challenge(); exit; }
    if ($route === 'reset-password') { AuthController::resetPassword(); exit; }
    if ($route === 'certificate-verify') { SystemController::certificateVerify(); exit; }

    Auth::requireAuth();

    // Roles selected in the server-side MFA policy must enroll TOTP before using LMS functions.
    $mustEnrollMfa = current_user_mfa_required() && !Auth::mfaEnabled();
    if ($mustEnrollMfa && $route !== 'mfa-setup') {
        flash_set('success', 'Set up authenticator MFA before continuing.');
        header('Location: ' . app_url('?r=mfa-setup')); exit;
    }

    match ($route) {
        'dashboard' => DashboardController::show(),
        'mfa-setup' => MfaController::setup(),
        'academics' => AcademicController::index(),
        'subjects' => LearningController::subjects(),
        'subject' => LearningController::subject(),
        'course' => LearningController::course(),
        'lesson' => LearningController::lesson(),
        'material-download' => LearningController::download(),
        'attendance' => AttendanceController::index(),
        'attendance-export' => AttendanceController::export(),
        'questions' => AssessmentController::questionBank(),
        'quizzes' => AssessmentController::quizzes(),
        'gradebook' => AssessmentController::gradebook(),
        'exams' => ExamController::manage(),
        'assessments' => ExamController::assessments(),
        'assessment-start' => ExamController::start(),
        'assessment-take' => ExamController::take(),
        'assessment-save' => ExamController::save(),
        'assessment-event' => ExamController::event(),
        'assessment-submit' => ExamController::submit(),
        'assessment-results' => ExamController::results(),
        'assessment-review' => ExamController::review(),
        'projects' => ProjectController::index(),
        'project' => ProjectController::view(),
        'project-file' => ProjectController::download(),
        'notifications' => SystemController::notifications(),
        'reports' => SystemController::reports(),
        'certificates' => SystemController::certificates(),
        'timetable' => SystemController::timetable(),
        'backup' => SystemController::backup(),
        'search' => SystemController::search(),
        'fees' => SystemController::fees(),
        'parent-portal' => SystemController::parentPortal(),
        'users' => AdminController::users(),
        'roles' => AdminController::roles(),
        'features' => AdminController::features(),
        'audit' => AdminController::audit(),
        'diagnostics' => DiagnosticsController::show(),
        default => (function(){ http_response_code(404); render_page('Not found','<div class="card"><h2>404</h2><p>The requested page was not found.</p></div>'); })(),
    };
} catch (Throwable $e) {
    error_log('[OpenLMS] ' . $e->getMessage());
    http_response_code(500);
    render_page('Server error','<div class="card"><h2>Something went wrong</h2><p>The error was logged. No private server details are shown.</p></div>');
}
