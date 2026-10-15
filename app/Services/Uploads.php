<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database as DB;

/** Task and comment files, stored outside the web root and served only to members. */
final class Uploads
{
    // Never store anything a browser or the server could run.
    private const BLOCKED_EXT = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht', 'cgi', 'pl', 'py', 'sh', 'exe', 'bat', 'cmd', 'com', 'js', 'mjs', 'htaccess', 'html', 'htm', 'shtml', 'xhtml', 'svgz'];

    public static function dir(int $orgId, int $taskId): string
    {
        return config('storage_path') . "/uploads/{$orgId}/{$taskId}";
    }

    /** Normalize $_FILES['files'] (single or multiple) into a list. */
    public static function fromRequest(string $field = 'files'): array
    {
        $f = $_FILES[$field] ?? null;
        if (!$f) {
            return [];
        }
        if (!is_array($f['name'])) {
            return [$f];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
        return $out;
    }

    /** Store one uploaded file for a task. Returns the attachment row, or throws with a readable message. */
    public static function store(array $file, array $task, ?int $commentId = null): array
    {
        $max = (int) config('upload_max_mb', 25) * 1048576;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "{$file['name']} is larger than the server allows.",
                UPLOAD_ERR_NO_FILE => 'No file was sent.',
                default => "{$file['name']} didn’t upload. Please try again.",
            });
        }
        if ($file['size'] > $max) {
            throw new \RuntimeException("{$file['name']} is over " . config('upload_max_mb', 25) . ' MB.');
        }
        $name = self::cleanName((string) $file['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, self::BLOCKED_EXT, true)) {
            throw new \RuntimeException("{$name}: this file type can’t be uploaded. Zip it first.");
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
        $dir = self::dir((int) $task['org_id'], (int) $task['id']);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('The server couldn’t save files. Check that storage/uploads is writable.');
        }
        $stored = bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . $ext : '');
        $ok = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], "$dir/$stored") : rename($file['tmp_name'], "$dir/$stored");
        if (!$ok) {
            throw new \RuntimeException("{$name} couldn’t be saved.");
        }
        $row = [
            'task_id'     => (int) $task['id'],
            'comment_id'  => $commentId,
            'user_id'     => Auth::id(),
            'file_name'   => $name,
            'stored_name' => $stored,
            'size'        => (int) $file['size'],
            'mime'        => mb_substr($mime, 0, 120),
            'created_at'  => now(),
        ];
        $row['id'] = DB::insert('attachments', $row);
        Activity::log((int) $task['id'], 'file_added', null, $name);
        return $row;
    }

    public static function path(array $attachment, int $orgId): string
    {
        return self::dir($orgId, (int) $attachment['task_id']) . '/' . basename($attachment['stored_name']);
    }

    public static function delete(array $attachment, int $orgId): void
    {
        $path = self::path($attachment, $orgId);
        if (is_file($path)) {
            @unlink($path);
        }
        DB::query('DELETE FROM attachments WHERE id = ?', [$attachment['id']]);
    }

    public static function isImage(string $mime): bool
    {
        return in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'], true);
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"<>|:*?]+/u', '', $name) ?? 'file';
        $name = trim($name, ". \t");
        return mb_substr($name !== '' ? $name : 'file', 0, 200);
    }
}
