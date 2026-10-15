<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;

final class AuthController
{
    public function show(): string
    {
        if (Auth::user()) {
            redirect('/');
        }
        return view('auth/login', ['title' => 'Sign in'], 'layouts/plain');
    }

    public function login(): never
    {
        $email = (string) input('email', '');
        $error = Auth::attempt($email, (string) ($_POST['password'] ?? ''));
        if ($error) {
            with_input_errors(['email' => $error], ['email' => $email]);
            redirect('/login');
        }
        $to = $_SESSION['intended'] ?? null;
        unset($_SESSION['intended']);
        redirect(is_string($to) && str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/');
    }

    public function logout(): never
    {
        Auth::logout();
        redirect('/login');
    }
}
