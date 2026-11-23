<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database as DB;
use App\Services\Access;
use App\Services\Uploads;

/** Streams an attachment to members who can see its task. */
final class FileController
{
    public function show(string $file): never
    {
        $a = DB::one('SELECT * FROM attachments WHERE id = ?', [(int) $file]);
        if (!$a) {
            abort(404);
        }
        $task = Access::task((int) $a['task_id'], withDeleted: true);
        $path = Uploads::path($a, (int) $task['org_id']);
        if (!is_file($path)) {
            abort(404, 'This file is missing on the server.');
        }
        if (Uploads::isHtml($a) && !isset($_GET['download'])) {
            // The page itself only loads inside the viewer's sandboxed frame; opened any other way,
            // go to the viewer, so it never shows full-screen as if it were part of LeanPM.
            $dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'iframe';
            if (!isset($_GET['raw']) || $dest !== 'iframe') {
                redirect('/files/' . (int) $a['id'] . '/view');
            }
            header('Vary: Sec-Fetch-Dest');
            self::send($path, 'text/html', 'inline', $a['file_name'],
                // A sandbox without allow-same-origin gives the page an origin of its own: its scripts
                // run, but it can't read LeanPM's cookies, storage or pages, or act as the user.
                'sandbox allow-scripts allow-forms allow-popups allow-modals allow-downloads');
        }
        // Images open in the browser; everything else downloads. Never sniffed, never run.
        $inline = Uploads::isImage($a['mime']) || $a['mime'] === 'application/pdf';
        self::send($path, $inline ? $a['mime'] : 'application/octet-stream', $inline ? 'inline' : 'attachment', $a['file_name'],
            "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    }

    /** An HTML file, shown in a sandboxed frame under a bar that says what it is. */
    public function view(string $file): string
    {
        $a = DB::one('SELECT * FROM attachments WHERE id = ?', [(int) $file]);
        if (!$a) {
            abort(404);
        }
        $task = Access::task((int) $a['task_id'], withDeleted: true);
        if (!Uploads::isHtml($a)) {
            redirect('/files/' . (int) $a['id']);
        }
        $by = DB::value('SELECT name FROM users WHERE id = ?', [$a['user_id']]);
        return view('files/html', [
            'file' => $a, 'task' => $task, 'by' => $by,
            'taskUrl' => url('/o/' . Access::$org['slug'] . '/p/' . $task['project_id'], ['task' => (int) $task['id']]),
        ], null);
    }

    public static function send(string $path, string $type, string $disposition, string $fileName, string $csp): never
    {
        $name = str_replace(['"', "\r", "\n"], '', $fileName);
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($name, '\\') . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
        header('Content-Security-Policy: ' . $csp);
        header('Cache-Control: private, max-age=86400');
        while (ob_get_level()) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }
}
