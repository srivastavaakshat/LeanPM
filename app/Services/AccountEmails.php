<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Core\View;

/**
 * Account emails: invites, "you've been added", and password reset links. Links are one-time
 * tokens (only their hash is stored) that expire; using one sets the password and signs the
 * person in. All mail goes through the queue (EmailQueue).
 */
final class AccountEmails
{
    public const LIFETIME = ['invite' => 7 * 86400, 'reset' => 3 * 86400, 'forgot' => 3600];

    /** Create a one-time link for a user. Earlier unused links of theirs stop working. */
    public static function link(int $userId, string $purpose): string
    {
        $token = bin2hex(random_bytes(24));
        DB::query('UPDATE password_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $userId]);
        DB::insert('password_tokens', [
            'user_id' => $userId, 'token_hash' => hash('sha256', $token), 'purpose' => $purpose,
            'expires_at' => date('Y-m-d H:i:s', time() + (self::LIFETIME[$purpose] ?? 3600)), 'created_at' => now(),
        ]);
        return absolute_url('/password/set/' . $token);
    }

    /** The user a link belongs to, if it's unused and not expired. */
    public static function find(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        return DB::one(
            'SELECT pt.id AS token_id, pt.purpose, u.* FROM password_tokens pt JOIN users u ON u.id = pt.user_id
             WHERE pt.token_hash = ? AND pt.used_at IS NULL AND pt.expires_at > ?',
            [hash('sha256', $token), now()]
        );
    }

    public static function invite(array $user, array $org, string $inviter, string $link): void
    {
        self::send($user, "{$inviter} added you to {$org['name']} on LeanPM", [
            "{$inviter} added you to <strong>" . e($org['name']) . '</strong> on LeanPM, where the team tracks its projects and tasks.',
            'Choose a password to get started. The link works for 7 days.',
        ], 'Set your password', $link, GoogleAuth::enabled() ? 'You can also sign in with your Google account (' . e($user['email']) . ').' : '');
    }

    public static function addedToOrg(array $user, array $org, string $inviter): void
    {
        self::send($user, "{$inviter} added you to {$org['name']} on LeanPM", [
            "{$inviter} added you to <strong>" . e($org['name']) . '</strong> on LeanPM.',
            'Sign in with your usual email and password and you’ll find it in the organization switcher.',
        ], 'Open LeanPM', absolute_url('/o/' . $org['slug']));
    }

    public static function reset(array $user, string $link, ?string $byAdmin = null): void
    {
        $lines = $byAdmin
            ? ["{$byAdmin} reset your LeanPM password.", 'Choose a new one with the link below. It works for 3 days.']
            : ['Someone (hopefully you) asked to reset your LeanPM password.', 'Choose a new one with the link below. It works for 1 hour. If you didn’t ask for this, ignore this email; your password stays the same.'];
        self::send($user, $byAdmin ? 'Your LeanPM password was reset' : 'Reset your LeanPM password', $lines, 'Choose a new password', $link);
    }

    /** $lines are trusted HTML fragments (escape anything user-provided before passing it in). */
    private static function send(array $user, string $subject, array $lines, string $button, string $url, string $note = ''): void
    {
        $text = "Hi {$user['name']},\n\n" . implode("\n\n", array_map(fn ($l) => html_entity_decode(strip_tags($l), ENT_QUOTES, 'UTF-8'), $lines))
            . "\n\n{$button}: {$url}\n" . ($note !== '' ? "\n" . html_entity_decode(strip_tags($note), ENT_QUOTES, 'UTF-8') . "\n" : '');
        $html = View::partial('emails/basic', ['name' => $user['name'], 'lines' => $lines, 'button' => $button, 'url' => $url, 'note' => $note]);
        EmailQueue::push($user['email'], $user['name'], $subject, $text, $html);
    }
}
