# Security Policy

## Supported branch

Security fixes target the default `main` branch while Open LMS is under active development.

## Reporting a vulnerability

Do not publish working exploit details, credentials, MFA secrets, private files or personal data in a public issue. Contact the repository owner privately when possible and include the affected route/file, reproducible steps, impact and a mitigation suggestion if known.

## Implemented controls

- `password_hash()` / `password_verify()` and minimum 12-character passwords in first-admin/user creation flows.
- HTTP-only, SameSite session cookies; Secure is enabled when HTTPS is detected.
- Session ID rotation after completed password/MFA authentication.
- Login throttling by normalized email and source IP.
- CSRF tokens on implemented state-changing forms.
- Prepared PDO statements for user-controlled values.
- Contextual HTML escaping and a restricted lesson-rich-text allowlist.
- Server-side RBAC, course ownership, enrollment/batch access and authorized download checks.
- Super-Admin-only role/permission console; ordinary admins cannot assign Super Admin.
- Super Admin grants are blocked from the ordinary user-create path and require the Super-Admin-only role console plus current-password reauthentication; removal, MFA disable and backup/restore also require protected workflows.
- Mandatory Super Admin TOTP and configurable mandatory-MFA role policy.
- AES-256-GCM encrypted TOTP secrets; hashed single-use recovery codes; replay-step tracking.
- Password reset tokens are random, stored only as SHA-256 hashes, expire and are one-use.
- Upload allowlist with extension, Fileinfo MIME, size and binary signature validation.
- Random `.dat` private filenames; direct `storage/` web requests denied; downloads require authorization.
- Server-enforced lesson release/prerequisite, exam deadline and project deadline rules.
- Exam events log browser visibility/focus/fullscreen/copy/paste signals without claiming browser lockdown.
- Server-side feature gates return a disabled response even if a user manually enters a hidden module URL.
- Audit logging for authentication/security/administrative workflows.
- Encrypted authenticated backup payloads include database records and referenced private uploads with SHA-256 integrity metadata.
- Generic visitor-facing production errors; private errors are written to server logging rather than exposed in HTML.

## Important boundaries

Browser controls cannot prevent every form of exam cheating, developer tools, OS screenshots, secondary devices or an externally modified client. All grading/deadline/authorization decisions therefore remain server-side.

`.htaccess` protection must be verified on the real host. If the hosting plan permits storage outside the public document root, that remains preferable for private files/configuration.

The application does not include a mandatory malware-scanning daemon because the target free shared host may not provide shell access, ClamAV or persistent workers. Uploads are instead restricted by a strict allowlist/signature policy. Add server-side malware scanning only when a trusted hosting capability is available.

## Deployment checklist

1. Use HTTPS for the public site.
2. Verify direct requests to `/config/`, `/database/`, `/storage/`, `/includes/`, `/app/` and `/tests/` are denied.
3. Keep generated `config/config.php`, `storage/install.lock`, uploads, logs and backups out of source control.
4. Confirm PHP exposes PDO MySQL, OpenSSL, mbstring, Fileinfo and sessions.
5. Use unique database credentials and a 12+ character password-manager-generated Super Admin password.
6. Complete Super Admin authenticator enrollment and save recovery codes offline.
7. Review Roles/MFA policy and Module settings.
8. Test a private upload and confirm the raw storage path cannot be downloaded directly.
9. Test authorization with at least one Teacher and one Student account.
10. Test exam deadline/duplicate submission behavior with server timestamps.
11. Create an encrypted backup, copy it off-host together with a protected copy of the application key/config, and perform a restore drill on a non-production installation.
12. Review Audit Log after installation/upgrades.
13. Keep PHP/application versions patched and review `KNOWN_LIMITATIONS.md` before public launch.

## Production-readiness statement

Local lint/schema/crypto smoke tests are documented in `TESTING.md`. A live MySQL/MariaDB/InfinityFree acceptance run is still required before describing a deployment as production-ready.

## Final hardening notes

Password-reset token consumption uses a conditional database update inside the password-change transaction. Installer failures are logged server-side while public setup errors are sanitized. Backup restore keeps its database history update inside the restore transaction before commit and rolls staged uploads back on restore failure. Optional announcements, search and backup/restore reject direct requests when disabled.
