<?php
/**
 * Bookero API — klient HTTP do publicznego API widgetu Bookero
 *
 * Endpoint: plugin.bookero.pl/plugin-api/v2/getMonth
 * Używa globalnego hasha konta (cal_id) i worker ID psychologa.
 * Nie wymaga autoryzacji Bearer — ten sam endpoint co bookero-compiled.js.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Pobiera dostępne terminy dla danego psychologa z API Bookero.
 *
 * @param string $worker_id   Worker ID psychologa (bookero_id_pelny / bookero_id_niski)
 * @param string $typ         'pelnoplatny' | 'nisko'
 * @param int    $plus_months Ile miesięcy do przodu (0 = bieżący, 1 = następny itd.)
 * @return array  Tablica slotów: [ ['date' => 'YYYY-MM-DD', 'hour' => 'HH:MM'], ... ]
 */
function np_bookero_get_terminy( string $worker_id, string $typ = 'pelnoplatny', int $plus_months = 0 ): array {
    $cal_hash = np_bookero_cal_id_for( $typ );

    if ( ! $cal_hash || ! $worker_id ) {
        return [];
    }

    $cache_key = 'np_bk_' . md5( $typ . $worker_id ) . '_m' . $plus_months;
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }

    $config     = np_bookero_get_account_config( $typ );
    $service_id = $config['service_id'] ?? 0;

    $url = 'https://plugin.bookero.pl/plugin-api/v2/getMonth?' . http_build_query( [
        'bookero_id'         => $cal_hash,
        'worker'             => $worker_id,
        'service'            => $service_id ?: 0,
        'plus_months'        => $plus_months,
        'people'             => 1,
        'lang'               => 'pl',
        'periodicity_id'     => 0,
        'custom_duration_id' => 0,
        'plugin_comment'     => wp_json_encode( [ 'data' => [ 'parameters' => [] ] ] ),
    ] );

    $response = wp_remote_get( $url, [
        'timeout' => 15,
        'headers' => [
            'Accept'     => 'application/json',
            // Bookero throttluje zapytania z WordPress UA — używamy browser-like UA
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        np_bookero_log_error(
            'getMonth',
            "worker={$worker_id} typ={$typ}: " . $response->get_error_message()
        );
        set_transient( $cache_key, [], 2 * MINUTE_IN_SECONDS );
        return [];
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || ! is_array( $body ) || ( (int) ( $body['result'] ?? 0 ) ) !== 1 ) {
        np_bookero_log_error(
            'getMonth',
            "worker={$worker_id} typ={$typ}: HTTP {$code}, result=" . ( $body['result'] ?? 'N/A' )
        );
        set_transient( $cache_key, [], 2 * MINUTE_IN_SECONDS );
        return [];
    }

    $slots = np_bookero_normalize_slots( $body );

    set_transient( $cache_key, $slots, 5 * MINUTE_IN_SECONDS );
    return $slots;
}

/**
 * Normalizuje odpowiedź getMonth Bookero v2 do tablicy dostępnych dat.
 *
 * Rzeczywisty format odpowiedzi API (zweryfikowany z bookero-compiled.js):
 *   { "result": 1, "days": { "1": {"date":"YYYY-MM-DD","valid_day":1,"open":1}, ... } }
 *
 * @param array $body  Zdekodowana odpowiedź JSON
 * @return array       [ ['date' => 'YYYY-MM-DD', 'hour' => ''], ... ] — tylko dni z valid_day > 0
 */
function np_bookero_normalize_slots( array $body ): array {
    $slots = [];

    // Główny format: { "days": { "1": {"date": "...", "valid_day": 1, ...}, ... } }
    if ( ! empty( $body['days'] ) && is_array( $body['days'] ) ) {
        foreach ( $body['days'] as $day_data ) {
            if ( ! is_array( $day_data ) ) continue;
            $date      = $day_data['date'] ?? '';
            $valid_day = $day_data['valid_day'] ?? 0;
            $open      = $day_data['open']      ?? 0;

            // Uwzględniamy tylko dni z faktycznie dostępnymi slotami (valid_day > 0).
            // open=1 przy valid_day=0 oznacza że pracownik jest wpisany w grafik, ale brak wolnych miejsc.
            if ( $date && (int) $valid_day > 0 ) {
                $slots[] = [ 'date' => (string) $date, 'hour' => '' ];
            }
        }
        return $slots;
    }

    // Fallback: stary format { "calendar": { "YYYY-MM-DD": ["16:30", ...] } }
    if ( ! empty( $body['calendar'] ) && is_array( $body['calendar'] ) ) {
        foreach ( $body['calendar'] as $date => $hours ) {
            $slots[] = [ 'date' => (string) $date, 'hour' => '' ];
        }
        return $slots;
    }

    return $slots;
}

/**
 * Zwraca klucz postmeta dla cache godzin danego typu konta.
 */
function np_bookero_hours_meta_key( string $typ ): string {
    return in_array( $typ, [ 'nisko', 'niskoplatny', 'niskoplatne' ], true )
        ? 'bookero_hours_nisko'
        : 'bookero_hours_pelno';
}

/**
 * Pobiera godziny z DB (postmeta) dla psychologa na konkretny dzień.
 *
 * @return string[]|null  Tablica godzin ('11:00', ...) lub null gdy brak w cache
 */
function np_bookero_get_cached_hours( int $post_id, string $typ, string $date ): ?array {
    $meta_key = np_bookero_hours_meta_key( $typ );
    $json     = get_post_meta( $post_id, $meta_key, true );
    if ( ! $json ) return null;

    $map = json_decode( $json, true );
    if ( ! is_array( $map ) ) return null;

    // null = brak w cache; [] = zsynchronizowane, brak dostępności
    return array_key_exists( $date, $map ) ? (array) $map[ $date ] : null;
}

/**
 * Zapisuje godziny do DB (postmeta) dla psychologa na konkretny dzień.
 * Przy okazji czyści daty starsze niż dziś — zapobiega nieograniczonemu wzrostowi.
 */
function np_bookero_cache_hours( int $post_id, string $typ, string $date, array $hours ): void {
    $meta_key = np_bookero_hours_meta_key( $typ );
    $json     = get_post_meta( $post_id, $meta_key, true );
    $map      = ( $json ? json_decode( $json, true ) : [] );
    if ( ! is_array( $map ) ) $map = [];

    $map[ $date ] = array_values( $hours );

    // Usuń daty przed dzisiaj
    $today = date( 'Y-m-d' );
    foreach ( array_keys( $map ) as $d ) {
        if ( $d < $today ) unset( $map[ $d ] );
    }

    update_post_meta( $post_id, $meta_key, wp_json_encode( $map ) );
}

/**
 * Pobiera konfigurację konta Bookero z endpointu /init (service_id, payment_id).
 * Cachuje wynik w transiencie przez 24 godziny.
 *
 * @param string $typ  'pelnoplatny' | 'nisko'
 * @return array  ['service_id' => int, 'service_name' => string, 'payment_id' => int]
 */
function np_bookero_get_account_config( string $typ ): array {
    $cache_key = 'np_bk_cfg_' . sanitize_key( $typ );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached && is_array( $cached ) ) {
        return $cached;
    }

    $cal_hash = np_bookero_cal_id_for( $typ );
    if ( ! $cal_hash ) {
        return [ 'service_id' => 0, 'service_name' => '', 'payment_id' => 0 ];
    }

    $url = 'https://plugin.bookero.pl/plugin-api/v2/init?' . http_build_query( [
        'bookero_id' => $cal_hash,
        'lang'       => 'pl',
        'type'       => 'calendar',
    ] );

    $response = wp_remote_get( $url, [
        'timeout' => 10,
        'headers' => [
            'Accept'     => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        np_bookero_log_error( 'init', "typ={$typ}: " . $response->get_error_message() );
        return [ 'service_id' => 0, 'service_name' => '', 'payment_id' => 0 ];
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $body ) || ( (int) ( $body['result'] ?? 0 ) ) !== 1 ) {
        return [ 'service_id' => 0, 'service_name' => '', 'payment_id' => 0 ];
    }

    // Usługa z największą liczbą pracowników — główna usługa konsultacyjna.
    // API zwraca workers jako tablicę ID. Dla nisko: 36549 (11), 90555 (2), 50604 (158 → główna).
    $service_id   = 0;
    $service_name = '';
    if ( ! empty( $body['services_list'] ) && is_array( $body['services_list'] ) ) {
        $best_svc   = $body['services_list'][0];
        $best_count = is_array( $best_svc['workers'] ?? null ) ? count( $best_svc['workers'] ) : 0;
        foreach ( $body['services_list'] as $svc ) {
            $cnt = is_array( $svc['workers'] ?? null ) ? count( $svc['workers'] ) : 0;
            if ( $cnt > $best_count ) {
                $best_count = $cnt;
                $best_svc   = $svc;
            }
        }
        $service_id   = (int) ( $best_svc['id']   ?? 0 );
        $service_name = (string) ( $best_svc['name'] ?? '' );
    }

    // Domyślna metoda płatności
    $payment_id = 0;
    if ( ! empty( $body['payment_methods'] ) && is_array( $body['payment_methods'] ) ) {
        foreach ( $body['payment_methods'] as $pm ) {
            if ( ! empty( $pm['is_default'] ) ) {
                $payment_id = (int) ( $pm['id'] ?? 0 );
                break;
            }
        }
        if ( ! $payment_id && isset( $body['payment_methods'][0]['id'] ) ) {
            $payment_id = (int) $body['payment_methods'][0]['id'];
        }
    }

    $config = [
        'service_id'   => $service_id,
        'service_name' => $service_name,
        'payment_id'   => $payment_id,
    ];

    if ( $service_id ) {
        set_transient( $cache_key, $config, 24 * HOUR_IN_SECONDS );
    }

    return $config;
}

/**
 * Pobiera dostępne godziny dla psychologa w danym dniu z API Bookero (getMonthDay).
 *
 * @param string $worker_id  Worker ID psychologa
 * @param string $typ        'pelnoplatny' | 'nisko' | 'pelno'
 * @param string $date       Data YYYY-MM-DD
 * @return string[]  Lista godzin, np. ['09:00', '10:00']
 */
function np_bookero_get_month_day( string $worker_id, string $typ, string $date ): array {
    if ( ! $worker_id || ! $date ) return [];

    $cal_hash   = np_bookero_cal_id_for( $typ );
    $config     = np_bookero_get_account_config( $typ );
    $service_id = $config['service_id'] ?? 0;

    if ( ! $cal_hash ) return [];

    $cache_key = 'np_bkday_' . md5( $typ . $worker_id . $date );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) return (array) $cached;

    $url = 'https://plugin.bookero.pl/plugin-api/v2/getMonthDay?' . http_build_query( [
        'bookero_id'         => $cal_hash,
        'worker'             => $worker_id,
        'date'               => $date,
        'service'            => $service_id ?: 0,
        'people'             => 1,
        'lang'               => 'pl',
        'periodicity_id'     => 0,
        'custom_duration_id' => 0,
        'hour'               => '',
        'phone'              => '',
        'email'              => '',
        'plugin_comment'     => wp_json_encode( [ 'data' => [ 'parameters' => (object) [] ] ] ),
    ] );

    $response = wp_remote_get( $url, [
        'timeout' => 10,
        'headers' => [
            'Accept'     => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        np_bookero_log_error( 'getMonthDay', "worker={$worker_id} date={$date}: " . $response->get_error_message() );
        set_transient( $cache_key, [], 2 * MINUTE_IN_SECONDS );
        return [];
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || ! is_array( $body ) || ( (int) ( $body['result'] ?? 0 ) ) !== 1 ) {
        np_bookero_log_error( 'getMonthDay', "worker={$worker_id} date={$date}: HTTP {$code}" );
        set_transient( $cache_key, [], 2 * MINUTE_IN_SECONDS );
        return [];
    }

    $hours = [];
    foreach ( ( $body['data']['hours'] ?? [] ) as $slot ) {
        if ( ! empty( $slot['valid'] ) && ! empty( $slot['hour'] ) ) {
            $hours[] = (string) $slot['hour'];
        }
    }

    set_transient( $cache_key, $hours, 5 * MINUTE_IN_SECONDS );
    return $hours;
}

/**
 * Pobiera JEDNOCZEŚNIE najbliższy termin i wszystkie dostępne daty z 3 miesięcy.
 * Wykonuje dokładnie 3 HTTP requesty (getMonth × 3) zamiast 3+3 przy osobnych wywołaniach.
 *
 * @param string $worker_id  Worker ID psychologa
 * @param string $typ        'pelnoplatny' | 'nisko'
 * @return array  ['nearest' => 'j F Y', 'dates' => ['YYYY-MM-DD', ...]]
 */
function np_bookero_get_availability( string $worker_id, string $typ ): array {
    if ( ! $worker_id ) return [ 'nearest' => '', 'dates' => [] ];

    $now     = time();
    $today   = date( 'Y-m-d' );
    $dates   = [];
    $nearest = '';

    for ( $i = 0; $i <= 2; $i++ ) {
        $slots = np_bookero_get_terminy( $worker_id, $typ, $i );
        foreach ( $slots as $slot ) {
            $date = $slot['date'] ?? '';
            if ( ! $date || $date < $today ) continue;

            $ts = strtotime( $date );
            if ( ! $ts || $ts < $now ) continue;

            if ( ! $nearest ) {
                $nearest = date_i18n( 'j F Y', $ts );
            }
            $dates[] = $date;
        }
    }

    $dates = array_values( array_unique( $dates ) );
    sort( $dates );

    return [ 'nearest' => $nearest, 'dates' => $dates ];
}

/**
 * Zbiera wszystkie dostępne daty psychologa z 3 miesięcy (z transient cache getMonth).
 * Wrapper dla kompatybilności — używa np_bookero_get_availability() wewnętrznie.
 *
 * @param string $worker_id  Worker ID psychologa
 * @param string $typ        'pelnoplatny' | 'nisko'
 * @return string[]  Posortowane daty YYYY-MM-DD
 */
function np_bookero_get_all_available_dates( string $worker_id, string $typ ): array {
    return np_bookero_get_availability( $worker_id, $typ )['dates'];
}

/**
 * Zwraca najbliższy dostępny termin jako sformatowany string.
 *
 * Przeszukuje bieżący miesiąc i do 2 miesięcy do przodu.
 *
 * @param string $worker_id  Worker ID psychologa
 * @param string $typ        'pelnoplatny' | 'nisko'
 * @return string  np. "15 maja 2025, 16:30" lub pusty string gdy brak
 */
function np_bookero_najblizszy_termin( string $worker_id, string $typ = 'pelnoplatny' ): string {
    if ( ! $worker_id ) {
        return '';
    }

    $now = time();

    for ( $i = 0; $i <= 2; $i++ ) {
        $slots = np_bookero_get_terminy( $worker_id, $typ, $i );

        foreach ( $slots as $slot ) {
            $date = $slot['date'] ?? '';
            $hour = $slot['hour'] ?? '';
            if ( ! $date ) continue;

            $ts = strtotime( $date . ( $hour ? ' ' . $hour : '' ) );
            if ( $ts && $ts >= $now ) {
                $label = date_i18n( 'j F Y', $ts );
                return $hour ? $label . ', ' . $hour : $label;
            }
        }
    }

    return '';
}
