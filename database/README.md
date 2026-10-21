# Database migrations

Open LMS uses versioned PDO migrations for both supported database adapters.

## MySQL / MariaDB (InfinityFree target)

- `001_init_mysql.sql` — core LMS/security schema.
- `002_phase2_mysql.sql` — batch membership, course-to-batch assignment, lesson links/prerequisites and Phase 2 role permissions.

## SQLite (optional compatible hosts/local development)

- `001_init_sqlite.sql`
- `002_phase2_sqlite.sql`

The first-run installer applies every pending migration before it creates the first Super Admin. Installed applications also check the `schema_migrations` table at startup and apply only migrations that have not already been recorded. Applied migrations are never intentionally rerun and the application does not recreate the database on normal page loads.

Back up the database before deploying a new application version that contains migrations. MySQL/MariaDB migration behavior must be verified on the target hosting account before production use.
