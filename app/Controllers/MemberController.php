<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\Access;
use App\Services\Perms;

/** Add members and choose exactly what each one can do (PRD §5.1a). */
final class MemberController
{
    public function index(string $org): string
    {
        $o = Access::org($org);
        $rows = DB::all(
            'SELECT m.*, u.name, u.email, u.color, u.is_owner, u.last_login_at, u.must_change_password
             FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.org_id = ? ORDER BY m.active DESC, u.name',
            [$o['id']]
        );
        foreach ($rows as &$r) {
            $r['perms'] = Perms::decode($r['permissions'], $r['preset']);
            $r['label'] = $r['is_owner'] ? 'Owner' : Perms::label($r['preset'], $r['perms']);
        }
        return view('members/index', ['title' => 'Members', 'rows' => $rows, 'canManage' => Access::can('manage_members')]);
    }

    public function create(string $org): string
    {
        Access::org($org);
        Access::require('manage_members');
        $preset = array_key_exists((string) old('preset'), Perms::PRESETS) ? (string) old('preset') : 'designer';
        return view('members/form', [
            'title'    => 'Add member',
            'member'   => null,
            'preset'   => $preset,
            'perms'    => Perms::clamp(Perms::normalize(Perms::PRESETS[$preset]['perms']), Access::perms()),
            'allProjects' => true,
            'projectIds'  => [],
            'projects' => Access::projects(true),
        ]);
    }

    public function store(string $org): never
    {
        $o = Access::org($org);
        Access::require('manage_members');
        $name = mb_substr((string) input('name', ''), 0, 120);
        $email = mb_strtolower((string) input('email', ''));
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Enter their name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        $user = $email ? DB::one('SELECT * FROM users WHERE email = ?', [$email]) : null;
        if ($user && DB::one('SELECT id FROM memberships WHERE org_id = ? AND user_id = ?', [$o['id'], $user['id']])) {
            $errors['email'] = 'They’re already a member here. Open them from the Members list.';
        }
        if ($errors) {
            with_input_errors($errors, $_POST);
            redirect("/o/{$o['slug']}/members/new");
        }
        [$preset, $perms, $all, $projectIds] = $this->submitted();

        $password = null;
        DB::transaction(function () use ($o, $name, $email, &$user, &$password, $preset, $perms, $all, $projectIds) {
            if (!$user) {
                $password = random_password();
                $id = DB::insert('users', [
                    'name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'color' => random_color(), 'is_owner' => 0, 'must_change_password' => 1, 'created_at' => now(),
                ]);
                $user = ['id' => $id, 'name' => $name];
            }
            $mid = DB::insert('memberships', [
                'org_id' => $o['id'], 'user_id' => $user['id'], 'preset' => $preset, 'permissions' => json_encode($perms),
                'all_projects' => $all ? 1 : 0, 'active' => 1, 'joined_at' => now(),
            ]);
            $this->saveProjects($mid, $all, $projectIds);
        });

        if ($password) {
            // Shown once, so the admin can pass it on. The member must change it at first sign-in.
            $_SESSION['new_credentials'] = ['name' => $name, 'email' => $email, 'password' => $password, 'url' => absolute_url('/login')];
        } else {
            flash('success', "{$user['name']} already had an account, so they can sign in with their usual password.");
        }
        redirect("/o/{$o['slug']}/members");
    }

    public function edit(string $org, string $member): string
    {
        $o = Access::org($org);
        Access::require('manage_members');
        $m = $this->load($o, (int) $member);
        $perms = Perms::decode($m['permissions'], $m['preset']);
        return view('members/form', [
            'title'       => $m['name'],
            'member'      => $m,
            'preset'      => $m['preset'],
            'perms'       => $perms,
            'allProjects' => (bool) $m['all_projects'],
            'projectIds'  => array_map('intval', array_column(DB::all('SELECT project_id FROM membership_projects WHERE membership_id = ?', [$m['id']]), 'project_id')),
            'projects'    => Access::projects(true),
        ]);
    }

