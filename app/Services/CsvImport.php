<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database as DB;

/**
 * Import tasks into a project from a CSV (PRD §5.11a). Works with LeanPM's own export,
 * ClickUp/Trello-style exports, or a hand-made sheet: columns are matched to fields by name
 * and the person confirms the mapping before anything is created.
 */
final class CsvImport
{
    public const MAX_ROWS = 2000;
    public const MAX_BYTES = 5 * 1048576;

    /** Built-in targets a column can map to. Custom fields are added as "field:ID". */
    public const TARGETS = [
        ''            => '— Don’t import —',
        'title'       => 'Title (required)',
        'description' => 'Description',
        'status'      => 'Stage / status',
        'priority'    => 'Priority',
        'assignees'   => 'Assignees (names or emails)',
        'start_date'  => 'Start date',
        'due_date'    => 'Due date',
        'created_at'  => 'Created date',
        'completed_at' => 'Completed date',
        'parent'      => 'Parent task (title)',
        'comment'     => 'Add as a comment',
    ];

    /** Header names (lower case) that map to each target, including ClickUp's and our own export's. */
    private const SYNONYMS = [
        'title'        => ['title', 'task', 'task name', 'name', 'task title', 'summary', 'card name'],
        'description'  => ['description', 'task content', 'content', 'details', 'notes', 'card description'],
        'status'       => ['status', 'stage', 'state', 'list', 'column'],
        'priority'     => ['priority'],
        'assignees'    => ['assignees', 'assignee', 'assigned to', 'owner', 'owners', 'members'],
        'start_date'   => ['start date', 'start', 'starts'],
        'due_date'     => ['due date', 'due', 'deadline', 'due on'],
        'created_at'   => ['created at', 'date created', 'created', 'created on', 'creation date'],
        'completed_at' => ['completed at', 'date closed', 'date done', 'completed', 'closed', 'done date'],
        'parent'       => ['parent task', 'parent', 'parent name', 'parent task name'],
        'comment'      => ['comment', 'comments', 'latest comment'],
    ];

