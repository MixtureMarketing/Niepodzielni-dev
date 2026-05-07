<?php

declare(strict_types=1);

/**
 * Testy np_s2s_hash_user_data() — PII normalization + SHA-256.
 *
 * Wymagania Meta CAPI / GA4 MP:
 *  - email/phone/first_name/last_name: lower-case + trim + SHA-256
 *  - phone: tylko cyfry, prefix 48 dla 9-cyfrowych PL numerów
 *  - IP: hash (do client_ip_hash w custom_data)
 */

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}
if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

// Stuby WP wymagane przez mu-plugin (load przy require).
if (! function_exists('add_action')) {
    function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}
if (! function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args): bool
    {
        return true;
    }
}

require_once dirname(__DIR__, 2) . '/web/app/mu-plugins/np-conversion-api/np-conversion-api.php';

it('hashuje email lower-case + trim → SHA-256', function () {
    $hashed = np_s2s_hash_user_data(['email' => '  JOHN.DOE@example.com '], '');
    expect($hashed['em'])->toBe(hash('sha256', 'john.doe@example.com'));
});

it('normalizuje phone PL — tylko cyfry, prefix 48', function () {
    $hashed = np_s2s_hash_user_data(['phone' => '+48 600 123 456'], '');
    expect($hashed['ph'])->toBe(hash('sha256', '48600123456'));
});

it('phone 9-cyfrowy bez plusa dodaje prefix 48', function () {
    $hashed = np_s2s_hash_user_data(['phone' => '600123456'], '');
    expect($hashed['ph'])->toBe(hash('sha256', '48600123456'));
});

it('hashuje first_name i last_name lower-case', function () {
    $hashed = np_s2s_hash_user_data([
        'first_name' => 'Jan',
        'last_name'  => 'KOWALSKI',
    ], '');
    expect($hashed['fn'])->toBe(hash('sha256', 'jan'));
    expect($hashed['ln'])->toBe(hash('sha256', 'kowalski'));
});

it('hashuje IP do client_ip_hash gdy podany', function () {
    $hashed = np_s2s_hash_user_data([], '203.0.113.42');
    expect($hashed['client_ip_hash'])->toBe(hash('sha256', '203.0.113.42'));
});

it('pomija puste pola (nie tworzy klucza em jeśli email pusty)', function () {
    $hashed = np_s2s_hash_user_data(['email' => '', 'phone' => null], '');
    expect($hashed)->not->toHaveKey('em');
    expect($hashed)->not->toHaveKey('ph');
});

it('akceptuje pełny payload Meta CAPI user_data', function () {
    $hashed = np_s2s_hash_user_data([
        'email'      => 'donor@example.com',
        'phone'      => '+48 500 600 700',
        'first_name' => 'Anna',
        'last_name'  => 'Nowak',
        'city'       => 'Warszawa',
        'zip'        => '00-001',
        'country'    => 'PL',
    ], '198.51.100.7');

    expect($hashed)->toHaveKeys(['em', 'ph', 'fn', 'ln', 'ct', 'zp', 'country', 'client_ip_hash']);
    expect($hashed['ct'])->toBe(hash('sha256', 'warszawa'));
    expect($hashed['country'])->toBe(hash('sha256', 'pl'));
});
