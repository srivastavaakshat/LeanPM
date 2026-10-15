<?php
declare(strict_types=1);

namespace App\Core;

/** Sign-in for team members. Accounts are created by the Owner or a member manager, never by sign-up. */
final class Auth
{
    private const MAX_ATTEMPTS = 6;      // per IP …
    private const WINDOW_MIN   = 15;     // … per 15 minutes

    private static ?array $cached = null;

    public static function user(): ?array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        self::$cached = Database::one('SELECT id, name, email, color, is_owner, must_change_password FROM users WHERE id = ?', [(int) $id]);
        // Deactivated everywhere (and not the Owner) = signed out.
        if (self::$cached && !self::$cached['is_owner']
            && !Database::value('SELECT COUNT(*) FROM memberships WHERE user_id = ? AND active = 1', [(int) $id])) {
            self::$cached = null;
        }
        return self::$cached;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function isOwner(): bool
    {
        return (bool) (self::user()['is_owner'] ?? false);
    }

    /** Returns null on success, or an error message. */
    public static function attempt(string $email, string $password): ?string
    {
        $ipHash = hash('sha256', client_ip() . '|' . config('app_key'));
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MIN * 60);
        $recent = (int) Database::value('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND attempted_at > ?', [$ipHash, $since]);
        if ($recent >= self::MAX_ATTEMPTS) {
            return 'Too many attempts. Please wait 15 minutes and try again.';
        }

        $user = Database::one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
        // Verify against a dummy hash when the email is unknown, so timing doesn't reveal valid emails.
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringforsaltO6fPmeJ7c0Ov7lW9X5xQ4m0YgQb6';
        if (!password_verify($password, $hash) || !$user) {
            Database::insert('login_attempts', ['ip_hash' => $ipHash, 'email' => mb_substr($email, 0, 190), 'attempted_at' => now()]);
            return 'That email and password don’t match.';
        }
        if (!$user['is_owner'] && !Database::value('SELECT COUNT(*) FROM memberships WHERE user_id = ? AND active = 1', [$user['id']])) {
            return 'Your account has been deactivated. Ask your admin to turn it back on.';
        }

        Database::query('DELETE FROM login_attempts WHERE ip_hash = ?', [$ipHash]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        Session::regenerate();
        $_SESSION['user_id'] = (int) $user['id'];
        Database::update('users', ['last_login_at' => now()], 'id = ?', [$user['id']]);
        self::$cached = null;
        return null;
    }

    public static function logout(): void
    {
        self::$cached = null;
        Session::destroy();
    }

    public static function enforce(bool $allowPasswordChange = false): void
    {
        $user = self::user();
        if (!$user) {
            if (!empty($GLOBALS['__json'])) {
                abort(401, 'You’ve been signed out. Refresh the page to sign in again.');
            }
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? null;
            }
            redirect('/login');
        }
        if ($user['must_change_password'] && !$allowPasswordChange) {
            if (!empty($GLOBALS['__json'])) {
                abort(403, 'Please set your own password first.');
            }
            redirect('/account/password');
        }
    }
}