    /** Read a CSV file: [headers, rows (lists of strings)]. Detects comma / semicolon / tab and strips a BOM. */
    public static function read(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($raw, "\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $delim = (string) array_key_first($counts);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $headers = null;
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($r === [null] || implode('', array_map('trim', array_map('strval', $r))) === '') {
                continue; // blank line
            }
            $r = array_map(fn ($v) => trim((string) $v), $r);
            if ($headers === null) {
                $headers = $r;
                continue;
            }
            $rows[] = array_pad(array_slice($r, 0, count($headers)), count($headers), '');
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        fclose($fh);
        return [$headers ?? [], $rows];
    }

    /** Best guess of a target for each header. Each target is used at most once. */
    public static function guess(array $headers, array $fields): array
    {
        $used = [];
        $out = [];
        foreach ($headers as $i => $h) {
            $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', $h) ?? $h));
            $target = '';
            foreach ($fields as $f) {
                if (mb_strtolower($f['name']) === $key) {
                    $target = 'field:' . $f['id'];
                }
            }
            if ($target === '') {
                foreach (self::SYNONYMS as $t => $names) {
                    if (in_array($key, $names, true)) {
                        $target = $t;
                        break;
                    }
                }
            }
            if ($target !== '' && isset($used[$target])) {
                $target = '';
            }
            $used[$target] = true;
            $out[$i] = $target;
        }
        return $out;
    }

    /**
     * Create the tasks. $map: column index => target. $opts: default_status (id), date_format ('dmy'|'mdy').
     * Returns ['created' => n, 'subtasks' => n, 'warnings' => [..]].
     */
    public static function run(array $project, array $headers, array $rows, array $map, array $opts): array
    {
        $pid = (int) $project['id'];
        $statuses = Tasks::statuses($pid);
        $byName = [];
        foreach ($statuses as $s) {
            $byName[mb_strtolower($s['name'])] = $s;
        }
        $default = null;
        foreach ($statuses as $s) {
            if ((int) $s['id'] === (int) ($opts['default_status'] ?? 0)) {
                $default = $s;
            }
        }
        $default ??= $statuses[0];
        $fields = array_column(Fields::forProject($pid), null, 'id');
        $people = Mentions::candidates((int) $project['org_id'], $pid);
        $mdy = ($opts['date_format'] ?? 'dmy') === 'mdy';

        // What this person may set (new tasks are "theirs", so "own" is enough).
        $canAssign = Access::level('assign') !== 'none';
        $canFields = Access::level('set_fields') !== 'none';
        $canComment = Access::can('comment');

        $col = fn (string $target) => array_search($target, $map, true);
        $warn = [];
        $skippedFields = [];
        $unknownStatus = $unknownPeople = $badDates = [];
        $created = 0;
        $parents = []; // task id => parent title
        $newIds = [];  // lower-cased title => first new top-level id

        $titleCol = $col('title');
        if ($titleCol === false) {
            throw new \InvalidArgumentException('Choose which column holds the task title.');
        }

        DB::transaction(function () use (
            $rows, $map, $pid, $byName, $default, $fields, $people, $mdy, $canAssign, $canFields, $canComment, $col, $titleCol,
            &$warn, &$skippedFields, &$unknownStatus, &$unknownPeople, &$badDates, &$created, &$parents, &$newIds
        ) {
            $positions = [];
            foreach ($rows as $n => $r) {
                $line = $n + 2; // header is line 1
                $title = mb_substr(trim($r[$titleCol] ?? ''), 0, 255);
                if ($title === '') {
                    $warn[] = "Line {$line}: no title, skipped.";
                    continue;
                }
                $get = fn (string $target) => ($i = $col($target)) === false ? '' : trim($r[$i] ?? '');

                $status = $default;
                if (($s = $get('status')) !== '') {
                    if (isset($byName[mb_strtolower($s)])) {
                        $status = $byName[mb_strtolower($s)];
                    } else {
                        $unknownStatus[$s] = true;
                    }
                }
                $date = function (string $target) use ($get, $mdy, &$badDates, $line) {
                    $v = $get($target);
                    if ($v === '') {
                        return null;
                    }
                    $d = self::parseDate($v, $mdy);
                    if ($d === null) {
                        $badDates[] = "line {$line} “{$v}”";
                    }
                    return $d;
                };
                $createdOn = $date('created_at');
                $completedOn = $date('completed_at');
                $start = $canFields ? $date('start_date') : null;
                $due = $canFields ? $date('due_date') : null;
                $priority = $canFields ? self::parsePriority($get('priority')) : null;
                $description = $get('description');
                $sid = (int) $status['id'];
                $positions[$sid] ??= Tasks::nextPosition($pid, $sid);

                $id = DB::insert('tasks', [
                    'project_id'   => $pid,
                    'status_id'    => $sid,
                    'title'        => $title,
                    'description'  => $description === '' ? null : RichText::clean(str_contains($description, '<') ? $description : '<p>' . nl2br(e($description)) . '</p>'),
                    'priority'     => $priority,
                    'position'     => $positions[$sid]++,
                    'start_date'   => $start,
                    'due_date'     => $due,
                    'created_by'   => Auth::id(),
                    'created_at'   => $createdOn ? $createdOn . ' 09:00:00' : now(),
                    'updated_at'   => now(),
                    'completed_at' => $status['type'] === 'done' ? ($completedOn ? $completedOn . ' 18:00:00' : now()) : null,
                ]);
                Activity::log($id, 'imported');
                $newIds[mb_strtolower($title)] ??= $id;
                if (($p = $get('parent')) !== '') {
                    $parents[$id] = $p;
                }

                if ($canAssign && ($a = $get('assignees')) !== '') {
                    foreach (self::people($a, $people, $unknownPeople) as $uid) {
                        DB::insert('task_assignees', ['task_id' => $id, 'user_id' => $uid]);
                    }
                }
                foreach ($map as $i => $target) {
                    if (!str_starts_with((string) $target, 'field:') || ($v = trim($r[$i] ?? '')) === '') {
                        continue;
                    }
                    $f = $fields[(int) substr($target, 6)] ?? null;
                    if (!$f || !$canFields) {
                        continue;
                    }
                    if ($f['type'] === 'date' && ($d = self::parseDate($v, $mdy))) {
                        $v = $d;
                    }
                    try {
                        $clean = Fields::clean($f, $v);
                    } catch (\InvalidArgumentException) {
                        $skippedFields[$f['name']][] = $line;
                        continue;
                    }
                    if ($clean !== '') {
                        DB::insert('task_field_values', ['task_id' => $id, 'field_id' => $f['id'], 'value' => $clean]);
                    }
                }
                if ($canComment && ($c = $get('comment')) !== '') {
                    DB::insert('comments', ['task_id' => $id, 'user_id' => Auth::id(), 'body' => mb_substr($c, 0, 10000), 'created_at' => now()]);
                }
                $created++;
            }

            // Second pass: subtasks. The parent can be in this file or already in the project (top level only).
            foreach ($parents as $childId => $parentTitle) {
                $key = mb_strtolower($parentTitle);
                $parentId = $newIds[$key] ?? (int) DB::value(
                    'SELECT id FROM tasks WHERE project_id = ? AND parent_id IS NULL AND deleted_at IS NULL AND LOWER(title) = ? ORDER BY id LIMIT 1',
                    [$pid, $key]
                );
                if (!$parentId || $parentId === $childId || isset($parents[$parentId])) {
                    $warn[] = "“{$parentTitle}” wasn’t found as a top-level task, so its subtask was added as a normal task.";
                    continue;
                }
                DB::update('tasks', ['parent_id' => $parentId], 'id = ?', [$childId]);
            }
        });

        if ($unknownStatus) {
            $warn[] = 'Stages not in this project, put in “' . $default['name'] . '”: ' . implode(', ', array_keys($unknownStatus)) . '. Add them under Edit stages and move the tasks if you want them.';
        }
        if ($unknownPeople) {
            $warn[] = 'People not found in this project (left unassigned): ' . implode(', ', array_keys($unknownPeople)) . '.';
        }
        if ($badDates) {
            $warn[] = 'Dates that couldn’t be read (left empty): ' . implode(', ', array_slice($badDates, 0, 8)) . (count($badDates) > 8 ? ' …' : '') . '.';
        }
        foreach ($skippedFields as $name => $lines) {
            $warn[] = "“{$name}” values that didn’t fit the field were skipped (lines " . implode(', ', array_slice($lines, 0, 8)) . ').';
        }
        if (!$canAssign && $col('assignees') !== false) {
            $warn[] = 'You can’t assign people, so the Assignees column was skipped.';
        }
        if (!$canFields && ($col('priority') !== false || $col('due_date') !== false || $col('start_date') !== false)) {
            $warn[] = 'You can’t set priority, dates or fields, so those columns were skipped.';
        }
        return ['created' => $created, 'subtasks' => count($parents), 'warnings' => array_values(array_unique($warn))];
    }

    /** Y-m-d from common formats: 2026-10-15, 15/10/2026 (or 10/15/2026 with $mdy), 15 Oct 2026, ClickUp's "Thursday, October 15th 2026", Unix ms. */
    public static function parseDate(string $v, bool $mdy = false): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{12,13}$/', $v)) {
            return date('Y-m-d', (int) ((int) $v / 1000));
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})#', $v, $m)) {
            [$d, $mo] = $mdy ? [(int) $m[2], (int) $m[1]] : [(int) $m[1], (int) $m[2]];
            $y = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        }
        $clean = preg_replace('/(\d+)(st|nd|rd|th)\b/i', '$1', $v) ?? $v;
        $ts = strtotime($clean);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** urgent/high/normal/low, ClickUp's 1–4, or P1–P4. */
    public static function parsePriority(string $v): ?string
    {
        $v = mb_strtolower(trim($v));
        return match (true) {
            in_array($v, ['urgent', '1', 'p1', 'critical', 'highest'], true) => 'urgent',
            in_array($v, ['high', '2', 'p2'], true) => 'high',
            in_array($v, ['normal', '3', 'p3', 'medium'], true) => 'normal',
            in_array($v, ['low', '4', 'p4', 'lowest'], true) => 'low',
            default => null,
        };
    }

    /** Match "Ravi Dutta, ayushi@example.com" (or ClickUp's "[Ravi, Ayushi]") to member ids. */
    private static function people(string $raw, array $people, array &$unknown): array
    {
        $ids = [];
        foreach (preg_split('/[,;|]+/', trim($raw, "[] ")) ?: [] as $name) {
            $name = mb_strtolower(trim($name, " \"'"));
            if ($name === '') {
                continue;
            }
            $hit = null;
            foreach ($people as $p) {
                if (mb_strtolower($p['name']) === $name || mb_strtolower($p['email']) === $name) {
                    $hit = $p['id'];
                }
            }
            if ($hit === null) {
                // A first name works when it's unique: "Ravi" → Ravi Dutta.
                $first = array_filter($people, fn ($p) => mb_strtolower(strtok($p['name'], ' ')) === $name);
                $hit = count($first) === 1 ? array_values($first)[0]['id'] : null;
            }
            if ($hit === null) {
                $unknown[trim($name)] = true;
                continue;
            }
            $ids[$hit] = $hit;
        }
        return array_values($ids);
    }
}
