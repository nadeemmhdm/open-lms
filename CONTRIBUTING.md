# Contributing

1. Create a focused branch.
2. Keep production compatibility with shared PHP hosting: no mandatory Node.js runtime, shell access, cron, daemon, Docker, or production build step.
3. Use prepared statements and server-side authorization for new data access.
4. Add CSRF validation to every state-changing browser form.
5. Never commit credentials, generated `config/config.php`, uploaded files, logs, backups, or local SQLite databases.
6. Run `php -l` on all changed PHP files.
7. Document hosting limitations and any untested assumptions.
8. Keep migrations additive and versioned; never recreate the database on normal page loads.
