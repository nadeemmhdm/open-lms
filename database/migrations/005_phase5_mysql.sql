ALTER TABLE projects ADD COLUMN allowed_extensions VARCHAR(255) NOT NULL DEFAULT 'pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,zip', ADD COLUMN allow_multiple_files TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN resubmission_allowed TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN late_policy VARCHAR(30) NOT NULL DEFAULT 'reject', ADD COLUMN max_marks DECIMAL(10,2) NULL, ADD COLUMN rubric_json TEXT NULL, ADD COLUMN updated_at DATETIME NULL;
CREATE TABLE IF NOT EXISTS project_groups (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(160) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_project_group_name(project_id,name),
 CONSTRAINT fk_project_group_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_project_group_creator FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS project_group_members (
 group_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(group_id,user_id),
 CONSTRAINT fk_project_group_member_group FOREIGN KEY(group_id) REFERENCES project_groups(id) ON DELETE CASCADE,
 CONSTRAINT fk_project_group_member_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE project_submissions ADD COLUMN group_id BIGINT UNSIGNED NULL, ADD COLUMN is_late TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN graded_by BIGINT UNSIGNED NULL, ADD COLUMN graded_at DATETIME NULL, ADD COLUMN rubric_scores_json TEXT NULL;
CREATE INDEX idx_project_submissions_project ON project_submissions(project_id,status);
CREATE TABLE IF NOT EXISTS project_submission_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 submission_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NULL,
 event_type VARCHAR(80) NOT NULL,
 details TEXT NULL,
 created_at DATETIME NOT NULL,
 CONSTRAINT fk_project_event_submission FOREIGN KEY(submission_id) REFERENCES project_submissions(id) ON DELETE CASCADE,
 CONSTRAINT fk_project_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
