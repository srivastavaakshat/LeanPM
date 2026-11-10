<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\Access;
use App\Services\Tasks;

/** Every task the member can see, across projects, with the Inbox (not yet in a project) first. */
final class AllTasksController
{
    public function show(string $org): string
    {
        $o = Access::org($org);
        $inbox = Access::inboxId();
        $projects = [['id' => $inbox, 'name' => 'Inbox', 'inbox' => true]];
        foreach (Access::projects() as $p) {
            $projects[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'inbox' => false];
        }
        $ids = array_column($projects, 'id');
        $rows = DB::all('SELECT * FROM tasks WHERE project_id IN (' . DB::placeholders($ids) . ') AND deleted_at IS NULL ORDER BY created_at DESC', $ids);
        $statuses = [];
        foreach (DB::all('SELECT id, project_id, name, color, type, position FROM statuses WHERE project_id IN (' . DB::placeholders($ids) . ') ORDER BY position', $ids) as $s) {
            $statuses[(int) $s['project_id']][] = ['id' => (int) $s['id'], 'name' => $s['name'], 'color' => $s['color'], 'type' => $s['type']];
        }
        $members = Access::members(includeInactive: true);
        $boot = [
            'org'      => $o['slug'],
            'projects' => $projects,
            'statuses' => (object) $statuses,
            'tasks'    => Tasks::serializeMany($rows),
            'members'  => array_map(fn ($m) => ['id' => (int) $m['id'], 'name' => $m['name'], 'color' => $m['color'], 'initials' => initials($m['name']), 'active' => (bool) $m['active']], $members),
            'priorities' => Tasks::PRIORITIES,
            'maxUpload' => (int) config('upload_max_mb', 25),
            'me'       => Auth::id(),
            'can'      => ['move' => Access::can('create_tasks')],
            'base'     => url('/'),
            'csrf'     => csrf_token(),
            'today'    => date('Y-m-d'),
        ];
        return view('tasks/all', ['title' => 'All tasks', 'boot' => $boot]);
    }
}
