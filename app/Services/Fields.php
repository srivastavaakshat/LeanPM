<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;

/**
 * Custom fields per project (PRD §5.6a), e.g. a "Figma file" link on every design task.
 * Each project has its own list; templates add a starting set. Values are stored as text.
 */
final class Fields
{
    public const TYPES = [
        'url'      => 'Link',
        'text'     => 'Text',
        'number'   => 'Number',
        'date'     => 'Date',
        'select'   => 'Dropdown',
        'checkbox' => 'Checkbox',
    ];

    /** Starting fields for each project template (see Tasks::TEMPLATES). [name, type, options] */
    public const TEMPLATES = [
        'design'  => [['Figma file', 'url', []]],
        'project' => [['Doc link', 'url', []], ['Estimate (hours)', 'number', []]],
        'review'  => [['Submission link', 'url', []], ['Reviewer', 'text', []]],
    ];

    public static function forProject(int $projectId): array
    {
        return array_map([self::class, 'shape'], DB::all('SELECT * FROM project_fields WHERE project_id = ? ORDER BY position, id', [$projectId]));
    }

    public static function shape(array $f): array
    {
        return [
            'id'       => (int) $f['id'],
            'name'     => $f['name'],
            'type'     => isset(self::TYPES[$f['type']]) ? $f['type'] : 'text',
            'options'  => array_values(array_filter(array_map('strval', json_decode((string) $f['options'], true) ?: []), 'strlen')),
            'position' => (int) $f['position'],
        ];
    }

    /** Add a template's fields to a project, skipping names it already has. Returns how many were added. */
    public static function addTemplate(int $projectId, string $template): int
    {
        $have = array_map('mb_strtolower', array_column(self::forProject($projectId), 'name'));
        $pos = count($have);
        $added = 0;
        foreach (self::TEMPLATES[Tasks::template($template)] ?? [] as [$name, $type, $options]) {
            if (in_array(mb_strtolower($name), $have, true)) {
                continue;
            }
            DB::insert('project_fields', ['project_id' => $projectId, 'name' => $name, 'type' => $type, 'options' => json_encode($options), 'position' => $pos++]);
            $added++;
        }
        return $added;
    }

    /** {task_id: {field_id: value}} for these tasks. */
    public static function values(array $taskIds): array
    {
        if (!$taskIds) {
            return [];
        }
        $out = [];
        foreach (DB::all('SELECT task_id, field_id, value FROM task_field_values WHERE task_id IN (' . DB::placeholders($taskIds) . ')', $taskIds) as $v) {
            $out[(int) $v['task_id']][(int) $v['field_id']] = $v['value'];
        }
        return $out;
    }

    /** Validate one submitted value. Returns the value to store ('' = clear), or throws with a readable message. */
    public static function clean(array $field, mixed $raw): string
    {
        $v = is_bool($raw) ? ($raw ? '1' : '') : trim((string) ($raw ?? ''));
        if ($v === '') {
            return '';
        }
        switch ($field['type']) {
            case 'url':
                if (!preg_match('#^https?://#i', $v)) {
                    $v = 'https://' . ltrim($v, '/');
                }
                if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v)) {
                    throw new \InvalidArgumentException("“{$field['name']}” needs a web link (https://…).");
                }
                return mb_substr($v, 0, 2000);
            case 'number':
                if (!is_numeric($v)) {
                    throw new \InvalidArgumentException("“{$field['name']}” needs a number.");
                }
                return (string) (0 + $v);
            case 'date':
                if (!valid_date($v)) {
                    throw new \InvalidArgumentException("“{$field['name']}” needs a valid date.");
                }
                return $v;
            case 'select':
                if (!in_array($v, $field['options'], true)) {
                    throw new \InvalidArgumentException("“{$v}” isn’t one of the options for “{$field['name']}”.");
                }
                return $v;
            case 'checkbox':
                return in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true) ? '1' : '';
            default:
                return mb_substr($v, 0, 2000);
        }
    }

    /** Readable value for history lines and CSV. */
    public static function display(array $field, string $value): string
    {
        if ($field['type'] === 'checkbox') {
            return $value === '1' ? 'Yes' : 'No';
        }
        return $value;
    }
}
