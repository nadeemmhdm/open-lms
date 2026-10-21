CREATE TABLE IF NOT EXISTS batch_members (
  batch_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL,
  PRIMARY KEY (batch_id, user_id),
  CONSTRAINT fk_batch_members_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_batch_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_batch_members_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_batches (
  course_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL,
  PRIMARY KEY (course_id, batch_id),
  CONSTRAINT fk_course_batches_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_course_batches_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
  INDEX idx_course_batches_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lesson_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  url VARCHAR(1200) NOT NULL,
  link_type ENUM('link','video') NOT NULL DEFAULT 'link',
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_lesson_links_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  INDEX idx_lesson_links_lesson (lesson_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_prerequisites (
  lesson_id BIGINT UNSIGNED NOT NULL,
  prerequisite_lesson_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (lesson_id, prerequisite_lesson_id),
  CONSTRAINT fk_lesson_prereq_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_lesson_prereq_required FOREIGN KEY (prerequisite_lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'administrator' AND p.name IN ('users.manage','settings.manage','features.manage','academics.manage','courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view','audit.view');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'teacher' AND p.name IN ('courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'hod' AND p.name IN ('academics.manage','courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'examiner' AND p.name IN ('questions.manage','exams.manage','reports.view');
