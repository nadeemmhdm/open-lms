CREATE TABLE IF NOT EXISTS class_members (
  class_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL,
  PRIMARY KEY(class_id,user_id),
  CONSTRAINT fk_class_members_class FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_class_members_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_class_members_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_subjects (
  class_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL,
  PRIMARY KEY(class_id,subject_id),
  CONSTRAINT fk_class_subjects_class FOREIGN KEY(class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_class_subjects_subject FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  INDEX idx_class_subjects_subject(subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO class_members (class_id,user_id,assigned_at)
SELECT DISTINCT b.class_id,bm.user_id,COALESCE(bm.assigned_at,UTC_TIMESTAMP())
FROM batch_members bm JOIN batches b ON b.id=bm.batch_id
WHERE b.class_id IS NOT NULL;

INSERT IGNORE INTO class_subjects (class_id,subject_id,assigned_at)
SELECT DISTINCT b.class_id,c.subject_id,COALESCE(cb.assigned_at,UTC_TIMESTAMP())
FROM course_batches cb JOIN batches b ON b.id=cb.batch_id JOIN courses c ON c.id=cb.course_id
WHERE b.class_id IS NOT NULL;

ALTER TABLE doubt_replies ADD COLUMN updated_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS doubt_reply_reactions (
  reply_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  reaction ENUM('like','dislike') NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY(reply_id,user_id),
  CONSTRAINT fk_doubt_reactions_reply FOREIGN KEY(reply_id) REFERENCES doubt_replies(id) ON DELETE CASCADE,
  CONSTRAINT fk_doubt_reactions_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_doubt_reactions_reply(reply_id,reaction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE project_submissions ADD COLUMN repository_url VARCHAR(1000) NULL;
