# Open LMS

Open LMS is a lightweight, modular Learning Management System built for shared PHP hosting, with an explicit compatibility path for InfinityFree free hosting. It uses plain PHP, PDO, HTML, CSS and vanilla JavaScript and does **not** require Node.js, npm, Docker, shell access, background workers or Composer on the production host.

## Current implementation status

This repository currently contains the secure platform foundation (Phase 1) plus the completed academic/learning workflows in Phase 2:

- First-run web installer with server diagnostics.
- First Super Admin account creation during installation.
- Installer lock after successful setup.
- MySQL/MariaDB first-class support for InfinityFree.
- Optional SQLite adapter for hosts where `PDO_SQLITE` is available.
- Secure password hashing and session handling.
- Login throttling using database-backed failed-attempt tracking.
- CSRF protection on state-changing forms.
- Role-based access foundation and protected Super Admin role.
- User creation and account suspension/activation.
- Feature/module enable-disable screen.
- Audit logging.
- Safe diagnostics page that does not display secrets or private filesystem paths.
- Responsive light/dark admin UI.
- Academic years, departments, classes, sections, batches and semesters.
- Student-to-batch membership and course-to-batch assignment.
- Responsive subject cards with teacher/course/lesson/progress data.
- Course creation, teacher ownership, draft/published/archive states and student enrollment.
- Ordered course sections and lesson creation/reordering.
- Lesson rich-text formatting with a server-side safe HTML allowlist.
- Learning objectives, instructions, duration, scheduled release and lesson prerequisites.
- Secure private lesson-material uploads and authorization-checked downloads.
- PDF, DOC, DOCX, PPT, PPTX, JPEG, PNG, WebP, MP4 and ZIP validation by extension, MIME type and file signature.
- External lesson links plus restricted YouTube/Vimeo video embedding.
- Student lesson completion and course/subject progress tracking.
- Resource ownership checks so teachers can only manage their assigned courses.
- Versioned automatic schema upgrade from Phase 1 through `002_phase2`.
- Normalized schema foundations for later attendance, quiz, exam, project, gradebook, notification and reporting phases.

Phases 3-6 are not claimed as complete yet.

## Requirements

Recommended runtime:

- PHP 8.1+ (PHP 8.4 is supported by the current codebase).
- PDO.
- `pdo_mysql` for InfinityFree / MySQL or MariaDB.
- `pdo_sqlite` only if you explicitly choose SQLite.
- PHP sessions.
- OpenSSL functions recommended for future MFA secret encryption.
- Writable `config/` and `storage/` directories during first installation.
- PHP Fileinfo extension for secure upload MIME inspection.

## InfinityFree deployment

InfinityFree free hosting is designed around PHP and MySQL/MariaDB. Use the database host, database name, username and password shown in the InfinityFree control panel; do not use `localhost` unless the panel explicitly says to do so.

### Upload layout

Upload the **contents of this repository** directly into your site's `htdocs` directory so the structure is:

```text
htdocs/
├── index.php
├── .htaccess
├── app/
├── assets/
├── config/
├── database/
├── includes/
├── storage/
├── admin/
├── teacher/
└── student/
```

Do not upload an extra wrapper folder such as `htdocs/open-lms/index.php` unless you intentionally want the LMS in a subdirectory.

### First visit / Super Admin setup

1. Create an empty MySQL database in the InfinityFree control panel.
2. Upload the project files into `htdocs`.
3. Open your website in a browser.
4. Open LMS automatically shows the protected first-run installer because `config/config.php` and `storage/install.lock` do not exist yet.
5. Select **MySQL / MariaDB (InfinityFree)**.
6. Enter the exact database host, database name, database username and database password from the hosting panel.
7. Enter the institution name.
8. Enter the first Super Admin name, email and a password of at least 12 characters.
9. Submit the installer.
10. The installer applies all current MySQL migrations (`001_init_mysql.sql`, `002_phase2_mysql.sql`), creates roles/permissions, creates the first Super Admin, writes `config/config.php`, and creates `storage/install.lock`.
11. You are redirected to the sign-in page.

After installation, requesting the installer route returns an installer-locked page.

