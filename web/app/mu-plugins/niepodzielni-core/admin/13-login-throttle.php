<?php

/**
 * Ograniczenie prób logowania per IP (sliding window w transients).
 *
 * Po MAX_ATTEMPTS nieudanych próbach w ciągu WINDOW sekund — IP jest blokowany
 * na LOCKOUT sekund.  Działa zarówno dla `wp-login.php` jak i Application
 * Passwords (REST `/wp-json/wp/v2/users/me`), bo wpina się w filtr
 * `authenticate` który WP wywołuje dla każdej formy logowania.
 */

if (! defined('ABSPATH')) {
    exit;
}

const NP_LOGIN_MAX_ATTEMPTS  = 5;
const NP_LOGIN_WINDOW        = 15 * MINUTE_IN_SECONDS;
const NP_LOGIN_LOCKOUT       = 30 * MINUTE_IN_SECONDS;

function np_login_client_ip(): string
{
    // CF / reverse-proxy aware.  REMOTE_ADDR jest ostatecznym fallbackiem.
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = isset($_SERVER[$key]) ? (string) $_SERVER[$key] : '';
        if ($value === '') {
            continue;
        }
        // X-Forwarded-For może być listą — bierzemy pierwszy element.
        $ip = trim((string) explode(',', $value)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

function np_login_keys(string $ip): array
{
    $hash = substr(hash('sha256', $ip), 0, 16);
    return [
        'attempts' => 'np_login_attempts_' . $hash,
        'lockout'  => 'np_login_lockout_'  . $hash,
    ];
}

// Krok 1 — przed walidacją hasła odrzucamy zlockowane IP (timing-stable response).
add_filter('authenticate', static function ($user, $username, $password) {
    if (empty($username) && empty($password)) {
        return $user; // wstępna inicjalizacja, bez próby logowania
    }

    $keys = np_login_keys(np_login_client_ip());
    if (get_transient($keys['lockout'])) {
        return new \WP_Error(
            'np_login_locked',
            __('Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za 30 minut.'),
        );
    }

    return $user;
}, 1, 3);

// Krok 2 — zliczamy nieudane próby.
add_action('wp_login_failed', static function (string $username): void {
    $keys     = np_login_keys(np_login_client_ip());
    $attempts = (int) get_transient($keys['attempts']);
    $attempts++;

    if ($attempts >= NP_LOGIN_MAX_ATTEMPTS) {
        set_transient($keys['lockout'],  1,        NP_LOGIN_LOCKOUT);
        delete_transient($keys['attempts']);

        // Hook dla audit-log mu-plugina (Etap 5).
        do_action('np_security_lockout', [
            'event'    => 'login_lockout',
            'ip'       => np_login_client_ip(),
            'username' => $username,
        ]);
        return;
    }

    set_transient($keys['attempts'], $attempts, NP_LOGIN_WINDOW);
});

// Krok 3 — sukces logowania zeruje licznik (bez resetu lockoutu — to zaszło już w 1).
add_action('wp_login', static function (): void {
    $keys = np_login_keys(np_login_client_ip());
    delete_transient($keys['attempts']);
});
