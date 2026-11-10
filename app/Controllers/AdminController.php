<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\AccountEmails;
use App\Services\Access;
use App\Services\Members;
use App\Services\Perms;

/**
 * The LeanPM owners' console: every organization (create, rename, delete, add an admin) and the
 * list of owners. Owners see no projects or tasks here; inside an org they need a membership.
 */
final class AdminController
{
    public function index(): string
    {
        Access::owner();
        $orgs = DB::all(
            'SELECT o.*,
                (SELECT COUNT(*) FROM memberships m WHERE m.org_id = o.id AND m.active = 1) AS members,
                (SELECT COUNT(*) FROM projects p WHERE p.org_id = o.id AND p.is_inbox = 0 AND p.archived = 0) AS projects,
                (SELECT COUNT(*) FROM tasks t JOIN projects p ON p.id = t.project_id WHERE p.org_id = o.id AND t.deleted_at IS NULL) AS tasks,
                (SELECT m.active FROM memberships m WHERE m.org_id = o.id AND m.user_id = ?) AS my_membership
             FROM organizations o ORDER BY o.name',
            [Auth::id()]
        );
        foreach ($orgs as &$o) {
            $o['admins'] = DB::all(
                "SELECT u.name, u.email FROM memberships m JOIN users u ON u.id = m.user_id
                 WHERE m.org_id = ? AND m.active = 1 AND m.preset = 'admin' ORDER BY u.name",
                [$o['id']]
            );
        }
        unset($o);
        return view('admin/index', [
            'title'  => 'Manage LeanPM',
            'orgs'   => $orgs,
            'owners' => DB::all('SELECT id, name, email, color, last_login_at FROM users WHERE is_owner = 1 ORDER BY name'),
        ]);
    }

    public function rename(string $id): never
    {
        Access::owner();
        $o = $this->org((int) $id);
        $name = mb_substr(trim((string) input('name', '')), 0, 120);
        if ($name === '') {
            flash('error', 'The name can’t be empty.');
            redirect('/admin');
        }
        DB::update('organizations', ['name' => $name], 'id = ?', [$o['id']]);
        flash('success', "Renamed to {$name}.");
        redirect('/admin');
    }

    /** Invite (or add) someone as an admin of an org: how an owner hands an org to the person who runs it. */
    public function storeAdmin(string $id): never
    {
        Access::owner();
        $o = $this->org((int) $id);
        $name = mb_substr(trim((string) input('name', '')), 0, 120);
        $email = mb_strtolower(trim((string) input('email', '')));
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? DB::one('SELECT id, name FROM users WHERE email = ?', [$email]) : null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || (!$user && $name === '')) {
            flash('error', 'Enter their name and a valid email address.');
            redirect('/admin');
        }
        $m = $user ? DB::one('SELECT * FROM memberships WHERE org_id = ? AND user_id = ?', [$o['id'], $user['id']]) : null;
        if ($m) {
            // Already in: make them an admin with everything, active again if they'd been turned off.
            DB::update('memberships', ['preset' => 'admin', 'permissions' => json_encode(Perms::all()), 'all_projects' => 1, 'active' => 1], 'id = ?', [$m['id']]);
            flash('success', "{$user['name']} is now an admin of {$o['name']}.");
            redirect('/admin');
        }
        self::addAdmin($o, $name, $email);
        redirect('/admin');
    }

    /** Shared with creating an org. Shows the invite link on the console for a brand-new account. */
    public static function addAdmin(array $o, string $name, string $email): array
    {
        $added = Members::add($o, $name, $email, 'admin', Perms::all(), true, [], (string) Auth::user()['name']);
        if ($added['new']) {
            $_SESSION['admin_credentials'] = ['name' => $name, 'email' => $email, 'link' => $added['link'], 'what' => "admin of {$o['name']}"];
        } elseif ((int) $added['user']['id'] !== Auth::id()) {
            flash('success', "{$added['user']['name']} already had an account, so they’re now an admin of {$o['name']} and sign in as usual. We emailed them.");
        }
        return $added;
    }

    /**
     * Permanently delete an organization and everything in it: projects, tasks, comments, files
     * and memberships. Confirmed by typing its name. People's accounts stay (they may be elsewhere).
     */
    public function destroy(string $id): never
    {
        Access::owner();
        $o = $this->org((int) $id);
        if (trim((string) input('confirm_name')) !== $o['name']) {
            flash('error', 'The name you typed didn’t match, so nothing was deleted.');
            redirect('/admin');
        }
        DB::transaction(function () use ($o) {
            // Tasks first (their comments, files, assignees and history go with them), then the
            // organization (its memberships, projects and stages go with it).
            DB::query('UPDATE tasks SET parent_id = NULL WHERE project_id IN (SELECT id FROM projects WHERE org_id = ?)', [$o['id']]);
            DB::query('DELETE FROM tasks WHERE project_id IN (SELECT id FROM projects WHERE org_id = ?)', [$o['id']]);
            DB::query('DELETE FROM organizations WHERE id = ?', [$o['id']]);
        });
        self::removeDir(config('storage_path') . '/uploads/' . (int) $o['id']);
        if (($_SESSION['last_org'] ?? null) === $o['slug']) {
            unset($_SESSION['last_org']);
        }
        flash('success', "“{$o['name']}” and everything in it was deleted.");
        redirect('/admin');
    }

    /** Make someone an owner. A new email gets an account and an invite link. */
    public function storeOwner(): never
    {
        Access::owner();
        $name = mb_substr(trim((string) input('name', '')), 0, 120);
        $email = mb_strtolower(trim((string) input('email', '')));
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? DB::one('SELECT * FROM users WHERE email = ?', [$email]) : null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || (!$user && $name === '')) {
            flash('error', 'Enter their name and a valid email address.');
            redirect('/admin');
        }
        $by = (string) Auth::user()['name'];
        if ($user) {
            if ((int) $user['is_owner']) {
                flash('success', "{$user['name']} is already an owner.");
                redirect('/admin');
            }
            DB::update('users', ['is_owner' => 1], 'id = ?', [$user['id']]);
            AccountEmails::madeOwner($user, $by, null);
            flash('success', "{$user['name']} is now a LeanPM owner. They sign in with their usual password. We emailed them.");
            redirect('/admin');
        }
        $id = DB::insert('users', [
            'name' => $name, 'email' => $email, 'password_hash' => password_hash(random_password(32), PASSWORD_DEFAULT),
            'color' => random_color(), 'is_owner' => 1, 'must_change_password' => 1, 'created_at' => now(),
        ]);
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        $link = AccountEmails::link($id, 'invite');
        AccountEmails::madeOwner($user, $by, $link);
        $_SESSION['admin_credentials'] = ['name' => $name, 'email' => $email, 'link' => $link, 'what' => 'a LeanPM owner'];
        redirect('/admin');
    }

    /** Take away owner rights. Never yourself, so there is always at least one owner. */
    public function removeOwner(string $id): never
    {
        Access::owner();
        $user = DB::one('SELECT * FROM users WHERE id = ? AND is_owner = 1', [(int) $id]);
        if (!$user) {
            abort(404);
        }
        if ((int) $user['id'] === Auth::id()) {
            abort(403, 'You can’t remove yourself as an owner. Ask another owner.');
        }
        DB::update('users', ['is_owner' => 0], 'id = ?', [$user['id']]);
        flash('success', "{$user['name']} is no longer an owner. They keep any organization memberships they have.");
        redirect('/admin');
    }

    private function org(int $id): array
    {
        $o = DB::one('SELECT * FROM organizations WHERE id = ?', [$id]);
        if (!$o) {
            abort(404);
        }
        return $o;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
