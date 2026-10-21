# Known limitations

- A live InfinityFree/MySQL/MariaDB deployment was not available inside the build environment, so host-specific SQL/configuration/file-permission behavior must be verified after upload.
- Free shared hosting may impose upload size, POST size, execution time, database connection and memory limits below Open LMS's application-level settings.
- Background workers and cron are not required. Features that would normally rely on persistent jobs are implemented synchronously or on user requests.
- Large video uploads can consume shared-host bandwidth/memory and make application backups large; external authorized video hosting is recommended.
- Encrypted application backups are intentionally self-contained and include referenced private uploads, which can use substantial PHP memory for large installations. For large institutions, move to a hosting tier that supports native database/filesystem backup tooling.
- Browser exam restrictions are detection/deterrence only and cannot prevent OS screenshots, developer tools in every environment, virtual machines, secondary devices or a modified client.
- Webcam proctoring is not enabled. The original design treats it as optional and requires separate consent/privacy configuration before any future implementation.
- Malware scanning is not bundled because the target free shared hosting environment may not provide a trusted scanner daemon. Strict extension/MIME/signature validation is used instead.
- No external email/SMS service is required. Password reset links are generated for trusted manual delivery. Absence, assessment-result, project and certificate alerts are in-app notifications; outbound email/SMS delivery would require a separately configured provider.
- Fee management records invoices/payments only; it is not a payment gateway or accounting system.
- Optional live classes store external HTTP/HTTPS join links; the LMS does not host a video-conferencing server.
