# LeanPM — Product Requirements (v0.1)

**Owner:** Akshat Srivastava · **Date:** 15 Oct 2026 · **Status:** Draft

## 1. Problem

Design teams track work in ClickUp or Trello, but those tools are heavy, expensive per seat, and keep the data in someone else's system. Akshat wants a small, self-hosted tool for his design team, with only the features they actually use: a board, a list, tasks with owners and deadlines, comments, files, and an easy way to get the data out for reports.

## 2. Goals

1. Run the team's design work from a Kanban board and a list view.
2. Keep every task's history (comments, files, status changes) in one place so it can be picked up again later.
3. Export work to CSV, filtered by team member and date range, for reports such as "what did Ravi ship in the last 3 months?"
4. Support several organizations (e.g. Sportskeeda), each with its own data.
5. Run on the existing Hostinger account.

**Non-goals for v1:** Google Sheets sync, time tracking, automations, AI, docs/wiki, chat, mobile apps, integrations, guest/client access, billing.

## 3. Users

- **Owner** (Akshat): created by the installer. Has every permission in every organization and can't be locked out.
- **Members**: created by the Owner, or by anyone with the *Manage members* permission. What each member can do is set member by member with checkboxes (see 5.1a).

## 4. Structure

```
Organization (e.g. Sportskeeda)
 └── Project (e.g. "Creative & Design Hub")
      └── Task
           ├── Subtasks (tasks with a parent)
           ├── Comments
           ├── Attachments
           └── Activity log
```

- A user can belong to several organizations and switch between them from the top-left switcher.
- Statuses are set **per project**, so different projects can use different workflows.

## 5. Features

### 5.1 Organizations and members
- Create an organization (name, logo optional).
- **Add a member directly:** name, email, and a temporary password, which they must change at first sign-in. Sending an email invite is optional and needs a Hostinger mailbox set up over SMTP.
- One person can be a member of several organizations, with different permissions in each.
- Deactivate a member. They can no longer sign in, but their tasks, comments, and history stay and show their name as "(inactive)". Nobody is ever hard-deleted, so reports stay accurate.
- Each organization's data is fully separate.
- **Rename** an organization in its Settings (Owner, or members with *Organization settings*).
- **Delete an organization** (Owner only): this permanently removes its projects, tasks, comments, files, and memberships. You confirm by typing the organization's name. People's accounts stay, because they may belong to other organizations.

### 5.1a Member permissions
When adding or editing a member, the admin first picks a **starting preset** and can then tick or untick any individual permission. The member's exact checkboxes are what get saved, so every member can be set up differently.

**Project access**
- [ ] All projects (including ones created later), **or** only the projects selected from a list

**Tasks**
| Permission | Notes |
|---|---|
| View tasks | Always on for the projects they can access |
| Create tasks and subtasks | |
| Edit tasks | No / **own tasks** (assigned to or created by them) / **all tasks**. Covers title, description, and subtasks. |
| Change status (move cards) | No / own / all. Can be on even when editing is off. |
| Assign / unassign people | No / own / all |
| Set priority, dates, and custom fields | No / own / all |
| Delete tasks | No / own / all. Moves the task and its subtasks to Trash. The Owner, and anyone allowed to delete all tasks, can restore it. |

**Comments and files**
| Permission | Notes |
|---|---|
| Comment | Members can always edit or delete their own comments |
| Upload files | |
| Delete anyone's comments and files | Moderation |

**Workspace**
| Permission | Notes |
|---|---|
| Create, rename, and archive projects | |
| Configure stages | Add, rename, reorder, and recolor a project's board columns, or switch them to a template. Also manages the project's custom fields. |
| Export CSV reports | Can be limited to **their own tasks only** |
| Manage members | Add or deactivate members and set permissions. A member can never grant permissions they don't have themselves. |
| Organization settings | Name and logo |

**Presets** (they only fill in the checkboxes as a starting point)
| Preset | What it turns on |
|---|---|
| Admin | Everything |
| Lead | Everything except Manage members and Organization settings |
| Designer | Create tasks; edit, move, assign, and set dates on own tasks; comment; upload; export own tasks |
| Viewer | View and comment only |

A permission change takes effect on the member's next page load. The member's profile shows a summary such as "Designer (customized)" when their checkboxes no longer match the preset.

### 5.2 Projects
- Create, rename, and archive projects inside an organization.
- A new project starts from a stage template (5.3), which can then be edited.

### 5.3 Customizable stages (board columns)
- Every project has **its own stages**. Changing one project's stages never affects another project.
- **Editing:** add, rename, recolor, reorder, and delete stages at any time (Project settings, or "Edit stages" on the board).
- **Stage type:** each stage is `Not started`, `In progress`, or `Done`. Moving a task into a "Done" stage sets its `completed_at`, which the reports rely on.
- **Deleting a stage** that still has tasks asks where to move them.
- **Templates:** a new project starts from one of three templates, picked when the project is created:

