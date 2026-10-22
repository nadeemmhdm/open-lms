-- Repair fresh/partial installs created by Phase 6 defaults before the installer seed.
-- Only remove these defaults when no user account exists yet, so existing LMS sites are untouched.
DELETE FROM feature_settings
WHERE feature_key IN ('announcements','search','backup_restore')
  AND (SELECT COUNT(*) FROM users) = 0;
