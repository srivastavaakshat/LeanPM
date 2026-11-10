<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Member permissions (PRD §5.1a). Each membership stores its own ticked boxes as JSON;
 * presets only fill the boxes in. Levels: bool permissions are true/false; scoped ones are
 * 'none' | 'own' | 'all', where "own" = tasks the member is assigned to or created.
 */
final class Perms
{
    /** key => [label, group, kind ('bool' | 'scope'), help] */
    public const DEFS = [
        'create_tasks'    => ['Create tasks and subtasks', 'Tasks', 'bool', ''],
        'edit_tasks'      => ['Edit tasks', 'Tasks', 'scope', 'Title, description and subtasks'],
        'move_tasks'      => ['Change status (move cards)', 'Tasks', 'scope', 'Works even when editing is off'],
        'assign'          => ['Assign and unassign people', 'Tasks', 'scope', ''],
        'set_fields'      => ['Set priority, dates and custom fields', 'Tasks', 'scope', 'e.g. the Figma file link'],
        'delete_tasks'    => ['Delete tasks', 'Tasks', 'scope', 'Goes to Trash; the Owner can restore it'],
        'comment'         => ['Comment', 'Comments and files', 'bool', 'Everyone can edit or delete their own comments'],
        'upload'          => ['Upload files', 'Comments and files', 'bool', ''],
        'moderate'        => ['Delete anyone’s comments and files', 'Comments and files', 'bool', ''],
        'meeting_notes'   => ['Use meeting notes', 'Workspace', 'bool', 'Keep meeting notes docs and see notes shared with the organization'],
        'manage_projects' => ['Create, rename and archive projects', 'Workspace', 'bool', ''],
        'manage_statuses' => ['Configure stages', 'Workspace', 'bool', 'Add, rename, reorder and recolor a project’s board columns, or switch them to a template'],
        'export'          => ['Export CSV reports', 'Workspace', 'scope', '“Own” = only tasks they’re assigned to'],
        'manage_members'  => ['Manage members', 'Workspace', 'bool', 'Add, deactivate and set permissions. They can’t grant more than they have.'],
        'org_settings'    => ['Organization settings', 'Workspace', 'bool', 'Name'],
    ];

    public const PRESETS = [
        'admin' => [
            'label' => 'Admin',
            'perms' => ['create_tasks' => true, 'edit_tasks' => 'all', 'move_tasks' => 'all', 'assign' => 'all', 'set_fields' => 'all', 'delete_tasks' => 'all',
                        'comment' => true, 'upload' => true, 'moderate' => true, 'meeting_notes' => true, 'manage_projects' => true, 'manage_statuses' => true,
                        'export' => 'all', 'manage_members' => true, 'org_settings' => true],
        ],
        'lead' => [
            'label' => 'Lead',
            'perms' => ['create_tasks' => true, 'edit_tasks' => 'all', 'move_tasks' => 'all', 'assign' => 'all', 'set_fields' => 'all', 'delete_tasks' => 'all',
                        'comment' => true, 'upload' => true, 'moderate' => true, 'meeting_notes' => true, 'manage_projects' => true, 'manage_statuses' => true,
                        'export' => 'all', 'manage_members' => false, 'org_settings' => false],
        ],
        'designer' => [
            'label' => 'Designer',
            'perms' => ['create_tasks' => true, 'edit_tasks' => 'own', 'move_tasks' => 'own', 'assign' => 'own', 'set_fields' => 'own', 'delete_tasks' => 'none',
                        'comment' => true, 'upload' => true, 'moderate' => false, 'meeting_notes' => true, 'manage_projects' => false, 'manage_statuses' => false,
                        'export' => 'own', 'manage_members' => false, 'org_settings' => false],
        ],
        'viewer' => [
            'label' => 'Viewer',
            'perms' => ['create_tasks' => false, 'edit_tasks' => 'none', 'move_tasks' => 'none', 'assign' => 'none', 'set_fields' => 'none', 'delete_tasks' => 'none',
                        'comment' => true, 'upload' => false, 'moderate' => false, 'meeting_notes' => false, 'manage_projects' => false, 'manage_statuses' => false,
                        'export' => 'none', 'manage_members' => false, 'org_settings' => false],
        ],
    ];

    private const RANK = ['none' => 0, 'own' => 1, 'all' => 2];

    /** Everything on — the Owner. */
    public static function all(): array
    {
        return self::PRESETS['admin']['perms'];
    }

    /** Clean a stored or submitted permission set: every key present, valid values only. */
    public static function normalize(array $raw): array
    {
        $out = [];
        foreach (self::DEFS as $key => [, , $kind]) {
            $v = $raw[$key] ?? null;
            if ($kind === 'bool') {
                $out[$key] = $v === true || $v === 1 || $v === '1' || $v === 'on';
            } else {
                $out[$key] = is_string($v) && isset(self::RANK[$v]) ? $v : 'none';
            }
        }
        return $out;
    }

    /**
     * A member's saved permissions. A permission added after they were saved (e.g. meeting_notes)
     * isn't in their JSON yet; it takes the value from their preset, so nobody silently loses access.
     */
    public static function decode(?string $json, ?string $preset = null): array
    {
        $saved = json_decode((string) $json, true) ?: [];
        $defaults = self::PRESETS[$preset ?? '']['perms'] ?? [];
        return self::normalize($saved + $defaults);
    }

    /** Never grant more than the granter has. */
    public static function clamp(array $wanted, array $granter): array
    {
        $out = [];
        foreach (self::DEFS as $key => [, , $kind]) {
            if ($kind === 'bool') {
                $out[$key] = $wanted[$key] && $granter[$key];
            } else {
                $out[$key] = self::RANK[$wanted[$key]] <= self::RANK[$granter[$key]] ? $wanted[$key] : $granter[$key];
            }
        }
        return $out;
    }

    /** The preset these permissions match exactly, or null when customized. */
    public static function matchingPreset(array $perms): ?string
    {
        foreach (self::PRESETS as $key => $p) {
            if (self::normalize($p['perms']) == $perms) {
                return $key;
            }
        }
        return null;
    }

    public static function label(string $preset, array $perms): string
    {
        $base = self::PRESETS[$preset]['label'] ?? 'Custom';
        return self::matchingPreset($perms) === $preset ? $base : $base . ' (customized)';
    }

    /** Groups for rendering the checkbox form. */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::DEFS as $key => [$label, $group, $kind, $help]) {
            $groups[$group][$key] = ['label' => $label, 'kind' => $kind, 'help' => $help];
        }
        return $groups;
    }
}
