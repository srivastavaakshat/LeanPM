<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\Access;
use App\Services\Activity;
use App\Services\RichText;
use App\Services\Tasks;

/**
 * Meeting notes (PRD §5.12): one running doc per meeting series ("Call with CEO", "Internal
 * stand-up"), made of dated entries, newest first. Tasks can be created right from a line.
 * Docs are private to their owner unless shared with the organization.
 */
final class NotesController
{
    /** Sections of the task quick view a doc can switch off (Stage and Project always show). */
    public const PEEK_SECTIONS = [
        'assignees'   => 'Assignees',
        'dates'       => 'Start and due dates',
        'priority'    => 'Priority',
        'fields'      => 'Custom fields (e.g. Figma file)',
        'description' => 'Description',
        'subtasks'    => 'Subtasks',
        'files'       => 'Files',
        'updates'     => 'Updates and comments',
    ];

    public static function sections(?string $json): array
    {
        $saved = json_decode((string) $json, true);
        return is_array($saved) ? array_values(array_intersect(array_keys(self::PEEK_SECTIONS), $saved)) : array_keys(self::PEEK_SECTIONS);
    }

    // ---------- pages ----------

    public function index(string $org): string
    {
        $o = Access::org($org);
        Access::require('meeting_notes');
        $docs = DB::all(
            "SELECT d.*, u.name AS owner_name,
                    (SELECT COUNT(*) FROM notes_entries e WHERE e.doc_id = d.id) AS entries,
                    (SELECT MAX(meeting_at) FROM notes_entries e WHERE e.doc_id = d.id) AS last_meeting,
                    (SELECT name FROM projects p WHERE p.id = d.default_project_id) AS default_project
             FROM notes_docs d JOIN users u ON u.id = d.owner_id
             WHERE d.org_id = ? AND (d.owner_id = ? OR d.visibility = 'org')
             ORDER BY COALESCE((SELECT MAX(meeting_at) FROM notes_entries e WHERE e.doc_id = d.id), d.created_at) DESC",
            [$o['id'], Auth::id()]
        );
        foreach ($docs as &$doc) {
            $doc['linked_task'] = self::linkedTask($doc)['title'] ?? null; // only tasks this member can see
        }
        unset($doc);
        // Tabs: every doc, only your private ones, or the ones shared with the organization.
        $counts = ['all' => count($docs), 'private' => 0, 'shared' => 0];
        foreach ($docs as $d) {
            $counts[$d['visibility'] === 'org' ? 'shared' : 'private']++;
        }
        $show = in_array($_GET['show'] ?? '', ['private', 'shared'], true) ? $_GET['show'] : 'all';
        if ($show !== 'all') {
            $docs = array_values(array_filter($docs, fn ($d) => ($d['visibility'] === 'org') === ($show === 'shared')));
        }
        return view('notes/index', ['title' => 'Meeting notes', 'docs' => $docs, 'show' => $show, 'counts' => $counts, 'projects' => $this->projectChoices()]);
    }

