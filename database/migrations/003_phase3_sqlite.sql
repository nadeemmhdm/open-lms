ALTER TABLE attendance_sessions ADD COLUMN academic_year_id INTEGER;
ALTER TABLE attendance_sessions ADD COLUMN semester_id INTEGER;
ALTER TABLE attendance_sessions ADD COLUMN locked_at TEXT;
CREATE INDEX IF NOT EXISTS idx_attendance_session_date ON attendance_sessions(session_date);
CREATE INDEX IF NOT EXISTS idx_attendance_record_student ON attendance_records(student_id);

ALTER TABLE questions ADD COLUMN explanation TEXT;
ALTER TABLE questions ADD COLUMN metadata_json TEXT;
ALTER TABLE quizzes ADD COLUMN assessment_type TEXT NOT NULL DEFAULT 'quiz';
ALTER TABLE quizzes ADD COLUMN negative_marking REAL NOT NULL DEFAULT 0;
ALTER TABLE quizzes ADD COLUMN randomize_questions INTEGER NOT NULL DEFAULT 0;
ALTER TABLE quizzes ADD COLUMN shuffle_options INTEGER NOT NULL DEFAULT 0;
ALTER TABLE quizzes ADD COLUMN review_policy TEXT NOT NULL DEFAULT 'after_submit';
ALTER TABLE quizzes ADD COLUMN result_publication TEXT NOT NULL DEFAULT 'immediate';
ALTER TABLE quizzes ADD COLUMN instructions TEXT;
ALTER TABLE quizzes ADD COLUMN updated_at TEXT;
CREATE INDEX IF NOT EXISTS idx_quizzes_course_status ON quizzes(course_id,status);
CREATE INDEX IF NOT EXISTS idx_questions_bank_type ON questions(question_bank_id,type);
CREATE INDEX IF NOT EXISTS idx_grades_course_user ON grades(course_id,user_id);
