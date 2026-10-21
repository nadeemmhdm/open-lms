ALTER TABLE attendance_sessions ADD COLUMN academic_year_id BIGINT UNSIGNED NULL, ADD COLUMN semester_id BIGINT UNSIGNED NULL, ADD COLUMN locked_at DATETIME NULL;
CREATE INDEX idx_attendance_session_date ON attendance_sessions(session_date);
CREATE INDEX idx_attendance_record_student ON attendance_records(student_id);

ALTER TABLE questions ADD COLUMN explanation TEXT NULL, ADD COLUMN metadata_json TEXT NULL;
ALTER TABLE quizzes ADD COLUMN assessment_type ENUM('quiz','exam') NOT NULL DEFAULT 'quiz', ADD COLUMN negative_marking DECIMAL(8,2) NOT NULL DEFAULT 0, ADD COLUMN randomize_questions TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN shuffle_options TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN review_policy VARCHAR(40) NOT NULL DEFAULT 'after_submit', ADD COLUMN result_publication VARCHAR(40) NOT NULL DEFAULT 'immediate', ADD COLUMN instructions TEXT NULL, ADD COLUMN updated_at DATETIME NULL;
CREATE INDEX idx_quizzes_course_status ON quizzes(course_id,status);
CREATE INDEX idx_questions_bank_type ON questions(question_bank_id,type);
CREATE INDEX idx_grades_course_user ON grades(course_id,user_id);
