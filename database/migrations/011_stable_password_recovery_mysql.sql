CREATE TABLE IF NOT EXISTS password_reset_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  mail_sent TINYINT(1) NOT NULL DEFAULT 0,
  requested_at DATETIME NOT NULL,
  INDEX idx_password_reset_request_email(email_hash, requested_at),
  INDEX idx_password_reset_request_ip(ip_address, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
