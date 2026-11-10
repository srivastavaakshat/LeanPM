<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;

/** Adding someone to an organization: used by the Members page and by owners adding an org's admin. */
final class Members
{
    /**
     * Add a person (new or existing account) to an org and email them. Returns
     * ['user' => row, 'new' => bool, 'link' => set-password link for a new account, else null].
     * The caller has already checked that they aren't a member and clamped $perms.
     */
    public static function add(array $org, string $name, string $email, string $preset, array $perms, bool $all, array $projectIds, string $inviter): array
    {
        $user = DB::one('SELECT * FROM users WHERE email = ?', [$email]);
        $isNew = !$user;
        DB::transaction(function () use ($org, $name, $email, &$user, $preset, $perms, $all, $projectIds) {
            if (!$user) {
                // Nobody knows this password; they choose their own from the invite link.
                $id = DB::insert('users', [
                    'name' => $name, 'email' => $email, 'password_hash' => password_hash(random_password(32), PASSWORD_DEFAULT),
                    'color' => random_color(), 'is_owner' => 0, 'must_change_password' => 1, 'created_at' => now(),
                ]);
                $user = ['id' => $id, 'name' => $name, 'email' => $email, 'is_owner' => 0];
            }
            $mid = DB::insert('memberships', [
                'org_id' => $org['id'], 'user_id' => $user['id'], 'preset' => $preset, 'permissions' => json_encode($perms),
                'all_projects' => $all ? 1 : 0, 'active' => 1, 'joined_at' => now(),
            ]);
            if (!$all) {
                foreach ($projectIds as $pid) {
                    DB::insert('membership_projects', ['membership_id' => $mid, 'project_id' => (int) $pid]);
                }
            }
        });

        $link = null;
        if ($isNew) {
            $link = AccountEmails::link((int) $user['id'], 'invite');
            AccountEmails::invite($user, $org, $inviter, $link);
        } elseif ((int) $user['id'] !== \App\Core\Auth::id()) {
            AccountEmails::addedToOrg($user, $org, $inviter);
        }
        return ['user' => $user, 'new' => $isNew, 'link' => $link];
    }

    /**
     * Whether a set-password link for this person may be shown on screen to an org admin. Not for
     * owners or people in other organizations: the link would let one org's admin take over an
     * account that reaches further than their org. Those links go by email only.
     */
    public static function linkMayBeShown(array $user, int $orgId): bool
    {
        return !(int) ($user['is_owner'] ?? 0)
            && !DB::value('SELECT COUNT(*) FROM memberships WHERE user_id = ? AND org_id <> ?', [$user['id'], $orgId]);
    }
}
