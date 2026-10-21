# Tests

Run static PHP verification with:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Acceptance checks: fresh install opens installer; PDO availability is reported; invalid DB driver is rejected; first-admin password under 12 chars is rejected; successful install creates schema/config/install lock; installer cannot rerun; failed logins are tracked/throttled; successful login rotates session ID; admin routes reject ordinary roles; CSRF failures are rejected; Super Admin cannot be suspended from the user screen; feature switches persist; audit events are written; protected directories deny direct web access; theme persists; responsive layout works.

Database integration requires PHP with the chosen PDO driver.
