<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database as DB;

/** Task queries and the rules every change goes through (status → completed_at, history). */
final class Tasks
{
    public const PRIORITIES = ['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'];

    /**
     * Stage templates offered when creating a project (PRD §5.3). Each project gets its own copy,
     * so editing one project's stages never changes another's. [name, colour, type]
     */
    public const TEMPLATES = [
        'design' => [
            'label' => 'Design task',
            'hint'  => 'Your current ClickUp flow: brief in, design, review, revise, approve.',
            'stages' => [
                ['Requested', '#8b8f98', 'open'],
                ['In Progress', '#0ea5e9', 'active'],
                ['For Review', '#f59e0b', 'active'],
                ['Revisions', '#ef4444', 'active'],
                ['Approved', '#22c55e', 'done'],
            ],
        ],
        'project' => [
            'label' => 'Project management',
            'hint'  => 'General work tracking with a backlog and a place for blocked tasks.',
            'stages' => [
                ['Backlog', '#8b8f98', 'open'],
                ['To Do', '#6366f1', 'open'],
                ['In Progress', '#0ea5e9', 'active'],
                ['Blocked', '#ef4444', 'active'],
                ['Done', '#22c55e', 'done'],
            ],
        ],
        'review' => [
            'label' => 'Task review',
            'hint'  => 'For reviewing work others submit: approve it, ask for changes, or reject it.',
            'stages' => [
                ['Submitted', '#8b8f98', 'open'],
                ['In Review', '#f59e0b', 'active'],
                ['Changes Requested', '#ef4444', 'active'],
                ['Approved', '#22c55e', 'done'],
                ['Rejected', '#6b7280', 'done'],
            ],
        ],
    ];

    public static function template(?string $key): string
    {
        return isset(self::TEMPLATES[(string) $key]) ? (string) $key : 'design';
    }

    public static function createDefaultStatuses(int $projectId, string $template = 'design'): void
    {
        foreach (self::TEMPLATES[self::template($template)]['stages'] as $i => [$name, $color, $type]) {
            DB::insert('statuses', ['project_id' => $projectId, 'name' => $name, 'color' => $color, 'type' => $type, 'position' => $i]);
        }
    }

    /**
     * Replace a project's stages with a template. Tasks move to the first new stage of the same
     * type (not started / in progress / done), so completed dates stay correct for reports.
     * Returns how many tasks moved.
     */
    public static function applyTemplate(int $projectId, string $template): int
    {
        $old = self::statuses($projectId);
        $moved = 0;
        DB::transaction(function () use ($projectId, $template, $old, &$moved) {
            // Park the old stages at the end so the new ones take positions 0..n.
            DB::query('UPDATE statuses SET position = position + 1000 WHERE project_id = ?', [$projectId]);
            self::createDefaultStatuses($projectId, $template);
            $new = DB::all('SELECT * FROM statuses WHERE project_id = ? AND position < 1000 ORDER BY position', [$projectId]);
            $firstOfType = [];
            foreach ($new as $s) {
                $firstOfType[$s['type']] ??= $s;
            }
            foreach ($old as $o) {
                $target = $firstOfType[$o['type']] ?? ($o['type'] === 'done' ? end($new) : $new[0]);
                $tasks = DB::all('SELECT id, completed_at FROM tasks WHERE status_id = ?', [$o['id']]);
                foreach ($tasks as $t) {
                    $completed = $target['type'] === 'done' ? ($t['completed_at'] ?? now()) : null;
                    DB::update('tasks', ['status_id' => $target['id'], 'completed_at' => $completed], 'id = ?', [$t['id']]);
                    if ($o['name'] !== $target['name']) {
                        Activity::log((int) $t['id'], 'status', $o['name'], $target['name']);
                    }
                    $moved++;
                }
                DB::query('DELETE FROM statuses WHERE id = ?', [$o['id']]);
            }
        });
        return $moved;
    }

    public static function statuses(int $projectId): array
    {
        return DB::all('SELECT id, name, color, type, position FROM statuses WHERE project_id = ? ORDER BY position, id', [$projectId]);
    }

