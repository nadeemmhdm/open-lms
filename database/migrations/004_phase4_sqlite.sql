ALTER TABLE exam_attempts ADD COLUMN attempt_no INTEGER NOT NULL DEFAULT 1;
ALTER TABLE exam_attempts ADD COLUMN last_activity_at TEXT;
ALTER TABLE exam_attempts ADD COLUMN submitted_reason TEXT;
ALTER TABLE exam_attempts ADD COLUMN ip_address TEXT;
ALTER TABLE exam_attempts ADD COLUMN user_agent TEXT;
ALTER TABLE exam_attempts ADD COLUMN grading_status TEXT NOT NULL DEFAULT 'pending';
ALTER TABLE exam_attempts ADD COLUMN results_published_at TEXT;
ALTER TABLE quizzes ADD COLUMN require_fullscreen INTEGER NOT NULL DEFAULT 0;
ALTER TABLE quizzes ADD COLUMN restrict_copy INTEGER NOT NULL DEFAULT 0;
ALTER TABLE quizzes ADD COLUMN log_focus INTEGER NOT NULL DEFAULT 1;
CREATE UNIQUE INDEX IF NOT EXISTS uq_exam_attempt_number ON exam_attempts(quiz_id,user_id,attempt_no);
CREATE INDEX IF NOT EXISTS idx_exam_attempt_status ON exam_attempts(status,expires_at);

ALTER TABLE exam_answers ADD COLUMN selected_options_json TEXT;
ALTER TABLE exam_answers ADD COLUMN is_correct INTEGER;
ALTER TABLE exam_answers ADD COLUMN feedback TEXT;
ALTER TABLE exam_answers ADD COLUMN graded_by INTEGER;
ALTER TABLE exam_answers ADD COLUMN graded_at TEXT;

CREATE TABLE IF NOT EXISTS attempt_questions (
  attempt_id INTEGER NOT NULL,
  question_id INTEGER NOT NULL,
  sort_order INTEGER NOT NULL,
  option_order_json TEXT,
  max_marks REAL NOT NULL DEFAULT 1,
  PRIMARY KEY(attempt_id,question_id),
  FOREIGN KEY(attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE,
  FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_attempt_questions_order ON attempt_questions(attempt_id,sort_order);