    public function update(string $org, string $member): never
    {
        $o = Access::org($org);
        Access::require('manage_members');
        $m = $this->load($o, (int) $member, forChange: true);
        [$preset, $perms, $all, $projectIds] = $this->submitted();
        if (Access::$member['project_ids'] !== null) {
            $all = (bool) $m['all_projects']; // a manager limited to some projects can't change "all projects"
        }
        DB::transaction(function () use ($m, $preset, $perms, $all, $projectIds) {
            DB::update('memberships', ['preset' => $preset, 'permissions' => json_encode($perms), 'all_projects' => $all ? 1 : 0], 'id = ?', [$m['id']]);
            $this->saveProjects((int) $m['id'], $all, $projectIds);
        });
        flash('success', "{$m['name']}’s permissions saved. They apply on their next page load.");
        redirect("/o/{$o['slug']}/members");
    }

    public function toggleActive(string $org, string $member): never
    {
        $o = Access::org($org);
        Access::require('manage_members');
        $m = $this->load($o, (int) $member, forChange: true);
        $active = (int) $m['active'] ? 0 : 1;
        DB::update('memberships', ['active' => $active], 'id = ?', [$m['id']]);
        flash('success', $active ? "{$m['name']} is active again." : "{$m['name']} is deactivated. Their tasks and comments stay.");
        redirect("/o/{$o['slug']}/members");
    }

    public function resetPassword(string $org, string $member): never
    {
        $o = Access::org($org);
        Access::require('manage_members');
        $m = $this->load($o, (int) $member, forChange: true);
        $password = random_password();
        DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change_password' => 1], 'id = ?', [$m['user_id']]);
        $_SESSION['new_credentials'] = ['name' => $m['name'], 'email' => $m['email'], 'password' => $password, 'url' => absolute_url('/login'), 'reset' => true];
        redirect("/o/{$o['slug']}/members");
    }

    /** A membership of this org. Changes are refused for the Owner and for yourself. */
    private function load(array $o, int $id, bool $forChange = false): array
    {
        $m = DB::one('SELECT m.*, u.name, u.email, u.color, u.is_owner FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.id = ? AND m.org_id = ?', [$id, $o['id']]);
        if (!$m) {
            abort(404);
        }
        if ($m['is_owner']) {
            abort(403, 'The Owner always has every permission.');
        }
        if ($forChange && (int) $m['user_id'] === Auth::id()) {
            abort(403, 'You can’t change your own permissions. Ask another admin.');
        }
        return $m;
    }

    /** Read the form, then clamp to what the granter has: nobody can hand out more than they hold. */
    private function submitted(): array
    {
        $preset = array_key_exists((string) input('preset'), Perms::PRESETS) ? (string) input('preset') : 'designer';
        $perms = Perms::clamp(Perms::normalize((array) ($_POST['perms'] ?? [])), Access::perms());
        $limited = Access::$member['project_ids'];
        $all = input('all_projects') === '1' && $limited === null;
        $ids = array_map('intval', (array) ($_POST['projects'] ?? []));
        $valid = array_map(fn ($p) => (int) $p['id'], Access::projects(true));
        $ids = array_values(array_intersect($ids, $valid));
        return [$preset, $perms, $all, $ids];
    }

    /** Only touches projects the granter can see; access to others is left as it was. */
    private function saveProjects(int $membershipId, bool $all, array $ids): void
    {
        $visible = array_map(fn ($p) => (int) $p['id'], Access::projects(true));
        if ($visible) {
            DB::query('DELETE FROM membership_projects WHERE membership_id = ? AND project_id IN (' . DB::placeholders($visible) . ')', array_merge([$membershipId], $visible));
        }
        if (!$all) {
            foreach ($ids as $pid) {
                DB::insert('membership_projects', ['membership_id' => $membershipId, 'project_id' => $pid]);
            }
        }
    }
}
