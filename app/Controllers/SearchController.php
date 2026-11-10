<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database as DB;
use App\Services\Access;

/** Task search for the command palette (⌘K): only tasks in projects this member can open. */
final class SearchController
{
    public function tasks(string $org): never
    {
        Access::org($org);
        $ids = array_merge([Access::inboxId()], array_map(fn ($p) => (int) $p['id'], Access::projects()));
        $q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 100));
        $base = 'SELECT t.id, t.title, t.project_id, t.parent_id, t.due_date, p.name AS project, p.is_inbox, s.name AS status, s.color AS status_color, s.type AS status_type
                 FROM tasks t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
                 WHERE t.deleted_at IS NULL AND t.project_id IN (' . DB::placeholders($ids) . ')';
        if ($q === '') {
            // Nothing typed yet: what's been worked on most recently.
            $rows = DB::all($base . ' ORDER BY t.updated_at DESC LIMIT 8', $ids);
        } else {
            $like = '%' . strtr(mb_strtolower($q), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $num = preg_match('/^#?(\d+)$/', $q, $m) ? (int) $m[1] : 0;
            // Open tasks before done ones; titles starting with the text first.
            $rows = DB::all(
                $base . " AND (LOWER(t.title) LIKE ? ESCAPE '!' OR t.id = ?)
                 ORDER BY t.id = ? DESC, s.type = 'done', LOWER(t.title) LIKE ? ESCAPE '!' DESC, t.updated_at DESC LIMIT 20",
                array_merge($ids, [$like, $num, $num, substr($like, 1)])
            );
        }
        json_response(['tasks' => array_map(fn ($r) => [
            'id'      => (int) $r['id'],
            'title'   => $r['title'],
            'project' => (int) $r['is_inbox'] ? 'Inbox' : $r['project'],
            'project_id' => (int) $r['project_id'],
            'inbox'   => (bool) (int) $r['is_inbox'],
            'subtask' => $r['parent_id'] !== null,
            'status'  => $r['status'],
            'color'   => $r['status_color'],
            'done'    => $r['status_type'] === 'done',
            'due'     => $r['due_date'],
        ], $rows)]);
    }
}
