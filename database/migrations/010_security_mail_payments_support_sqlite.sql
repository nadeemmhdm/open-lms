ALTER TABLE users ADD COLUMN first_login_at TEXT;
ALTER TABLE users ADD COLUMN first_login_ip TEXT;
ALTER TABLE users ADD COLUMN first_login_user_agent TEXT;
ALTER TABLE users ADD COLUMN last_login_ip TEXT;
ALTER TABLE users ADD COLUMN last_login_user_agent TEXT;

CREATE TABLE IF NOT EXISTS auth_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  remember_me INTEGER NOT NULL DEFAULT 0,
  ip_address TEXT,
  user_agent TEXT,
  device_label TEXT,
  created_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  revoked_at TEXT,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_auth_sessions_user_active ON auth_sessions(user_id,revoked_at,expires_at);
CREATE INDEX IF NOT EXISTS idx_auth_sessions_expiry ON auth_sessions(expires_at);

CREATE TABLE IF NOT EXISTS user_login_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  ip_address TEXT,
  user_agent TEXT,
  device_label TEXT,
  remember_me INTEGER NOT NULL DEFAULT 0,
  login_at TEXT NOT NULL,
  session_expires_at TEXT NOT NULL,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_login_history_user_time ON user_login_history(user_id,login_at);

ALTER TABLE fee_invoices ADD COLUMN currency TEXT NOT NULL DEFAULT 'INR';
ALTER TABLE fee_invoices ADD COLUMN notes TEXT;
ALTER TABLE fee_invoices ADD COLUMN updated_at TEXT;
ALTER TABLE fee_payments ADD COLUMN provider TEXT;
ALTER TABLE fee_payments ADD COLUMN provider_reference TEXT;

CREATE TABLE IF NOT EXISTS payment_transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  provider TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  amount REAL NOT NULL,
  currency TEXT NOT NULL DEFAULT 'INR',
  provider_order_id TEXT,
  provider_payment_id TEXT,
  reference TEXT,
  metadata_json TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  completed_at TEXT,
  FOREIGN KEY(invoice_id) REFERENCES fee_invoices(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE(provider,provider_order_id)
);
CREATE INDEX IF NOT EXISTS idx_payment_tx_invoice ON payment_transactions(invoice_id,status);
CREATE INDEX IF NOT EXISTS idx_payment_tx_user ON payment_transactions(user_id,created_at);

CREATE TABLE IF NOT EXISTS support_tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id INTEGER NOT NULL UNIQUE,
  user_id INTEGER NOT NULL,
  subject TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'general',
  priority TEXT NOT NULL DEFAULT 'normal',
  status TEXT NOT NULL DEFAULT 'open',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  resolved_at TEXT,
  resolved_by INTEGER,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(resolved_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_support_ticket_status ON support_tickets(status,updated_at);
CREATE INDEX IF NOT EXISTS idx_support_ticket_user ON support_tickets(user_id,updated_at);

CREATE TABLE IF NOT EXISTS support_replies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL,
  sender_user_id INTEGER NOT NULL,
  body TEXT NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY(ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_support_reply_ticket ON support_replies(ticket_id,created_at);

INSERT OR IGNORE INTO feature_settings (feature_key,enabled,config_json,updated_at) VALUES ('support',1,NULL,datetime('now'));
INSERT OR IGNORE INTO app_settings (setting_key,setting_value,is_public,updated_at) VALUES
 ('app_timezone','UTC',0,datetime('now')),
 ('maintenance_enabled','0',0,datetime('now')),
 ('maintenance_message','Open LMS is temporarily unavailable for maintenance. Please try again later.',0,datetime('now')),
 ('smtp_enabled','0',0,datetime('now')),
 ('smtp_host','',0,datetime('now')),
 ('smtp_port','587',0,datetime('now')),
 ('smtp_encryption','tls',0,datetime('now')),
 ('smtp_username','',0,datetime('now')),
 ('smtp_from_email','',0,datetime('now')),
 ('smtp_from_name','Open LMS',0,datetime('now')),
 ('smtp_password_secret','',0,datetime('now')),
 ('mail_login_alerts_enabled','0',0,datetime('now')),
 ('mail_announcements_enabled','0',0,datetime('now')),
 ('payment_currency','INR',0,datetime('now')),
 ('cash_payments_enabled','1',0,datetime('now')),
 ('cash_payment_instructions','Pay at the institution counter and keep your receipt.',0,datetime('now')),
 ('razorpay_enabled','0',0,datetime('now')),
 ('razorpay_key_id','',0,datetime('now')),
 ('razorpay_key_secret','',0,datetime('now')),
 ('razorpay_webhook_secret','',0,datetime('now')),
 ('stripe_enabled','0',0,datetime('now')),
 ('stripe_secret_key','',0,datetime('now')),
 ('stripe_webhook_secret','',0,datetime('now'));
