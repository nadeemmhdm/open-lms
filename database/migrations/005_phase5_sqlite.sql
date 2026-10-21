ALTER TABLE projects ADD COLUMN allowed_extensions TEXT NOT NULL DEFAULT 'pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,zip';
ALTER TABLE projects ADD COLUMN allow_multiple_files INTEGER NOT NULL DEFAULT 1;
ALTER TABLE projects ADD COLUMN resubmission_allowed INTEGER NOT NULL DEFAULT 1;
ALTER TABLE projects ADD COLUMN late_policy TEXT NOT NULL DEFAULT 'reject';
ALTER TABLE projects ADD COLUMN max_marks REAL;
ALTER TABLE projects ADD COLUMN rubric_json TEXT;
ALTER TABLE projects ADD COLUMN updated_at TEXT;

CREATE TABLE IF NOT EXISTS project_groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE(project_id,name),
  FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS project_group_members (
  group_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  PRIMARY KEY(group_id,user_id),
  FOREIGN KEY(group_id) REFERENCES project_groups(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
ALTER TABLE project_submissions ADD COLUMN group_id INTEGER;
ALTER TABLE project_submissions ADD COLUMN is_late INTEGER NOT NULL DEFAULT 0;
ALTER TABLE project_submissions ADD COLUMN graded_by INTEGER;
ALTER TABLE project_submissions ADD COLUMN graded_at TEXT;
ALTER TABLE project_submissions ADD COLUMN rubric_scores_json TEXT;
CREATE INDEX IF NOT EXISTS idx_project_submissions_project ON project_submissions(project_id,status);
CREATE TABLE IF NOT EXISTS project_submission_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 submission_id INTEGER NOT NULL,
 actor_user_id INTEGER,
 event_type TEXT NOT NULL,
 details TEXT,
 created_at TEXT NOT NULL,
 FOREIGN KEY(submission_id) REFERENCES project_submissions(id) ON DELETE CASCADE,
 FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);
