# Tests

- `full_schema_smoke.py` — executes SQLite migrations `001`–`006` and representative cross-module data-integrity checks.
- `totp_rfc_test.php` — RFC 6238 SHA-1 vector verification for the TOTP implementation.
- `qrcode_smoke.js` — local MFA QR SVG generation test.
- `security_static.py` — high-value CSRF, privilege, MFA, module-gate and role-scope regression checks.
- `phase2_smoke.php` — earlier Phase 2 helper retained for compatible PHP environments with a PDO SQLite driver.

See the repository-level `TESTING.md` for the full command list and target-host acceptance checklist.
