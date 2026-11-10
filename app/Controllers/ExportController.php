<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\Access;
use App\Services\Tasks;

/** CSV reports, e.g. "what did each designer finish in the last 3 months" (PRD §5.11). */
final class ExportController
{
    public function form(string $org): string
    {
        Access::org($org);
        Access::require('export');
        return view('org/export', [
            'title'    => 'Export',
            'members'  => Access::members(includeInactive: true),
            'projects' => [...Access::projects(true), ['id' => Access::inboxId(), 'name' => 'Inbox', 'archived' => 0]],
            'ownOnly'  => Access::level('export') === 'own',
        ]);
    }

    public function tasks(string $org): never
    {
        $o = Access::org($org);
        Access::require('export');
        [$rows, $label] = $this->query($o);

        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $assignees = $files = $comments = [];
        if ($ids) {
            $in = DB::placeholders($ids);
            foreach (DB::all("SELECT ta.task_id, u.name FROM task_assignees ta JOIN users u ON u.id = ta.user_id WHERE ta.task_id IN ($in) ORDER BY u.name", $ids) as $a) {
                $assignees[(int) $a['task_id']][] = $a['name'];
            }
            foreach (DB::all("SELECT id, task_id FROM attachments WHERE task_id IN ($in) ORDER BY id", $ids) as $a) {
                $files[(int) $a['task_id']][] = absolute_url('/files/' . $a['id']);
            }
            $comments = array_column(DB::all("SELECT task_id, COUNT(*) AS n FROM comments WHERE task_id IN ($in) GROUP BY task_id", $ids), 'n', 'task_id');
        }

        // Custom fields become extra columns, matched by name across the exported projects.
        $fieldCols = [];   // lower-cased name => display name
        $fieldDefs = [];   // field id => [column key, def]
        $projectIds = array_values(array_unique(array_map(fn ($r) => (int) $r['project_id'], $rows)));
        if ($projectIds) {
            foreach (DB::all('SELECT * FROM project_fields WHERE project_id IN (' . DB::placeholders($projectIds) . ') ORDER BY project_id, position, id', $projectIds) as $f) {
                $def = \App\Services\Fields::shape($f);
                $key = mb_strtolower($def['name']);
                $fieldCols[$key] ??= $def['name'];
                $fieldDefs[$def['id']] = [$key, $def];
            }
        }
        $values = \App\Services\Fields::values($ids);

        $today = date('Y-m-d');
        $out = [['Task ID', 'Title', 'Project', 'Parent Task', 'Status', 'Priority', 'Assignees', 'Created By', 'Created At', 'Start Date', 'Due Date', 'Completed At', 'Days to Complete', 'Overdue (Y/N)', 'Comment Count', 'Attachment URLs', 'Task URL', ...array_values($fieldCols)]];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $finished = $r['completed_at'] ? substr($r['completed_at'], 0, 10) : null;
            $overdue = $r['due_date'] && ($finished ?? $today) > $r['due_date'];
            $out[] = [
                $id, $r['title'], $r['project'], $r['parent_title'] ?? '', $r['status'],
                $r['priority'] ? Tasks::PRIORITIES[$r['priority']] ?? $r['priority'] : '',
                implode(', ', $assignees[$id] ?? []), $r['creator'] ?? '', $r['created_at'],
                $r['start_date'] ?? '', $r['due_date'] ?? '', $r['completed_at'] ?? '',
                $r['completed_at'] ? (string) max(0, (int) floor((strtotime($r['completed_at']) - strtotime($r['created_at'])) / 86400)) : '',
                $overdue ? 'Y' : 'N', (int) ($comments[$id] ?? 0), implode(' ', $files[$id] ?? []),
                absolute_url("/o/{$o['slug']}/p/{$r['project_id']}", ['task' => $id]),
            ];
            $extra = array_fill_keys(array_keys($fieldCols), '');
            foreach ($values[$id] ?? [] as $fid => $v) {
                if (isset($fieldDefs[$fid])) {
                    [$key, $def] = $fieldDefs[$fid];
                    $extra[$key] = \App\Services\Fields::display($def, $v);
                }
            }
            array_push($out[count($out) - 1], ...array_values($extra));
        }
        $this->send("{$o['slug']}-tasks-{$label}.csv", $out);
    }

    public function comments(string $org): never
    {
        $o = Access::org($org);
        Access::require('export');
        [$rows, $label] = $this->query($o);
        $out = [['Task ID', 'Task Title', 'Project', 'Author', 'Comment', 'Posted At', 'Edited At']];
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        if ($ids) {
            $titles = array_column($rows, null, 'id');
            foreach (DB::all('SELECT c.*, u.name FROM comments c JOIN users u ON u.id = c.user_id WHERE c.task_id IN (' . DB::placeholders($ids) . ') ORDER BY c.task_id, c.id', $ids) as $c) {
                $t = $titles[$c['task_id']];
                $out[] = [(int) $c['task_id'], $t['title'], $t['project'], $c['name'], $c['body'], $c['created_at'], $c['edited_at'] ?? ''];
            }
        }
        $this->send("{$o['slug']}-comments-{$label}.csv", $out);
    }

    /** Tasks matching the filters, limited to what this member may export. */
    private function query(array $o): array
    {
        $where = ['p.org_id = ?', 't.deleted_at IS NULL'];
        $params = [$o['id']];

        $visible = array_map(fn ($p) => (int) $p['id'], Access::projects(true));
        $visible[] = Access::inboxId(); // tasks not sorted into a project yet
        $chosen = array_values(array_intersect(array_map('intval', (array) ($_GET['projects'] ?? [])), $visible));
        $projects = $chosen ?: $visible;
        if (!$projects) {
            $projects = [0];
        }
        $where[] = 't.project_id IN (' . DB::placeholders($projects) . ')';
        array_push($params, ...$projects);

        // "Own" export = only tasks they're assigned to.
        $members = Access::level('export') === 'own' ? [Auth::id()] : array_map('intval', (array) ($_GET['members'] ?? []));
        if ($members) {
            $where[] = 'EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id IN (' . DB::placeholders($members) . '))';
            array_push($params, ...$members);
        }

        $basis = ['created' => 't.created_at', 'completed' => 't.completed_at', 'due' => 't.due_date'][$_GET['basis'] ?? 'completed'] ?? 't.completed_at';
        [$from, $to] = $this->range();
        if ($from) {
            $where[] = "$basis >= ?";
            $params[] = $from;
        }
        if ($to) {
            $where[] = "$basis <= ?";
            $params[] = $basis === 't.due_date' ? $to : $to . ' 23:59:59';
        }

        $types = array_values(array_intersect((array) ($_GET['types'] ?? []), ['open', 'active', 'done']));
        if ($types) {
            $where[] = 's.type IN (' . DB::placeholders($types) . ')';
            array_push($params, ...$types);
        }
        if (($_GET['subtasks'] ?? '1') !== '1') {
            $where[] = 't.parent_id IS NULL';
        }

        $rows = DB::all(
            'SELECT t.*, p.name AS project, s.name AS status, s.type AS status_type, parent.title AS parent_title, cu.name AS creator
             FROM tasks t
             JOIN projects p ON p.id = t.project_id
             JOIN statuses s ON s.id = t.status_id
             LEFT JOIN tasks parent ON parent.id = t.parent_id
             LEFT JOIN users cu ON cu.id = t.created_by
             WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name, t.created_at',
            $params
        );
        return [$rows, ($from ?: 'start') . '-to-' . ($to ?: date('Y-m-d'))];
    }

    /** [from, to] as Y-m-d (either may be null), from a preset or custom dates. */
    private function range(): array
    {
        $range = (string) ($_GET['range'] ?? '90');
        $today = date('Y-m-d');
        return match ($range) {
            '30', '60', '90', '180', '365' => [date('Y-m-d', strtotime("-{$range} days")), $today],
            'quarter' => [date('Y-m-d', mktime(0, 0, 0, (int) (ceil((int) date('n') / 3) * 3 - 2), 1)), $today],
            'all'     => [null, null],
            default   => [valid_date($_GET['from'] ?? null), valid_date($_GET['to'] ?? null)],
        };
    }

    private function send(string $filename, array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
        $fh = fopen('php://output', 'w');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM, so Excel reads UTF-8 correctly
        foreach ($rows as $row) {
            // Cells starting with = + - @ would run as formulas in Excel/Sheets.
            fputcsv($fh, array_map(fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v, $row), ',', '"', '');
        }
        fclose($fh);
        exit;
    }
}
