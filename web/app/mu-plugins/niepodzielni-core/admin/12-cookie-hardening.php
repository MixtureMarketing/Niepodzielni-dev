<?php

/**
 * Hartowanie cookies WordPressa: HttpOnly + Secure + SameSite=Lax dla cookies
 * uwierzytelnienia (auth_cookie, logged_in_cookie).  Skraca też okno admin
 * sesji do 24 h (zamiast WP-defaultowych 14 dni dla "remember me").
 *
 * Aktywne tylko gdy serwer działa po HTTPS — w lokalnym dev na HTTP cookie
 * `Secure` byłoby niewysyłane i blokowałoby logowanie.
 */

if (! defined('ABSPATH')) {
    exit;
}

// Wymuś flagę Secure na cookie `wordpress_logged_in_*` jeśli żądanie po HTTPS.
add_filter('secure_logged_in_cookie', static function (bool $secure): bool {
    return $secure || is_ssl();
});

// Skrócenie sesji niezależnie od checkboxa "Remember me" — admin ma 24 h,
// reszta 12 h.  Filtr dostaje aktualną wartość + `$user_id` + `$remember`.
add_filter('auth_cookie_expiration', static function (int $expiration, int $user_id, bool $remember): int {
    if (user_can($user_id, 'manage_options')) {
        return DAY_IN_SECONDS;
    }
    return $remember ? 12 * HOUR_IN_SECONDS : 4 * HOUR_IN_SECONDS;
}, 10, 3);

// SameSite cookie attribute — wspierane przez WP od 5.7 przez `wp_set_auth_cookie`
// poprzez setcookie() z opcją 'samesite'.  Dla logged_in cookie (frontend)
// hooka brak — używamy `set_logged_in_cookie` action i wystawiamy manual setcookie.
add_action('set_logged_in_cookie', static function (
    string $logged_in_cookie,
    int $expire,
    int $expiration,
    int $user_id,
    string $scheme,
    string $token = ''
): void {
    if (headers_sent()) {
        return;
    }
    setcookie(
        LOGGED_IN_COOKIE,
        $logged_in_cookie,
        [
            'expires'  => $expire,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ],
    );
    if (COOKIEPATH !== SITECOOKIEPATH) {
        setcookie(
            LOGGED_IN_COOKIE,
            $logged_in_cookie,
            [
                'expires'  => $expire,
                'path'     => SITECOOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        );
    }
}, 10, 6);

// Ten sam zabieg dla auth_cookie (panelu admina).
add_action('set_auth_cookie', static function (
    string $auth_cookie,
    int $expire,
    int $expiration,
    int $user_id,
    string $scheme,
    string $token = ''
): void {
    if (headers_sent()) {
        return;
    }
    $cookie_name = SECURE_AUTH_COOKIE;
    if ($scheme !== 'secure_auth') {
        $cookie_name = AUTH_COOKIE;
    }
    setcookie(
        $cookie_name,
        $auth_cookie,
        [
            'expires'  => $expire,
            'path'     => PLUGINS_COOKIE_PATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => $scheme === 'secure_auth',
            'httponly' => true,
            'samesite' => 'Lax',
        ],
    );
    setcookie(
        $cookie_name,
        $auth_cookie,
        [
            'expires'  => $expire,
            'path'     => ADMIN_COOKIE_PATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => $scheme === 'secure_auth',
            'httponly' => true,
            'samesite' => 'Lax',
        ],
    );
}, 10, 6);