    /** Every live task in a project, ready for the board and list. */
    public static function forProject(int $projectId): array
    {
        $rows = DB::all('SELECT * FROM tasks WHERE project_id = ? AND deleted_at IS NULL ORDER BY position, id', [$projectId]);
        return self::serializeMany($rows);
    }

    public static function serializeMany(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $in = DB::placeholders($ids);
        $assignees = [];
        foreach (DB::all("SELECT task_id, user_id FROM task_assignees WHERE task_id IN ($in)", $ids) as $a) {
            $assignees[(int) $a['task_id']][] = (int) $a['user_id'];
        }
        $counts = fn (string $sql) => array_column(DB::all($sql, $ids), 'n', 'k');
        $fieldValues = Fields::values($ids);
        $comments = $counts("SELECT task_id AS k, COUNT(*) AS n FROM comments WHERE task_id IN ($in) GROUP BY task_id");
        $files = $counts("SELECT task_id AS k, COUNT(*) AS n FROM attachments WHERE task_id IN ($in) GROUP BY task_id");
        $subs = [];
        foreach (DB::all(
            "SELECT t.parent_id, COUNT(*) AS total, SUM(CASE WHEN s.type = 'done' THEN 1 ELSE 0 END) AS done
             FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.parent_id IN ($in) AND t.deleted_at IS NULL GROUP BY t.parent_id",
            $ids
        ) as $s) {
            $subs[(int) $s['parent_id']] = [(int) $s['total'], (int) $s['done']];
        }
        return array_map(function ($r) use ($assignees, $comments, $files, $subs, $fieldValues) {
            $id = (int) $r['id'];
            $r['assignee_ids'] = $assignees[$id] ?? [];
            $r['field_values'] = $fieldValues[$id] ?? [];
            return self::serialize($r, $subs[$id] ?? [0, 0], (int) ($comments[$id] ?? 0), (int) ($files[$id] ?? 0));
        }, $rows);
    }

    public static function serialize(array $r, array $subs = [0, 0], int $comments = 0, int $files = 0): array
    {
        return [
            'id'            => (int) $r['id'],
            'project_id'    => (int) $r['project_id'],
            'parent_id'     => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'status_id'     => (int) $r['status_id'],
            'title'         => $r['title'],
            'priority'      => $r['priority'],
            'start_date'    => $r['start_date'],
            'due_date'      => $r['due_date'],
            'created_at'    => $r['created_at'],
            'created_by'    => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'completed_at'  => $r['completed_at'],
            'position'      => (int) $r['position'],
            'assignees'     => $r['assignee_ids'],
            'fields'        => (object) ($r['field_values'] ?? []),
            'subtasks'      => $subs[0],
            'subtasks_done' => $subs[1],
            'comments'      => $comments,
            'files'         => $files,
            'can'           => self::abilities($r),
        ];
    }

    /** What the current member may do to this task (the server checks again on every change). */
    public static function abilities(array $task): array
    {
        return [
            'edit'   => Access::canTask('edit_tasks', $task),
            'move'   => Access::canTask('move_tasks', $task),
            'assign' => Access::canTask('assign', $task),
            'fields' => Access::canTask('set_fields', $task),
            'delete' => Access::canTask('delete_tasks', $task),
        ];
    }

