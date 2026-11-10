-- Meeting notes: running docs (one per meeting series) made of dated entries, plus a per-org
-- Inbox project for tasks that aren't in a project yet.
ALTER TABLE projects ADD COLUMN is_inbox TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS notes_docs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    visibility VARCHAR(10) NOT NULL DEFAULT 'private',
    default_project_id INT UNSIGNED NULL,
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
