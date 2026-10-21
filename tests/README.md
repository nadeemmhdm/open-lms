# Tests

## Static verification

Run from the repository root:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Phase 2 SQLite smoke test

If `pdo_sqlite` is installed:

```bash
php tests/phase2_smoke.php
```

The smoke test validates both migrations plus batch membership, course-to-batch access, lesson progress and Phase 2 permission seeding. Exit code `77` means the local PHP runtime does not provide `pdo_sqlite`.

## Acceptance checks through Phase 2

- Fresh install redirects to first-run installer and applies all current migrations.
- Installer creates the first Super Admin and cannot be rerun after the install lock exists.
- Invalid login is rejected and repeated failures are throttled.
- Successful login rotates the session ID.
- CSRF failure rejects state-changing forms.
- Academic year, department, class, section, batch and semester creation works.
- Student batch membership persists and duplicate membership is prevented.
- Subject create/edit/archive works for authorized academic roles.
- Teachers cannot directly open or edit another teacher's course.
- Course draft/published/archive states are enforced for students.
- Course-to-batch assignment grants access only to students who belong to that batch.
- Explicit course enrollment grants student access independently of batch assignment.
- Course sections and lessons can be created and lessons can be reordered.
- Scheduled lessons are hidden from students before release time.
- Prerequisite lessons block access until required lessons are completed.
- Student completion writes `lesson_progress` and updates course/subject progress.
- Uploads reject unsupported extensions, MIME mismatches, signature mismatches and over-limit files.
- Private material download requires authorization for the parent course and lesson.
- Uploaded files cannot execute as PHP and are stored under randomized `.dat` names.
- YouTube/Vimeo embeds are generated only from recognized URLs; other external resources open as links.
- Direct web access to config/database/storage/app/includes/tests is denied on Apache hosts honoring `.htaccess`.
- Light/dark theme and responsive layouts work at desktop/tablet/mobile widths.

MySQL/MariaDB integration must be run on a PHP runtime that includes `pdo_mysql`, ideally the target InfinityFree account, before public deployment is called production-ready.
