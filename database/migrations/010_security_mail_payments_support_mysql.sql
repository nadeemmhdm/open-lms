ALTER TABLE users ADD COLUMN first_login_at DATETIME NULL;
ALTER TABLE users ADD COLUMN first_login_ip VARCHAR(45) NULL;
ALTER TABLE users ADD COLUMN first_login_user_agent VARCHAR(500) NULL;
ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL;
ALTER TABLE users ADD COLUMN last_login_user_agent VARCHAR(500) NULL;

CREATE TABLE IF NOT EXISTS auth_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  remember_me TINYINT(1) NOT NULL DEFAULT 0,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  device_label VARCHAR(160) NULL,
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  CONSTRAINT fk_auth_sessions_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_auth_sessions_user_active(user_id,revoked_at,expires_at),
  INDEX idx_auth_sessions_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_login_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  device_label VARCHAR(160) NULL,
  remember_me TINYINT(1) NOT NULL DEFAULT 0,
  login_at DATETIME NOT NULL,
  session_expires_at DATETIME NOT NULL,
  CONSTRAINT fk_login_history_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_login_history_user_time(user_id,login_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fee_invoices ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT 'INR';
ALTER TABLE fee_invoices ADD COLUMN notes TEXT NULL;
ALTER TABLE fee_invoices ADD COLUMN updated_at DATETIME NULL;
ALTER TABLE fee_payments ADD COLUMN provider VARCHAR(30) NULL;
ALTER TABLE fee_payments ADD COLUMN provider_reference VARCHAR(190) NULL;

CREATE TABLE IF NOT EXISTS payment_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(30) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  amount DECIMAL(12,2) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'INR',
  provider_order_id VARCHAR(190) NULL,
  provider_payment_id VARCHAR(190) NULL,
  reference VARCHAR(190) NULL,
  metadata_json TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  CONSTRAINT fk_payment_tx_invoice FOREIGN KEY(invoice_id) REFERENCES fee_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_tx_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_payment_provider_order(provider,provider_order_id),
  INDEX idx_payment_tx_invoice(invoice_id,status),
  INDEX idx_payment_tx_user(user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id INT UNSIGNED NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(180) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT 'general',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  resolved_by BIGINT UNSIGNED NULL,
  CONSTRAINT fk_support_ticket_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_ticket_resolver FOREIGN KEY(resolved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_support_ticket_status(status,updated_at),
  INDEX idx_support_ticket_user(user_id,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_replies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id BIGINT UNSIGNED NOT NULL,
  sender_user_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_support_reply_ticket FOREIGN KEY(ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_reply_sender FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_support_reply_ticket(ticket_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO feature_settings (feature_key,enabled,config_json,updated_at) VALUES ('support',1,NULL,UTC_TIMESTAMP());
INSERT IGNORE INTO app_settings (setting_key,setting_value,is_public,updated_at) VALUES
 ('app_timezone','UTC',0,UTC_TIMESTAMP()),
 ('maintenance_enabled','0',0,UTC_TIMESTAMP()),
 ('maintenance_message','Open LMS is temporarily unavailable for maintenance. Please try again later.',0,UTC_TIMESTAMP()),
 ('smtp_enabled','0',0,UTC_TIMESTAMP()),
 ('smtp_host','',0,UTC_TIMESTAMP()),
 ('smtp_port','587',0,UTC_TIMESTAMP()),
 ('smtp_encryption','tls',0,UTC_TIMESTAMP()),
 ('smtp_username','',0,UTC_TIMESTAMP()),
 ('smtp_from_email','',0,UTC_TIMESTAMP()),
 ('smtp_from_name','Open LMS',0,UTC_TIMESTAMP()),
 ('smtp_password_secret','',0,UTC_TIMESTAMP()),
 ('mail_login_alerts_enabled','0',0,UTC_TIMESTAMP()),
 ('mail_announcements_enabled','0',0,UTC_TIMESTAMP()),
 ('payment_currency','INR',0,UTC_TIMESTAMP()),
 ('cash_payments_enabled','1',0,UTC_TIMESTAMP()),
 ('cash_payment_instructions','Pay at the institution counter and keep your receipt.',0,UTC_TIMESTAMP()),
 ('razorpay_enabled','0',0,UTC_TIMESTAMP()),
 ('razorpay_key_id','',0,UTC_TIMESTAMP()),
 ('razorpay_key_secret','',0,UTC_TIMESTAMP()),
 ('razorpay_webhook_secret','',0,UTC_TIMESTAMP()),
 ('stripe_enabled','0',0,UTC_TIMESTAMP()),
 ('stripe_secret_key','',0,UTC_TIMESTAMP()),
 ('stripe_webhook_secret','',0,UTC_TIMESTAMP());
