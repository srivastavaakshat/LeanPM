<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database as DB;

/**
 * The signed-in user's context for one request: which organization, their membership and
 * permissions there, and which projects they can open. Every controller goes through this,
 * so access is checked on the server, never only by hiding buttons.
 */
final class Access
{
    public static ?array $org = null;
    public static ?array $member = null;   // membership row + 'perms' (array) + 'project_ids' (null = all)

    /**
     * LeanPM owners manage the organizations themselves (create, rename, delete, add an admin)
     * and the list of owners. That's all: inside an org they need a membership like anyone else.
     */
    public static function owner(): void
    {
        if (!Auth::isOwner()) {
            abort(403, 'Only a LeanPM owner can do this.');
        }
    }

    /** Load an organization by slug for the current user, or 404. */
    public static function org(string $slug): array
    {
        $org = DB::one('SELECT * FROM organizations WHERE slug = ?', [$slug]);
        if (!$org) {
            abort(404);
        }
        return self::enter($org);
    }

    public static function orgById(int $id): array
    {
        $org = DB::one('SELECT * FROM organizations WHERE id = ?', [$id]);
        if (!$org) {
            abort(404);
        }
        return self::enter($org);
    }

    private static function enter(array $org): array
    {
        $uid = Auth::id();
        $m = DB::one('SELECT * FROM memberships WHERE org_id = ? AND user_id = ?', [$org['id'], $uid]);
        // Being a LeanPM owner gives no access inside an organization; only a membership does.
        if (!$m || !(int) $m['active']) {
            abort(404);
        }
        $m['perms'] = Perms::decode($m['permissions'], $m['preset']);
        $m['project_ids'] = (int) $m['all_projects'] ? null
            : array_map('intval', array_column(DB::all('SELECT project_id FROM membership_projects WHERE membership_id = ?', [$m['id']]), 'project_id'));
        self::$org = $org;
        self::$member = $m;
        $_SESSION['last_org'] = $org['slug'];
        return $org;
    }

    public static function perms(): array
    {
        return self::$member['perms'] ?? [];
    }

    /** A bool permission is on, or a scoped one is at least "own". */
    public static function can(string $key): bool
    {
        $v = self::perms()[$key] ?? false;
        return $v === true || $v === 'own' || $v === 'all';
    }

    public static function level(string $key): string
    {
        $v = self::perms()[$key] ?? 'none';
        return is_bool($v) ? ($v ? 'all' : 'none') : $v;
    }

    public static function require(string $key): void
    {
        if (!self::can($key)) {
            abort(403);
        }
    }

    /** Does a scoped permission cover this task? */
    public static function canTask(string $key, array $task): bool
    {
        return match (self::level($key)) {
            'all'   => true,
            'own'   => self::isOwn($task),
            default => false,
        };
    }

    public static function requireTask(string $key, array $task): void
    {
        if (!self::canTask($key, $task)) {
            abort(403);
        }
    }

    public static function isOwn(array $task): bool
    {
        $uid = Auth::id();
        if ((int) ($task['created_by'] ?? 0) === $uid) {
            return true;
        }
        $assignees = $task['assignee_ids'] ?? array_map('intval', array_column(DB::all('SELECT user_id FROM task_assignees WHERE task_id = ?', [$task['id']]), 'user_id'));
        return in_array($uid, $assignees, true);
    }

    public static function canSeeProject(int $projectId): bool
    {
        $ids = self::$member['project_ids'] ?? null;
        // Everyone in the org can see the Inbox (tasks not sorted into a project yet).
        return $ids === null || in_array($projectId, $ids, true) || $projectId === self::inboxId();
    }

    private static array $inboxIds = [];

    /** The org's Inbox project id (created the first time it's needed). */
    public static function inboxId(): int
    {
        $orgId = (int) (self::$org['id'] ?? 0);
        if (!$orgId) {
            return 0;
        }
        if (!isset(self::$inboxIds[$orgId])) {
            $id = (int) DB::value('SELECT id FROM projects WHERE org_id = ? AND is_inbox = 1 ORDER BY id LIMIT 1', [$orgId]);
            if (!$id) {
                $id = DB::insert('projects', ['org_id' => $orgId, 'name' => 'Inbox', 'is_inbox' => 1, 'created_by' => Auth::id(), 'created_at' => now()]);
                foreach ([['To do', '#8b8f98', 'open'], ['Doing', '#0ea5e9', 'active'], ['Done', '#22c55e', 'done']] as $i => [$n, $c, $t]) {
                    DB::insert('statuses', ['project_id' => $id, 'name' => $n, 'color' => $c, 'type' => $t, 'position' => $i]);
                }
            }
            self::$inboxIds[$orgId] = $id;
        }
        return self::$inboxIds[$orgId];
    }

    /** Projects this member can open in the current org (never the Inbox; see inboxId()). */
    public static function projects(bool $includeArchived = false): array
    {
        $rows = DB::all('SELECT * FROM projects WHERE org_id = ? AND is_inbox = 0' . ($includeArchived ? '' : ' AND archived = 0') . ' ORDER BY name', [self::$org['id']]);
        return array_values(array_filter($rows, fn ($p) => self::canSeeProject((int) $p['id'])));
    }

    /** A project in the current org that this member can open, or 404. */
    public static function project(int $id): array
    {
        $p = DB::one('SELECT * FROM projects WHERE id = ? AND org_id = ?', [$id, self::$org['id']]);
        if (!$p || !self::canSeeProject((int) $p['id'])) {
            abort(404);
        }
        return $p;
    }

    /** Load a (not deleted) task by id and enter its organization; 404 when not visible. */
    public static function task(int $id, bool $withDeleted = false): array
    {
        $task = DB::one('SELECT t.*, p.org_id FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ?' . ($withDeleted ? '' : ' AND t.deleted_at IS NULL'), [$id]);
        if (!$task) {
            abort(404, 'This task no longer exists.');
        }
        self::orgById((int) $task['org_id']);
        if (!self::canSeeProject((int) $task['project_id'])) {
            abort(404, 'This task no longer exists.');
        }
        $task['assignee_ids'] = array_map('intval', array_column(DB::all('SELECT user_id FROM task_assignees WHERE task_id = ?', [$id]), 'user_id'));
        return $task;
    }

    /** Organizations the current user can open. */
    public static function myOrgs(): array
    {
        return DB::all('SELECT o.* FROM organizations o JOIN memberships m ON m.org_id = o.id WHERE m.user_id = ? AND m.active = 1 ORDER BY o.name', [Auth::id()]);
    }

    /** Active members of the current org (for assignee pickers and filters). */
    public static function members(bool $includeInactive = false): array
    {
        return DB::all(
            'SELECT u.id, u.name, u.email, u.color, m.active FROM users u JOIN memberships m ON m.user_id = u.id
             WHERE m.org_id = ?' . ($includeInactive ? '' : ' AND m.active = 1') . ' ORDER BY u.name',
            [self::$org['id']]
        );
    }
}
