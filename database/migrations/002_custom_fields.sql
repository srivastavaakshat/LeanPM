-- Custom fields per project (e.g. "Figma file" link) and their values on tasks.
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
