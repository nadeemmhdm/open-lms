ALTER TABLE lessons ADD COLUMN content_format TEXT NOT NULL DEFAULT 'rich';
ALTER TABLE announcements ADD COLUMN image_url TEXT;
ALTER TABLE announcements ADD COLUMN video_url TEXT;

CREATE TABLE IF NOT EXISTS announcement_media (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  announcement_id INTEGER NOT NULL,
  original_name TEXT NOT NULL,
  stored_name TEXT NOT NULL,
  mime_type TEXT NOT NULL,
  size_bytes INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY(announcement_id) REFERENCES announcements(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_announcement_media_announcement ON announcement_media(announcement_id);
