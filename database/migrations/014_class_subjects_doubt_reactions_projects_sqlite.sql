CREATE TABLE IF NOT EXISTS class_members (
  class_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  assigned_at TEXT NOT NULL,
  PRIMARY KEY(class_id,user_id),
  FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_class_members_user ON class_members(user_id);

CREATE TABLE IF NOT EXISTS class_subjects (
  class_id INTEGER NOT NULL,
  subject_id INTEGER NOT NULL,
  assigned_at TEXT NOT NULL,
  PRIMARY KEY(class_id,subject_id),
  FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_class_subjects_subject ON class_subjects(subject_id);

INSERT OR IGNORE INTO class_members (class_id,user_id,assigned_at)
SELECT DISTINCT b.class_id,bm.user_id,COALESCE(bm.assigned_at,datetime('now'))
FROM batch_members bm JOIN batches b ON b.id=bm.batch_id
WHERE b.class_id IS NOT NULL;

INSERT OR IGNORE INTO class_subjects (class_id,subject_id,assigned_at)
SELECT DISTINCT b.class_id,c.subject_id,COALESCE(cb.assigned_at,datetime('now'))
FROM course_batches cb JOIN batches b ON b.id=cb.batch_id JOIN courses c ON c.id=cb.course_id
WHERE b.class_id IS NOT NULL;

ALTER TABLE doubt_replies ADD COLUMN updated_at TEXT NULL;

CREATE TABLE IF NOT EXISTS doubt_reply_reactions (
  reply_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  reaction TEXT NOT NULL CHECK(reaction IN ('like','dislike')),
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  PRIMARY KEY(reply_id,user_id),
  FOREIGN KEY(reply_id) REFERENCES doubt_replies(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_doubt_reactions_reply ON doubt_reply_reactions(reply_id,reaction);

ALTER TABLE project_submissions ADD COLUMN repository_url TEXT NULL;