    /** Everything the task panel shows. */
    public static function detail(array $task): array
    {
        $id = (int) $task['id'];
        $data = self::serializeMany([$task])[0];
        $data['description'] = $task['description'];
        $data['project_id'] = (int) $task['project_id'];
        // For the quick-view panel on pages outside the board (meeting notes, All tasks).
        $proj = DB::one('SELECT id, name, is_inbox FROM projects WHERE id = ?', [$task['project_id']]);
        $data['project'] = ['id' => (int) $proj['id'], 'name' => $proj['name'], 'inbox' => (bool) $proj['is_inbox']];
        $data['statuses'] = array_map(fn ($s) => $s + ['id' => (int) $s['id']], self::statuses((int) $task['project_id']));
        $data['url'] = url('/o/' . Access::$org['slug'] . '/p/' . $task['project_id'], ['task' => $id]);
        $data['field_defs'] = Fields::forProject((int) $task['project_id']);
        $data['can'] += ['comment' => Access::can('comment'), 'upload' => Access::can('upload'), 'create' => Access::can('create_tasks')];
        $data['parent'] = $task['parent_id'] ? DB::one('SELECT id, title FROM tasks WHERE id = ? AND deleted_at IS NULL', [$task['parent_id']]) : null;
        $data['subtask_list'] = self::serializeMany(DB::all('SELECT * FROM tasks WHERE parent_id = ? AND deleted_at IS NULL ORDER BY position, id', [$id]));

        $files = DB::all('SELECT a.*, u.name AS user_name FROM attachments a JOIN users u ON u.id = a.user_id WHERE a.task_id = ? ORDER BY a.id', [$id]);
        $files = array_map(fn ($f) => self::file($f), $files);
        $byComment = [];
        foreach ($files as $f) {
            if ($f['comment_id']) {
                $byComment[$f['comment_id']][] = $f;
            }
        }
        $data['attachments'] = array_values(array_filter($files, fn ($f) => !$f['comment_id']));

        $feed = [];
        foreach (DB::all('SELECT * FROM activity WHERE task_id = ? ORDER BY id', [$id]) as $a) {
            $feed[] = ['type' => 'event', 'user_id' => $a['user_id'] !== null ? (int) $a['user_id'] : null, 'text' => Activity::describe($a), 'at' => $a['created_at'], 'sort' => $a['created_at'] . '-0-' . $a['id']];
        }
        $uid = Auth::id();
        $mentionable = Mentions::candidates((int) Access::$org['id'], (int) $task['project_id']);
        foreach (DB::all('SELECT * FROM comments WHERE task_id = ? ORDER BY id', [$id]) as $c) {
            $mine = (int) $c['user_id'] === $uid;
            $feed[] = [
                'type' => 'comment', 'id' => (int) $c['id'], 'user_id' => (int) $c['user_id'],
                'body' => $c['body'], 'html' => RichText::comment($c['body'], $mentionable), 'at' => $c['created_at'], 'edited' => $c['edited_at'] !== null,
                'files' => $byComment[(int) $c['id']] ?? [],
                'can_edit' => $mine, 'can_delete' => $mine || Access::can('moderate'),
                'sort' => $c['created_at'] . '-1-' . $c['id'],
            ];
        }
        usort($feed, fn ($a, $b) => strcmp($a['sort'], $b['sort']));
        $data['feed'] = $feed;
        return $data;
    }

    public static function file(array $f): array
    {
        return [
            'id'         => (int) $f['id'],
            'comment_id' => $f['comment_id'] !== null ? (int) $f['comment_id'] : null,
            'name'       => $f['file_name'],
            'size'       => human_size((int) $f['size']),
            'image'      => Uploads::isImage($f['mime']),
            'url'        => url('/files/' . $f['id']),
            'user_id'    => (int) $f['user_id'],
            'can_delete' => (int) $f['user_id'] === Auth::id() || Access::can('moderate'),
        ];
    }

    /** Move a task to a status: sets or clears completed_at and records the change. */
    public static function setStatus(array $task, array $status): void
    {
        if ((int) $task['status_id'] === (int) $status['id']) {
            return;
        }
        $old = DB::one('SELECT name, type FROM statuses WHERE id = ?', [$task['status_id']]);
        $completed = $task['completed_at'];
        if ($status['type'] === 'done' && ($old['type'] ?? '') !== 'done') {
            $completed = now();
        } elseif ($status['type'] !== 'done') {
            $completed = null;
        }
        DB::update('tasks', ['status_id' => (int) $status['id'], 'completed_at' => $completed, 'updated_at' => now()], 'id = ?', [$task['id']]);
        Activity::log((int) $task['id'], 'status', $old['name'] ?? '?', $status['name']);
    }

    /** A status of the task's project, or 422. */
    public static function status(int $projectId, mixed $statusId): array
    {
        $s = DB::one('SELECT * FROM statuses WHERE id = ? AND project_id = ?', [(int) $statusId, $projectId]);
        if (!$s) {
            abort(422, 'That status doesn’t exist in this project.');
        }
        return $s;
    }

