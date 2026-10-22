# Open LMS

Open LMS is a modular Learning Management System built for shared PHP hosting, including an InfinityFree-compatible deployment path. The production runtime is plain PHP + PDO + HTML/CSS + vanilla JavaScript: no Node.js build step, Docker, shell access, cron daemon, Composer-on-server, or paid API is required.

## Implementation status

Phases 1–6 are implemented in this repository.

### Phase 1 — platform and security foundation
- First-run web installer with safe environment diagnostics.
- First Super Admin creation and `storage/install.lock` installer lock.
- MySQL/MariaDB default for InfinityFree; optional SQLite adapter where supported.
- Secure sessions, password hashing, CSRF, prepared statements, login throttling and audit logs.
- Server-side roles/permissions, protected Super Admin elevation/removal rules and account suspension controls.
- One-time 30-minute password-reset links for trusted manual delivery.
- Admin-generated self-service onboarding links: seat-limited bulk Student links and one-user role setup links with 6–8 digit public IDs, hashed secrets, automatic expiry/consumption and immediate sign-in after account creation.
- RFC 6238 TOTP MFA with locally generated QR enrollment, encrypted secrets, replay prevention, one-time hashed recovery codes, challenge throttling, mandatory-role policy, user disable/re-enroll, and Super-Admin-authorized MFA reset.
- Responsive light/dark UI and safe diagnostics.

### Phase 2 — academics and learning
- Academic years, departments, classes, sections, batches and semesters.
- Student-to-batch membership, course-to-batch assignment and direct course enrollment.
- Subject/course cards, teacher ownership and server-side resource authorization.
- Ordered course sections and lessons, rich-text allowlist, objectives, instructions and duration.
- Scheduled lesson release, prerequisites, previous/next navigation and progress/completion tracking.
- Private learning-material uploads/downloads with size, extension, server MIME and signature validation.
- PDF, DOC/DOCX, PPT/PPTX, JPEG, PNG, WebP, MP4 and ZIP support; executable uploads rejected.
- External links and restricted YouTube/Vimeo embedding.

### Phase 3 — attendance, question bank, quizzes and gradebook
- Daily/period, batch/course attendance with Present/Absent/Late/Excused states.
- Bulk marking, duplicate prevention, corrections with reason/history, locking, percentages and CSV export.
- Academic-year, semester and date-range attendance reporting.
- Reusable question banks: MCQ, multiple-select, true/false, fill blank, short, descriptive and matching.
- Quiz duration, marks/pass marks, negative marking, attempts, randomization, option shuffling, windows, review/result policies.
- Automatic objective grading, manual descriptive grading and consolidated gradebook.

### Phase 4 — examination engine
- Server-controlled start/expiry times and unique numbered attempts.
- Frozen per-attempt question/option order, autosave and reconnect/resume behavior.
- Automatic expiry/submission and server-side grading.
- Manual descriptive grading, result publication and authorized regrading.
- Fullscreen request plus visibility/focus/copy/paste/context-menu event logging and warnings.
- Browser controls are explicitly detection/deterrence only; important enforcement remains server-side.

### Phase 5 — projects and assignments
- Individual/group projects, group membership and ownership.
- Start/deadline, late policy, allowed extensions, max size, multi-file policy and resubmission policy.
- Private versioned submissions and authorized downloads.
- Rubric data, marks, feedback, grading history and gradebook propagation.

### Phase 6 — operational modules and hardening
- Personal notifications and independently configurable scoped announcements.
- Reports/analytics and teacher-workload/course metrics with role-aware data scope.
- Verifiable/revocable course-completion certificates.
- Weekly timetable and optional external live-class links with batch privacy controls.
- Optional parent/guardian read-only portal for explicitly linked students.
- Optional fee invoice/payment-recording module (not a payment gateway).
- Authorization-aware search with a server-side module switch.
- Configurable encrypted application backup/restore including referenced private upload bytes.
- Versioned migrations through `008_registration_invites` plus security/regression tests.
- Server-side module switches for optional announcements, search, backup/restore, certificates, timetable/live classes, parent portal and fee management.

## Runtime requirements

Recommended: PHP 8.1+ with PDO, sessions, OpenSSL, mbstring and Fileinfo. For InfinityFree use `pdo_mysql`; SQLite requires `pdo_sqlite` and safe writable private storage.

