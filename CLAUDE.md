# LeanPM: working notes

- Same stack and conventions as Rare Flavours / Wear Human: plain PHP, no Composer, no build step, MariaDB in production, SQLite locally. Keep it that way.
- **Permissions are checked on the server, always.** Every controller enters an org through `Access::org()`, `Access::task()` or `Access::orgById()` and then calls `Access::require()` / `Access::requireTask()`. A new endpoint without one of those is a bug. Hiding a button is never the guard.
- **Owners** (`users.is_owner`) manage organizations and other owners from `/admin` (`AdminController`, guarded by `Access::owner()`). They get **no** access inside an org without a membership: `Access::enter()` treats them like anyone else. Add people to an org with `Members::add()`.
- New permission? Add it to `Perms::DEFS` and every preset in `Perms::PRESETS`; the member form and clamp logic pick it up automatically. Update PRD §5.1a.
- Status changes go through `Tasks::setStatus()` so `completed_at` and the activity log stay right (reports depend on `completed_at`).
- Custom fields: definitions in `project_fields`, values in `task_field_values` (text). Validate with `Fields::clean()`; templates in `Fields::TEMPLATES` sit beside the stage templates in `Tasks::TEMPLATES` (same keys).
- Outgoing email goes through `EmailQueue::push()` (never send inline). Locally, without `mail.host`, `Mailer` writes to `storage/logs/mail.log`.
- Every org has a hidden **Inbox** project (`projects.is_inbox = 1`, created by `Access::inboxId()`): not in `Access::projects()`, but visible to all members. Create tasks with `Tasks::create()`; move them with `Tasks::moveToProject()`.
- Meeting notes: `notes_docs` / `notes_entries`; task chips are `<span class="task-ref" data-task="ID">title</span>` inside entry HTML (kept by `RichText::clean()`), hydrated live by `public/assets/notes.js`. `peek.js` is the shared quick-view panel.
- A permission added later falls back to the member's preset (`Perms::decode($json, $preset)`), so existing members don't silently lose access.
- Log user-visible task changes with `Activity::log()` and add a sentence in `Activity::describe()`.
- Descriptions go through `RichText::clean()`; comments are plain text rendered with `RichText::comment()`. Never echo user HTML anywhere else.
- Files live in `storage/uploads/{org}/{task}/`, outside the web root, served only by `FileController`.
- Schema changes: add `database/migrations/NNN_name.sql` (MariaDB syntax) **and** fold it into `database/schema.sql`.
- CSS: generic page classes (`.card`, `.empty-state`) and board classes (`.tcard`, `.column`) are separate; don't reuse names across them.
- Local: `php bin/install.php --fresh --seed --owner-email=…` then `php -S localhost:8090 -t public public/router.php`. Test logins are in `storage/local-admin.txt` / `storage/local-test-member.txt` (git-ignored).
