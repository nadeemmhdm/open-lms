# Database migrations

`001_init_mysql.sql` is the deployment migration for InfinityFree/MySQL/MariaDB. `001_init_sqlite.sql` is the optional compatibility migration when `PDO_SQLITE` is available and private storage is writable. Migrations run only through the protected first-run installer; normal page requests never recreate the database.
