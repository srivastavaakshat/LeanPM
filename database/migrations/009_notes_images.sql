-- Meeting notes: images placed between the text of an entry. Files live in
-- storage/uploads/{org}/notes/{doc}/ and are served only to people who can open the doc.
CREATE TABLE IF NOT EXISTS notes_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    doc_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(80) NOT NULL,
    size INT UNSIGNED NOT NULL DEFAULT 0,
    mime VARCHAR(120) NOT NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY notes_images_doc (doc_id),
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (doc_id) REFERENCES notes_docs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
