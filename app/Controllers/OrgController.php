<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\Access;
use App\Services\Activity;

final class OrgController
{
    /** Visitors see the landing page; signed-in people open the last organization used, else the first one. */
    public function home(): string
    {
        if (!Auth::user()) {
            header_remove('X-Robots-Tag'); // the one page search engines may list
            return view('landing', ['repo' => (string) config('repo_url', 'https://github.com/srivastavaakshat/LeanPM')], null);
        }
        Auth::enforce();
        $orgs = Access::myOrgs();
        if (!$orgs) {
            if (Auth::isOwner()) {
                redirect('/admin');
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
        Access::owner();
        return view('org/new', ['title' => 'New organization']);
    }

    public function store(): never
    {
        Access::owner();
        $name = mb_substr((string) input('name', ''), 0, 120);
        $project = mb_substr((string) input('project', ''), 0, 120) ?: 'Design';
        $adminName = mb_substr(trim((string) input('admin_name', '')), 0, 120);
        $adminEmail = mb_strtolower(trim((string) input('admin_email', '')));
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Give the organization a name.';
        }
        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = 'Enter a valid email address, or leave it empty.';
        }
        $isMe = $adminEmail !== '' && $adminEmail === mb_strtolower((string) Auth::user()['email']);
        if ($adminEmail !== '' && !$isMe && $adminName === '' && !DB::value('SELECT COUNT(*) FROM users WHERE email = ?', [$adminEmail])) {
            $errors['admin_name'] = 'Enter their name.';
        }
        if ($errors) {
            with_input_errors($errors, $_POST);
            redirect('/orgs/new');
        }
        $slug = slugify($name);
        $base = $slug;
        for ($i = 2; DB::value('SELECT COUNT(*) FROM organizations WHERE slug = ?', [$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        $template = \App\Services\Tasks::template((string) input('template'));
        [$orgId, $projectId] = DB::transaction(function () use ($name, $slug, $project, $template) {
            // The owner creating it is not added: they join only if they name themselves as the admin.
            $orgId = DB::insert('organizations', ['name' => $name, 'slug' => $slug, 'created_by' => Auth::id(), 'created_at' => now()]);
            $pid = DB::insert('projects', ['org_id' => $orgId, 'name' => $project, 'created_by' => Auth::id(), 'created_at' => now()]);
            \App\Services\Tasks::createDefaultStatuses($pid, $template);
            \App\Services\Fields::addTemplate($pid, $template);
            return [$orgId, $pid];
        });
        $o = DB::one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
        if ($adminEmail === '') {
            flash('success', "{$name} is ready. It has no members yet: use “Add admin” to invite the person who will run it.");
            redirect('/admin');
        }
        AdminController::addAdmin($o, $adminName, $adminEmail);
        if ($isMe) {
            flash('success', "{$name} is ready and you’re its admin. Add your team from Members.");
            redirect("/o/{$slug}/p/{$projectId}");
        }
        redirect('/admin');
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
