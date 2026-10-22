ALTER TABLE lesson_links
  MODIFY COLUMN link_type ENUM('link','video','image','pdf','document') NOT NULL DEFAULT 'link';
