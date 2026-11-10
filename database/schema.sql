-- LeanPM schema (MariaDB / MySQL). bin/install.php translates it to SQLite for local development.
-- Later changes go in database/migrations/ (run with bin/migrate.php); fold them in here too.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#6b7280',
    is_owner TINYINT(1) NOT NULL DEFAULT 0,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    notify_mentions TINYINT(1) NOT NULL DEFAULT 1,
    google_sub VARCHAR(64) NULL,
    UNIQUE KEY users_email (email),
    UNIQUE KEY users_google_sub (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organizations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY organizations_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memberships (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    preset VARCHAR(20) NOT NULL DEFAULT 'designer',
    permissions TEXT NOT NULL,
    all_projects TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    joined_at DATETIME NOT NULL,
    UNIQUE KEY memberships_org_user (org_id, user_id),
    KEY memberships_user (user_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    is_inbox TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY projects_org (org_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Project access for members who don't have "all projects".
CREATE TABLE IF NOT EXISTS membership_projects (
    membership_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (membership_id, project_id),
    FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Board columns. type: open | active | done (done sets tasks.completed_at).
CREATE TABLE IF NOT EXISTS statuses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#6b7280',
    type VARCHAR(10) NOT NULL DEFAULT 'active',
    position INT NOT NULL DEFAULT 0,
    KEY statuses_project (project_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- priority: urgent | high | normal | low | NULL. parent_id = subtask of (one level only).
CREATE TABLE IF NOT EXISTS tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL,
    status_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description MEDIUMTEXT NULL,
    priority VARCHAR(10) NULL,
    position INT NOT NULL DEFAULT 0,
    start_date DATE NULL,
    due_date DATE NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,
    KEY tasks_project_status (project_id, status_id),
    KEY tasks_parent (parent_id),
    KEY tasks_completed (completed_at),
    KEY tasks_created (created_at),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (status_id) REFERENCES statuses(id),
    FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom fields per project (type: url | text | number | date | select | checkbox; options = JSON list for select).
CREATE TABLE IF NOT EXISTS project_fields (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,
    type VARCHAR(12) NOT NULL DEFAULT 'text',
    options TEXT NULL,
    position INT NOT NULL DEFAULT 0,
    KEY project_fields_project (project_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_field_values (
    task_id INT UNSIGNED NOT NULL,
    field_id INT UNSIGNED NOT NULL,
    value TEXT NOT NULL,
    PRIMARY KEY (task_id, field_id),
    KEY task_field_values_field (field_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES project_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_assignees (
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (task_id, user_id),
    KEY task_assignees_user (user_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    edited_at DATETIME NULL,
    KEY comments_task (task_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files live in storage/uploads/{org}/{task}/{stored_name}, outside the web root.
CREATE TABLE IF NOT EXISTS attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    comment_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(80) NOT NULL,
    size INT UNSIGNED NOT NULL DEFAULT 0,
    mime VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
    created_at DATETIME NOT NULL,
    KEY attachments_task (task_id),
    KEY attachments_comment (comment_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task history. action: created, status, assignee_added, assignee_removed, priority, start_date,
-- due_date, title, mentioned, subtask_added, subtask_removed, file_added, deleted, restored.
CREATE TABLE IF NOT EXISTS activity (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    from_value VARCHAR(255) NULL,
    to_value VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    KEY activity_task (task_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outgoing email (e.g. @mention notifications). Sent right after the request, plus a cron fallback.
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

-- Meeting notes (PRD §5.12): a doc per meeting series, made of dated entries. visibility: private | org.
CREATE TABLE IF NOT EXISTS notes_docs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    visibility VARCHAR(10) NOT NULL DEFAULT 'private',
    default_project_id INT UNSIGNED NULL,
    peek_sections TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY notes_docs_org (org_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id),
    FOREIGN KEY (default_project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notes_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_id INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NOT NULL,
    meeting_at DATETIME NOT NULL,
    title VARCHAR(120) NULL,
    body MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY notes_entries_doc (doc_id, meeting_at),
    FOREIGN KEY (doc_id) REFERENCES notes_docs(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash VARCHAR(64) NOT NULL,
    email VARCHAR(190) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY login_attempts_ip (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
