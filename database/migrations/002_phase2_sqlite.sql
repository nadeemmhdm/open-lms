CREATE TABLE IF NOT EXISTS batch_members (
  batch_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  assigned_at TEXT NOT NULL,
  PRIMARY KEY (batch_id, user_id),
  FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_batch_members_user ON batch_members(user_id);

CREATE TABLE IF NOT EXISTS course_batches (
  course_id INTEGER NOT NULL,
  batch_id INTEGER NOT NULL,
  assigned_at TEXT NOT NULL,
  PRIMARY KEY (course_id, batch_id),
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_course_batches_batch ON course_batches(batch_id);

CREATE TABLE IF NOT EXISTS lesson_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lesson_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  url TEXT NOT NULL,
  link_type TEXT NOT NULL DEFAULT 'link',
  created_at TEXT NOT NULL,
  FOREIGN KEY(lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_lesson_links_lesson ON lesson_links(lesson_id);

CREATE TABLE IF NOT EXISTS lesson_prerequisites (
  lesson_id INTEGER NOT NULL,
  prerequisite_lesson_id INTEGER NOT NULL,
  PRIMARY KEY (lesson_id, prerequisite_lesson_id),
  FOREIGN KEY(lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  FOREIGN KEY(prerequisite_lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'administrator' AND p.name IN ('users.manage','settings.manage','features.manage','academics.manage','courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view','audit.view');

INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'teacher' AND p.name IN ('courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view');

INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'hod' AND p.name IN ('academics.manage','courses.manage','lessons.manage','attendance.manage','questions.manage','exams.manage','projects.manage','reports.view');

INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'examiner' AND p.name IN ('questions.manage','exams.manage','reports.view');
