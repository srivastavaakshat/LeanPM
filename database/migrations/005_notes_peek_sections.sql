-- Meeting notes: which sections the task quick view shows for this doc (JSON list; NULL = all).
ALTER TABLE notes_docs ADD COLUMN peek_sections TEXT NULL;
