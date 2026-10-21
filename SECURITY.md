# Security Policy

## Supported branch

Security fixes target the default `main` branch while the project is in active development.

## Reporting a vulnerability

Please do not publish exploitable security details in a public issue before a fix is available. Use a private communication channel with the repository owner when possible, and include the affected route/file, reproduction steps, impact, and a suggested mitigation if known.

## Security design currently implemented

- PHP password hashing and verification.
- Secure session configuration with authentication-time session ID rotation.
- CSRF protection for implemented state-changing forms.
- Database prepared statements for user-provided values.
- Server-side RBAC checks for administrative routes plus teacher course ownership and student enrollment/batch access checks.
- Super Admin protection in the current account-status workflow.
- Login rate limiting by email and IP.
- Audit logging.
- Private configuration and storage web-access restrictions.
- Learning-material upload allowlist with extension, MIME, size and binary-signature checks.
- Randomized non-executable stored filenames and authorization-checked download streaming.
- Scheduled lesson release and prerequisite enforcement on the server.
- Generic production error pages with server-side error logging.
- No hard-coded credentials or committed generated configuration.

## Deployment checklist

1. Enable HTTPS.
2. Verify direct requests to `/config/`, `/database/`, `/storage/`, `/includes/`, `/app/` and `/tests/` are denied.
3. Keep `config/config.php` out of source control.
4. Use a unique database password.
5. Use a strong Super Admin password (12+ characters; a password manager is recommended).
6. Review the audit log after installation.
7. Keep PHP and the application updated.
8. Back up the database before schema upgrades.
9. Do not broaden upload permissions to executable file types.
10. Verify the host honors `.htaccess` denial for `storage/`; if the hosting plan permits storage outside the public document root, prefer that location.
11. Keep large video content on a trusted external video host instead of relaxing upload limits.
12. Do not claim later LMS modules are security-complete until their acceptance tests are implemented and passed.
