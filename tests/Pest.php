<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| WP function stubs — umożliwiają uruchamianie testów jednostkowych bez
| ładowania środowiska WordPress. Każdy stub naśladuje semantykę WP:
|   - transienty: in-memory array $GLOBALS['_wp_transients']
|   - postmeta:   in-memory array $GLOBALS['_wp_postmeta'][$post_id][$key]
|
| Funkcje globalne WP używane przez src/Bookero/ są tu stubowane raz.
| Testy mogą nadpisać zachowanie przez bezpośrednią manipulację $GLOBALS.
|
| UWAGA: PSR-4 dla 'Niepodzielni\Bookero\' jest zarejestrowane poniżej
| ręcznie, żeby nie wymagać `composer dump-autoload` po każdej zmianie
| w composer.json. CI uruchamia `composer install` — tam autoload jest
| generowany automatycznie z composer.json i ten blok jest zbędny.
|
*/

// ─── PSR-4 registration — fallback gdy vendor nie zawiera namespace projektu ──
// Composer generuje to automatycznie po `composer install` / `composer dump-autoload`.
// require zwraca ten sam obiekt ClassLoader co phpunit bootstrap — wywołanie
// addPsr4() na nim jest bezpieczne i nie rejestruje duplikatu.
/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4(
    'Niepodzielni\\Bookero\\',
    __DIR__ . '/../web/app/mu-plugins/niepodzielni-core/src/Bookero/',
);

// ─── Global state dla stubów ──────────────────────────────────────────────────

$GLOBALS['_wp_transients'] = [];
$GLOBALS['_wp_postmeta']   = [];

// Reset stanu przed każdym testem — izolacja między przypadkami
\Pest\Support\Closure::bind(function () {}, null);

uses()->beforeEach(function () {
    $GLOBALS['_wp_transients'] = [];
    $GLOBALS['_wp_postmeta']   = [];
})->in('Unit');

// ─── Transient stubs ──────────────────────────────────────────────────────────

if (! function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return $GLOBALS['_wp_transients'][$transient] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['_wp_transients'][$transient] = $value;
        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        unset($GLOBALS['_wp_transients'][$transient]);
        return true;
    }
}

// ─── Post meta stubs ──────────────────────────────────────────────────────────

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        $meta = $GLOBALS['_wp_postmeta'][$post_id] ?? [];
        if ($key === '') {
            return $meta;
        }
        return $meta[$key] ?? ($single ? '' : []);
    }
}

if (! function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $meta_key, mixed $meta_value): mixed
    {
        $GLOBALS['_wp_postmeta'][$post_id][$meta_key] = $meta_value;
        return true;
    }
}

if (! function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $meta_key, mixed $meta_value = ''): bool
    {
        unset($GLOBALS['_wp_postmeta'][$post_id][$meta_key]);
        return true;
    }
}

// ─── WP utility stubs ─────────────────────────────────────────────────────────

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key));
    }
}

if (! function_exists('date_i18n')) {
    /**
     * Uproszczony stub — używa PHP date() z mapą polskich nazw miesięcy.
     */
    function date_i18n(string $format, int $timestamp_with_offset = 0): string
    {
        if ($format === 'j F Y') {
            $months = [
                1 => 'stycznia', 2 => 'lutego', 3 => 'marca', 4 => 'kwietnia',
                5 => 'maja', 6 => 'czerwca', 7 => 'lipca', 8 => 'sierpnia',
                9 => 'września', 10 => 'października', 11 => 'listopada', 12 => 'grudnia',
            ];
            $ts = $timestamp_with_offset ?: time();
            return date('j', $ts) . ' ' . ($months[(int) date('n', $ts)] ?? date('F', $ts)) . ' ' . date('Y', $ts);
        }
        return date($format, $timestamp_with_offset ?: time());
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

// ─── WP_Error stub ────────────────────────────────────────────────────────────

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private string $code    = '',
            private string $message = '',
        ) {}

        public function get_error_message(string $code = ''): string
        {
            return $this->message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}

// ─── WP constants ─────────────────────────────────────────────────────────────

if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// ─── Stubs funkcji domenowych projektu ───────────────────────────────────────

if (! function_exists('np_bookero_cal_id_for')) {
    /**
     * Domyślnie zwraca fikcyjny hash — testy nadpisują przez zamknięcie
     * lub bezpośrednią podmianę w $GLOBALS['_np_cal_ids'].
     */
    function np_bookero_cal_id_for(string $typ): string
    {
        return $GLOBALS['_np_cal_ids'][$typ] ?? 'test-cal-hash';
    }
}

if (! function_exists('np_bookero_log_error')) {
    function np_bookero_log_error(string $context, string $msg): void
    {
        // W testach pomijamy logowanie — jeśli test chce sprawdzić logi,
        // może nadpisać tę funkcję przez runkit lub użyć spy w mocku.
    }
}

if (! function_exists('np_get_sortable_date')) {
    function np_get_sortable_date(string $date_string): string
    {
        if (empty($date_string)) {
            return '99999999';
        }
        $months = [
            'stycznia' => '01', 'lutego' => '02', 'marca' => '03', 'kwietnia' => '04',
            'maja' => '05', 'czerwca' => '06', 'lipca' => '07', 'sierpnia' => '08',
            'września' => '09', 'października' => '10', 'listopada' => '11', 'grudnia' => '12',
        ];
        if (preg_match('/(\d{1,2})\s+(\w+)\s+(\d{4})/', $date_string, $m)) {
            $month = $months[strtolower($m[2])] ?? '00';
            return sprintf('%04d%02d%02d', $m[3], $month, $m[1]);
        }
        $ts = strtotime($date_string);
        return $ts ? date('Ymd', $ts) : '99999999';
    }
}

if (! function_exists('np_get_post_image_url')) {
    function np_get_post_image_url(int $post_id, array $keys, string $size = 'large'): string
    {
        return '';
    }
}

if (! function_exists('get_the_permalink')) {
    function get_the_permalink(int $post_id = 0): string
    {
        return 'https://example.com/?p=' . $post_id;
    }
}
