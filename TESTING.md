# Testing and acceptance

Open LMS deliberately separates tests that were actually executed from target-host checks that still require the deployment environment.

## Automated/local checks

Run from the repository root:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/app.js
node --check assets/js/vendor/openlms-qrcode.js
python3 tests/full_schema_smoke.py
php tests/totp_rfc_test.php
node tests/qrcode_smoke.js
python3 tests/security_static.py
```

`tests/full_schema_smoke.py` applies all SQLite migrations and exercises representative integrity relationships across academics, attendance, quizzes/exams, projects, grades, notifications, certificates, timetable, parent links, fees, reset tokens and backup history. It also runs a foreign-key integrity check.

`tests/totp_rfc_test.php` verifies the underlying TOTP/HOTP calculation against RFC 6238 SHA-1 vectors.

`tests/qrcode_smoke.js` verifies the local bundled QR encoder can produce an SVG from an authenticator provisioning URI without a network service.

`tests/security_static.py` scans rendered POST forms for CSRF fields and guards against regressions in Super Admin elevation, MFA reset/challenge throttling, module gating (including announcements/search/backup), announcement scope, report scope and timetable batch privacy.

## Manual/target-host acceptance checklist

Before public use on InfinityFree/MySQL:

1. Installer diagnostics all required capabilities.
2. Fresh MySQL database applies migrations `001`–`006` without SQL errors.
3. First Super Admin can sign in, is forced to MFA setup, can sign out/in with TOTP and can use a recovery code exactly once.
4. Invalid credentials hit login rate limits without revealing whether an account exists.
5. Administrator cannot suspend or elevate itself to Super Admin.
6. Super Admin can create a custom role, assign permissions, and safely manage role assignments.
7. Teacher A cannot open/edit Teacher B's course by changing a URL ID.
8. Student cannot open an unenrolled/unassigned course/lesson/file.
9. Private lesson/project file upload/download works; executable/mismatched files are rejected.
10. Attendance duplicate/session/correction/report/CSV workflows work.
11. Question bank, quiz creation, objective grading and descriptive manual grading work.
12. Exam autosave/reconnect, server deadline expiry, duplicate-attempt rules, event logging and result publication work.
13. Project deadline/late/resubmission/group/version/file/grading workflows work.
14. Disabled modules reject direct routes server-side.
15. Certificate verification accepts active certificates and rejects revoked ones.
16. Parent portal only exposes explicitly linked students.
17. Backup is created, copied safely, then restored on a non-production copy including private upload bytes.
18. Light/dark themes and mobile/tablet/desktop layouts are manually checked in current browsers.
19. Direct HTTP access to private directories is denied.
20. Audit Log contains expected sensitive-operation events.
21. Teacher announcements cannot target all users/batches, teacher reports do not expose institution-wide totals, and a student cannot see a timetable row for another batch.
22. Super Admin MFA reset requires another Super Admin and current-password reauthentication.
23. Optional fee/live-class actions generate in-app notifications when the notifications module is enabled.

## Current build-environment limitation

The build environment used for the final local verification has PHP 8.4 but exposes no PHP PDO database drivers (`PDO::getAvailableDrivers()` is empty) and also lacks mbstring. Therefore PHP syntax/crypto logic and the first-run diagnostics page can be tested here, while a real PHP PDO MySQL session cannot. Python's SQLite runtime is used for schema/integrity smoke tests. This limitation is why live target-host acceptance remains mandatory.

## Final hardening checks

- Installer seed creation is transaction-protected after schema migration.
- Installer errors are logged privately and returned to visitors as sanitized actionable categories.
- One-time password reset tokens are consumed atomically before a password change.
- Encrypted restore updates backup history before database commit so a post-commit history failure cannot desynchronize restored database/upload state.
- Search, announcements and backup/restore are controlled by server-side feature gates, not navigation visibility alone.
