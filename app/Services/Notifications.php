<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database as DB;

/**
 * In-app notifications. Created when someone else:
 *   mention  - @mentions you in a comment or a task description
 *   comment  - comments on a task you're assigned to or created (unless that comment mentions you)
 *   assigned - assigns a task to you
 *   done     - completes a task you're assigned to or created
 * Only people who can open the task's project are notified, never the person who acted.
 */
final class Notifications
{
    public const TYPES = ['mention', 'comment', 'assigned', 'done'];

    private static ?bool $ready = null;

    /** False until migration 008 has run, so a deploy before the migration never takes the site down. */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                DB::one('SELECT id FROM notifications LIMIT 1');
                self::$ready = true;
            } catch (\Throwable) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** Notify $userIds about $task. Returns the ids actually notified. */
    public static function send(array $task, array $userIds, string $type, ?int $commentId = null, ?string $excerpt = null): array
    {
        $actor = Auth::id();
        $orgId = (int) (Access::$org['id'] ?? 0);
        $userIds = array_values(array_diff(array_unique(array_map('intval', $userIds)), [$actor]));
        if (!$userIds || !$orgId || !self::ready()) {
            return [];
        }
        $allowed = Mentions::candidates($orgId, (int) $task['project_id']);
        $excerpt = $excerpt !== null ? trim(preg_replace('/\s+/u', ' ', $excerpt) ?? '') : null;
        if ($excerpt !== null && mb_strlen($excerpt) > 280) {
            $excerpt = mb_substr($excerpt, 0, 280) . '…';
        }
        $sent = [];
        foreach ($userIds as $uid) {
            if (!isset($allowed[$uid])) {
                continue;
            }
            DB::insert('notifications', [
                'org_id' => $orgId, 'user_id' => $uid, 'actor_id' => $actor, 'task_id' => (int) $task['id'],
                'comment_id' => $commentId, 'type' => $type, 'excerpt' => $excerpt ?: null, 'created_at' => now(),
            ]);
            $sent[] = $uid;
        }
        return $sent;
    }

    /** The people "on" a task: its assignees and whoever created it. */
    public static function people(array $task): array
    {
        $ids = array_map('intval', array_column(DB::all('SELECT user_id FROM task_assignees WHERE task_id = ?', [$task['id']]), 'user_id'));
        if (!empty($task['created_by'])) {
            $ids[] = (int) $task['created_by'];
        }
        return array_values(array_unique($ids));
    }

    /** Unread count for the sidebar badge (only tasks you can still open). */
    public static function unread(): int
    {
        if (!Access::$org || !self::ready()) {
            return 0;
        }
        return count(array_filter(self::rows(onlyUnread: true, limit: 100)));
    }

    /** Your notifications in the current org, newest first, for tasks you can still open. */
    public static function rows(bool $onlyUnread = false, int $limit = 200): array
    {
        if (!self::ready()) {
            return [];
        }
        $rows = DB::all(
            'SELECT n.*, t.title AS task_title, t.project_id, t.parent_id, t.status_id, p.name AS project_name, p.is_inbox,
                    s.name AS status_name, s.color AS status_color, s.type AS status_type,
                    pt.title AS parent_title
             FROM notifications n
             JOIN tasks t ON t.id = n.task_id AND t.deleted_at IS NULL
             JOIN projects p ON p.id = t.project_id
             JOIN statuses s ON s.id = t.status_id
             LEFT JOIN tasks pt ON pt.id = t.parent_id
             WHERE n.user_id = ? AND n.org_id = ?' . ($onlyUnread ? ' AND n.read_at IS NULL' : '') . '
             ORDER BY n.created_at DESC, n.id DESC LIMIT ' . max(1, $limit),
            [Auth::id(), Access::$org['id']]
        );
        return array_values(array_filter($rows, fn ($r) => Access::canSeeProject((int) $r['project_id'])));
    }

    /** Opening a task counts as seeing what happened on it. */
    public static function readTask(int $taskId): void
    {
        if (!self::ready()) {
            return;
        }
        DB::query('UPDATE notifications SET read_at = ? WHERE user_id = ? AND task_id = ? AND read_at IS NULL', [now(), Auth::id(), $taskId]);
    }
}
