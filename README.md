# Open LMS

**Open LMS** is a lightweight, self-hosted, open-source Learning Management System designed for standard PHP hosting, including shared-host environments such as InfinityFree-style MySQL hosting.

**Stable version:** `v1.0.0`  
**License:** MIT  
**Runtime:** PHP + PDO + HTML/CSS + vanilla JavaScript  
**Database:** MySQL/MariaDB, with SQLite support where the host provides PDO SQLite

Open LMS is intentionally built without a required Node.js production runtime, Docker, shell access, cron worker, queue server, or background daemon.

## What is included

Open LMS provides a complete foundation for institutions that need a practical LMS on low-cost hosting:

- Role-based access for Super Admin, Administrator, Teacher, Student, HOD, Examiner, and Parent accounts.
- Academic years, departments, classes, sections, batches, semesters, subjects, and courses.
- Class-based subject access: assign a subject once to a class and every student in that class receives the same published courses for that subject. Batches remain available for cohort, attendance, timetable, and onboarding workflows but do not grant subject access.
- Secure bulk student onboarding with optional year, batch, and automatic course enrollment.
- Teacher/staff invitation links with expiry and single-use controls.
- Course structure using **Subject → Course → Chapter → Lesson**.
- Rich-text and Markdown lesson authoring.
- Lesson resources from protected uploads or external HTTPS links, including Google Drive, Google Docs, OneDrive, Dropbox, YouTube, Vimeo, direct PDF/document/image/video URLs, and other providers.
- Lesson headings, bold, italic, underline, lists, quotes, links, code blocks, and Markdown images.
- Private lesson uploads for documents, images, presentations, ZIP files, and MP4 video.
- Inline lesson image/video playback with authorization-checked file delivery.
- External lesson image, video, YouTube, Vimeo, MP4, and general resource links.
- Attendance, question bank, quizzes, exams, autosave, gradebook, and projects.
- Project submissions with optional protected file uploads or a GitHub repository URL for code projects.
- Private Student Doubts & Q&A: a student sees only their own threads; assigned teaching staff/admins can reply, clear/reopen threads, edit/resend their own messages, and users can Like/Dislike replies.
- Announcements for all users, courses, or batches.
- Announcement image/video links and protected image/video uploads.
- SMTP delivery for password recovery, login alerts, and optional announcement email.
- Secure self-service Forgot Password with one-time 30-minute reset links.
- Password-reset email cooldowns and per-IP/per-account mail ceilings.
- Login brute-force throttling with per-account/IP limits, network-wide limits, and cooldown periods.
- 12-hour normal login sessions and 24-hour Remember Me sessions using hashed server-side tokens.
- Login history with first/last sign-in time, IP address, and device/browser details.
- TOTP MFA with locally generated QR codes.
- Account suspension with immediate active-session revocation.
- Maintenance mode that preserves Administrator/Super Admin access.
- LMS-wide timezone configuration while timestamps remain stored in UTC.
- Configurable LMS name used consistently throughout authentication, navigation, mail, MFA, and payments.
- Fee/invoice management with cash-at-counter, Razorpay, and Stripe flows.
- Support-ticket workflow for students, teachers, and other users.
- AES-256-GCM authenticated encrypted backups with compressed application data and private uploads.
- Safe subject, course, chapter, lesson, batch, semester, and account management.
- Responsive desktop/mobile UI with a scrollable sidebar and automatic current-page positioning.

## Requirements

Recommended production environment:

- PHP 8.1 or newer.
- PDO MySQL/MariaDB, or PDO SQLite where supported.
- OpenSSL.
- mbstring.
- fileinfo.
- zlib recommended for compressed backups.
- HTTPS strongly recommended.
- Writable `config/` during first installation.
- Writable `storage/` for the installation lock, private uploads, logs, and backups.

Your hosting provider may enforce lower upload, execution-time, memory, or outbound SMTP limits than Open LMS itself.

## Installation

