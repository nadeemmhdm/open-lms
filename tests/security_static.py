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
assert "function require_feature" in helpers and "http_response_code(404)" in helpers, 'server-side module disablement missing'
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

print(f"PASS: CSRF on literal POST forms ({len(php_files)} PHP files scanned), privilege/MFA guards, module gates, timetable/report/announcement scoping, notification wiring.")

# Dashboard privacy / universal profile regression checks.
dashboard = (ROOT / 'app/Controllers/DashboardController.php').read_text()
index_php = (ROOT / 'index.php').read_text()
app_js = (ROOT / 'assets/js/app.js').read_text()
assert 'WHERE actor_user_id=?' in dashboard, 'non-admin dashboard activity must be scoped to current actor'
assert "'profile' => ProfileController::show()" in index_php, 'profile route missing'
assert "r=profile" in app_js and "My profile" in app_js, 'profile navigation missing'
