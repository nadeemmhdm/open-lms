ALTER TABLE lessons ADD COLUMN content_format VARCHAR(20) NOT NULL DEFAULT 'rich';
ALTER TABLE lesson_links MODIFY COLUMN link_type ENUM('link','video','image') NOT NULL DEFAULT 'link';

ALTER TABLE announcements ADD COLUMN image_url VARCHAR(1200) NULL;
ALTER TABLE announcements ADD COLUMN video_url VARCHAR(1200) NULL;

CREATE TABLE IF NOT EXISTS announcement_media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  announcement_id BIGINT UNSIGNED NOT NULL,
  original_name VARCHAR(180) NOT NULL,
  stored_name VARCHAR(500) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_announcement_media_announcement FOREIGN KEY(announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
  INDEX idx_announcement_media_announcement(announcement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
