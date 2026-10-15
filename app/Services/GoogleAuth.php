<?php
declare(strict_types=1);

namespace App\Services;

/**
 * "Sign in with Google" (OpenID Connect, authorization-code flow) without any library.
 * Credentials live in config/local.php under 'google' (client_id, client_secret, optional
 * allowed_domains). The ID token comes straight from Google's token endpoint over TLS, so per
 * the OpenID Connect spec its claims are checked (issuer, audience, expiry, nonce, verified email)
 * rather than its signature.
 */
final class GoogleAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public static function enabled(): bool
    {
        return (string) config('google.client_id', '') !== '' && (string) config('google.client_secret', '') !== '';
    }

    public static function redirectUri(): string
    {
        return absolute_url('/auth/google/callback');
    }

    /** The Google consent URL; remembers state and nonce in the session. */
    public static function authUrl(): string
    {
        $_SESSION['google_state'] = bin2hex(random_bytes(16));
        $_SESSION['google_nonce'] = bin2hex(random_bytes(16));
        $params = [
            'client_id'     => config('google.client_id'),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $_SESSION['google_state'],
            'nonce'         => $_SESSION['google_nonce'],
            'prompt'        => 'select_account',
        ];
        $domains = (array) config('google.allowed_domains', []);
        if (count($domains) === 1) {
            $params['hd'] = $domains[0]; // a hint for Google's account chooser; still checked below
        }
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Finish the sign-in: check state, swap the code for an ID token, check its claims.
     * Returns ['sub', 'email', 'name'] or throws with a message for the person.
     */
    public static function finish(array $query): array
    {
        $state = (string) ($query['state'] ?? '');
        $expected = (string) ($_SESSION['google_state'] ?? '');
        $nonce = (string) ($_SESSION['google_nonce'] ?? '');
        unset($_SESSION['google_state'], $_SESSION['google_nonce']);
        if ($expected === '' || !hash_equals($expected, $state)) {
            throw new \RuntimeException('That Google sign-in link expired. Please try again.');
        }
        if (!empty($query['error'])) {
            throw new \RuntimeException($query['error'] === 'access_denied' ? 'Google sign-in was cancelled.' : 'Google sign-in didn’t work. Please try again.');
        }
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            throw new \RuntimeException('Google didn’t send a sign-in code. Please try again.');
        }

        $res = self::post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => config('google.client_id'),
            'client_secret' => config('google.client_secret'),
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        $idToken = (string) ($res['id_token'] ?? '');
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            error_log('Google token response: ' . json_encode(array_diff_key($res, ['access_token' => 1, 'refresh_token' => 1])));
            throw new \RuntimeException('Google sign-in didn’t work. Please try again.');
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) ?: [];
        return self::verify($claims, $nonce);
    }

    /** Check the ID token's claims. Returns ['sub', 'email', 'name'] or throws with a message for the person. */
    public static function verify(array $claims, string $nonce): array
    {
        $ok = in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
            && ($claims['aud'] ?? '') === config('google.client_id')
            && (int) ($claims['exp'] ?? 0) > time() - 60
            && $nonce !== '' && hash_equals($nonce, (string) ($claims['nonce'] ?? ''));
        if (!$ok) {
            throw new \RuntimeException('Google sign-in couldn’t be verified. Please try again.');
        }
        $email = mb_strtolower((string) ($claims['email'] ?? ''));
        if ($email === '' || !($claims['email_verified'] ?? false)) {
            throw new \RuntimeException('Your Google account’s email isn’t verified, so it can’t be used to sign in.');
        }
        $domains = array_map('mb_strtolower', (array) config('google.allowed_domains', []));
        if ($domains && !in_array(substr(strrchr($email, '@') ?: '', 1), $domains, true)) {
            throw new \RuntimeException("Only Google accounts from " . implode(', ', $domains) . " can sign in here.");
        }
        return ['sub' => (string) $claims['sub'], 'email' => $email, 'name' => (string) ($claims['name'] ?? '')];
    }

    private static function post(string $url, array $fields): array
    {
        $body = http_build_query($fields);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            ]);
            $raw = curl_exec($ch);
            $err = curl_error($ch);
        } else {
            $raw = @file_get_contents($url, false, stream_context_create(['http' => [
                'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $body, 'timeout' => 15, 'ignore_errors' => true,
            ]]));
            $err = $raw === false ? 'request failed' : '';
        }
        if ($raw === false || $raw === '') {
            error_log('Google token request failed: ' . $err);
            throw new \RuntimeException('Couldn’t reach Google. Please try again in a minute.');
        }
        return json_decode((string) $raw, true) ?: [];
    }
}
