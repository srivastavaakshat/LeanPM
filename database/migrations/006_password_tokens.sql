-- One-time links to set or reset a password (invite: 7 days, admin reset: 3 days, "forgot password": 1 hour).
-- Only a hash of the token is stored.
CREATE TABLE IF NOT EXISTS password_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    purpose VARCHAR(10) NOT NULL DEFAULT 'reset',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY password_tokens_hash (token_hash),
    KEY password_tokens_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
