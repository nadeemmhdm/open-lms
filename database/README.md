# Database migrations

Open LMS uses ordered PDO migrations for both adapters. `schema_migrations` records each successfully applied file so migrations are not intentionally rerun.

| Phase | MySQL / MariaDB | SQLite | Purpose |
|---|---|---|---|
| 1 | `001_init_mysql.sql` | `001_init_sqlite.sql` | Core users/RBAC, academics, learning, attendance, assessments, projects, grades, settings/security tables |
| 2 | `002_phase2_mysql.sql` | `002_phase2_sqlite.sql` | Batch membership, course-batch access, lesson links/prerequisites and Phase 2 permission additions |
| 3 | `003_phase3_mysql.sql` | `003_phase3_sqlite.sql` | Attendance reporting context, question metadata and quiz configuration |
| 4 | `004_phase4_mysql.sql` | `004_phase4_sqlite.sql` | Exam attempts, frozen attempt questions, answer/event/grading metadata and browser-control flags |
| 5 | `005_phase5_mysql.sql` | `005_phase5_sqlite.sql` | Project policies, groups, versioned submission/grading/event metadata |
| 6 | `006_phase6_mysql.sql` | `006_phase6_sqlite.sql` | Announcements, certificates, timetable/live classes, parent links, fees, password reset, backups and course completions |

The first-run installer applies all pending migrations before seeding the first Super Admin. Existing installations call `Migrator` at startup; it checks the migration table and applies only missing versions. Normal page loads do not intentionally recreate the database.

## Deployment rules

- Back up before deploying code containing new migrations.
- Never delete or edit `schema_migrations` to hide a failed schema change.
- MySQL/MariaDB migration execution must be tested on the target hosting account before production rollout.
- SQLite is optional; use it only if `PDO_SQLITE` exists and private writable storage is safe on the host.
- MySQL/MariaDB is the intended InfinityFree adapter.
