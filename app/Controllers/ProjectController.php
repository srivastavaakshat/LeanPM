<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\Access;
use App\Services\Tasks;

final class ProjectController
{
    public function store(string $org): never
    {
        $o = Access::org($org);
        Access::require('manage_projects');
        $name = mb_substr((string) input('name', ''), 0, 120);
        if ($name === '') {
            flash('error', 'Give the project a name.');
            back();
        }
        $template = Tasks::template((string) input('template'));
        $id = DB::transaction(function () use ($o, $name, $template) {
            $id = DB::insert('projects', ['org_id' => $o['id'], 'name' => $name, 'created_by' => Auth::id(), 'created_at' => now()]);
            Tasks::createDefaultStatuses($id, $template);
            // Members limited to chosen projects keep seeing the projects they create.
            if (Access::$member['project_ids'] !== null && Access::$member['id']) {
                DB::insert('membership_projects', ['membership_id' => Access::$member['id'], 'project_id' => $id]);
            }
            return $id;
        });
        redirect("/o/{$o['slug']}/p/{$id}");
    }

    /** The board / list page. The browser draws both views from the same data. */
    public function show(string $org, string $project): string
    {
        $o = Access::org($org);
        $p = Access::project((int) $project);
        if ((int) $p['archived']) {
            flash('error', 'That project is archived. Restore it from Organization settings.');
            redirect("/o/{$o['slug']}");
        }
        $members = Access::members(includeInactive: true);
        $boot = [
            'org'      => ['slug' => $o['slug'], 'name' => $o['name']],
            'project'  => ['id' => (int) $p['id'], 'name' => $p['name']],
            'statuses' => array_map(fn ($s) => $s + ['id' => (int) $s['id']], Tasks::statuses((int) $p['id'])),
            'tasks'    => Tasks::forProject((int) $p['id']),
            'members'  => array_map(fn ($m) => ['id' => (int) $m['id'], 'name' => $m['name'], 'color' => $m['color'], 'initials' => initials($m['name']), 'active' => (bool) $m['active']], $members),
            'me'       => Auth::id(),
            // People who can open this project, offered when typing @ (the server checks again).
            'mentionable' => array_keys(\App\Services\Mentions::candidates((int) $o['id'], (int) $p['id'])),
            'can'      => [
                'create'   => Access::can('create_tasks'),
                'comment'  => Access::can('comment'),
                'upload'   => Access::can('upload'),
                'statuses' => Access::can('manage_statuses'),
            ],
            'priorities' => Tasks::PRIORITIES,
            'settingsUrl' => url("/o/{$o['slug']}/p/{$p['id']}/settings"),
            'base'       => url('/'),
            'csrf'       => csrf_token(),
            'maxUpload'  => (int) config('upload_max_mb', 25),
            'today'      => date('Y-m-d'),
        ];
        return view('project/board', ['title' => $p['name'], 'project' => $p, 'boot' => $boot]);
    }

    public function settings(string $org, string $project): string
    {
        Access::org($org);
        $p = Access::project((int) $project);
        if (!Access::can('manage_statuses') && !Access::can('manage_projects')) {
            abort(403);
        }
        $counts = array_column(DB::all('SELECT status_id, COUNT(*) AS n FROM tasks WHERE project_id = ? AND deleted_at IS NULL GROUP BY status_id', [$p['id']]), 'n', 'status_id');
        return view('project/settings', ['title' => $p['name'] . ' settings', 'project' => $p, 'statuses' => Tasks::statuses((int) $p['id']), 'counts' => $counts]);
    }

    public function rename(string $org, string $project): never
    {
        $o = Access::org($org);
        $p = Access::project((int) $project);
        Access::require('manage_projects');
        $name = mb_substr((string) input('name', ''), 0, 120);
        if ($name !== '') {
            DB::update('projects', ['name' => $name], 'id = ?', [$p['id']]);
            flash('success', 'Project renamed.');
        }
        redirect("/o/{$o['slug']}/p/{$p['id']}/settings");
    }

    public function archive(string $org, string $project): never
    {
        $o = Access::org($org);
        $p = Access::project((int) $project);
        Access::require('manage_projects');
        $archive = input('archived') === '1';
        DB::update('projects', ['archived' => $archive ? 1 : 0], 'id = ?', [$p['id']]);
        flash('success', $archive ? "“{$p['name']}” is archived. Restore it any time from Organization settings." : "“{$p['name']}” is back.");
        redirect($archive ? "/o/{$o['slug']}" : "/o/{$o['slug']}/p/{$p['id']}");
    }

