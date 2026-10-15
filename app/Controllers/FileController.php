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
        // Images open in the browser; everything else downloads. Never sniffed, never run.
        $inline = Uploads::isImage($a['mime']) || $a['mime'] === 'application/pdf';
        $name = str_replace(['"', "\r", "\n"], '', $a['file_name']);
        header('Content-Type: ' . ($inline ? $a['mime'] : 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes($name, '\\') . '"; filename*=UTF-8\'\'' . rawurlencode($a['file_name']));
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, max-age=86400');
        while (ob_get_level()) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }
}
