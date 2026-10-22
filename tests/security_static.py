#!/usr/bin/env python3
"""Static regression checks for high-value server-side security wiring."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
php_files = list(ROOT.rglob('*.php'))
text = {p: p.read_text(encoding='utf-8') for p in php_files}
controllers = ROOT / 'app/Controllers'

def controller_bundle(prefix: str) -> str:
    files = [controllers / f'{prefix}Controller.php', *sorted(controllers.glob(f'{prefix}*Trait.php'))]
    return '\n'.join(text[p] for p in files if p in text)

# Every literal POST form rendered by PHP must carry a CSRF field.
missing = []
for p, src in text.items():
    for m in re.finditer(r'<form\b[^>]*method=["\']post["\'][^>]*>(.*?)</form>', src, re.I | re.S):
        if '_csrf' not in m.group(1):
            missing.append(f"{p.relative_to(ROOT)}:{src.count(chr(10), 0, m.start()) + 1}")
assert not missing, 'POST forms without CSRF: ' + ', '.join(missing)

admin = controller_bundle('Admin')
authc = controller_bundle('Auth')
mfa = controller_bundle('Mfa')
system = controller_bundle('System')
helpers = text[ROOT / 'includes/functions.php']

assert "if ($role === 'super_admin')" in admin, 'ordinary user-create path can appoint Super Admin'
assert "Only a Super Admin can reset MFA enrollment" in admin and "Auth::verifyCurrentPassword" in admin, 'MFA admin reset reauthentication missing'
assert "$_SERVER['REQUEST_METHOD'] !== 'POST'" in authc and "Csrf::validate" in authc, 'logout must be POST + CSRF'
assert "mfa_pending_attempts" in mfa and "mfa_pending_locked_until" in mfa, 'MFA challenge throttle missing'
assert "Teachers can publish only to their own courses" in system, 'teacher announcement scope guard missing'
assert "The selected batch is not assigned to this course" in system, 'timetable course/batch scope validation missing'
assert "bm2.batch_id=t.batch_id" in system, 'student timetable batch privacy filter missing'
assert "c.teacher_id=?" in system, 'teacher report/course scope missing'
assert "function require_feature" in helpers and "render_status_page(404" in helpers, 'server-side module disablement missing'
assert "function notify_user" in helpers and "function notify_course_students" in helpers, 'notification delivery helpers missing'
assert "require_feature('search')" in system and "require_feature('backup_restore')" in system, 'search/backup module gates missing'
assert "feature_enabled('announcements')" in system and "Announcements module is disabled" in system, 'announcement module gate missing'

# Optional module entrypoints must enforce their feature gates in their controller/trait bundle.
required_gates = {
    'Attendance': "require_feature('attendance')",
    'Project': "require_feature('projects')",
    'Assessment': "require_feature('gradebook')",
    'System': "require_feature('notifications')",
}
for prefix, needle in required_gates.items():
    src = controller_bundle(prefix)
    assert needle in src, f'missing feature gate in {prefix} controller bundle: {needle}'

# Dashboard privacy / universal profile regression checks.
dashboard = (ROOT / 'app/Controllers/DashboardController.php').read_text()
index_php = (ROOT / 'index.php').read_text()
app_js = (ROOT / 'assets/js/app.js').read_text()
assert 'WHERE actor_user_id=?' in dashboard, 'non-admin dashboard activity must be scoped to current actor'
assert "'profile' => ProfileController::show()" in index_php, 'profile route missing'
assert "r=profile" in app_js and "My profile" in app_js, 'profile navigation missing'

# Invite onboarding security and lifecycle checks.
invite_service = (ROOT / 'app/Services/InviteService.php').read_text()
invite_controller = (ROOT / 'app/Controllers/RegistrationInviteController.php').read_text()
admin_users = (ROOT / 'app/Controllers/AdminUsersTrait.php').read_text()
assert "hash('sha256', $secret)" in invite_service, 'invite secret must be stored/compared as a hash'
assert 'MIN_PUBLIC_ID = 100000' in invite_service and 'MAX_PUBLIC_ID = 99999999' in invite_service, 'public invite IDs must stay 6-8 digits'
assert "'used','expired','revoked'" in invite_service and 'archiveAndDelete' in invite_service, 'invite terminal states must archive and remove active links'
assert "'join' =>" not in index_php and "if ($route === 'join')" in index_php, 'invite setup must be available before authenticated routes'
assert 'Auth::finishLogin' in invite_controller, 'successful invite registration must sign the new user in'
onboarding_src = (ROOT / 'app/Controllers/OnboardingController.php').read_text()
assert "InviteService::createStudent" in onboarding_src and "InviteService::create($role, 1" in onboarding_src, 'dedicated onboarding invite generators missing'
assert "role === 'super_admin'" in invite_service, 'invite service must block Super Admin creation'
assert 'FOR UPDATE' in invite_service and 'BEGIN IMMEDIATE' in invite_service, 'invite seat consumption needs DB locking on MySQL/SQLite'

# Application chrome must not rely on emoji glyphs for icons.
emoji_ranges = [(0x1F300,0x1FAFF),(0x2600,0x26FF),(0x2700,0x27BF),(0x1F1E6,0x1F1FF)]
for path in [*ROOT.rglob('*.php'), *ROOT.rglob('*.js'), *ROOT.rglob('*.css')]:
    src = path.read_text(encoding='utf-8')
    assert not any(any(start <= ord(ch) <= end for start,end in emoji_ranges) for ch in src), f'emoji glyph found in application UI source: {path.relative_to(ROOT)}'


# Student onboarding context and Doubts/Q&A access checks.
doubt_controller = (ROOT / 'app/Controllers/DoubtController.php').read_text()
onboarding = (ROOT / 'app/Controllers/OnboardingController.php').read_text()
assert 'InviteService::createStudent' in onboarding, 'student onboarding must collect academic context and courses'
assert 'registration_invite_contexts' in invite_service and 'registration_invite_courses' in invite_service, 'student invite context storage missing'
assert 'batch_members' in invite_service and 'enrollments' in invite_service, 'student invite registration must auto-assign batch and courses'
assert "'doubts' => DoubtController::index()" in index_php, 'doubts route missing'
assert "'onboarding' => OnboardingController::index()" in index_php, 'onboarding route missing'
assert 'd.student_id=?' in doubt_controller, 'student doubt list must be scoped to the signed-in student'
assert 'teacher_id=?' in doubt_controller, 'teacher doubt course scope missing'
assert 'public_id' in doubt_controller and '100000' in doubt_controller and '99999999' in doubt_controller, 'doubt public IDs must remain 6-8 digits'

# Sidebar navigation must be independently scrollable and assets cache-busted.
modules_css = (ROOT / 'assets/css/modules.css').read_text()
project_list = (ROOT / 'app/Controllers/ProjectListTrait.php').read_text()
project_view = (ROOT / 'app/Controllers/ProjectViewTrait.php').read_text()
academics = (ROOT / 'app/Controllers/AcademicController.php').read_text()
subjects = (ROOT / 'app/Controllers/SubjectLearningTrait.php').read_text()
assert '.sidebar>nav' in modules_css and 'overflow-y:auto!important' in modules_css and 'height:100dvh' in modules_css, 'sidebar links must own the scroll viewport'
assert 'function asset_url' in helpers and "asset_url('assets/css/app.css')" in helpers, 'asset cache-busting helper missing'
assert 'delete_batch' in academics and 'update_semester' in academics and 'remove_batch_member' in academics, 'academic manage/delete actions missing'
assert 'delete_course' in subjects and 'update_course' in subjects, 'course manage/delete actions missing'
assert 'Rubric JSON' not in project_list and 'scores JSON' not in project_view, 'raw JSON labels should not be exposed in project UI'


# Session lifetime, timezone, maintenance, mail, payments, and support checks.
auth_core = (ROOT / 'app/Core/Auth.php').read_text()
settings_controller = (ROOT / 'app/Controllers/SettingsController.php').read_text()
fee_controller = (ROOT / 'app/Controllers/FeeController.php').read_text()
support_controller = (ROOT / 'app/Controllers/SupportController.php').read_text()
secret_service = (ROOT / 'app/Services/SecretService.php').read_text()
mail_service = (ROOT / 'app/Services/MailService.php').read_text()
payment_service = (ROOT / 'app/Services/PaymentService.php').read_text()
learning_helpers = (ROOT / 'app/Controllers/LearningHelpersTrait.php').read_text()
subject_learning = (ROOT / 'app/Controllers/SubjectLearningTrait.php').read_text()
notifications_trait = (ROOT / 'app/Controllers/SystemNotificationsTrait.php').read_text()
installer = (ROOT / 'app/Services/Installer.php').read_text()

assert 'NORMAL_LIFETIME = 43200' in auth_core and 'REMEMBER_LIFETIME = 86400' in auth_core, '12-hour / 1-day session policy missing'
assert 'remember_me' in authc and 'Remember me for 1 day' in authc, 'remember-me login UI/wiring missing'
assert 'auth_sessions' in auth_core and "hash('sha256',$token)" in auth_core.replace(' ', ''), 'hashed server-side auth session token missing'
assert 'Auth::revokeAllSessions($uid)' in admin_users, 'suspension must revoke active sessions'
assert 'delete_user' in admin_users and 'Auth::verifyCurrentPassword' in admin_users, 'protected user deletion wiring missing'
assert 'Auth::revokeAllSessions((int)$row[\'user_id\'])' in authc, 'password reset must revoke existing sessions'

assert 'function app_timezone' in helpers and 'function format_app_datetime' in helpers, 'LMS timezone helpers missing'
assert "new \\DateTimeZone(app_timezone())" in helpers and 'setTimezone' in helpers, 'UTC/local timezone conversion missing'
assert 'data-live-clock' in settings_controller and 'data-server-epoch' in settings_controller, 'server-baselined LMS clock missing'
assert 'maintenance_mode_enabled() && !Auth::isPrivileged()' in index_php, 'maintenance mode must exempt privileged administrators'
assert "if ($route === 'join')" in index_php and 'maintenance_mode_enabled()' in index_php, 'maintenance mode must cover public onboarding'

assert "c.teacher_id=?" in learning_helpers and 'teacher_id=?' in subject_learning, 'teacher course/subject scoping missing'
assert 'Parent accounts use the Parent portal' in subject_learning, 'parent subject browsing must be blocked'

assert "'aes-256-gcm'" in secret_service and "PREFIX = 'enc:v1:'" in secret_service, 'encrypted settings secret storage missing'
assert 'smtp_password_secret' in settings_controller and 'SecretService::encrypt($password)' in settings_controller, 'SMTP password must be encrypted before storage'
assert 'stream_socket_client' in mail_service and 'STARTTLS' in mail_service and 'verify_peer' in mail_service, 'authenticated TLS SMTP transport missing'
assert 'mail_login_alerts_enabled' in settings_controller and 'mail_announcements_enabled' in settings_controller, 'SMTP mail controls missing'
assert 'sendLoginAlert' in auth_core and 'MailService::sendMany' in notifications_trait and 'send_email' in notifications_trait, 'login/announcement email wiring missing'

assert "'fees' => FeeController::index()" in index_php and "'payment-webhook'" in index_php, 'fee/payment routing missing'
assert "fi.user_id=?" in fee_controller and 'Pay with Razorpay' in fee_controller and 'Pay with Stripe' in fee_controller and 'Pay at counter' in fee_controller, 'student fee visibility/payment options missing'
assert 'razorpay_key_secret' in settings_controller and 'stripe_secret_key' in settings_controller, 'payment gateway credential settings missing'
assert "hash_hmac('sha256', $orderId . '|' . $paymentId" in payment_service, 'Razorpay callback HMAC verification missing'
assert "($payment['status'] ?? '') !== 'captured'" in payment_service, 'Razorpay captured-payment verification missing'
assert 'HTTP_STRIPE_SIGNATURE' in fee_controller and 'HTTP_X_RAZORPAY_SIGNATURE' in fee_controller, 'raw provider webhook signature headers missing'
assert "hash_hmac('sha256', $timestamp . '.' . $payload" in payment_service and 'abs(time()-$timestamp) > 300' in payment_service, 'Stripe webhook signature/tolerance validation missing'
assert "hash_hmac('sha256',$payload,$webhookSecret)" in payment_service, 'Razorpay webhook HMAC validation missing'
assert 'FOR UPDATE' in payment_service and 'BEGIN IMMEDIATE' in payment_service, 'payment finalization must use DB write locking'

assert "'support' => SupportController::index()" in index_php and "require_feature('support')" in support_controller, 'support module routing/gate missing'
assert 'random_int(100000,99999999)' in support_controller, 'support ticket public IDs must remain 6-8 digits'
assert "status']==='resolved'" in support_controller and 'Replies are now locked' in support_controller, 'resolved support tickets must reject further replies'
assert "'support'" in installer, 'fresh installer must seed support module state'

# Stable v1.0 password recovery, branding, chapter management, backup V3 and active-nav checks.
backup_service = (ROOT / 'app/Services/BackupService.php').read_text()
course_learning = (ROOT / 'app/Controllers/CourseLearningTrait.php').read_text()
assert "if ($route === 'forgot-password')" in index_php and 'forgotPassword' in authc, 'public forgot-password route missing'
assert 'password_reset_requests' in authc and "time() + 1800" in authc, '30-minute reset request lifecycle missing'
assert 'SELECT COUNT(*) FROM password_reset_requests WHERE ip_address=?' in authc and 'MailService::sendPasswordReset' in authc, 'password reset throttling/mail wiring missing'
assert 'sendPasswordReset' in mail_service and 'expires in 30 minutes' in mail_service, 'password reset email missing'
assert 'Auth::revokeAllSessions' in authc, 'password reset must revoke existing sessions'
assert 'function app_name' in helpers and "SettingsService::get('institution_name'" in helpers, 'database-backed LMS branding helper missing'
assert 'name="lms_name"' in settings_controller and "'institution_name'=>$lmsName" in settings_controller, 'admin LMS-name control missing'
assert 'aria-current="page"' in helpers and 'scrollIntoView' in app_js and 'data-nav-active' in helpers, 'active sidebar link indicator/reveal missing'
assert 'update_section' in course_learning and 'delete_section' in course_learning and 'Chapter title' in course_learning, 'chapter manage/delete controls missing'
assert 'delete_subject' in subject_learning and 'Delete empty subject' in subject_learning, 'subject delete control missing'
assert "FORMAT = 'open-lms-backup-v3'" in backup_service and 'OPENLMS-BACKUP-V3' in backup_service, 'backup V3 authenticated format missing'
for table in ['registration_invites','registration_invite_contexts','registration_invite_courses','registration_invite_history','doubts','doubt_replies']:
    assert f"'{table}'" in backup_service, f'backup missing {table}'
assert "'schema_migrations'" not in backup_service.split('private const TABLES = [',1)[1].split('];',1)[0], 'schema_migrations must not be restored from backups'
assert "'auth_sessions'" not in backup_service.split('private const TABLES = [',1)[1].split('];',1)[0], 'active auth sessions must not be backed up'
assert 'gzencode' in backup_service and "aes-256-gcm" in backup_service and 'pathForDownload' in backup_service, 'compressed encrypted backup/download wiring missing'
assert "'backup-download' => SystemController::backupDownload()" in index_php, 'secure backup download route missing'

lesson_learning = (ROOT / 'app/Controllers/LessonLearningTrait.php').read_text()
release_doc = (ROOT / 'RELEASE.md').read_text()
assert 'delete_lesson' in lesson_learning and 'Delete empty lesson' in lesson_learning, 'safe lesson delete control missing'
assert 'lesson_progress' in lesson_learning and 'lesson_prerequisites' in lesson_learning, 'lesson deletion must guard dependent learning history'
assert '# Open LMS Stable' in release_doc and '**Version:** v1.0.0' in release_doc and '**Description:**' in release_doc, 'minimal stable release metadata missing'
assert not (ROOT / 'CODE_OF_CONDUCT.md').exists() and not (ROOT / 'CONTRIBUTING.md').exists() and not (ROOT / 'TESTING.md').exists(), 'redundant markdown files should stay consolidated into README/SECURITY'
assert not (ROOT / 'app/Helpers').exists() and not (ROOT / 'app/Models').exists(), 'empty source folders should not remain in stable package'

print(f"PASS: {len(php_files)} PHP files scanned; stable v1.0 security, recovery, backup V3, branding, sidebar, content-management and scope regressions verified.")
