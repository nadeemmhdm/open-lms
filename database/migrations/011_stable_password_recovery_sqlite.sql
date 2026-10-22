CREATE TABLE IF NOT EXISTS password_reset_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email_hash TEXT NOT NULL,
  ip_address TEXT NOT NULL,
  mail_sent INTEGER NOT NULL DEFAULT 0,
  requested_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_password_reset_request_email ON password_reset_requests(email_hash, requested_at);
CREATE INDEX IF NOT EXISTS idx_password_reset_request_ip ON password_reset_requests(ip_address, requested_at);