| Template | Stages | Starting fields (5.6a) |
|---|---|---|
| **Design task** (default, the current ClickUp flow) | Requested → In Progress → For Review → Revisions → Approved | Figma file (link) |
| **Project management** | Backlog → To Do → In Progress → Blocked → Done | Doc link (link), Estimate (hours) (number) |
| **Task review** | Submitted → In Review → Changes Requested → Approved / Rejected | Submission link (link), Reviewer (text) |

- **Switching an existing project to a template:** this replaces its stages. Each task moves to the first new stage of the same type (not started, in progress, or done), so tasks keep their progress and completed dates.

### 5.4 Board view (Kanban)
- One column per status, showing a task count.
- Drag cards between columns to change status, and within a column to reorder.
- A card shows the title, assignee avatars, due date (red when overdue), priority flag, and subtask count.
- "+ Add Task" at the bottom of each column for quick creation (title only).
- Toolbar: filter by assignee, priority, and due date; search by title; show/hide closed tasks.

### 5.5 List view
- The same tasks shown as a table, grouped by status (groups can collapse).
- Columns: Title, Assignees, Priority, Start, Due, Created, Status.
- Sort by any column. Uses the same filters as the board.
- Edit fields directly in the row (status, assignee, priority, dates).
- A **Board | List** toggle switches views and remembers the last one used per project.

### 5.5a Files tab
- A third tab next to **Board | List** shows every file shared in the project, on tasks, subtasks, and comments, newest first.
- Each file shows a preview (images) or a file-type tile, its name, size, date, and who uploaded it, plus the task it belongs to. Clicking the task opens it.
- Filter by type: Images, PDFs, Docs & sheets, Design files (.fig, .psd, .ai, .sketch …), Other. Search by file name or task title.
- Members can delete their own files; members with *Delete anyone's comments and files* can delete any file.
- Files deleted with their task or moved to Trash don't appear.

### 5.5b Sidebar
- The sidebar can collapse into a thin rail showing only icons, using the toggle at the top or the `[` key. Each browser remembers the choice.
- In the rail, projects show as colored letter tiles. Hovering over or tabbing to any icon shows its name in a tooltip.

### 5.6 Task
| Field | Notes |
|---|---|
| Title | Required |
| Description | Rich text: bold, lists, links, headings |
| Status | From the project's statuses |
| Assignees | Several allowed |
| Priority | Urgent / High / Normal / Low / None |
| Start date | Optional |
| Due date | Optional; overdue is highlighted |
| Created at / Created by | Set automatically, read-only |
| Completed at | Set automatically when the task moves to a Done status; cleared if it moves back |

- Clicking a task opens a detail panel, as in the ClickUp screenshot: fields on the left, Activity and comments on the right.

### 5.6a Custom fields per project
- Each project has its own **fields**, e.g. **Figma file** on design projects. You add, rename, reorder, and delete them in Project settings → Fields. A project template adds a starting set, and "Add a template's fields" adds any of a template's fields the project is missing.
- **Types:** Link, Text, Number, Date, Dropdown (options you define), and Checkbox. An existing field keeps its type. To change it, delete the field and add it again.
- **Where they show:**
  - Task panel: an editable row under Priority. Links get an "Open Figma" (or "Open docs.google.com"…) button.
  - Board cards: link fields appear as chips that open the link.
  - List view: one sortable column per field.
  - Tasks CSV: one column per field name.
- Editing a field's value uses the *Set priority, dates and custom fields* permission. Values are validated: a real link, a number, a valid date, or one of the dropdown options.
- Changing a value is recorded in the task's history ("set Figma file to …"). Deleting a field removes its values.

### 5.7 Subtasks
- Add a new subtask from inside a task.
- **Link an existing task as a subtask** by searching tasks in the same project and picking one.
- Detach a subtask, which turns it back into a normal task.
- Subtasks have the full set of task fields.
- Only one level of nesting: a subtask can't have its own subtasks. This keeps the board and reports simple.
- The parent task shows its subtasks' progress ("2 of 3 done").
- The board shows parent tasks only, with a subtask count. A toggle shows subtasks as separate cards.

### 5.8 Comments
- Post comments on a task as plain text with links. @mentions can come later.
- Edit or delete your own comments; edits are marked "edited".
- Comments show in the Activity panel in time order, alongside system events.

### 5.8a @mentions
- Type `@` in a comment or in a task description to open a list of the people who can open that project. Arrow keys plus Enter (or a click) inserts the person.
- Mentions are highlighted in comments and appear as chips in descriptions. The task's history records "mentioned Ravi Dutta".
- Each mentioned person gets one email: who mentioned them, the task, the text, and a button that opens the task. Editing a comment or description only emails people who weren't already mentioned in it. Nobody gets an email for mentioning themselves.
- Emails are queued and sent right after the save, with a cron job retrying any that failed. They go out from a Hostinger mailbox over SMTP.
- Each person can turn mention emails off under Your account.

### 5.9 Attachments
- Upload files to a task or to a comment by drag-and-drop or the file picker.
- Files are stored on the Hostinger server, not a third-party service.
- Image previews; other files show as a download link.
- Size limit per file can be configured (default 25 MB).
- Files can only be downloaded by members of that organization.
- Files that could run on the server or in a browser (.php, .html, .js, .exe, and similar) are refused. Zip them first.

