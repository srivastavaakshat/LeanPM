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
        ]);
    }

    public function update(): never
    {
        $name = mb_substr((string) input('name', ''), 0, 120);
        if ($name === '') {
            with_input_errors(['name' => 'Please enter your name.']);
            redirect('/account');
        }
        DB::update('users', ['name' => $name, 'notify_mentions' => input('notify_mentions') === '1' ? 1 : 0], 'id = ?', [Auth::id()]);
        flash('success', 'Saved.');
        redirect('/account');
    }
}
