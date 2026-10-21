# Security Policy

Security fixes target `main` while Open LMS is in active development. Do not publish exploitable vulnerability details before a fix is available.

Current controls include password hashing, secure sessions with login-time ID rotation, CSRF protection, prepared statements, server-side RBAC, Super Admin protection in account-status workflows, login throttling, audit logs, private configuration/storage restrictions, and generic production error pages.

Deployment checklist: enable HTTPS; verify `/config/`, `/database/`, `/storage/`, `/includes/`, `/app/` and `/tests/` are denied; keep `config/config.php` out of Git; use unique DB credentials and a strong Super Admin password; review audit logs; back up before migrations; never allow executable uploads.
