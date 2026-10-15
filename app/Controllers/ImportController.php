<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Access;
use App\Services\CsvImport;
use App\Services\Fields;
use App\Services\Tasks;

/** Import tasks from a CSV: upload → check the column mapping and preview → import. */
final class ImportController
{
    private function enter(string $org, string $project): array
    {
        $o = Access::org($org);
        $p = Access::project((int) $project);
        Access::require('create_tasks');
        return [$o, $p, "/o/{$o['slug']}/p/{$p['id']}/import"];
    }

    private static function file(string $token): string
    {
        return config('storage_path') . '/imports/' . preg_replace('/[^a-f0-9]/', '', $token) . '.csv';
    }

    /** The uploaded file waiting for this project, if any. */
    private static function pending(int $projectId): ?array
    {
        $p = $_SESSION['import'] ?? null;
        return $p && (int) $p['project'] === $projectId && is_file(self::file($p['token'])) ? $p : null;
    }

    public function show(string $org, string $project): string
    {
        [$o, $p, $base] = $this->enter($org, $project);
        $pending = self::pending((int) $p['id']);
        $data = ['title' => 'Import tasks', 'project' => $p, 'base' => $base, 'result' => $_SESSION['import_result'] ?? null];
        unset($_SESSION['import_result']);
        if (!$pending) {
            return view('project/import', $data + ['step' => 'upload']);
        }
        [$headers, $rows] = CsvImport::read(self::file($pending['token']));
        $fields = Fields::forProject((int) $p['id']);
        return view('project/import', $data + [
            'step' => 'map', 'name' => $pending['name'], 'headers' => $headers, 'rows' => $rows,
            'guess' => CsvImport::guess($headers, $fields), 'fields' => $fields, 'statuses' => Tasks::statuses((int) $p['id']),
        ]);
    }

    public function upload(string $org, string $project): never
    {
        [, $p, $base] = $this->enter($org, $project);
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Choose a CSV file to upload.');
            redirect($base);
        }
        if ($f['size'] > CsvImport::MAX_BYTES) {
            flash('error', 'That file is over 5 MB. Split it into smaller files.');
            redirect($base);
        }
        if (!preg_match('/\.(csv|tsv|txt)$/i', (string) $f['name'])) {
            flash('error', 'Upload a .csv file. In Excel or Google Sheets use File → Download / Save as → CSV.');
            redirect($base);
        }
        [$headers, $rows] = CsvImport::read($f['tmp_name']);
        if (!$headers || !$rows) {
            flash('error', 'That file has no rows to import. The first row should be the column names.');
            redirect($base);
        }
        if (count($rows) > CsvImport::MAX_ROWS) {
            flash('error', 'That file has more than ' . CsvImport::MAX_ROWS . ' rows. Split it into smaller files.');
            redirect($base);
        }
        $dir = config('storage_path') . '/imports';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Clear out abandoned uploads older than a day.
        foreach (glob($dir . '/*.csv') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        $token = bin2hex(random_bytes(16));
        move_uploaded_file($f['tmp_name'], self::file($token));
        $_SESSION['import'] = ['project' => (int) $p['id'], 'token' => $token, 'name' => basename((string) $f['name'])];
        redirect($base);
    }

    public function run(string $org, string $project): never
    {
        [$o, $p, $base] = $this->enter($org, $project);
        $pending = self::pending((int) $p['id']);
        if (!$pending) {
            flash('error', 'Upload the file again, the previous upload expired.');
            redirect($base);
        }
        [$headers, $rows] = CsvImport::read(self::file($pending['token']));
        $allowed = array_merge(array_keys(CsvImport::TARGETS), array_map(fn ($f) => 'field:' . $f['id'], Fields::forProject((int) $p['id'])));
        $map = [];
        foreach ($headers as $i => $_) {
            $t = (string) ($_POST['map'][$i] ?? '');
            $map[$i] = in_array($t, $allowed, true) ? $t : '';
        }
        $targets = array_filter($map);
        if (count($targets) !== count(array_unique($targets))) {
            flash('error', 'Two columns are set to the same field. Each field can come from one column only.');
            redirect($base);
        }
        try {
            $result = CsvImport::run($p, $headers, $rows, $map, [
                'default_status' => (int) input('default_status'),
                'date_format'    => input('date_format') === 'mdy' ? 'mdy' : 'dmy',
            ]);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect($base);
        }
        @unlink(self::file($pending['token']));
        unset($_SESSION['import']);
        $_SESSION['import_result'] = $result;
        redirect($base);
    }

    public function cancel(string $org, string $project): never
    {
        [, $p, $base] = $this->enter($org, $project);
        if ($pending = self::pending((int) $p['id'])) {
            @unlink(self::file($pending['token']));
        }
        unset($_SESSION['import']);
        redirect($base);
    }

    /** A sample file with every column, matching this project's stages and fields. */
    public function sample(string $org, string $project): never
    {
        [, $p] = $this->enter($org, $project);
        $statuses = Tasks::statuses((int) $p['id']);
        $fields = Fields::forProject((int) $p['id']);
        $head = ['Title', 'Description', 'Status', 'Priority', 'Assignees', 'Start Date', 'Due Date', 'Parent Task', ...array_column($fields, 'name')];
        $sampleField = fn ($f) => match ($f['type']) { 'url' => 'https://www.figma.com/design/your-file-id', 'number' => '3', 'date' => date('Y-m-d'), 'select' => $f['options'][0] ?? '', 'checkbox' => 'yes', default => 'text' };
        $rows = [
            ['Homepage banner for IPL final', 'Two sizes: 1200x628 and 1080x1080', $statuses[0]['name'] ?? '', 'High', \App\Core\Auth::user()['name'], date('Y-m-d'), date('Y-m-d', strtotime('+5 days')), '', ...array_map($sampleField, $fields)],
            ['Mobile version', '', $statuses[0]['name'] ?? '', 'Normal', '', '', date('Y-m-d', strtotime('+5 days')), 'Homepage banner for IPL final', ...array_map(fn () => '', $fields)],
        ];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leanpm-import-sample.csv"');
        $fh = fopen('php://output', 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ([$head, ...$rows] as $r) {
            fputcsv($fh, $r, ',', '"', '');
        }
        exit;
    }
}