### 5.10 Activity log
- Records task creation, status changes, assignee changes, date changes, priority changes, subtasks added or removed, and files added.
- Each entry has who did it and when ("You changed status: In Progress → For Review · Oct 13, 3:49 pm").

### 5.11 CSV export (reports)
- An **Export** button at the organization or project level.
- Filters:
  - **Team member** (one, several, or all)
  - **Date range**, with presets (last 30 / 60 / 90 days, this quarter) or a custom range
  - **Date basis**: which date the range applies to (created, completed, or due)
  - Project(s), status(es)
  - Whether to include subtasks
- One row per task, with these columns:
  `Task ID, Title, Project, Parent Task, Status, Priority, Assignees, Created By, Created At, Start Date, Due Date, Completed At, Days to Complete, Overdue (Y/N), Comment Count, Attachment URLs, Task URL`
- Optional second file: **comments export** (`Task ID, Task Title, Author, Comment, Posted At`).
- UTF-8 with BOM so Excel and Google Sheets open it cleanly.

## 6. Data model (draft)

```
users            id, name, email, password_hash, avatar_url, is_owner, must_change_password,
                 created_at, last_login_at
organizations    id, name, slug, logo_url, created_at
memberships      id, user_id, org_id, preset, permissions (JSON of ticked boxes),
                 all_projects (bool), active (bool), joined_at
membership_projects  membership_id, project_id     -- used when all_projects = false
projects         id, org_id, name, archived, created_at
statuses         id, project_id, name, color, type(open|active|done), position
tasks            id, project_id, parent_id NULL, status_id, title, description,
                 priority, start_date, due_date, position,
                 created_by, created_at, updated_at, completed_at, deleted_at
task_assignees   task_id, user_id
comments         id, task_id, user_id, body, created_at, edited_at
attachments      id, task_id, comment_id NULL, user_id, file_name, path, size, mime, created_at
activity         id, task_id, user_id, action, from_value, to_value, created_at
login_attempts   email, ip_hash, attempted_at            -- rate limiting
```

## 7. Technical approach

This follows the same setup as Rare Flavours and Wear Human.

- **URL:** `https://pm.houseofjugaad.com`. It's a subdomain on the existing Hostinger **Business** shared plan, deployed into that subdomain's folder.
- **Stack:** plain **PHP 8.3** and **MariaDB**, server-rendered, with vanilla JS. There's no Composer and no build step. Locally it runs on SQLite with `php -S`.
- **Code layout:** the same as Rare Flavours:
  - `app/Core` holds Router, Database (PDO with prepared statements), Auth, Csrf, Session, Validator, and View.
  - `app/Controllers`, `app/Models`, `app/Services`, and `app/Views` hold the app itself.
  - `public/` is the front controller.
  - `database/schema.sql` and `database/migrations` hold the schema.
  - `bin/install.php` and `bin/migrate.php` set up and upgrade the database.
- **Source control:** a private GitHub repo, `srivastavaakshat/leanpm`, managed with GitHub Desktop.
  - Hostinger pulls it through hPanel → Git using a read-only deploy key.
  - A push webhook deploys automatically.
  - A root `.htaccess` sends every request into `/public` and blocks `app/`, `config/`, `storage/`, and `bin/`.
- **Secrets:** `config/local.php` exists only on the server and is git-ignored. Deploys never touch the database, the config file, or the uploaded files.
- **Board interactions:** native drag-and-drop, or a small vendored library (SortableJS) committed to the repo. There is no CDN dependency.
- **Files:** stored in `storage/uploads/{org}/{task}/`, outside the web root. They are served through `/files/{id}`, which checks that the viewer belongs to the organization.
  - On shared hosting, this needs `upload_max_filesize` set to 25M or more in hPanel's PHP options.
- **Security:**
  - Authentication: bcrypt passwords, session cookies, CSRF tokens on every form and AJAX call, and sign-in rate limiting.
  - Authorization: every query is scoped to the organization and checked against the member's permissions on the server. Hidden buttons are never the only protection.
- **Backups:** Hostinger's daily backups on the Business plan, plus a full JSON/CSV export in Settings.
- **Docs in the repo:** `README.md` (running it locally), `DEPLOY.md` (step-by-step hPanel setup, in the same style as the other projects), and `CLAUDE.md` (working notes).

## 8. Success criteria

- The design team moves off ClickUp for day-to-day work within 2 weeks of launch.
- Exporting a "member × last 3 months" report takes under 30 seconds.
- Board loads in under 1 second with 500 tasks.

## 9. Later (not v1)

email when you're assigned a task · project-level files and links (not attached to a task) · tags · Google Sheets sync · recurring tasks · time tracking · calendar view · dashboards and charts inside the app · client guest access · dark mode

## 10. Decisions log

- **15 Oct 2026:** No online/presence indicators.
- **15 Oct 2026:** Email notifications (assignment and @mentions) ship together in a later version. They need a Hostinger mailbox and a cron job.
- **15 Oct 2026:** @mentions with email moved into v1 (comments and descriptions). Assignment emails stay in a later version.
