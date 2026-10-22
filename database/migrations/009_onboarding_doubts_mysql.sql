CREATE TABLE IF NOT EXISTS registration_invite_contexts (
  public_id INT UNSIGNED NOT NULL PRIMARY KEY,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_invite_context_invite FOREIGN KEY(public_id) REFERENCES registration_invites(public_id) ON DELETE CASCADE,
  CONSTRAINT fk_invite_context_year FOREIGN KEY(academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
  CONSTRAINT fk_invite_context_batch FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE RESTRICT,
  INDEX idx_invite_context_year_batch(academic_year_id,batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registration_invite_courses (
  public_id INT UNSIGNED NOT NULL,
  course_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(public_id,course_id),
  CONSTRAINT fk_invite_course_invite FOREIGN KEY(public_id) REFERENCES registration_invites(public_id) ON DELETE CASCADE,
  CONSTRAINT fk_invite_course_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
  INDEX idx_invite_course_course(course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doubts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id INT UNSIGNED NOT NULL UNIQUE,
  course_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_doubts_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_doubts_student FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_doubts_resolver FOREIGN KEY(resolved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_doubts_course_status(course_id,status,created_at),
  INDEX idx_doubts_student(student_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doubt_replies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doubt_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_doubt_replies_doubt FOREIGN KEY(doubt_id) REFERENCES doubts(id) ON DELETE CASCADE,
  CONSTRAINT fk_doubt_replies_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_doubt_replies_doubt(doubt_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
