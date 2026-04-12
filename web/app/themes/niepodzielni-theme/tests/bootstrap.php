<?php
/**
 * PHPUnit bootstrap — WordPress function stubs for unit testing.
 * Loads only the PHP files under test; does NOT bootstrap WordPress.
 */

// ABSPATH is checked by all files before executing.
if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 4) . DIRECTORY_SEPARATOR);
}

// WordPress constants used in business logic.
if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// ----------------------------------------------------------------
// Global mock storage — tests can populate these per-test.
// ----------------------------------------------------------------
$GLOBALS['_np_test_post_meta']  = [];   // [post_id => [meta_key => value]]
$GLOBALS['_np_test_wp_terms']   = [];   // [post_id => [taxonomy => [names]]]

// ----------------------------------------------------------------
// WordPress function stubs
// ----------------------------------------------------------------

if (! function_exists('get_post_meta')) {
    /**
     * Reads from $GLOBALS['_np_test_post_meta'].
     * @param int    $post_id
     * @param string $key
     * @param bool   $single
     * @return mixed
     */
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed {
        $all = $GLOBALS['_np_test_post_meta'][$post_id] ?? [];
        if ($key === '') return $all;
        $val = $all[$key] ?? '';
        return $single ? $val : ($val !== '' ? [$val] : []);
    }
}

if (! function_exists('wp_get_post_terms')) {
    /**
     * Reads from $GLOBALS['_np_test_wp_terms'].
     * @return string[]
     */
    function wp_get_post_terms(int $post_id, string $taxonomy, array $args = []): array {
        return $GLOBALS['_np_test_wp_terms'][$post_id][$taxonomy] ?? [];
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool {
        return false;
    }
}

if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $key): mixed {
        return false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool {
        return true;
    }
}

if (! function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []): array {
        return [];
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(array $response): string {
        return '';
    }
}

if (! function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url = ''): string {
        return $url . '?' . http_build_query($args);
    }
}

// ----------------------------------------------------------------
// Load files under test
// ----------------------------------------------------------------
// __DIR__ = .../niepodzielni-theme/tests
// dirname 3× → .../wp-content
$pluginBase = dirname(__DIR__, 3) . '/mu-plugins/niepodzielni-core';

require_once $pluginBase . '/misc/1-helpers.php';
require_once $pluginBase . '/api/13-bookero-worker-sync.php';