## InfinityFree deployment

1. Create an empty MySQL/MariaDB database in the InfinityFree control panel.
2. Extract/upload the **contents** of the release ZIP directly into the site's `htdocs` directory so `htdocs/index.php` exists.
3. Open the website. If `config/config.php` and `storage/install.lock` do not exist, Open LMS displays its first-run installer.
4. Select **MySQL / MariaDB (InfinityFree)** and enter the exact database host/name/user/password from the hosting panel. Do not assume `localhost`.
5. Enter the institution name and first Super Admin credentials (minimum 12-character password).
6. The installer applies all pending migrations `001`–`008`, seeds roles/permissions/features, creates the first Super Admin, generates a random application encryption key, writes `config/config.php`, and creates `storage/install.lock`.
7. Sign in. The Super Admin is required to enroll authenticator MFA before continuing.

Expected upload layout:

```text
htdocs/
├── index.php
├── .htaccess
├── app/
├── assets/
├── config/
├── database/
├── includes/
├── licenses/
├── storage/
├── tests/
├── admin/
├── teacher/
└── student/
```

Do not commit/generated-upload `config/config.php`, database credentials, install locks, local SQLite files, logs, backups, or user uploads.

## Security model

- Authentication uses `password_hash()` / `password_verify()` and rotates the session ID on successful login.
- Cookies are HttpOnly/SameSite and become Secure under HTTPS.
- Implemented state-changing forms carry CSRF tokens; logout is POST-only.
- Server-side RBAC/resource ownership is authoritative; hidden UI controls are never treated as authorization.
- Ordinary administrators cannot create, suspend, or self-elevate to Super Admin through user management.
- Teachers are limited to assigned courses; students require enrollment or an assigned batch.
- Optional module routes reject direct access when disabled.
- TOTP secrets are AES-256-GCM encrypted using the generated application key; recovery codes are stored only as password hashes.
- Private LMS files use randomized non-executable storage names and are streamed only after authorization.
- Public errors do not expose stack traces, credentials or internal database paths.
- Registration invite secrets are stored only as SHA-256 hashes; active links disappear after their allowed account count is reached, after expiry, or after administrator revocation.

Read `SECURITY.md`, `TESTING.md` and `KNOWN_LIMITATIONS.md` before public deployment.

## Database migrations

Both adapters use ordered versioned migrations:

- `001_init_*` — core users/RBAC + base LMS schema
- `002_phase2_*` — academic/learning access relationships
- `003_phase3_*` — attendance/question/quiz extensions
- `004_phase4_*` — exam attempt/grading/event data
- `005_phase5_*` — projects/groups/versioning/grading
- `006_phase6_*` — notifications/certificates/timetable/parent/fees/password-reset/backups/completions
- `007_installer_seed_repair_*` — safe recovery for interrupted first-run seeds
- `008_registration_invites_*` — secure self-service account onboarding links and invite history states

`schema_migrations` prevents intentional reapplication. Existing installations run the migration checker at startup and only missing versions are applied. Back up before deploying a new schema version.

## Local development

```bash
php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/`. A supported PDO driver is required to complete installation.

## Verification

Local automated checks include PHP syntax lint, JavaScript syntax, migrations `001`–`008` in a temporary SQLite database, cross-module integrity workflows, RFC 6238 vectors, local QR generation, CSRF/security wiring and first-run installer HTTP rendering. See `TESTING.md` for exact commands and the required InfinityFree/MySQL acceptance checklist.

The current build environment does **not** expose a PHP PDO database driver, so a live PHP/MySQL migration and end-to-end deployment cannot truthfully be marked as tested here. Complete the target-host checklist before treating an installation as production-ready.

## Hosting limitations

Free shared hosting can impose upload, POST body, memory, execution-time and database limits below the application's configured values. Open LMS therefore does not depend on persistent workers or cron. Large video files should normally be hosted by an approved external video service. Application backups are self-contained and may consume substantial memory on large installations.

## Third-party notices

The bundled local QR encoder includes third-party algorithm code under its applicable license. See `THIRD_PARTY_NOTICES.md` and `licenses/`.

## License

MIT. See `LICENSE`.