    /** Create a task (or subtask) in a project. Returns its id. Callers check permissions first. */
    public static function create(array $project, string $title, ?array $status = null, ?array $parent = null): int
    {
        $status ??= self::statuses((int) $project['id'])[0];
        return DB::transaction(function () use ($project, $title, $status, $parent) {
            $id = DB::insert('tasks', [
                'project_id'   => $project['id'],
                'parent_id'    => $parent['id'] ?? null,
                'status_id'    => $status['id'],
                'title'        => $title,
                'position'     => self::nextPosition((int) $project['id'], (int) $status['id']),
                'created_by'   => Auth::id(),
                'created_at'   => now(),
                'updated_at'   => now(),
                'completed_at' => $status['type'] === 'done' ? now() : null,
            ]);
            // The person creating it is assigned by default; they can change it from there.
            if (Auth::id()) {
                DB::insert('task_assignees', ['task_id' => $id, 'user_id' => Auth::id()]);
            }
            Activity::log($id, 'created');
            if ($parent) {
                Activity::log((int) $parent['id'], 'subtask_added', null, $title);
            }
            return $id;
        });
    }

    /**
     * Move a task (and its subtasks) to another project in the same org. The stage is matched by
     * name, else by kind (not started / in progress / done); custom field values carry over to
     * fields with the same name and type. A subtask moved on its own becomes a normal task.
     */
    public static function moveToProject(array $task, array $target): void
    {
        $from = DB::one('SELECT * FROM projects WHERE id = ?', [$task['project_id']]);
        $targetStatuses = self::statuses((int) $target['id']);
        $targetFields = Fields::forProject((int) $target['id']);
        $sourceFields = array_column(Fields::forProject((int) $task['project_id']), null, 'id');

        DB::transaction(function () use ($task, $target, $from, $targetStatuses, $targetFields, $sourceFields) {
            $ids = array_merge([(int) $task['id']], array_map('intval', array_column(DB::all('SELECT id FROM tasks WHERE parent_id = ?', [$task['id']]), 'id')));
            foreach ($ids as $id) {
                $t = DB::one('SELECT t.*, s.name AS status_name, s.type AS status_type FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.id = ?', [$id]);
                $match = null;
                foreach ($targetStatuses as $s) {
                    if (mb_strtolower($s['name']) === mb_strtolower($t['status_name'])) {
                        $match = $s;
                        break;
                    }
                }
                if (!$match) {
                    foreach ($targetStatuses as $s) {
                        if ($s['type'] === $t['status_type']) {
                            $match = $s;
                            break;
                        }
                    }
                }
                $match ??= $targetStatuses[0];
                $completed = $match['type'] === 'done' ? ($t['completed_at'] ?? now()) : null;
                DB::update('tasks', [
                    'project_id' => $target['id'], 'status_id' => $match['id'], 'completed_at' => $completed,
                    'parent_id' => $id === (int) $task['id'] ? null : $t['parent_id'],
                    'position' => self::nextPosition((int) $target['id'], (int) $match['id']), 'updated_at' => now(),
                ], 'id = ?', [$id]);

                foreach (DB::all('SELECT * FROM task_field_values WHERE task_id = ?', [$id]) as $v) {
                    $src = $sourceFields[(int) $v['field_id']] ?? null;
                    $dst = null;
                    foreach ($targetFields as $f) {
                        if ($src && mb_strtolower($f['name']) === mb_strtolower($src['name']) && $f['type'] === $src['type']) {
                            $dst = $f;
                        }
                    }
                    DB::query('DELETE FROM task_field_values WHERE task_id = ? AND field_id = ?', [$id, $v['field_id']]);
                    if ($dst) {
                        DB::query('DELETE FROM task_field_values WHERE task_id = ? AND field_id = ?', [$id, $dst['id']]);
                        DB::insert('task_field_values', ['task_id' => $id, 'field_id' => $dst['id'], 'value' => $v['value']]);
                    }
                }
            }
            if ($task['parent_id']) {
                Activity::log((int) $task['parent_id'], 'subtask_removed', $task['title']);
            }
            Activity::log((int) $task['id'], 'moved_project', $from['name'] ?? '?', $target['name']);
        });
    }

    public static function nextPosition(int $projectId, int $statusId): int
    {
        return (int) DB::value('SELECT COALESCE(MAX(position), -1) + 1 FROM tasks WHERE project_id = ? AND status_id = ?', [$projectId, $statusId]);
    }
}