## Security notes

- `config/`, `database/`, `storage/`, `app/`, `includes/` and `tests/` are denied direct web access through the root `.htaccess` rules.
- `config/config.php` is excluded from Git.
- Passwords are stored using `password_hash()` and verified using `password_verify()`.
- Session IDs rotate after successful login.
- Session cookies use `HttpOnly` and `SameSite=Lax`, and automatically use `Secure` when HTTPS is detected.
- All implemented state-changing forms use CSRF tokens.
- Login attempts are rate-limited by email + IP within a configurable window.
- Ordinary admin user-management actions cannot suspend a Super Admin account.
- Private errors are logged server-side; visitors receive a generic server error page.
- Uploaded/private LMS files live under `storage/uploads/`, use random `.dat` server filenames, and are blocked from direct web access. Downloads are streamed only after course/lesson authorization checks.
- Uploads are checked by extension, server-detected MIME type, maximum size and file signature; PHP/executable uploads are not accepted.
- Teachers can manage only courses assigned to their account; student access requires explicit enrollment or membership in an assigned batch.

Before public use, enable HTTPS for your domain and verify that direct requests to `/config/config.php`, `/database/`, and `/storage/` are denied.

## Database choice

### MySQL / MariaDB

Recommended for InfinityFree. This is the default installer option and uses:

`database/migrations/001_init_mysql.sql` and `database/migrations/002_phase2_mysql.sql`

### SQLite

Available only when the server exposes the `pdo_sqlite` PDO driver and the application can safely write to `storage/`. The installer checks this before allowing SQLite setup. It uses:

`database/migrations/001_init_sqlite.sql` and `database/migrations/002_phase2_sqlite.sql`

If the host cannot safely support SQLite, use MySQL/MariaDB instead.

## Local development

1. Copy `config/config.example.php` only if you want to configure manually. Normally, delete/omit `config/config.php` and use the web installer.
2. Ensure PHP has the PDO driver for your selected database.
3. From the repository root, run:

```bash
php -S 127.0.0.1:8080
```

4. Open `http://127.0.0.1:8080/`.

The repository does not require npm or a build step.

## Directory overview

```text
app/Core/          configuration, database, auth, CSRF
app/Controllers/   request handlers
app/Services/      installer, migrations and secure upload services
app/Middleware/    authorization middleware foundation
app/Policies/      resource policy foundation
assets/            responsive CSS and vanilla JS
config/            example config; generated config is ignored by Git
database/          versioned MySQL and SQLite migrations
storage/           private uploads, logs, backups, install lock
admin/             compatible entry redirect
teacher/           compatible entry redirect
student/           compatible entry redirect
tests/             local verification scripts/checklists
```

## Hosting limitations to account for

Free shared hosting may impose fixed upload/file limits, execution-time limits and restrictions on automated background requests. Open LMS therefore does not depend on cron jobs, persistent workers or server-side daemons. The application upload policy defaults to 10 MB but the effective limit can be lower if the host enforces a smaller PHP upload limit. Large videos should be externally hosted/embedded instead of stored as application uploads.

## Testing performed for this commit

- Every PHP file passes syntax lint using PHP 8.4 CLI.
- `assets/js/app.js` passes JavaScript syntax validation.
- Both SQLite migrations were executed in a temporary database and the Phase 2 relationships were exercised: batch membership, course-to-batch access and lesson progress all passed.
- Phase 2 permission seeding for administrator/teacher/HOD/examiner roles was verified in the SQLite smoke database.
- The current local PHP CLI exposes PDO core but not `pdo_mysql` or `pdo_sqlite`, so an end-to-end PHP database session was not falsely reported as tested. MySQL/MariaDB integration must still be verified on the target InfinityFree database before calling the deployment production-ready.

## Next phases

- Phase 3: attendance, reusable question bank, quizzes, gradebook.
- Phase 4: exam attempts, autosave, expiry, event logging, grading/results.
- Phase 5: projects, submission versioning, grading and feedback.
- Phase 6: notifications, reporting, certificates, backup/restore hardening and final acceptance/security testing.

## License

MIT License. See `LICENSE`.
