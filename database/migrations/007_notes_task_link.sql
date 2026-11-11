-- Meeting notes: a doc can be connected to one task. Tasks created in that doc become its subtasks.
-- NULL = not connected. (No foreign key: a deleted or missing task simply counts as not connected.)
ALTER TABLE notes_docs ADD COLUMN task_id INT UNSIGNED NULL;
CREATE INDEX notes_docs_task ON notes_docs (task_id);
