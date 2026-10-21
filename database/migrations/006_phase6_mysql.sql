ALTER TABLE notifications ADD COLUMN type VARCHAR(50) NOT NULL DEFAULT 'general', ADD COLUMN link_url VARCHAR(500) NULL;
CREATE INDEX idx_notifications_user_read ON notifications(user_id,read_at,created_at);
CREATE TABLE IF NOT EXISTS announcements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, body TEXT NOT NULL, audience VARCHAR(30) NOT NULL DEFAULT 'all', course_id BIGINT UNSIGNED NULL, batch_id BIGINT UNSIGNED NULL, created_by BIGINT UNSIGNED NOT NULL, published_at DATETIME NULL, expires_at DATETIME NULL, created_at DATETIME NOT NULL,
 CONSTRAINT fk_announcement_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
 CONSTRAINT fk_announcement_batch FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE CASCADE,
 CONSTRAINT fk_announcement_creator FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS certificates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, course_id BIGINT UNSIGNED NOT NULL, certificate_no VARCHAR(100) NOT NULL UNIQUE, issued_by BIGINT UNSIGNED NOT NULL, issued_at DATETIME NOT NULL, verification_token VARCHAR(128) NOT NULL UNIQUE, revoked_at DATETIME NULL,
 CONSTRAINT fk_certificate_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_certificate_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
 CONSTRAINT fk_certificate_issuer FOREIGN KEY(issued_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS timetable_entries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, course_id BIGINT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NULL, weekday TINYINT NOT NULL, starts_at TIME NOT NULL, ends_at TIME NOT NULL, room_label VARCHAR(120) NULL, created_by BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_timetable_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
 CONSTRAINT fk_timetable_batch FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE SET NULL,
 CONSTRAINT fk_timetable_creator FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS live_classes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, course_id BIGINT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL, join_url VARCHAR(500) NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NULL, created_by BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_live_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
 CONSTRAINT fk_live_creator FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS parent_student_links (
 parent_user_id BIGINT UNSIGNED NOT NULL, student_user_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(parent_user_id,student_user_id),
 CONSTRAINT fk_parent_link_parent FOREIGN KEY(parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_parent_link_student FOREIGN KEY(student_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fee_invoices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL, amount DECIMAL(12,2) NOT NULL, due_on DATE NULL, status VARCHAR(30) NOT NULL DEFAULT 'unpaid', created_at DATETIME NOT NULL,
 CONSTRAINT fk_fee_invoice_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fee_payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, invoice_id BIGINT UNSIGNED NOT NULL, amount DECIMAL(12,2) NOT NULL, reference VARCHAR(190) NULL, paid_at DATETIME NOT NULL, recorded_by BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_fee_payment_invoice FOREIGN KEY(invoice_id) REFERENCES fee_invoices(id) ON DELETE CASCADE,
 CONSTRAINT fk_fee_payment_recorder FOREIGN KEY(recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS password_reset_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, token_hash VARCHAR(255) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL,
 CONSTRAINT fk_password_token_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS backup_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, file_name VARCHAR(255) NOT NULL, driver VARCHAR(20) NOT NULL, size_bytes BIGINT UNSIGNED NOT NULL, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, restored_at DATETIME NULL,
 CONSTRAINT fk_backup_creator FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS course_completions (
 user_id BIGINT UNSIGNED NOT NULL, course_id BIGINT UNSIGNED NOT NULL, completed_at DATETIME NOT NULL, PRIMARY KEY(user_id,course_id),
 CONSTRAINT fk_completion_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_completion_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT IGNORE INTO feature_settings (feature_key,enabled,config_json,updated_at) VALUES
 ('announcements',1,NULL,UTC_TIMESTAMP()),
 ('search',1,NULL,UTC_TIMESTAMP()),
 ('backup_restore',1,NULL,UTC_TIMESTAMP());
