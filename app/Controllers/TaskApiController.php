<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\Access;
use App\Services\Activity;
use App\Services\RichText;
use App\Services\Tasks;

/** JSON endpoints for the board, list and task panel. Every change is permission-checked here. */
final class TaskApiController
{
    private function project(string $id): array
    {
        $p = DB::one('SELECT * FROM projects WHERE id = ?', [(int) $id]);
        if (!$p) {
            abort(404);
        }
        Access::orgById((int) $p['org_id']);
        return Access::project((int) $p['id']);
    }

    public function index(string $project): never
    {
        $p = $this->project($project);
        json_response(['tasks' => Tasks::forProject((int) $p['id']), 'statuses' => Tasks::statuses((int) $p['id'])]);
    }

    public function store(string $project): never
    {
        $p = $this->project($project);
        Access::require('create_tasks');
        $in = json_input();
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 255);
        if ($title === '') {
            abort(422, 'Give the task a title.');
        }
        $parent = null;
        if (!empty($in['parent_id'])) {
            $parent = DB::one('SELECT * FROM tasks WHERE id = ? AND project_id = ? AND deleted_at IS NULL', [(int) $in['parent_id'], $p['id']]);
            if (!$parent || $parent['parent_id'] !== null) {
                abort(422, 'Subtasks can’t have their own subtasks.');
            }
        }
        $statuses = Tasks::statuses((int) $p['id']);
        $status = !empty($in['status_id']) ? Tasks::status((int) $p['id'], $in['status_id']) : $statuses[0];

