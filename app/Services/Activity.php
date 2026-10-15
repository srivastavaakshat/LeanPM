<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database as DB;

/** The task history shown in the Activity panel. */
final class Activity
{
    public static function log(int $taskId, string $action, ?string $from = null, ?string $to = null): void
    {
        DB::insert('activity', [
            'task_id'    => $taskId,
            'user_id'    => Auth::id(),
            'action'     => $action,
            'from_value' => $from === null ? null : mb_substr($from, 0, 255),
            'to_value'   => $to === null ? null : mb_substr($to, 0, 255),
            'created_at' => now(),
        ]);
    }

    /** One readable line, e.g. "changed status from In Progress to For Review". */
    public static function describe(array $a): string
    {
        $from = $a['from_value'];
        $to = $a['to_value'];
        return match ($a['action']) {
            'created'          => 'created this task',
            'status'           => "changed status from {$from} to {$to}",
            'title'            => "renamed this task to “{$to}”",
            'description'      => 'updated the description',
            'assignee_added'   => "assigned {$to}",
            'assignee_removed' => "unassigned {$from}",
            'priority'         => $to ? "set priority to {$to}" : 'cleared the priority',
            'start_date'       => $to ? 'set the start date to ' . fmt_date($to) : 'removed the start date',
            'due_date'         => $to ? 'set the due date to ' . fmt_date($to) : 'removed the due date',
            'subtask_added'    => "added subtask “{$to}”",
            'subtask_removed'  => "removed subtask “{$from}”",
            'parent_set'       => "made this a subtask of “{$to}”",
            'parent_removed'   => "detached this from “{$from}”",
            'mentioned'        => "mentioned {$to}",
            'field'            => $to === null ? "cleared {$from}" : "set {$from} to {$to}",
            'file_added'       => "attached {$to}",
            'file_removed'     => "removed {$from}",
            'deleted'          => 'moved this task to Trash',
            'restored'         => 'restored this task from Trash',
            default            => $a['action'],
        };
    }
}
