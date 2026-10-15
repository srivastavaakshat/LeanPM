<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Services\GoogleAuth;

/**
 * Sign in with Google. Only for people already added as members: the Google account's verified
 * email must match their LeanPM email (or the Google account they linked before). No sign-ups.
 */
final class GoogleController
{
    public function start(): never
    {
        if (!GoogleAuth::enabled()) {
            abort(404);
        }
        $_SESSION['google_linking'] = Auth::user() !== null; // "Connect Google" from Your account
        redirect(GoogleAuth::authUrl());
    }

    public function callback(): never
    {
        if (!GoogleAuth::enabled()) {
            abort(404);
        }
        $linking = !empty($_SESSION['google_linking']) && Auth::user();
        unset($_SESSION['google_linking']);
        try {
            $g = GoogleAuth::finish($_GET);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), $linking);
        }

        if ($linking) {
            $me = Auth::user();
            $other = DB::one('SELECT id FROM users WHERE google_sub = ? AND id <> ?', [$g['sub'], $me['id']]);
            if ($other) {
                $this->fail('That Google account is already connected to another LeanPM user.', true);
            }
            DB::update('users', ['google_sub' => $g['sub']], 'id = ?', [$me['id']]);
            flash('success', "Google account {$g['email']} connected. You can now sign in with Google.");
            redirect('/account');
        }

        $user = DB::one('SELECT * FROM users WHERE google_sub = ?', [$g['sub']])
            ?? DB::one('SELECT * FROM users WHERE email = ? AND google_sub IS NULL', [$g['email']]);
        if (!$user) {
            $this->fail("There’s no LeanPM account for {$g['email']}. Ask your admin to add you with this email, or sign in with your password.", false);
        }
        if ($error = Auth::loginAs($user)) {
            $this->fail($error, false);
        }
        // Google proved who they are, so there's no temporary password left to replace.
        DB::update('users', ['google_sub' => $g['sub'], 'must_change_password' => 0], 'id = ?', [$user['id']]);

        $to = $_SESSION['intended'] ?? null;
        unset($_SESSION['intended']);
        redirect(is_string($to) && str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/');
    }

    public function unlink(): never
    {
        DB::update('users', ['google_sub' => null], 'id = ?', [Auth::id()]);
        flash('success', 'Google account disconnected. Sign in with your email and password from now on.');
        redirect('/account');
    }

    private function fail(string $message, bool $linking): never
    {
        if ($linking) {
            flash('error', $message);
            redirect('/account');
        }
        with_input_errors(['email' => $message]);
        redirect('/login');
    }
}