        $id = DB::transaction(function () use ($p, $title, $parent, $status) {
            $id = DB::insert('tasks', [
                'project_id'   => $p['id'],
                'parent_id'    => $parent['id'] ?? null,
                'status_id'    => $status['id'],
                'title'        => $title,
                'position'     => Tasks::nextPosition((int) $p['id'], (int) $status['id']),
                'created_by'   => Auth::id(),
                'created_at'   => now(),
                'updated_at'   => now(),
                'completed_at' => $status['type'] === 'done' ? now() : null,
            ]);
            Activity::log($id, 'created');
            if ($parent) {
                Activity::log((int) $parent['id'], 'subtask_added', null, $title);
            }
            return $id;
        });
        $task = DB::one('SELECT * FROM tasks WHERE id = ?', [$id]);
        json_response(['task' => Tasks::serializeMany([$task])[0]], 201);
    }

    public function show(string $task): never
    {
        $t = Access::task((int) $task);
        json_response(['task' => Tasks::detail($t)]);
    }

    public function update(string $task): never
    {
        $t = Access::task((int) $task);
        $in = json_input();
        $projectId = (int) $t['project_id'];

        DB::transaction(function () use ($t, $in, $projectId) {
            $set = [];
            if (array_key_exists('title', $in)) {
                Access::requireTask('edit_tasks', $t);
                $title = mb_substr(trim((string) $in['title']), 0, 255);
                if ($title === '') {
                    abort(422, 'The title can’t be empty.');
                }
                if ($title !== $t['title']) {
                    $set['title'] = $title;
                    Activity::log((int) $t['id'], 'title', $t['title'], $title);
                }
            }
            if (array_key_exists('description', $in)) {
                Access::requireTask('edit_tasks', $t);
                $html = RichText::clean(is_string($in['description']) ? $in['description'] : null);
                if ($html !== $t['description']) {
                    $set['description'] = $html;
                    // One history line per editing session, not per keystroke save.
                    $recent = DB::one("SELECT id FROM activity WHERE task_id = ? AND action = 'description' AND user_id = ? AND created_at > ?", [$t['id'], Auth::id(), date('Y-m-d H:i:s', time() - 600)]);
                    if (!$recent) {
                        Activity::log((int) $t['id'], 'description');
                    }
                }
            }
            if (array_key_exists('priority', $in)) {
                Access::requireTask('set_fields', $t);
                $pr = isset(Tasks::PRIORITIES[$in['priority'] ?? '']) ? $in['priority'] : null;
                if ($pr !== $t['priority']) {
                    $set['priority'] = $pr;
                    Activity::log((int) $t['id'], 'priority', $t['priority'] ? Tasks::PRIORITIES[$t['priority']] : null, $pr ? Tasks::PRIORITIES[$pr] : null);
                }
            }
            foreach (['start_date', 'due_date'] as $f) {
                if (array_key_exists($f, $in)) {
                    Access::requireTask('set_fields', $t);
                    $d = valid_date($in[$f]);
                    if ($in[$f] && !$d) {
                        abort(422, 'That date isn’t valid.');
                    }
                    if ($d !== $t[$f]) {
                        $set[$f] = $d;
                        Activity::log((int) $t['id'], $f, $t[$f], $d);
                    }
                }
            }
            if (array_key_exists('assignees', $in)) {
                Access::requireTask('assign', $t);
                $wanted = array_values(array_unique(array_map('intval', (array) $in['assignees'])));
                $active = array_map(fn ($m) => (int) $m['id'], Access::members());
                $current = $t['assignee_ids'];
                foreach (array_diff($wanted, $current) as $uid) {
                    if (!in_array($uid, $active, true)) {
                        abort(422, 'You can only assign active members of this organization.');
                    }
                    DB::insert('task_assignees', ['task_id' => $t['id'], 'user_id' => $uid]);
                    Activity::log((int) $t['id'], 'assignee_added', null, (string) DB::value('SELECT name FROM users WHERE id = ?', [$uid]));
                }
                foreach (array_diff($current, $wanted) as $uid) {
                    DB::query('DELETE FROM task_assignees WHERE task_id = ? AND user_id = ?', [$t['id'], $uid]);
                    Activity::log((int) $t['id'], 'assignee_removed', (string) DB::value('SELECT name FROM users WHERE id = ?', [$uid]));
                }
            }
            if ($set) {
                $set['updated_at'] = now();
                DB::update('tasks', $set, 'id = ?', [$t['id']]);
            }
            if (array_key_exists('status_id', $in)) {
                Access::requireTask('move_tasks', $t);
                $status = Tasks::status($projectId, $in['status_id']);
                if ((int) $status['id'] !== (int) $t['status_id']) {
                    $fresh = DB::one('SELECT * FROM tasks WHERE id = ?', [$t['id']]);
                    Tasks::setStatus($fresh, $status);
                    DB::update('tasks', ['position' => Tasks::nextPosition($projectId, (int) $status['id'])], 'id = ?', [$t['id']]);
                }
            }
        });

        json_response(['task' => Tasks::detail(Access::task((int) $t['id']))]);
    }

    /** Drag and drop: new status (optional) plus the full order of the target column. */
    public function move(string $task): never
    {
        $t = Access::task((int) $task);
        Access::requireTask('move_tasks', $t);
        $in = json_input();
        $projectId = (int) $t['project_id'];
        $status = Tasks::status($projectId, $in['status_id'] ?? $t['status_id']);
        DB::transaction(function () use ($t, $status, $in, $projectId) {
            Tasks::setStatus($t, $status);
            $order = array_values(array_map('intval', (array) ($in['order'] ?? [])));
            foreach ($order as $pos => $id) {
                DB::query('UPDATE tasks SET position = ? WHERE id = ? AND project_id = ? AND status_id = ?', [$pos, $id, $projectId, $status['id']]);
            }
        });
        $fresh = DB::one('SELECT * FROM tasks WHERE id = ?', [$t['id']]);
        json_response(['task' => Tasks::serializeMany([$fresh])[0]]);
    }

    /** To Trash, together with its subtasks. */
    public function destroy(string $task): never
    {
        $t = Access::task((int) $task);
        Access::requireTask('delete_tasks', $t);
        $at = now();
        DB::transaction(function () use ($t, $at) {
            DB::query('UPDATE tasks SET deleted_at = ?, deleted_by = ? WHERE parent_id = ? AND deleted_at IS NULL', [$at, Auth::id(), $t['id']]);
            DB::update('tasks', ['deleted_at' => $at, 'deleted_by' => Auth::id()], 'id = ?', [$t['id']]);
            Activity::log((int) $t['id'], 'deleted');
            if ($t['parent_id']) {
                Activity::log((int) $t['parent_id'], 'subtask_removed', $t['title']);
            }
        });
        json_response(['ok' => true]);
    }

    /** Tasks that could become subtasks of this one: same project, top level, no subtasks of their own. */
    public function linkable(string $task): never
    {
        $t = Access::task((int) $task);
        if ($t['parent_id'] !== null) {
            json_response(['tasks' => []]);
        }
        $q = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr((string) ($_GET['q'] ?? ''), 0, 100)) . '%';
        $rows = DB::all(
            "SELECT t.id, t.title, s.name AS status, s.color FROM tasks t JOIN statuses s ON s.id = t.status_id
             WHERE t.project_id = ? AND t.id <> ? AND t.parent_id IS NULL AND t.deleted_at IS NULL AND t.title LIKE ? ESCAPE '!'
               AND NOT EXISTS (SELECT 1 FROM tasks c WHERE c.parent_id = t.id AND c.deleted_at IS NULL)
             ORDER BY t.updated_at DESC LIMIT 20",
            [$t['project_id'], $t['id'], $q]
        );
        json_response(['tasks' => array_map(fn ($r) => $r + ['id' => (int) $r['id']], $rows)]);
    }

    public function linkSubtask(string $task): never
    {
        $parent = Access::task((int) $task);
        Access::requireTask('edit_tasks', $parent);
        $child = Access::task((int) (json_input()['task_id'] ?? 0));
        Access::requireTask('edit_tasks', $child);
        $hasChildren = DB::value('SELECT COUNT(*) FROM tasks WHERE parent_id = ? AND deleted_at IS NULL', [$child['id']]);
        if ((int) $child['project_id'] !== (int) $parent['project_id'] || $parent['parent_id'] !== null || $child['parent_id'] !== null || $hasChildren || $child['id'] === $parent['id']) {
            abort(422, 'Only top-level tasks without subtasks, from the same project, can be linked.');
        }
        DB::transaction(function () use ($parent, $child) {
            DB::update('tasks', ['parent_id' => $parent['id'], 'updated_at' => now()], 'id = ?', [$child['id']]);
            Activity::log((int) $parent['id'], 'subtask_added', null, $child['title']);
            Activity::log((int) $child['id'], 'parent_set', null, $parent['title']);
        });
        json_response(['task' => Tasks::detail(Access::task((int) $parent['id']))]);
    }

    /** Make a subtask a normal task again. */
    public function detach(string $task): never
    {
        $t = Access::task((int) $task);
        if (!$t['parent_id']) {
            abort(422, 'This isn’t a subtask.');
        }
        $parent = Access::task((int) $t['parent_id']);
        if (!Access::canTask('edit_tasks', $t) && !Access::canTask('edit_tasks', $parent)) {
            abort(403);
        }
        DB::transaction(function () use ($t, $parent) {
            DB::update('tasks', ['parent_id' => null, 'updated_at' => now()], 'id = ?', [$t['id']]);
            Activity::log((int) $parent['id'], 'subtask_removed', $t['title']);
            Activity::log((int) $t['id'], 'parent_removed', $parent['title']);
        });
        json_response(['ok' => true]);
    }
}
