-- @mentions send email: a queue the app (and a cron job) works through, and a per-person opt-out.
ALTER TABLE users ADD COLUMN notify_mentions TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS email_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(190) NOT NULL,
    to_name VARCHAR(120) NOT NULL DEFAULT '',
    subject VARCHAR(255) NOT NULL,
    body_text TEXT NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    KEY email_queue_pending (sent_at, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
