<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\Access;
use App\Services\Notifications;
use App\Services\Tasks;

/** The Notifications page, plus marking them read. Everyone only ever sees and changes their own. */
final class NotificationController
{
    public function index(string $org): string
    {
        $o = Access::org($org);
        $members = Access::members(includeInactive: true);
        $boot = [
            'org'      => $o['slug'],
            'items'    => array_map([self::class, 'item'], Notifications::rows()),
            'members'  => array_map(fn ($m) => ['id' => (int) $m['id'], 'name' => $m['name'], 'color' => $m['color'], 'initials' => initials($m['name']), 'active' => (bool) $m['active']], $members),
            'priorities' => Tasks::PRIORITIES,
            'maxUpload' => (int) config('upload_max_mb', 25),
            'me'       => Auth::id(),
            'base'     => url('/'),
            'csrf'     => csrf_token(),
            'today'    => date('Y-m-d'),
            'now'      => date('Y-m-d H:i:s'),
        ];
        return view('notifications/index', ['title' => 'Notifications', 'boot' => $boot]);
    }

    /** Unread count, for the sidebar badge to refresh without reloading. */
    public function count(string $org): never
    {
        Access::org($org);
        json_response(['unread' => Notifications::unread()]);
    }

    /** Mark some (ids) or all of your notifications read, or unread with read=false. */
    public function read(string $org): never
    {
        $o = Access::org($org);
        $in = json_input();
        $read = ($in['read'] ?? true) !== false;
        $where = 'user_id = ? AND org_id = ?';
        $args = [Auth::id(), $o['id']];
        if (empty($in['all'])) {
            $ids = array_slice(array_values(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))), 0, 500);
            if (!$ids) {
                abort(422, 'Nothing to mark.');
            }
            $where .= ' AND id IN (' . DB::placeholders($ids) . ')';
            $args = [...$args, ...$ids];
        }
        DB::query('UPDATE notifications SET read_at = ? WHERE ' . $where, [$read ? now() : null, ...$args]);
        json_response(['unread' => Notifications::unread()]);
    }

    private static function item(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'type'       => $r['type'],
            'actor'      => $r['actor_id'] !== null ? (int) $r['actor_id'] : null,
            'task_id'    => (int) $r['task_id'],
            'task'       => $r['task_title'],
            'parent'     => $r['parent_title'],
            'project'    => (int) $r['is_inbox'] ? 'Inbox' : $r['project_name'],
            'status'     => ['name' => $r['status_name'], 'color' => $r['status_color'], 'done' => $r['status_type'] === 'done'],
            'comment_id' => $r['comment_id'] !== null ? (int) $r['comment_id'] : null,
            'excerpt'    => $r['excerpt'],
            'read'       => $r['read_at'] !== null,
            'at'         => $r['created_at'],
            'url'        => url('/o/' . Access::$org['slug'] . '/p/' . $r['project_id'], ['task' => (int) $r['task_id']] + ($r['comment_id'] !== null ? ['comment' => (int) $r['comment_id']] : [])),
        ];
    }
}