    public function store(string $org): never
    {
        $o = Access::org($org);
        Access::require('meeting_notes');
        $title = mb_substr(trim((string) input('title', '')), 0, 120);
        if ($title === '') {
            flash('error', 'Give the doc a name, e.g. “Call with CEO”.');
            redirect("/o/{$o['slug']}/notes");
        }
        $id = DB::insert('notes_docs', [
            'org_id' => $o['id'], 'owner_id' => Auth::id(), 'title' => $title,
            'visibility' => input('visibility') === 'org' ? 'org' : 'private',
            'default_project_id' => $this->validProject(input('default_project')),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        redirect("/o/{$o['slug']}/notes/{$id}");
    }

    public function show(string $org, string $doc): string
    {
        $o = Access::org($org);
        $d = $this->doc((int) $doc);
        $uid = Auth::id();
        $entries = array_map(fn ($e) => [
            'id' => (int) $e['id'], 'author_id' => (int) $e['author_id'], 'meeting_at' => $e['meeting_at'],
            'title' => $e['title'], 'body' => $e['body'],
            'can_edit' => (int) $e['author_id'] === $uid || (int) $d['owner_id'] === $uid,
        ], DB::all('SELECT * FROM notes_entries WHERE doc_id = ? ORDER BY meeting_at DESC, id DESC', [$d['id']]));

        $members = Access::members(includeInactive: true);
        $boot = [
            'doc'      => ['id' => (int) $d['id'], 'title' => $d['title'], 'visibility' => $d['visibility'], 'default_project' => $d['default_project_id'] ? (int) $d['default_project_id'] : Access::inboxId(), 'owner' => (int) $d['owner_id'], 'sections' => self::sections($d['peek_sections']), 'task' => self::taskSummary(self::linkedTask($d))],
            'entries'  => $entries,
            'projects' => $this->projectChoices(),
            'members'  => array_map(fn ($m) => ['id' => (int) $m['id'], 'name' => $m['name'], 'color' => $m['color'], 'initials' => initials($m['name']), 'active' => (bool) $m['active']], $members),
            'priorities' => Tasks::PRIORITIES,
            'maxUpload' => (int) config('upload_max_mb', 25),
            'me'       => $uid,
            'isOwner'  => (int) $d['owner_id'] === $uid,
            'can'      => ['create' => Access::can('create_tasks')],
            'base'     => url('/'),
            'org'      => $o['slug'],
            'csrf'     => csrf_token(),
            'now'      => date('Y-m-d H:i:s'),
        ];
        return view('notes/doc', ['title' => $d['title'], 'doc' => $d, 'boot' => $boot, 'projects' => $this->projectChoices(), 'linked' => $boot['doc']['task']]);
    }

    public function update(string $org, string $doc): never
    {
        $o = Access::org($org);
        $d = $this->doc((int) $doc, ownerOnly: true);
        $title = mb_substr(trim((string) input('title', '')), 0, 120);
        $set = [
            'title' => $title !== '' ? $title : $d['title'],
            'visibility' => input('visibility') === 'org' ? 'org' : 'private',
            'default_project_id' => $this->validProject(input('default_project')),
            'updated_at' => now(),
        ];
        // Only the full Settings dialog sends the quick-view checklist (the header's project picker doesn't).
        if (input('sections_form') === '1') {
            $set['peek_sections'] = json_encode(array_values(array_intersect(array_keys(self::PEEK_SECTIONS), (array) ($_POST['peek'] ?? []))));
        }
        if (input('unlink_task') === '1' && $d['task_id']) {
            $set['task_id'] = null;
            self::logLink($d, (int) $d['task_id'], false);
        }
        DB::update('notes_docs', $set, 'id = ?', [$d['id']]);
        flash('success', 'Doc settings saved.');
        redirect("/o/{$o['slug']}/notes/{$d['id']}");
    }

    public function destroy(string $org, string $doc): never
    {
        $o = Access::org($org);
        $d = $this->doc((int) $doc, ownerOnly: true);
        DB::query('DELETE FROM notes_docs WHERE id = ?', [$d['id']]); // its entries go with it; tasks stay
        flash('success', "“{$d['title']}” deleted. Tasks created from it are still on their boards.");
        redirect("/o/{$o['slug']}/notes");
    }

    // ---------- API ----------

    public function createEntry(string $doc): never
    {
        $d = $this->apiDoc((int) $doc);
        $in = json_input();
        $at = is_string($in['meeting_at'] ?? null) && strtotime($in['meeting_at']) ? date('Y-m-d H:i:s', strtotime($in['meeting_at'])) : now();
        $id = DB::insert('notes_entries', [
            'doc_id' => $d['id'], 'author_id' => Auth::id(), 'meeting_at' => $at,
            'title' => nullable(mb_substr((string) ($in['title'] ?? ''), 0, 120)),
            'body' => RichText::clean(is_string($in['body'] ?? null) ? $in['body'] : null),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::update('notes_docs', ['updated_at' => now()], 'id = ?', [$d['id']]);
        json_response(['entry' => ['id' => $id, 'meeting_at' => $at]], 201);
    }

    public function updateEntry(string $entry): never
    {
        [$e, $d] = $this->entry((int) $entry);
        $in = json_input();
        $set = ['updated_at' => now()];
        if (array_key_exists('body', $in)) {
            $set['body'] = RichText::clean(is_string($in['body']) ? $in['body'] : null);
        }
        if (array_key_exists('title', $in)) {
            $set['title'] = nullable(mb_substr((string) $in['title'], 0, 120));
        }
        DB::update('notes_entries', $set, 'id = ?', [$e['id']]);
        DB::update('notes_docs', ['updated_at' => now()], 'id = ?', [$d['id']]);
        json_response(['ok' => true]);
    }

    public function deleteEntry(string $entry): never
    {
        [$e] = $this->entry((int) $entry);
        DB::query('DELETE FROM notes_entries WHERE id = ?', [$e['id']]);
        json_response(['ok' => true]);
    }

    /** Create a task from a line in the notes. Goes to the chosen project, else the doc's default, else the Inbox. */
    public function createTask(string $doc): never
    {
        $d = $this->apiDoc((int) $doc);
        Access::require('create_tasks');
        $in = json_input();
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 255);
        if ($title === '') {
            abort(422, 'Give the task a name.');
        }
        $parent = self::linkedTask($d);
        if ($parent) {
            // A doc connected to a task: new tasks become its subtasks, in its project.
            $project = Access::project((int) $parent['project_id']);
        } else {
            $pid = (int) ($in['project_id'] ?? 0) ?: (int) ($d['default_project_id'] ?? 0) ?: Access::inboxId();
            $project = Access::project($pid);
        }
        if ((int) $project['archived']) {
            abort(422, 'That project is archived. Pick another one.');
        }
        $id = Tasks::create($project, $title, null, $parent);
        Activity::log($id, 'from_notes', null, $d['title']);
        json_response(['task' => $this->chips([$id])[$id] ?? null], 201);
    }

    /** Live state of the tasks this doc links to (title, stage, project, people, due). */
    public function tasks(string $doc): never
    {
        $d = $this->apiDoc((int) $doc);
        $ids = [];
        foreach (DB::all('SELECT body FROM notes_entries WHERE doc_id = ?', [$d['id']]) as $e) {
            preg_match_all('/data-task="(\d+)"/', (string) $e['body'], $m);
            array_push($ids, ...array_map('intval', $m[1]));
        }
        json_response(['tasks' => (object) $this->chips(array_values(array_unique($ids)))]);
    }

    // ---------- docs on a task ----------

    /** Start a new doc connected to this task (shared with the org, named after the task). */
    public function createForTask(string $task): never
    {
        $t = $this->docTask((int) $task);
        $title = mb_substr(trim((string) (json_input()['title'] ?? '')), 0, 120) ?: mb_substr($t['title'], 0, 120);
        $isInbox = (int) DB::value('SELECT is_inbox FROM projects WHERE id = ?', [$t['project_id']]);
        $id = DB::insert('notes_docs', [
            'org_id' => Access::$org['id'], 'owner_id' => Auth::id(), 'title' => $title, 'visibility' => 'org',
            'default_project_id' => $isInbox ? null : (int) $t['project_id'], 'task_id' => $t['id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $d = DB::one('SELECT * FROM notes_docs WHERE id = ?', [$id]);
        self::logLink($d, (int) $t['id'], true);
        json_response(['doc' => self::docSummary($d), 'task' => Tasks::detail(Access::task((int) $t['id']))], 201);
    }

    /** Docs you created that could be connected to this task. */
    public function availableForTask(string $task): never
    {
        $t = $this->docTask((int) $task);
        $rows = DB::all('SELECT * FROM notes_docs WHERE org_id = ? AND owner_id = ? AND (task_id IS NULL OR task_id <> ?) ORDER BY updated_at DESC', [Access::$org['id'], Auth::id(), $t['id']]);
        json_response(['docs' => array_map(function ($d) {
            $other = self::linkedTask($d);
            return self::docSummary($d) + ['linked_to' => $other ? $other['title'] : null];
        }, $rows)]);
    }

    public function linkToTask(string $task): never
    {
        $t = $this->docTask((int) $task);
        $d = $this->doc((int) (json_input()['doc_id'] ?? 0), ownerOnly: true);
        if ($d['task_id'] && (int) $d['task_id'] !== (int) $t['id']) {
            self::logLink($d, (int) $d['task_id'], false); // moved off its previous task
        }
        DB::update('notes_docs', ['task_id' => $t['id'], 'updated_at' => now()], 'id = ?', [$d['id']]);
        self::logLink($d, (int) $t['id'], true);
        json_response(['task' => Tasks::detail(Access::task((int) $t['id']))]);
    }

    public function unlinkFromTask(string $task, string $doc): never
    {
        $t = Access::task((int) $task);
        $d = $this->doc((int) $doc, ownerOnly: true);
        if ((int) $d['task_id'] === (int) $t['id']) {
            DB::update('notes_docs', ['task_id' => null, 'updated_at' => now()], 'id = ?', [$d['id']]);
            self::logLink($d, (int) $t['id'], false);
        }
        json_response(['task' => Tasks::detail(Access::task((int) $t['id']))]);
    }

    /** Docs connected to a task that the current member can open (for the task panel). */
    public static function docsForTask(int $taskId): array
    {
        if (!Access::can('meeting_notes')) {
            return [];
        }
        $rows = DB::all("SELECT * FROM notes_docs WHERE task_id = ? AND org_id = ? AND (owner_id = ? OR visibility = 'org') ORDER BY created_at", [$taskId, Access::$org['id'], Auth::id()]);
        return array_map(fn ($d) => self::docSummary($d) + ['can_unlink' => (int) $d['owner_id'] === Auth::id()], $rows);
    }

    // ---------- export ----------

    /** Download the whole doc: pdf (a print view to save as PDF), doc (Word), md or txt. */
    public function export(string $org, string $doc, string $format): string
    {
        Access::org($org);
        $d = $this->doc((int) $doc);
        if (!in_array($format, ['pdf', 'doc', 'md', 'txt'], true)) {
            abort(404);
        }
        $entries = DB::all('SELECT e.*, u.name AS author FROM notes_entries e JOIN users u ON u.id = e.author_id WHERE e.doc_id = ? ORDER BY e.meeting_at DESC, e.id DESC', [$d['id']]);
        $ids = [];
        foreach ($entries as $e) {
            preg_match_all('/data-task="(\d+)"/', (string) $e['body'], $m);
            array_push($ids, ...array_map('intval', $m[1]));
        }
        $export = new \App\Services\NotesExport($d, $entries, $this->chips(array_values(array_unique($ids))), self::linkedTask($d));
        $name = slugify($d['title']) ?: 'meeting-notes';
        header('X-Robots-Tag: noindex');
        switch ($format) {
            case 'pdf':
                return $export->printView();
            case 'doc':
                header('Content-Type: application/msword; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$name}.doc\"");
                return $export->word();
            case 'md':
                header('Content-Type: text/markdown; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$name}.md\"");
                return $export->markdown();
            default:
                header('Content-Type: text/plain; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$name}.txt\"");
                return $export->text();
        }
    }

    // ---------- helpers ----------

    /** The task a doc is connected to, if it still exists and this member can see it. */
    public static function linkedTask(array $d): ?array
    {
        if (empty($d['task_id'])) {
            return null;
        }
        $t = DB::one('SELECT t.*, p.org_id, p.name AS project_name FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ? AND t.deleted_at IS NULL', [(int) $d['task_id']]);
        if (!$t || (int) $t['org_id'] !== (int) $d['org_id'] || !Access::canSeeProject((int) $t['project_id']) || $t['parent_id'] !== null) {
            return null;
        }
        return $t;
    }

    private static function taskSummary(?array $t): ?array
    {
        return $t ? ['id' => (int) $t['id'], 'title' => $t['title'], 'project' => $t['project_name'], 'url' => url('/o/' . Access::$org['slug'] . '/p/' . $t['project_id'], ['task' => (int) $t['id']])] : null;
    }

    private static function docSummary(array $d): array
    {
        return ['id' => (int) $d['id'], 'title' => $d['title'], 'visibility' => $d['visibility'], 'url' => url('/o/' . Access::$org['slug'] . '/notes/' . $d['id'])];
    }

    /** A task docs can be connected to: one you can see, not a subtask, and you may use meeting notes. */
    private function docTask(int $id): array
    {
        $t = Access::task($id);
        Access::require('meeting_notes');
        if ($t['parent_id'] !== null) {
            abort(422, 'Connect the doc to the main task. Tasks made in it become its subtasks, and subtasks can’t have their own.');
        }
        return $t;
    }

    private static function logLink(array $d, int $taskId, bool $linked): void
    {
        if (DB::value('SELECT id FROM tasks WHERE id = ?', [$taskId])) {
            Activity::log($taskId, $linked ? 'doc_linked' : 'doc_unlinked', null, $d['title']);
        }
    }


    /** A doc in the current org that the member can open (owner, or shared with the org). */
    private function doc(int $id, bool $ownerOnly = false): array
    {
        Access::require('meeting_notes');
        $d = DB::one('SELECT * FROM notes_docs WHERE id = ? AND org_id = ?', [$id, Access::$org['id']]);
        $uid = Auth::id();
        if (!$d || ((int) $d['owner_id'] !== $uid && $d['visibility'] !== 'org')) {
            abort(404);
        }
        if ($ownerOnly && (int) $d['owner_id'] !== $uid) {
            abort(403, 'Only the person who created this doc can change its settings.');
        }
        return $d;
    }

    private function apiDoc(int $id): array
    {
        $d = DB::one('SELECT org_id FROM notes_docs WHERE id = ?', [$id]);
        if (!$d) {
            abort(404);
        }
        Access::orgById((int) $d['org_id']);
        return $this->doc($id);
    }

    /** An entry the member may change: its author, or the doc's owner. */
    private function entry(int $id): array
    {
        $e = DB::one('SELECT * FROM notes_entries WHERE id = ?', [$id]);
        if (!$e) {
            abort(404);
        }
        $d = $this->apiDoc((int) $e['doc_id']);
        if ((int) $e['author_id'] !== Auth::id() && (int) $d['owner_id'] !== Auth::id()) {
            abort(403, 'Only the person who wrote this entry can change it.');
        }
        return [$e, $d];
    }

    /** Projects a new task can go to: the Inbox first, then open projects. */
    private function projectChoices(): array
    {
        $out = [['id' => Access::inboxId(), 'name' => 'Inbox', 'inbox' => true]];
        foreach (Access::projects() as $p) {
            $out[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'inbox' => false];
        }
        return $out;
    }

    private function validProject(mixed $id): ?int
    {
        $id = (int) $id;
        if (!$id || $id === Access::inboxId()) {
            return null; // the Inbox
        }
        foreach (Access::projects() as $p) {
            if ((int) $p['id'] === $id) {
                return $id;
            }
        }
        return null;
    }

    /** Small summaries for task chips, keyed by id. Tasks the member can't see are left out. */
    private function chips(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $rows = DB::all(
            'SELECT t.*, s.name AS status_name, s.color AS status_color, s.type AS status_type, p.name AS project_name, p.is_inbox, p.org_id
             FROM tasks t JOIN statuses s ON s.id = t.status_id JOIN projects p ON p.id = t.project_id
             WHERE t.id IN (' . DB::placeholders($ids) . ') AND t.deleted_at IS NULL',
            $ids
        );
        $assignees = [];
        foreach (DB::all('SELECT task_id, user_id FROM task_assignees WHERE task_id IN (' . DB::placeholders($ids) . ')', $ids) as $a) {
            $assignees[(int) $a['task_id']][] = (int) $a['user_id'];
        }
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r['org_id'] !== (int) Access::$org['id'] || !Access::canSeeProject((int) $r['project_id'])) {
                continue;
            }
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'], 'title' => $r['title'],
                'status' => ['name' => $r['status_name'], 'color' => $r['status_color'], 'type' => $r['status_type']],
                'project' => ['id' => (int) $r['project_id'], 'name' => $r['project_name'], 'inbox' => (bool) $r['is_inbox']],
                'assignees' => $assignees[(int) $r['id']] ?? [], 'due_date' => $r['due_date'],
                'created_at' => $r['created_at'],
                'url' => url('/o/' . Access::$org['slug'] . '/p/' . $r['project_id'], ['task' => (int) $r['id']]),
            ];
        }
        return $out;
    }
}
