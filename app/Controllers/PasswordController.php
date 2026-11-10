<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\AccountEmails;

/** "Forgot password?" and the one-time links from invite / reset emails. */
final class PasswordController
{
    public function forgot(): string
    {
        return view('auth/forgot', ['title' => 'Reset your password', 'sent' => (bool) ($_SESSION['forgot_sent'] ?? false)], 'layouts/plain');
    }

    public function sendForgot(): never
    {
        $email = mb_strtolower(trim((string) input('email', '')));
        $ipHash = hash('sha256', client_ip() . '|' . config('app_key'));
        $since = date('Y-m-d H:i:s', time() - 3600);
        // Rate limits: 10 requests per hour from one address, 3 emails per hour to one person.
        $fromIp = (int) DB::value("SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND email LIKE 'forgot:%' AND attempted_at > ?", [$ipHash, $since]);
        DB::insert('login_attempts', ['ip_hash' => $ipHash, 'email' => mb_substr('forgot:' . $email, 0, 190), 'attempted_at' => now()]);
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) && $fromIp < 10 ? DB::one('SELECT * FROM users WHERE email = ?', [$email]) : null;
        if ($user) {
            $recent = (int) DB::value("SELECT COUNT(*) FROM password_tokens WHERE user_id = ? AND purpose = 'forgot' AND created_at > ?", [$user['id'], $since]);
            $active = $user['is_owner'] || DB::value('SELECT COUNT(*) FROM memberships WHERE user_id = ? AND active = 1', [$user['id']]);
            if ($recent < 3 && $active) {
                AccountEmails::reset($user, AccountEmails::link((int) $user['id'], 'forgot'));
            }
        }
        // Same answer whether or not the email has an account, so this can't be used to find accounts.
        $_SESSION['forgot_sent'] = true;
        redirect('/password/forgot');
    }

    public function show(string $token): string
    {
        $user = AccountEmails::find($token);
        if (!$user) {
            return view('auth/link-expired', ['title' => 'Link expired'], 'layouts/plain');
        }
        return view('auth/set-password', ['title' => 'Choose your password', 'user' => $user, 'token' => $token, 'invite' => $user['purpose'] === 'invite'], 'layouts/plain');
    }

    public function save(string $token): never
    {
        $user = AccountEmails::find($token);
        if (!$user) {
            redirect('/password/set/' . $token);
        }
        $new = (string) ($_POST['password'] ?? '');
        if (mb_strlen($new) < 8) {
            with_input_errors(['password' => 'Use at least 8 characters.']);
            redirect('/password/set/' . $token);
        }
        if ($new !== ($_POST['password_confirm'] ?? '')) {
            with_input_errors(['password' => 'The two passwords don’t match.']);
            redirect('/password/set/' . $token);
        }
        DB::transaction(function () use ($user, $new) {
            DB::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = ?', [$user['id']]);
            DB::query('UPDATE password_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $user['id']]);
        });
        if ($error = Auth::loginAs(DB::one('SELECT * FROM users WHERE id = ?', [$user['id']]))) {
            flash('error', $error);
            redirect('/login');
        }
        unset($_SESSION['forgot_sent']);
        flash('success', $user['purpose'] === 'invite' ? 'Welcome to LeanPM!' : 'Password saved. You’re signed in.');
        redirect('/');
    }
}
