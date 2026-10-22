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

`tests/full_schema_smoke.py` applies all SQLite migrations and exercises representative integrity relationships across academics, attendance, quizzes/exams, projects, grades, notifications, certificates, timetable, parent links, fees, reset tokens and backup history, registration invites/invite history, onboarding year/batch/course mappings, and doubts/replies, auth sessions/login history, payment transactions, and support tickets/replies. It also runs a foreign-key integrity check.

`tests/totp_rfc_test.php` verifies the underlying TOTP/HOTP calculation against RFC 6238 SHA-1 vectors.

`tests/qrcode_smoke.js` verifies the local bundled QR encoder can produce an SVG from an authenticator provisioning URI without a network service.

`tests/security_static.py` scans rendered POST forms for CSRF fields and guards against regressions in Super Admin elevation, MFA reset/challenge throttling, module gating (including announcements/search/backup), announcement scope, report scope, timetable batch privacy, 12h/1-day auth sessions, maintenance mode, timezone conversion, encrypted SMTP/payment secrets, gateway signature verification, student fee scoping, and support ticket closure rules.

## Manual/target-host acceptance checklist

Before public use on InfinityFree/MySQL:

1. Installer diagnostics all required capabilities.
2. Fresh MySQL database applies migrations `001`–`010` without SQL errors.
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
24. A bulk Student registration link creates exactly the configured number of Student accounts, does not consume a seat on validation failure, then becomes unavailable with the custom completed-link page.
25. A single-role setup link creates exactly one account with the selected non-Super-Admin role, signs the user in, and cannot be reused. Expired and revoked links show the custom unavailable page.
26. A Student onboarding link applies the selected Academic Year/Batch context, adds the new Student to the batch, and enrolls the Student in every selected course.
27. Students can only see their own Doubts/Q&A threads; teachers only see course doubts for courses they teach; administrators can oversee all and teaching staff can resolve questions.
28. LMS timezone can be changed to `Asia/Kolkata` (or another IANA zone); exam/quiz/project/live-class/lesson schedule inputs display in that zone while persisted timestamps remain UTC.
29. Sign-in without Remember me expires after 12 hours; Remember me expires after 1 day; profile shows first/recent login time, IP and device information.
30. Suspending a user revokes active server-side sessions and a subsequent sign-in shows the suspended/contact-administration message.
31. Maintenance mode blocks non-admin application/onboarding access while Super Admin/Administrator accounts retain dashboard and feature access.
32. Teacher course/subject views expose only courses assigned to that teacher; parent-only accounts cannot browse normal subjects/courses and use Parent Portal progress instead.
33. SMTP test delivery works using the configured host/port/TLS/SSL/username/app password; login-alert and announcement-mail switches work independently.
34. Student fee portal shows only that student's invoices. Cash-counter, Razorpay test-mode, and Stripe test-mode flows record exactly one verified payment and reject bad signatures/amounts.
35. Payment provider webhooks use their configured webhook secret and the raw request body; duplicate provider callbacks remain idempotent.
36. All authenticated roles can raise Support tickets; only administrators can view all tickets; once resolved, both user and administrator replies are rejected.
37. Admin user deletion protects self/Super Admin and requires the current administrator password; protected financial/audit relations fall back to suspension instead of unsafe deletion.

## Current build-environment limitation

The build environment used for the final local verification has PHP 8.4 but exposes no PHP PDO database drivers (`PDO::getAvailableDrivers()` is empty) and also lacks mbstring. Therefore PHP syntax/crypto logic and the first-run diagnostics page can be tested here, while a real PHP PDO MySQL session cannot. Python's SQLite runtime is used for schema/integrity smoke tests. This limitation is why live target-host acceptance remains mandatory.

## Final hardening checks

- Installer seed creation is transaction-protected after schema migration.
- Installer errors are logged privately and returned to visitors as sanitized actionable categories.
- One-time password reset tokens are consumed atomically before a password change.
- Encrypted restore updates backup history before database commit so a post-commit history failure cannot desynchronize restored database/upload state.
- Search, announcements and backup/restore are controlled by server-side feature gates, not navigation visibility alone.
