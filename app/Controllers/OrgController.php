<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\Access;
use App\Services\Activity;
use App\Services\Perms;

final class OrgController
{
    /** Open the last organization used, else the first one. */
    public function home(): never
    {
        $orgs = Access::myOrgs();
        if (!$orgs) {
            if (Auth::isOwner()) {
                redirect('/orgs/new');
            }
            abort(403, 'You aren’t a member of any organization yet. Ask your admin to add you.');
        }
        $last = $_SESSION['last_org'] ?? null;
        foreach ($orgs as $o) {
            if ($o['slug'] === $last) {
                redirect('/o/' . $o['slug']);
            }
        }
        redirect('/o/' . $orgs[0]['slug']);
    }

    public function create(): string
    {
        if (!Auth::isOwner()) {
            abort(403, 'Only the Owner can create organizations.');
        }
        return view('org/new', ['title' => 'New organization']);
    }

    public function store(): never
    {
        if (!Auth::isOwner()) {
            abort(403);
        }
        $name = mb_substr((string) input('name', ''), 0, 120);
        $project = mb_substr((string) input('project', ''), 0, 120) ?: 'Design';
        if ($name === '') {
            with_input_errors(['name' => 'Give the organization a name.'], $_POST);
            redirect('/orgs/new');
        }
        $slug = slugify($name);
        $base = $slug;
        for ($i = 2; DB::value('SELECT COUNT(*) FROM organizations WHERE slug = ?', [$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        $projectId = DB::transaction(function () use ($name, $slug, $project) {
            $orgId = DB::insert('organizations', ['name' => $name, 'slug' => $slug, 'created_by' => Auth::id(), 'created_at' => now()]);
            DB::insert('memberships', [
                'org_id' => $orgId, 'user_id' => Auth::id(), 'preset' => 'admin',
                'permissions' => json_encode(Perms::all()), 'all_projects' => 1, 'active' => 1, 'joined_at' => now(),
            ]);
            $pid = DB::insert('projects', ['org_id' => $orgId, 'name' => $project, 'created_by' => Auth::id(), 'created_at' => now()]);
            \App\Services\Tasks::createDefaultStatuses($pid);
            return $pid;
        });
        flash('success', "{$name} is ready. Add your team from Members.");
        redirect("/o/{$slug}/p/{$projectId}");
    }

    /** The organization's first project, or an empty state. */
    public function show(string $org): string
    {
        $o = Access::org($org);
        $projects = Access::projects();
        if ($projects) {
            redirect("/o/{$o['slug']}/p/{$projects[0]['id']}");
        }
        return view('org/empty', ['title' => $o['name']]);
    }

    public function settings(string $org): string
    {
        Access::org($org);
        Access::require('org_settings');
        return view('org/settings', [
            'title'    => 'Organization settings',
            'archived' => DB::all('SELECT * FROM projects WHERE org_id = ? AND archived = 1 ORDER BY name', [Access::$org['id']]),
        ]);
    }

    public function updateSettings(string $org): never
    {
        $o = Access::org($org);
        Access::require('org_settings');
        $name = mb_substr((string) input('name', ''), 0, 120);
        if ($name === '') {
            with_input_errors(['name' => 'The name can’t be empty.']);
            redirect("/o/{$o['slug']}/settings");
        }
        DB::update('organizations', ['name' => $name], 'id = ?', [$o['id']]);
        flash('success', 'Saved.');
        redirect("/o/{$o['slug']}/settings");
    }

    /** Deleted tasks (and their subtasks) can be restored by the Owner or anyone who can delete any task. */
    public function trash(string $org): string
    {
        $o = Access::org($org);
        if (Access::level('delete_tasks') !== 'all') {
            abort(403);
        }
        $rows = DB::all(
            'SELECT t.id, t.title, t.deleted_at, t.project_id, p.name AS project, u.name AS deleted_by_name
             FROM tasks t JOIN projects p ON p.id = t.project_id LEFT JOIN users u ON u.id = t.deleted_by
             WHERE p.org_id = ? AND t.deleted_at IS NOT NULL
               AND (t.parent_id IS NULL OR t.parent_id NOT IN (SELECT id FROM tasks WHERE deleted_at = t.deleted_at))
             ORDER BY t.deleted_at DESC LIMIT 300',
            [$o['id']]
        );
        $rows = array_values(array_filter($rows, fn ($r) => Access::canSeeProject((int) $r['project_id'])));
        return view('org/trash', ['title' => 'Trash', 'rows' => $rows]);
    }

    public function restore(string $org, string $task): never
    {
        $o = Access::org($org);
        if (Access::level('delete_tasks') !== 'all') {
            abort(403);
        }
        $t = Access::task((int) $task, withDeleted: true);
        if ((int) $t['org_id'] !== (int) $o['id'] || $t['deleted_at'] === null) {
            abort(404);
        }
        DB::transaction(function () use ($t) {
            // Subtasks deleted together with the parent come back with it.
            DB::query('UPDATE tasks SET deleted_at = NULL, deleted_by = NULL WHERE parent_id = ? AND deleted_at = ?', [$t['id'], $t['deleted_at']]);
            $parentGone = $t['parent_id'] && DB::value('SELECT deleted_at FROM tasks WHERE id = ?', [$t['parent_id']]);
            DB::update('tasks', ['deleted_at' => null, 'deleted_by' => null, 'parent_id' => $parentGone ? null : $t['parent_id'], 'updated_at' => now()], 'id = ?', [$t['id']]);
            Activity::log((int) $t['id'], 'restored');
        });
        flash('success', '“' . $t['title'] . '” is back on its board.');
        redirect("/o/{$o['slug']}/trash");
    }
}
