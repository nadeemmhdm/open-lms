CREATE TABLE IF NOT EXISTS registration_invites (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id INTEGER NOT NULL UNIQUE,
  token_hash TEXT NOT NULL,
  role_name TEXT NOT NULL,
  max_uses INTEGER NOT NULL,
  used_count INTEGER NOT NULL DEFAULT 0,
  expires_at TEXT NOT NULL,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  last_used_at TEXT,
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(role_name) REFERENCES roles(name) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_registration_invites_expiry ON registration_invites(expires_at);
CREATE INDEX IF NOT EXISTS idx_registration_invites_creator ON registration_invites(created_by,created_at);

CREATE TABLE IF NOT EXISTS registration_invite_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id INTEGER NOT NULL UNIQUE,
  token_hash TEXT NOT NULL,
  role_name TEXT NOT NULL,
  max_uses INTEGER NOT NULL,
  used_count INTEGER NOT NULL,
  final_state TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  created_by INTEGER,
  created_at TEXT NOT NULL,
  archived_at TEXT NOT NULL,
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_registration_invite_history_state ON registration_invite_history(final_state,archived_at);
