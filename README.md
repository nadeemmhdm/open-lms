# Open LMS

Open LMS is a self-hosted, open-source learning management system for PHP shared hosting, including InfinityFree-style MySQL hosting. It provides academic setup, course delivery, assessments, projects, attendance, onboarding, payments, support, security controls, and administration without requiring a worker process or shell access on the server.

**Stable release:** `v1.0.0`  
**License:** MIT

## Highlights

- Responsive role-based dashboards for Super Admin, Admin, Teacher, Student, HOD, Examiner, and Parent roles.
- Academic years, departments, classes, sections, batches, semesters, subjects, courses, chapters, lessons, materials, and progress tracking.
- Bulk student onboarding links that can preassign academic year, batch, and courses.
- Attendance, question banks, quizzes, exams, gradebook, projects, certificates, timetable, announcements, notifications, Doubts & Q&A, and support tickets.
- Teacher course scoping, parent progress-only access, account suspension, MFA, audit logs, CSRF protection, prepared SQL, and protected private uploads.
- 12-hour normal sessions or 24-hour Remember Me sessions using hashed server-side session tokens.
- User profile login history with first/last sign-in time, IP address, and device information.
- SMTP configuration, sign-in alerts, announcement email delivery, and secure self-service password recovery with one-time 30-minute links.
- LMS-wide timezone management with UTC storage and local-time input/display.
- Maintenance mode that keeps privileged administrator access available.
- Fee management with cash-at-counter requests plus Razorpay and Stripe integrations.
- AES-256-GCM encrypted, compressed application backups with authenticated restore and secure download.
- Configurable LMS name used across sign-in, sidebar, browser titles, account setup, MFA issuer, email, and payment branding.

## Requirements

- PHP 8.1 or newer recommended.
- PDO MySQL/MariaDB for typical shared hosting; PDO SQLite is optional when supported by the host.
- PHP extensions: OpenSSL, mbstring, fileinfo, and the selected PDO driver.
- Writable `config/` and `storage/` directories during installation and normal operation.
- HTTPS is strongly recommended for production use.

## Install

1. Upload the contents of this repository to the web root, for example `htdocs/`.
2. Open the site in a browser.
3. Complete the first-run installer.
4. For InfinityFree/MySQL, use the exact database host, database name, username, and password shown in the hosting control panel. Do not assume `localhost`.
5. Create the first Super Admin account.
6. Sign in and configure LMS name, timezone, SMTP, modules, payments, and other settings from the administration UI.

The installer writes `config/config.php` and `storage/install.lock`. Do not commit or publicly expose the generated configuration file.

## Upgrade

For an existing installation:

1. Back up the current application and database first.
2. Upload the new release files over the existing application files.
3. Preserve `config/config.php`, `storage/install.lock`, the live database, and private uploads.
4. Open the LMS. Missing migrations are applied automatically.

Never delete the database just to upgrade between Open LMS releases.

## Password recovery

When SMTP is configured, users can select **Forgot password?** on the sign-in page and request a recovery email using their account email/username. Recovery requests are rate-limited, the response does not reveal whether an account exists, and each reset URL is single-use with a 30-minute expiry. Completing a reset revokes existing login sessions.

## Courses, chapters, and lessons

Administrators can create and manage subjects and courses. Teachers can manage only courses assigned to them. Inside a course, learning content is organized as:

`Subject → Course → Chapter → Lesson`

Chapters can be added, renamed, or deleted when empty. Lessons can be created inside chapters and edited with content, release controls, prerequisites, links, and private learning materials. Empty subjects and courses can be deleted; records with learning or student history are protected from destructive deletion.

## Backups

The Backup module creates `.olmsbak` files using compressed AES-256-GCM authenticated encryption. Backups include application data and referenced private lesson/project uploads, while intentionally excluding active sessions, password-reset credentials, login throttles, backup history, and migration bookkeeping.

Important: keep the installation `app.key` from `config/config.php` safe. A backup cannot be decrypted after losing or changing the key that created it.

Restore runs against the **current migrated schema** so restoring data does not roll the application schema backward. Generated encrypted backups can be downloaded from the admin Backup page.

## Email

Configure SMTP in **Settings** with host, port, encryption, username, password/app password, sender email, and sender name. SMTP can be disabled globally. Login alerts and announcement email delivery have separate controls, and announcement email can be selected per announcement.

## Payments

Fee management supports:

- Cash at counter with admin confirmation.
- Razorpay order/payment verification and signed webhooks.
- Stripe Checkout and signed webhooks.

Provider secrets are encrypted with the LMS application key. Use provider-hosted checkout and configure the webhook URLs shown in Settings. Test gateway configuration before enabling production payments.

## Security

Open LMS uses password hashing, CSRF tokens, prepared SQL statements, server-side RBAC, session revocation, TOTP MFA, login throttling, file validation, encrypted secrets, signed payment verification, role-scoped queries, and audit logs. See [SECURITY.md](SECURITY.md) for reporting and deployment guidance.

## Testing

The repository includes portable regression checks for migrations, schema integrity, CSRF/RBAC wiring, session policy, onboarding, Q&A scoping, timezone handling, SMTP security, payment verification, support tickets, password recovery, backup format rules, and MFA utilities.

Typical checks:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/app.js
python3 tests/full_schema_smoke.py
python3 tests/security_static.py
php tests/totp_rfc_test.php
node tests/qrcode_smoke.js
```

## Contributing

Issues and pull requests are welcome. Keep changes compatible with shared PHP hosting, preserve server-side authorization checks, add or update regression coverage for security-sensitive behavior, and do not commit credentials, database dumps, generated configuration, private uploads, or production backup files.

## License and third-party code

Open LMS is released under the MIT License. The bundled QR implementation retains its applicable third-party license notice under `licenses/`.