1. Upload the repository contents to the site web root, such as `htdocs/`.
2. Open the site in a browser.
3. Complete the first-run installer.
4. Enter the exact MySQL host, database name, username, and password supplied by the hosting provider.
5. Create the first Super Admin account.
6. Sign in and configure the LMS name, timezone, modules, SMTP, and payment providers.

The installer creates:

- `config/config.php`
- `storage/install.lock`

Do not commit or publicly expose the generated configuration file.

### InfinityFree-style hosting

Use the exact remote MySQL hostname displayed by the provider. Do not automatically substitute `localhost` when the provider supplies a host such as `sqlXXX.example.com`.

Open LMS keeps `.htaccess` directives conservative for shared-host compatibility; application security headers are emitted by PHP where appropriate.

## Upgrading an existing installation

Open LMS uses ordered SQL migrations. Existing installations should not be reset simply to apply an update.

1. Create an encrypted LMS backup and, when possible, an independent hosting/database backup.
2. Upload the new application files over the existing source.
3. Preserve `config/config.php`, `storage/install.lock`, the live database, and existing private uploads.
4. Open the LMS. Missing migrations are applied automatically.

The stable `v1.0.0` codebase currently includes migrations `001` through `014`.

## Authentication security

### Login throttling

Failed password attempts are recorded server-side. Open LMS applies:

- account + IP attempt windows,
- a configurable failed-attempt threshold,
- a configurable cooldown after the threshold,
- a separate network/IP-wide failure ceiling,
- automatic cleanup of old throttle records,
- failed-pair reset after a successful credential check.

Default fresh-install policy:

- 5 failed attempts per account/IP window,
- 15-minute window,
- 15-minute cooldown,
- 20 failed attempts per IP/network window.

These values can be adjusted in `config/config.php`.

### Password recovery

The sign-in page includes **Forgot password?** when the LMS is installed.

Password recovery is designed to avoid account enumeration. The public response remains generic regardless of whether the submitted address exists.

Mail delivery is additionally limited to prevent SMTP abuse:

- one reset email per account/address every 5 minutes,
- maximum 3 sent reset emails per account/address per hour,
- maximum 5 sent reset emails per account/address per day,
- maximum 5 reset requests per IP in 15 minutes,
- maximum 10 reset requests per IP per hour.

Reset tokens are random, stored only as hashes, single-use, and expire after 30 minutes. Completing a password reset revokes existing login sessions.

## Class-based subject access

Student learning access is class-first. Administrators assign students to classes and assign subjects to those classes. A student can see a course only when:

- the course is published,
- its subject is active, and
- that subject is assigned to the student's class.

Draft courses remain hidden from students even when a direct course URL is known. Batch membership and optional enrollment records do not override this rule. Existing batch/course access is migrated once into class assignments during migration `014` so upgrades retain access while future management uses the class model.

## Lessons and content authoring

Teachers and authorized staff can organize course content into chapters and lessons.

Each lesson supports two authoring modes:

### Rich Text

The built-in editor supports common formatting without exposing raw HTML controls:

- bold,
- italic,
- underline,
- bulleted and numbered lists,
- H2/H3 headings,
- block quotes,
- safe external links,
- formatting reset.

Server-side sanitization remains authoritative when the lesson is saved.

### Markdown

Markdown mode supports headings, bold, italic, ordered/unordered lists, block quotes, links, external images, inline code, fenced code blocks, horizontal rules, and strikethrough. Markdown is escaped and converted through the LMS safe renderer rather than rendered as arbitrary HTML.

### Lesson media

Teachers can upload supported files as private lesson resources. JPG, PNG, WebP, and MP4 files can be displayed directly inside the lesson. Document files remain protected downloads.

External resources can be classified as normal links, images, or videos. Supported video embeds include YouTube and Vimeo, with direct MP4 playback where applicable.

## Announcements

Authorized publishers can target announcements to:

- all users,
- a specific course,
- a specific batch.

Announcements support:

- text messages,
- optional expiry,
- optional SMTP delivery,
- external image URLs,
- external video URLs,
- up to four protected uploaded image/video files per announcement.

