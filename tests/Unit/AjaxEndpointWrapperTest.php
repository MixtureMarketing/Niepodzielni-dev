<?php

declare(strict_types=1);

/**
 * Testy np_ajax_endpoint() — wrapper boilerplate dla AJAX endpointów.
 *
 * Strategia: stuby globalnych funkcji WP (check_ajax_referer, wp_send_json_*,
 * current_user_can, status_header). Każdy test resetuje stan i sprawdza
 * jedną ścieżkę: success / invalid_nonce / forbidden / rate_limited.
 *
 * Test wywołuje callback bezpośrednio — pomija add_action()/wp_ajax_*, bo to
 * tylko hookowanie. Cała logika wrappera jest w callbacku.
 */

// ─── Stuby — załadowane raz ──────────────────────────────────────────────────

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['_np_actions'][$hook] = $callback;
        return true;
    }
}

if (! function_exists('check_ajax_referer')) {
    function check_ajax_referer(string $action, string $field = '_wpnonce', bool $die = true): int|false
    {
        // Test ustawia $GLOBALS['_np_nonce_valid'] = bool przed wywołaniem
        return ($GLOBALS['_np_nonce_valid'] ?? false) ? 1 : false;
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $cap): bool
    {
        return $GLOBALS['_np_user_can'][$cap] ?? false;
    }
}

if (! function_exists('status_header')) {
    function status_header(int $code): void
    {
        $GLOBALS['_np_status'] = $code;
    }
}

if (! function_exists('wp_send_json_success')) {
    function wp_send_json_success(mixed $data = null): void
    {
        $GLOBALS['_np_response'] = ['success' => true, 'data' => $data];
        // Imituj exit przez throw — test łapie i sprawdza wynik.
        throw new \RuntimeException('__np_test_exit_success__');
    }
}

if (! function_exists('wp_send_json_error')) {
    function wp_send_json_error(mixed $data = null, ?int $status = null): void
    {
        $GLOBALS['_np_response'] = ['success' => false, 'data' => $data];
        if ($status !== null) {
            $GLOBALS['_np_status'] = $status;
        }
        throw new \RuntimeException('__np_test_exit_error__');
    }
}

require_once __DIR__ . '/../../web/app/mu-plugins/niepodzielni-core/api/0-ajax-endpoint-wrapper.php';

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Rejestruje endpoint i zwraca callback (priv) — pozwala wywołać go bezpośrednio.
 */
function np_test_register(string $action, array $config, callable $handler): callable
{
    $GLOBALS['_np_actions'] = [];
    np_ajax_endpoint($action, $config, $handler);
    return $GLOBALS['_np_actions']['wp_ajax_' . $action];
}

function np_test_invoke(callable $cb): array
{
    $GLOBALS['_np_response'] = null;
    $GLOBALS['_np_status']   = null;
    try {
        $cb();
    } catch (\RuntimeException $e) {
        // expected — wp_send_json_* throws by stub
    }
    return [
        'response' => $GLOBALS['_np_response'],
        'status'   => $GLOBALS['_np_status'],
    ];
}

beforeEach(function () {
    $GLOBALS['_np_nonce_valid'] = false;
    $GLOBALS['_np_user_can']    = [];
    $GLOBALS['_wp_transients']  = [];
    $_REQUEST                   = [];
});

// ─── Testy ───────────────────────────────────────────────────────────────────

it('returns success envelope when handler returns array and nonce is valid', function () {
    $cb = np_test_register('np_test_a', [
        'nonce_action' => 'np_test_nonce',
    ], fn() => ['hello' => 'world']);

    $GLOBALS['_np_nonce_valid'] = true;

    $result = np_test_invoke($cb);

    expect($result['response'])->toBe(['success' => true, 'data' => ['hello' => 'world']]);
});

it('returns 403 invalid_nonce when nonce missing/wrong', function () {
    $cb = np_test_register('np_test_b', [
        'nonce_action' => 'np_test_nonce',
    ], fn() => ['ok' => true]);

    $GLOBALS['_np_nonce_valid'] = false;

    $result = np_test_invoke($cb);

    expect($result['status'])->toBe(403);
    expect($result['response']['success'])->toBeFalse();
    expect($result['response']['data']['error'])->toBe('invalid_nonce');
});

it('returns 403 forbidden when capability check fails', function () {
    $cb = np_test_register('np_test_c', [
        'nonce_action' => 'np_test_nonce',
        'capability'   => 'manage_options',
    ], fn() => ['ok' => true]);

    $GLOBALS['_np_nonce_valid']                     = true;
    $GLOBALS['_np_user_can']['manage_options']      = false;

    $result = np_test_invoke($cb);

    expect($result['status'])->toBe(403);
    expect($result['response']['data']['error'])->toBe('forbidden');
});

it('returns 429 rate_limited when over limit', function () {
    $cb = np_test_register('np_test_d', [
        'nonce_action' => null,
        'rate_limit'   => 3,
    ], fn() => ['ok' => true]);

    // Pre-wypełniamy licznik — 3 trafienia już wykorzystane
    $ip = $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    set_transient('np_rl_np_test_d_' . md5($ip), 3, 60);

    $result = np_test_invoke($cb);

    expect($result['status'])->toBe(429);
    expect($result['response']['data']['error'])->toBe('rate_limited');
});

it('skips nonce check when nonce_action is null', function () {
    $cb = np_test_register('np_test_e', [
        'nonce_action' => null,
    ], fn() => ['public' => true]);

    $GLOBALS['_np_nonce_valid'] = false; // celowo

    $result = np_test_invoke($cb);

    expect($result['response'])->toBe(['success' => true, 'data' => ['public' => true]]);
});

it('returns 500 with exception message when handler throws', function () {
    $cb = np_test_register('np_test_f', [
        'nonce_action' => null,
    ], function () {
        throw new \RuntimeException('boom');
    });

    $result = np_test_invoke($cb);

    expect($result['status'])->toBe(500);
    expect($result['response']['data']['error'])->toBe('boom');
});

it('respects auth_callback returning false', function () {
    $cb = np_test_register('np_test_g', [
        'nonce_action'  => null,
        'auth_callback' => fn(array $req) => ! empty($req['allow']),
    ], fn() => ['ok' => true]);

    $_REQUEST = ['allow' => 0];

    $result = np_test_invoke($cb);

    expect($result['status'])->toBe(403);
    expect($result['response']['data']['error'])->toBe('forbidden');
});
