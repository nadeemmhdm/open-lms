CREATE TABLE IF NOT EXISTS registration_invite_contexts (
  public_id INTEGER PRIMARY KEY,
  academic_year_id INTEGER NOT NULL,
  batch_id INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY(public_id) REFERENCES registration_invites(public_id) ON DELETE CASCADE,
  FOREIGN KEY(academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
  FOREIGN KEY(batch_id) REFERENCES batches(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_invite_context_year_batch ON registration_invite_contexts(academic_year_id,batch_id);

CREATE TABLE IF NOT EXISTS registration_invite_courses (
  public_id INTEGER NOT NULL,
  course_id INTEGER NOT NULL,
  PRIMARY KEY(public_id,course_id),
  FOREIGN KEY(public_id) REFERENCES registration_invites(public_id) ON DELETE CASCADE,
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_invite_course_course ON registration_invite_courses(course_id);

CREATE TABLE IF NOT EXISTS doubts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id INTEGER NOT NULL UNIQUE,
  course_id INTEGER NOT NULL,
  student_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','resolved')),
  resolved_by INTEGER,
  resolved_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(resolved_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_doubts_course_status ON doubts(course_id,status,created_at);
CREATE INDEX IF NOT EXISTS idx_doubts_student ON doubts(student_id,created_at);

CREATE TABLE IF NOT EXISTS doubt_replies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  doubt_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  body TEXT NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY(doubt_id) REFERENCES doubts(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_doubt_replies_doubt ON doubt_replies(doubt_id,created_at);
