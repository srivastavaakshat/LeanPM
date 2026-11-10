# LeanPM

A small, self-hosted project manager for the design team: Kanban board + list view, tasks with assignees, dates, priority, subtasks, comments and files, per-member permissions, multiple organizations, and CSV reports. See [PRD.md](PRD.md).

Plain PHP 8.1+ and MariaDB/MySQL (SQLite locally), server-rendered pages plus a small JSON API for the board, vanilla JS, no build step, no Composer. Deployed to Hostinger from GitHub, the same way as Rare Flavours and Wear Human. Live at **https://leanpm.houseofjugaad.in** (see [DEPLOY.md](DEPLOY.md)).

## Run locally

```bash
php bin/install.php --fresh --seed --owner-email=you@example.com --owner-name="Your Name"
php -S localhost:8090 -t public public/router.php
```

Open http://localhost:8090. The Owner password is printed once and saved to `storage/local-admin.txt` (git-ignored). `--seed` adds a demo **Sportskeeda** organization with sample members and the tasks from the old ClickUp board.

## What's where

```
public/              front controller, .htaccess, assets (app.css, board.js, ui.js)
app/Core             Router (auth/api middleware), Database (PDO, prepared statements), Auth, Csrf, Session, View
app/Services         Access (org/project/task access + permission checks), Perms (permission list + presets),
                     Tasks (board data, status rules), Activity (history), RichText (description sanitizer), Uploads
app/Controllers      Auth, Account, Org, Project, Member, TaskApi, CommentApi, File, Export
app/Views            layouts (app, plain), pages per area
database/schema.sql  every table (MariaDB; translated to SQLite automatically)
database/migrations  future schema changes (run with bin/migrate.php)
bin/install.php      creates tables, the Owner, optional demo data
storage/uploads      task files, outside the web root (git-ignored)
```

## How permissions work

- The **Owner** (created by the installer) has every permission everywhere and is the only one who can create organizations.
- Every other member has a **membership** per organization holding their own ticked permissions (JSON). Presets (Admin, Lead, Designer, Viewer) only fill the boxes in.
- Task permissions are scoped: **No / Own tasks / All tasks**. "Own" = tasks they're assigned to or created.
- Project access is "all projects" or a chosen list.
- Every controller goes through `App\Services\Access`; the server always re-checks, hiding a button is never the only guard. Nobody can grant more than they hold (`Perms::clamp`).

## Data rules

- Moving a task into a **Done**-type status sets `completed_at`; moving it out clears it. Reports use this date.
- Subtasks are one level deep. A top-level task without subtasks can be linked under another task.
- Deleting a task moves it (and its subtasks) to **Trash**; Owner/“delete all tasks” members restore it.
- Members are deactivated, never deleted, so history and reports stay complete.
