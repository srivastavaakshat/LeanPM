<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\Access;
use App\Services\Activity;
use App\Services\Tasks;
use App\Services\Uploads;

/** Comments and files on a task. */
final class CommentApiController
{
    /** A comment with optional files (multipart: body, files[]). */
    public function store(string $task): never
    {
        $t = Access::task((int) $task);
        Access::require('comment');
        $body = mb_substr(trim((string) (json_input()['body'] ?? '')), 0, 10000);
        $files = Uploads::fromRequest();
        if ($files) {
            Access::require('upload');
        }
        if ($body === '' && !$files) {
            abort(422, 'Write something or attach a file.');
        }
        $errors = [];
        DB::transaction(function () use ($t, $body, $files, &$errors) {
            $cid = DB::insert('comments', ['task_id' => $t['id'], 'user_id' => Auth::id(), 'body' => $body, 'created_at' => now()]);
            foreach ($files as $f) {
                try {
                    Uploads::store($f, $t, $cid);
                } catch (\RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            DB::update('tasks', ['updated_at' => now()], 'id = ?', [$t['id']]);
        });
        json_response(['task' => Tasks::detail(Access::task((int) $t['id'])), 'errors' => $errors]);
    }

    public function update(string $comment): never
    {
        $c = $this->comment($comment);
        if ((int) $c['user_id'] !== Auth::id()) {
            abort(403, 'You can only edit your own comments.');
        }
        $body = mb_substr(trim((string) (json_input()['body'] ?? '')), 0, 10000);
        if ($body === '') {
            abort(422, 'A comment can’t be empty. Delete it instead.');
        }
        DB::update('comments', ['body' => $body, 'edited_at' => now()], 'id = ?', [$c['id']]);
        json_response(['task' => Tasks::detail(Access::task((int) $c['task_id']))]);
    }

    public function destroy(string $comment): never
    {
        $c = $this->comment($comment);
        if ((int) $c['user_id'] !== Auth::id() && !Access::can('moderate')) {
            abort(403);
        }
        $orgId = (int) Access::$org['id'];
        DB::transaction(function () use ($c, $orgId) {
            foreach (DB::all('SELECT * FROM attachments WHERE comment_id = ?', [$c['id']]) as $a) {
                Uploads::delete($a, $orgId);
            }
            DB::query('DELETE FROM comments WHERE id = ?', [$c['id']]);
        });
        json_response(['task' => Tasks::detail(Access::task((int) $c['task_id']))]);
    }

    /** Files attached to the task itself. */
    public function upload(string $task): never
    {
        $t = Access::task((int) $task);
        Access::require('upload');
        $files = Uploads::fromRequest();
        if (!$files) {
            abort(422, 'No file was sent. It may be larger than the server allows.');
        }
        $errors = [];
        foreach ($files as $f) {
            try {
                Uploads::store($f, $t);
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        json_response(['task' => Tasks::detail(Access::task((int) $t['id'])), 'errors' => $errors]);
    }

    public function deleteFile(string $file): never
    {
        $a = DB::one('SELECT * FROM attachments WHERE id = ?', [(int) $file]);
        if (!$a) {
            abort(404);
        }
        $t = Access::task((int) $a['task_id']);
        if ((int) $a['user_id'] !== Auth::id() && !Access::can('moderate')) {
            abort(403);
        }
        Uploads::delete($a, (int) Access::$org['id']);
        Activity::log((int) $t['id'], 'file_removed', $a['file_name']);
        json_response(['task' => Tasks::detail(Access::task((int) $t['id']))]);
    }

    private function comment(string $id): array
    {
        $c = DB::one('SELECT * FROM comments WHERE id = ?', [(int) $id]);
        if (!$c) {
            abort(404);
        }
        Access::task((int) $c['task_id']);
        return $c;
    }
}
