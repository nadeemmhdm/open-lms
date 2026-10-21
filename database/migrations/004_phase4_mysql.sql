ALTER TABLE exam_attempts ADD COLUMN attempt_no INT NOT NULL DEFAULT 1, ADD COLUMN last_activity_at DATETIME NULL, ADD COLUMN submitted_reason VARCHAR(60) NULL, ADD COLUMN ip_address VARCHAR(64) NULL, ADD COLUMN user_agent VARCHAR(255) NULL, ADD COLUMN grading_status VARCHAR(30) NOT NULL DEFAULT 'pending', ADD COLUMN results_published_at DATETIME NULL;
ALTER TABLE quizzes ADD COLUMN require_fullscreen TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN restrict_copy TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN log_focus TINYINT(1) NOT NULL DEFAULT 1;
CREATE UNIQUE INDEX uq_exam_attempt_number ON exam_attempts(quiz_id,user_id,attempt_no);
CREATE INDEX idx_exam_attempt_status ON exam_attempts(status,expires_at);
ALTER TABLE exam_answers ADD COLUMN selected_options_json TEXT NULL, ADD COLUMN is_correct TINYINT(1) NULL, ADD COLUMN feedback TEXT NULL, ADD COLUMN graded_by BIGINT UNSIGNED NULL, ADD COLUMN graded_at DATETIME NULL;
CREATE TABLE IF NOT EXISTS attempt_questions (
  attempt_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL,
  option_order_json TEXT NULL,
  max_marks DECIMAL(10,2) NOT NULL DEFAULT 1,
  PRIMARY KEY(attempt_id,question_id),
  CONSTRAINT fk_attempt_questions_attempt FOREIGN KEY(attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE,
  CONSTRAINT fk_attempt_questions_question FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE,
  INDEX idx_attempt_questions_order(attempt_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