Uploaded announcement media uses the same server-side authorization model as the announcement itself and is not exposed through a public static URL.

## SMTP

Configure SMTP from **Settings** using:

- host,
- port,
- TLS / SSL / none,
- username,
- password or app password,
- From email,
- From name.

The SMTP password is encrypted using the LMS application key. Login-alert mail and announcement mail each have separate enable/disable controls.

Some free hosting providers block outbound SMTP ports. In that case, mail features cannot work until the host permits an outbound SMTP connection.

## Fees and payments

The fee module supports:

- invoice creation and management,
- student outstanding balances,
- cash-at-counter requests and admin confirmation,
- Razorpay checkout/verification,
- Stripe Checkout/verification,
- signed webhook validation.

Payment credentials are stored encrypted with the LMS application key. Production payment deployments should use HTTPS and provider-issued live credentials only after successful test-mode verification.

## Encrypted backups

The Backup module creates `.olmsbak` files using AES-256-GCM authenticated encryption. Application JSON is compressed when zlib is available.

Backups include application data plus referenced private uploads, including lesson materials, project files, and announcement media. They intentionally exclude transient security/runtime state such as active sessions, password-reset requests/tokens, login-throttle records, backup history, and migration bookkeeping.

Restore imports data into the **currently migrated schema** rather than rolling schema state backward.

Keep the installation `app.key` in `config/config.php` safe. Encrypted secrets and backups created with that key cannot be recovered after the key is lost or changed without a migration plan.

## Project structure

The repository intentionally keeps a small top-level layout:

```text
app/        Controllers, core security/database code, policies, services
assets/     CSS, JavaScript, bundled local QR runtime
database/   Ordered SQL migrations
includes/   Bootstrap and shared rendering/helpers
config/     Configuration template and generated local config
storage/    Private uploads, backups, logs, install lock
tests/      Portable regression/security checks
index.php   Front controller/router
```

Project documentation is intentionally consolidated into `README.md` and `SECURITY.md`, plus the MIT `LICENSE`.

## Testing

Before publishing changes, run the portable checks from the repository root:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/app.js
python3 tests/full_schema_smoke.py
python3 tests/security_static.py
php tests/totp_rfc_test.php
node tests/qrcode_smoke.js
```

The schema smoke test uses SQLite so it can validate migration ordering, required tables, constraints, and foreign-key integrity without requiring a production database.

## Security

Open LMS uses server-side authorization and does not rely on hidden menu items as an access-control mechanism. Important controls include:

- CSRF protection,
- prepared SQL statements,
- role/permission checks,
- teacher course scoping,
- parent progress-only restrictions,
- session revocation,
- login/password-reset throttling,
- TOTP MFA,
- upload MIME/signature validation,
- private-file authorization,
- encrypted secrets,
- authenticated encrypted backups,
- signed payment verification,
- audit logging.

See [SECURITY.md](SECURITY.md) for vulnerability reporting and deployment guidance.

## Contributing

Issues and pull requests are welcome.

Contributions should:

1. remain compatible with ordinary PHP shared hosting unless the change is explicitly optional,
2. preserve server-side authorization and CSRF protections,
3. avoid exposing credentials, private uploads, database dumps, generated configuration, or production backups,
4. include regression coverage for security-sensitive behavior,
5. keep the interface responsive and usable on both desktop and mobile,
6. avoid adding a production build dependency when the same result can be achieved with the existing PHP/vanilla-JS stack.

## Stable release

**Open LMS Stable — v1.0.0**

The first stable release provides the complete shared-host LMS foundation: academic management, secure onboarding, courses/chapters/lessons, assessments, projects, attendance, Q&A, SMTP recovery, timezone-aware scheduling, fees/payments, support, MFA, encrypted backups, responsive navigation, and protected media delivery.

The additional security limits, Markdown/rich lesson authoring, lesson media, and announcement media support are maintenance improvements to the same `v1.0.0` stable line; the public version number has not been changed.

## License

Open LMS is released under the [MIT License](LICENSE).

Bundled third-party code retains its applicable license notice under `licenses/`.
