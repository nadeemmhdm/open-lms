ALTER TABLE notifications ADD COLUMN type TEXT NOT NULL DEFAULT 'general';
ALTER TABLE notifications ADD COLUMN link_url TEXT;
CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id,read_at,created_at);

CREATE TABLE IF NOT EXISTS announcements (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 title TEXT NOT NULL,
 body TEXT NOT NULL,
 audience TEXT NOT NULL DEFAULT 'all',
 course_id INTEGER,
 batch_id INTEGER,
 created_by INTEGER NOT NULL,
 published_at TEXT,
 expires_at TEXT,
 created_at TEXT NOT NULL,
 FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
 FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE CASCADE,
 FOREIGN KEY(created_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS certificates (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 course_id INTEGER NOT NULL,
 certificate_no TEXT NOT NULL UNIQUE,
 issued_by INTEGER NOT NULL,
 issued_at TEXT NOT NULL,
 verification_token TEXT NOT NULL UNIQUE,
 revoked_at TEXT,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
 FOREIGN KEY(issued_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS timetable_entries (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 course_id INTEGER NOT NULL,
 batch_id INTEGER,
 weekday INTEGER NOT NULL,
 starts_at TEXT NOT NULL,
 ends_at TEXT NOT NULL,
 room_label TEXT,
 created_by INTEGER NOT NULL,
 FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
 FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE SET NULL,
 FOREIGN KEY(created_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS live_classes (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 course_id INTEGER NOT NULL,
 title TEXT NOT NULL,
 join_url TEXT NOT NULL,
 starts_at TEXT NOT NULL,
 ends_at TEXT,
 created_by INTEGER NOT NULL,
 FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
 FOREIGN KEY(created_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS parent_student_links (
 parent_user_id INTEGER NOT NULL,
 student_user_id INTEGER NOT NULL,
 created_at TEXT NOT NULL,
 PRIMARY KEY(parent_user_id,student_user_id),
 FOREIGN KEY(parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(student_user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS fee_invoices (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 title TEXT NOT NULL,
 amount REAL NOT NULL,
 due_on TEXT,
 status TEXT NOT NULL DEFAULT 'unpaid',
 created_at TEXT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS fee_payments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 invoice_id INTEGER NOT NULL,
 amount REAL NOT NULL,
 reference TEXT,
 paid_at TEXT NOT NULL,
 recorded_by INTEGER NOT NULL,
 FOREIGN KEY(invoice_id) REFERENCES fee_invoices(id) ON DELETE CASCADE,
 FOREIGN KEY(recorded_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS password_reset_tokens (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 token_hash TEXT NOT NULL UNIQUE,
 expires_at TEXT NOT NULL,
 used_at TEXT,
 created_at TEXT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS backup_history (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 file_name TEXT NOT NULL,
 driver TEXT NOT NULL,
 size_bytes INTEGER NOT NULL,
 created_by INTEGER NOT NULL,
 created_at TEXT NOT NULL,
 restored_at TEXT,
 FOREIGN KEY(created_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS course_completions (
 user_id INTEGER NOT NULL,
 course_id INTEGER NOT NULL,
 completed_at TEXT NOT NULL,
 PRIMARY KEY(user_id,course_id),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
);


INSERT OR IGNORE INTO feature_settings (feature_key,enabled,config_json,updated_at) VALUES
 ('announcements',1,NULL,datetime('now')),
 ('search',1,NULL,datetime('now')),
 ('backup_restore',1,NULL,datetime('now'));
