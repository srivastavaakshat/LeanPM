<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database as DB;

/**
 * @mentions in comments (plain text "@Full Name") and descriptions (<span class="mention" data-user="ID">).
 * Only people who can open the project can be mentioned; each newly mentioned person gets one email.
 */
final class Mentions
{
    /** Active members of the org who can open this project: [id => ['id', 'name', 'email', 'notify_mentions']]. */
    public static function candidates(int $orgId, int $projectId): array
    {
        $rows = DB::all(
            'SELECT u.id, u.name, u.email, u.notify_mentions, u.is_owner, m.all_projects, m.id AS membership_id
             FROM users u JOIN memberships m ON m.user_id = u.id
             WHERE m.org_id = ? AND m.active = 1',
            [$orgId]
        );
        $out = [];
        foreach ($rows as $r) {
            $ok = $r['is_owner'] || (int) $r['all_projects']
                || DB::value('SELECT 1 FROM membership_projects WHERE membership_id = ? AND project_id = ?', [$r['membership_id'], $projectId]);
            if ($ok) {
                $out[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'notify_mentions' => (int) $r['notify_mentions']];
            }
        }
        return $out;
    }

    /** User ids mentioned as "@Full Name" in plain text (longest names first, so "@Ravi Dutta" beats "@Ravi"). */
    public static function inText(string $text, array $candidates): array
    {
        $ids = [];
        foreach (self::byLongestName($candidates) as $c) {
            $re = '/@' . preg_quote($c['name'], '/') . '(?![\p{L}\p{N}])/iu';
            if (preg_match($re, $text)) {
                $ids[] = $c['id'];
                $text = preg_replace($re, '', $text) ?? $text;
            }
        }
        return $ids;
    }

    /** User ids in a (sanitized) description's mention chips. */
    public static function inHtml(?string $html, array $candidates): array
    {
        preg_match_all('/<span class="mention" data-user="(\d+)"/', (string) $html, $m);
        return array_values(array_intersect(array_unique(array_map('intval', $m[1])), array_keys($candidates)));
    }

    /** Wrap "@Full Name" of known people in a highlight, in already-escaped comment HTML. */
    public static function highlight(string $html, array $candidates): string
    {
        foreach (self::byLongestName($candidates) as $c) {
            $html = preg_replace('/(?<![\p{L}\p{N}>])@' . preg_quote(e($c['name']), '/') . '(?![\p{L}\p{N}])/iu', '<span class="mention">$0</span>', $html) ?? $html;
        }
        return $html;
    }

    /**
     * Email everyone in $userIds (except the author and anyone who turned it off) and note it in
     * the task's history. $where: 'comment' | 'description'.
     */
    public static function notify(array $task, array $userIds, string $where, string $excerpt, array $candidates): void
    {
        $me = Auth::user();
        $org = Access::$org;
        $project = DB::one('SELECT name FROM projects WHERE id = ?', [$task['project_id']]);
        $url = absolute_url("/o/{$org['slug']}/p/{$task['project_id']}", ['task' => $task['id']]);
        $excerpt = trim(preg_replace('/\s+/u', ' ', $excerpt) ?? '');
        if (mb_strlen($excerpt) > 400) {
            $excerpt = mb_substr($excerpt, 0, 400) . '…';
        }
        foreach (array_unique($userIds) as $uid) {
            $c = $candidates[$uid] ?? null;
            if (!$c || $uid === (int) $me['id']) {
                continue;
            }
            Activity::log((int) $task['id'], 'mentioned', null, $c['name']);
            if (!$c['notify_mentions']) {
                continue;
            }
            $place = $where === 'comment' ? 'a comment on' : 'the description of';
            $subject = "{$me['name']} mentioned you in “{$task['title']}”";
            $text = "{$me['name']} mentioned you in {$place} “{$task['title']}” ({$project['name']}, {$org['name']}):\n\n"
                . ($excerpt !== '' ? "“{$excerpt}”\n\n" : '')
                . "Open the task: {$url}\n\n"
                . "You get this email because you were @mentioned in LeanPM. Turn these emails off under Your account: " . absolute_url('/account');
            $html = \App\Core\View::partial('emails/mention', [
                'actor' => $me['name'], 'place' => $place, 'task' => $task['title'], 'project' => $project['name'],
                'org' => $org['name'], 'excerpt' => $excerpt, 'url' => $url, 'accountUrl' => absolute_url('/account'),
            ]);
            EmailQueue::push($c['email'], $c['name'], $subject, $text, $html);
        }
    }

    private static function byLongestName(array $candidates): array
    {
        $list = array_values($candidates);
        usort($list, fn ($a, $b) => mb_strlen($b['name']) <=> mb_strlen($a['name']));
        return $list;
    }
}