    /** Save every status row at once: order, names, colours and types; new rows have id "new". */
    public function saveStatuses(string $org, string $project): never
    {
        $o = Access::org($org);
        $p = Access::project((int) $project);
        Access::require('manage_statuses');
        $back = "/o/{$o['slug']}/p/{$p['id']}/settings";

        $ids = (array) ($_POST['status_id'] ?? []);
        $names = (array) ($_POST['name'] ?? []);
        $colors = (array) ($_POST['color'] ?? []);
        $types = (array) ($_POST['type'] ?? []);
        $existing = array_column(Tasks::statuses((int) $p['id']), null, 'id');

        $rows = [];
        foreach ($ids as $i => $id) {
            $name = mb_substr(trim((string) ($names[$i] ?? '')), 0, 60);
            if ($name === '') {
                continue;
            }
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($colors[$i] ?? '')) ? strtolower($colors[$i]) : '#8b8f98';
            $type = in_array($types[$i] ?? '', ['open', 'active', 'done'], true) ? $types[$i] : 'active';
            $rows[] = ['id' => $id === 'new' ? null : (int) $id, 'name' => $name, 'color' => $color, 'type' => $type];
        }
        if (!$rows) {
            flash('error', 'A project needs at least one status.');
            redirect($back);
        }

        DB::transaction(function () use ($rows, $existing, $p) {
            foreach ($rows as $pos => $r) {
                if ($r['id'] && isset($existing[$r['id']])) {
                    $was = $existing[$r['id']]['type'];
                    DB::update('statuses', ['name' => $r['name'], 'color' => $r['color'], 'type' => $r['type'], 'position' => $pos], 'id = ?', [$r['id']]);
                    // Becoming "done" completes its tasks now; leaving "done" re-opens them.
                    if ($was !== 'done' && $r['type'] === 'done') {
                        DB::query('UPDATE tasks SET completed_at = ? WHERE status_id = ? AND completed_at IS NULL', [now(), $r['id']]);
                    } elseif ($was === 'done' && $r['type'] !== 'done') {
                        DB::query('UPDATE tasks SET completed_at = NULL WHERE status_id = ?', [$r['id']]);
                    }
                } elseif (!$r['id']) {
                    DB::insert('statuses', ['project_id' => $p['id'], 'name' => $r['name'], 'color' => $r['color'], 'type' => $r['type'], 'position' => $pos]);
                }
            }
        });
        flash('success', 'Statuses saved.');
        redirect($back);
    }

    /** Swap this project's stages for a template; tasks keep their progress (see Tasks::applyTemplate). */
    public function applyTemplate(string $org, string $project): never
    {
        $o = Access::org($org);
        $p = Access::project((int) $project);
        Access::require('manage_statuses');
        $key = (string) input('template');
        if (!isset(Tasks::TEMPLATES[$key])) {
            flash('error', 'Pick a template.');
            redirect("/o/{$o['slug']}/p/{$p['id']}/settings");
        }
        $moved = Tasks::applyTemplate((int) $p['id'], $key);
        flash('success', 'Stages switched to “' . Tasks::TEMPLATES[$key]['label'] . '”' . ($moved ? ", {$moved} tasks moved to matching stages." : '.'));
        redirect("/o/{$o['slug']}/p/{$p['id']}/settings");
    }

    public function deleteStatus(string $org, string $project, string $status): never
    {
        $o = Access::org($org);
        $p = Access::project((int) $project);
        Access::require('manage_statuses');
        $back = "/o/{$o['slug']}/p/{$p['id']}/settings";
        $s = Tasks::status((int) $p['id'], $status);
        $all = Tasks::statuses((int) $p['id']);
        if (count($all) < 2) {
            flash('error', 'A project needs at least one status.');
            redirect($back);
        }
        $count = (int) DB::value('SELECT COUNT(*) FROM tasks WHERE status_id = ?', [$s['id']]);
        $target = null;
        if ($count) {
            $target = Tasks::status((int) $p['id'], input('move_to'));
            if ((int) $target['id'] === (int) $s['id']) {
                flash('error', 'Pick a different status to move its tasks to.');
                redirect($back);
            }
        }
        DB::transaction(function () use ($s, $target) {
            if ($target) {
                $completed = $target['type'] === 'done' ? now() : null;
                if ($target['type'] === 'done') {
                    DB::query('UPDATE tasks SET status_id = ?, completed_at = COALESCE(completed_at, ?) WHERE status_id = ?', [$target['id'], $completed, $s['id']]);
                } else {
                    DB::query('UPDATE tasks SET status_id = ?, completed_at = NULL WHERE status_id = ?', [$target['id'], $s['id']]);
                }
            }
            DB::query('DELETE FROM statuses WHERE id = ?', [$s['id']]);
        });
        flash('success', "“{$s['name']}” deleted" . ($target ? ", its tasks moved to {$target['name']}." : '.'));
        redirect($back);
    }
}
