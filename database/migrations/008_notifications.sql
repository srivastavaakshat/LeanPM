-- In-app notifications: @mentions, comments on your tasks, assignments, tasks completed.
CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NULL,
    task_id INT UNSIGNED NOT NULL,
    comment_id INT UNSIGNED NULL,
    type VARCHAR(20) NOT NULL,
    excerpt VARCHAR(300) NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY notifications_user (user_id, org_id, created_at),
    KEY notifications_task (task_id, user_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
