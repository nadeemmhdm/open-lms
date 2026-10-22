CREATE TABLE IF NOT EXISTS registration_invites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id INT UNSIGNED NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  role_name VARCHAR(80) NOT NULL,
  max_uses INT UNSIGNED NOT NULL,
  used_count INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  CONSTRAINT fk_registration_invite_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_registration_invite_role FOREIGN KEY(role_name) REFERENCES roles(name) ON DELETE RESTRICT,
  INDEX idx_registration_invites_expiry(expires_at),
  INDEX idx_registration_invites_creator(created_by,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registration_invite_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id INT UNSIGNED NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  role_name VARCHAR(80) NOT NULL,
  max_uses INT UNSIGNED NOT NULL,
  used_count INT UNSIGNED NOT NULL,
  final_state VARCHAR(20) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  archived_at DATETIME NOT NULL,
  CONSTRAINT fk_registration_invite_history_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_registration_invite_history_state(final_state,archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
