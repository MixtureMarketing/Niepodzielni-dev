<?php

/**
 * WordPress function and class stubs for PHPStan static analysis.
 *
 * Używane wyłącznie przez PHPStan (bootstrapFiles w phpstan.neon).
 * Nie są ładowane w runtime — nie zawierają implementacji, tylko
 * sygnatury typów potrzebne do analizy src/Bookero/.
 *
 * Pokrywa funkcje i klasy WP używane przez:
 *   - BookeroApiClient (wp_remote_get/post, wp_json_encode, get_site_url)
 *   - PsychologistRepository (get/update/delete_post_meta, get/set/delete_transient,
 *                              sanitize_key, wp_json_encode)
 *   - BookeroSyncService (np_bookero_cal_id_for, date_i18n, np_bookero_log_error)
 *   - BookeroSyncService (np_get_post_image_url, get_the_permalink)
 *   - WorkerRecord constructor (WP_Post shape)
 */

declare(strict_types=1);

// ─── WP constants ─────────────────────────────────────────────────────────────

if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (! defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

if (! defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

// ─── WP_Error ─────────────────────────────────────────────────────────────────

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public readonly string $code    = '',
            public readonly string $message = '',
            public readonly mixed  $data    = '',
        ) {}

        public function get_error_message(string $code = ''): string
        {
            return $this->message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_data(string $code = ''): mixed
        {
            return $this->data;
        }
    }
}

// ─── WP_Post ──────────────────────────────────────────────────────────────────

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public int    $ID            = 0;
        public string $post_title    = '';
        public string $post_status   = 'publish';
        public string $post_type     = '';
        public string $post_content  = '';
        public string $post_date     = '';
        public string $post_modified = '';
    }
}

// ─── WP_Query ─────────────────────────────────────────────────────────────────

if (! class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var WP_Post[] */
        public array $posts = [];

        /** @param array<string, mixed> $args */
        public function __construct(array $args = []) {}
    }
}

// ─── Post meta ────────────────────────────────────────────────────────────────

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        return $single ? '' : [];
    }
}

if (! function_exists('update_post_meta')) {
    function update_post_meta(
        int    $post_id,
        string $meta_key,
        mixed  $meta_value,
        mixed  $prev_value = '',
    ): int|bool {
        return true;
    }
}

if (! function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $meta_key, mixed $meta_value = ''): bool
    {
        return true;
    }
}

// ─── Transienty ───────────────────────────────────────────────────────────────

if (! function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        return true;
    }
}

// ─── Options ──────────────────────────────────────────────────────────────────

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default_value = false): mixed
    {
        return $default_value;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, string|bool $autoload = true): bool
    {
        return true;
    }
}

// ─── WP HTTP ──────────────────────────────────────────────────────────────────

if (! function_exists('wp_remote_get')) {
    /** @param array<string, mixed> $args
     *  @return array<string, mixed>|WP_Error
     */
    function wp_remote_get(string $url, array $args = []): array|WP_Error
    {
        return [];
    }
}

if (! function_exists('wp_remote_post')) {
    /** @param array<string, mixed> $args
     *  @return array<string, mixed>|WP_Error
     */
    function wp_remote_post(string $url, array $args = []): array|WP_Error
    {
        return [];
    }
}

if (! function_exists('wp_remote_retrieve_response_code')) {
    /** @param array<string, mixed>|WP_Error $response */
    function wp_remote_retrieve_response_code(array|WP_Error $response): int|string
    {
        return 200;
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    /** @param array<string, mixed>|WP_Error $response */
    function wp_remote_retrieve_body(array|WP_Error $response): string
    {
        return '';
    }
}

if (! function_exists('wp_remote_retrieve_header')) {
    /** @param array<string, mixed>|WP_Error $response */
    function wp_remote_retrieve_header(array|WP_Error $response, string $header): string
    {
        return '';
    }
}

// ─── WP utilities ─────────────────────────────────────────────────────────────

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return $key;
    }
}

if (! function_exists('date_i18n')) {
    function date_i18n(string $format, int|false $timestamp_with_offset = false): string
    {
        return date($format, $timestamp_with_offset ?: time());
    }
}

if (! function_exists('is_wp_error')) {
    /**
     * @phpstan-assert-if-true WP_Error $thing
     */
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('get_site_url')) {
    function get_site_url(
        ?int $blog_id    = null,
        string   $path       = '',
        ?string $scheme  = null,
    ): string {
        return 'https://example.com';
    }
}

if (! function_exists('get_the_permalink')) {
    function get_the_permalink(int|WP_Post $post = 0, bool $leavename = false): string|false
    {
        return 'https://example.com/?p=' . (is_int($post) ? $post : $post->ID);
    }
}

if (! function_exists('get_posts')) {
    /** @param array<string, mixed> $args
     *  @return WP_Post[]|int[]
     */
    function get_posts(array $args = []): array
    {
        return [];
    }
}

// ─── WP Object Cache ──────────────────────────────────────────────────────────

if (! function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
    {
        return false;
    }
}

if (! function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool
    {
        return true;
    }
}

if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        return true;
    }
}

// ─── Projekt-specifyczne funkcje (z mu-plugins) ───────────────────────────────

if (! function_exists('np_bookero_cal_id_for')) {
    function np_bookero_cal_id_for(string $typ): string
    {
        return '';
    }
}

if (! function_exists('np_bookero_log_error')) {
    function np_bookero_log_error(string $context, string $msg): void {}
}

if (! function_exists('np_get_sortable_date')) {
    function np_get_sortable_date(string $date_string): string
    {
        return '99999999';
    }
}

if (! function_exists('np_get_post_image_url')) {
    /** @param string[] $keys */
    function np_get_post_image_url(int $post_id, array $keys, string $size = 'large'): string
    {
        return '';
    }
}
