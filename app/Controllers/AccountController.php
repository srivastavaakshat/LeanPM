<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;

final class AccountController
{
    public function password(): string
    {
        return view('auth/password', ['title' => 'Set your password', 'forced' => (bool) Auth::user()['must_change_password']], 'layouts/plain');
    }

    public function updatePassword(): never
    {
        $user = DB::one('SELECT * FROM users WHERE id = ?', [Auth::id()]);
        $new = (string) ($_POST['password'] ?? '');
        $errors = [];
        if (!$user['must_change_password'] && !password_verify((string) ($_POST['current'] ?? ''), $user['password_hash'])) {
            $errors['current'] = 'That isn’t your current password.';
        }
        if (mb_strlen($new) < 8) {
            $errors['password'] = 'Use at least 8 characters.';
        } elseif ($new !== ($_POST['password_confirm'] ?? '')) {
            $errors['password'] = 'The two passwords don’t match.';
        } elseif (password_verify($new, $user['password_hash'])) {
            $errors['password'] = 'Choose a new password, not the temporary one.';
        }
        if ($errors) {
            with_input_errors($errors);
            redirect('/account/password');
        }
        DB::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = ?', [$user['id']]);
        \App\Core\Session::regenerate();
        flash('success', 'Password saved.');
        redirect($user['must_change_password'] ? '/' : '/account');
    }

    public function show(): string
    {
        return view('auth/account', [
            'title' => 'Your account', 'user' => Auth::user(), 'orgs' => \App\Services\Access::myOrgs(),
            'notify' => (int) DB::value('SELECT notify_mentions FROM users WHERE id = ?', [Auth::id()]),
            'googleLinked' => (bool) DB::value('SELECT google_sub FROM users WHERE id = ?', [Auth::id()]),
        ]);
    }

    public function update(): never
    {
        $user = DB::one('SELECT * FROM users WHERE id = ?', [Auth::id()]);
        $name = mb_substr(trim((string) input('name', '')), 0, 120);
        $email = mb_strtolower(trim((string) input('email', $user['email'])));
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Please enter your name.';
        }
        $emailChanged = $email !== $user['email'];
        if ($emailChanged) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                $errors['email'] = 'Enter a valid email address.';
            } elseif (DB::value('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$email, $user['id']])) {
                $errors['email'] = 'Another account already uses that email.';
            } elseif (!password_verify((string) ($_POST['current'] ?? ''), $user['password_hash'])) {
                $errors['current'] = 'Enter your current password to change your email.';
            }
        }
        if ($errors) {
            with_input_errors($errors, ['name' => $name, 'email' => $email]);
            redirect('/account');
        }
        $data = ['name' => $name, 'notify_mentions' => input('notify_mentions') === '1' ? 1 : 0];
        if ($emailChanged) {
            // Sign-in now uses the new email. Old set-password links stop working.
            $data['email'] = $email;
            DB::query('UPDATE password_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $user['id']]);
        }
        DB::update('users', $data, 'id = ?', [$user['id']]);
        flash('success', $emailChanged ? "Saved. Sign in with {$email} from now on." : 'Saved.');
        redirect('/account');
    }
}
