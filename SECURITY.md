# Security Policy

## Supported version

Security fixes target the current stable Open LMS release (`v1.0.x`) and the latest code on the default branch.

## Reporting a vulnerability

Do not publish credentials, private user data, exploit details, or active-site secrets in a public issue. Prefer a private GitHub security report/security advisory for the repository when available. Include the affected version, affected route or component, reproduction steps, impact, and any proposed mitigation.

## Deployment requirements

- Use HTTPS in production.
- Keep `config/config.php`, the database, private uploads, and encrypted backups outside public disclosure.
- Use a unique high-entropy application key and do not rotate it without a migration plan; encrypted secrets and backups depend on it.
- Use least-privilege MySQL credentials dedicated to the LMS database.
- Enable MFA for privileged roles.
- Keep SMTP and payment secrets private and use provider-issued app/API credentials rather than personal passwords where possible.
- Configure Razorpay/Stripe webhooks only with HTTPS production URLs and provider webhook secrets.
- Review audit logs, login history, suspended accounts, active modules, and backup handling regularly.


## Authentication abuse controls

Open LMS applies server-side sign-in throttling using account/IP windows, a cooldown after repeated failures, and a separate IP-wide failure ceiling. Password recovery uses generic public responses to reduce account enumeration and applies both request ceilings and stricter mail-send ceilings before SMTP delivery. Reset tokens are single-use, stored only as SHA-256 hashes, expire after 30 minutes, and revoke existing account sessions after a successful reset.

## Backup security

Open LMS backup files are authenticated and encrypted with AES-256-GCM using a key derived from the installation application key. Possession of a backup file alone should not be treated as sufficient protection: restrict filesystem access, download/store backups securely, and retain the matching application key in a protected location.

## Scope notes

Browser-based exam controls cannot provide operating-system kiosk isolation. Shared-host restrictions may also limit outgoing SMTP, PHP execution time, upload size, and backup size. These deployment constraints should be considered when assessing operational security.
