# LeanPM: working notes

- Same stack and conventions as Rare Flavours / Wear Human: plain PHP, no Composer, no build step, MariaDB in production, SQLite locally. Keep it that way.
- **Permissions are checked on the server, always.** Every controller enters an org through `Access::org()`, `Access::task()` or `Access::orgById()` and then calls `Access::require()` / `Access::requireTask()`. A new endpoint without one of those is a bug. Hiding a button is never the guard.
- New permission? Add it to `Perms::DEFS` and every preset in `Perms::PRESETS`; the member form and clamp logic pick it up automatically. Update PRD §5.1a.
- Status changes go through `Tasks::setStatus()` so `completed_at` and the activity log stay right (reports depend on `completed_at`).
- Log user-visible task changes with `Activity::log()` and add a sentence in `Activity::describe()`.
- Descriptions go through `RichText::clean()`; comments are plain text rendered with `RichText::comment()`. Never echo user HTML anywhere else.
- Files live in `storage/uploads/{org}/{task}/`, outside the web root, served only by `FileController`.
- Schema changes: add `database/migrations/NNN_name.sql` (MariaDB syntax) **and** fold it into `database/schema.sql`.
- CSS: generic page classes (`.card`, `.empty-state`) and board classes (`.tcard`, `.column`) are separate; don't reuse names across them.
- Local: `php bin/install.php --fresh --seed --owner-email=…` then `php -S localhost:8090 -t public public/router.php`. Test logins are in `storage/local-admin.txt` / `storage/local-test-member.txt` (git-ignored).
