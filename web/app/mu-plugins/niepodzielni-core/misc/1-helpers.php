<?php
/**
 * Helpers — funkcje pomocnicze niezależne od motywu
 * Używane przez shortcodes, admin i szablony Blade.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Zwraca klucz API Bookero dla danego typu konsultacji.
 * Priorytet: stała PHP (z .env przez config/application.php) → WP option.
 *
 * @param string $typ  'pelnoplatny' | 'nisko' | 'nisko' | 'pelno'
 * @return string
 */
function np_bookero_api_key_for( string $typ ): string {
    $is_nisko = in_array( $typ, [ 'nisko', 'niskoplatny', 'niskoplatne' ], true );
    if ( $is_nisko ) {
        return defined( 'NP_BOOKERO_API_KEY_NISKO' ) && NP_BOOKERO_API_KEY_NISKO
            ? NP_BOOKERO_API_KEY_NISKO
            : get_option( 'np_bookero_api_key_nisko', '' );
    }
    return defined( 'NP_BOOKERO_API_KEY_PELNY' ) && NP_BOOKERO_API_KEY_PELNY
        ? NP_BOOKERO_API_KEY_PELNY
        : get_option( 'np_bookero_api_key_pelny', '' );
}

/**
 * Zwraca ID kalendarza Bookero (hash) dla danego typu konsultacji.
 * Priorytet: stała PHP (z .env) → WP option.
 *
 * @param string $typ  'pelnoplatny' | 'nisko' | ...
 * @return string
 */
function np_bookero_cal_id_for( string $typ ): string {
    $is_nisko = in_array( $typ, [ 'nisko', 'niskoplatny', 'niskoplatne' ], true );
    if ( $is_nisko ) {
        return defined( 'NP_BOOKERO_CAL_ID_NISKO' ) && NP_BOOKERO_CAL_ID_NISKO
            ? NP_BOOKERO_CAL_ID_NISKO
            : get_option( 'np_bookero_cal_nisko', '' );
    }
    return defined( 'NP_BOOKERO_CAL_ID_PELNY' ) && NP_BOOKERO_CAL_ID_PELNY
        ? NP_BOOKERO_CAL_ID_PELNY
        : get_option( 'np_bookero_cal_pelny', '' );
}

/**
 * Zwraca inline SVG dla podanego klucza ikony.
 *
 * @param string $icon  Klucz ikony: 'online', 'stacjonarnie', 'arrow_link'
 * @return string HTML ikony SVG
 */
function get_niepodzielni_svg_icon( string $icon ): string {
    $icons = [
        'online' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',

        'stacjonarnie' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',

        'arrow_link' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
    ];

    return $icons[ $icon ] ?? '';
}

/**
 * Zwraca tablicę nazw termów przypisanych do postu dla podanej taksonomii.
 *
 * @param int    $post_id   ID postu
 * @param string $taxonomy  Slug taksonomii
 * @return string[]
 */
function np_get_post_terms( int $post_id, string $taxonomy, int $limit = 0 ): array {
    $terms = get_the_terms( $post_id, $taxonomy );
    if ( ! $terms || is_wp_error( $terms ) ) {
        return [];
    }
    $names = array_map( fn( $t ) => $t->name, $terms );
    return $limit > 0 ? array_slice( $names, 0, $limit ) : $names;
}

/**
 * Zwraca nazwę klucza postmeta dla najbliższego terminu Bookero.
 *
 * @param string $typ  'pelnoplatny' | 'niskoplatny'
 * @return string  Klucz postmeta
 */
function np_bk_meta_key( string $typ ): string {
    return in_array( $typ, [ 'niskoplatny', 'niskoplatne', 'nisko' ], true )
        ? 'najblizszy_termin_niskoplatny'
        : 'najblizszy_termin_pelnoplatny';
}

/**
 * Zwraca wartość pola ACF lub postmeta jako string (bezpieczny fallback).
 *
 * @param string   $key      Klucz pola
 * @param int|null $post_id  ID postu (domyślnie bieżący)
 * @return string
 */
function np_field( string $key, ?int $post_id = null ): string {
    if ( function_exists( 'get_field' ) ) {
        $val = get_field( $key, $post_id ?? get_the_ID() );
        return is_string( $val ) ? $val : ( is_array( $val ) ? implode( ', ', $val ) : (string) $val );
    }
    return (string) get_post_meta( $post_id ?? get_the_ID(), $key, true );
}

/**
 * Czyści i waliduje datę z Bookero.
 * Przyjmuje różne formaty: "15 maja 2025", "2025-05-15", itp.
 * Zwraca sformatowaną datę "j F Y" lub pusty string jeśli data jest nieprawidłowa.
 *
 * @param string $date_string  Data do sprawdzenia
 * @return string  Oczyszczona data lub pusty string
 */
function bookero_sanitize_date( string $date_string ): string {
    if ( empty( $date_string ) ) return '';
    if ( strpos( $date_string, 'Brak' ) !== false ) return '';
    if ( strpos( $date_string, 'Błąd' ) !== false ) return '';

    // Próbujemy sparsować datę
    $timestamp = strtotime( $date_string );
    if ( false === $timestamp || $timestamp <= 0 ) {
        // Próba polskiego formatu "15 maja 2025"
        $months_pl = [
            'stycznia' => 'January', 'lutego' => 'February', 'marca' => 'March',
            'kwietnia' => 'April', 'maja' => 'May', 'czerwca' => 'June',
            'lipca' => 'July', 'sierpnia' => 'August', 'września' => 'September',
            'października' => 'October', 'listopada' => 'November', 'grudnia' => 'December',
            'styczeń' => 'January', 'luty' => 'February', 'marzec' => 'March',
            'kwiecień' => 'April', 'maj' => 'May', 'czerwiec' => 'June',
            'lipiec' => 'July', 'sierpień' => 'August', 'wrzesień' => 'September',
            'październik' => 'October', 'listopad' => 'November', 'grudzień' => 'December',
        ];
        $normalized = strtr( strtolower( $date_string ), $months_pl );
        $timestamp  = strtotime( $normalized );
        if ( false === $timestamp || $timestamp <= 0 ) return '';
    }

    return date_i18n( 'j F Y', $timestamp );
}

/**
 * Konwertuje datę do formatu Ymd (do sortowania).
 *
 * @param string $date_string  Sformatowana data (np. "15 maja 2025")
 * @return string  Format Ymd lub '99999999' gdy brak daty (sortowanie na koniec)
 */
function np_get_sortable_date( string $date_string ): string {
    if ( empty( $date_string ) ) return '99999999';

    $months_pl = [
        'stycznia' => '01', 'lutego' => '02', 'marca' => '03', 'kwietnia' => '04',
        'maja' => '05', 'czerwca' => '06', 'lipca' => '07', 'sierpnia' => '08',
        'września' => '09', 'października' => '10', 'listopada' => '11', 'grudnia' => '12',
    ];

    // Format "15 maja 2025" → "20250515"
    if ( preg_match( '/(\d{1,2})\s+(\w+)\s+(\d{4})/', $date_string, $m ) ) {
        $month = $months_pl[ strtolower( $m[2] ) ] ?? '00';
        return sprintf( '%04d%02d%02d', $m[3], $month, $m[1] );
    }

    // Format ISO "2025-05-15"
    $ts = strtotime( $date_string );
    return $ts ? date( 'Ymd', $ts ) : '99999999';
}

/**
 * Zapisuje błąd do logu Bookero API (max 30 ostatnich wpisów).
 *
 * @param string $context  Kontekst (np. 'getMonth', 'init')
 * @param string $msg      Opis błędu
 */
function np_bookero_log_error( string $context, string $msg ): void {
    $log = get_option( 'np_bookero_error_log', [] );
    if ( ! is_array( $log ) ) $log = [];

    array_unshift( $log, [
        'ts'      => time(),
        'context' => $context,
        'msg'     => $msg,
    ] );

    // Trzymaj max 30 ostatnich błędów
    $log = array_slice( $log, 0, 30 );
    update_option( 'np_bookero_error_log', $log, false );
}

/**
 * Zwraca URL obrazka z jednego z podanych kluczy postmeta (pierwszy niepusty).
 * Obsługuje zarówno ID attachment jak i URL stringa.
 *
 * @param int      $post_id  ID postu
 * @param string[] $keys     Lista kluczy do sprawdzenia po kolei
 * @param string   $size     Rozmiar obrazka WordPress (np. 'medium', 'large')
 * @return string  URL obrazka lub pusty string
 */
function np_get_post_image_url( int $post_id, array $keys, string $size = 'large' ): string {
    foreach ( $keys as $key ) {
        $val = get_post_meta( $post_id, $key, true );
        if ( empty( $val ) ) continue;

        // Jeśli to ID attachmentu
        if ( is_numeric( $val ) ) {
            $url = wp_get_attachment_image_url( (int) $val, $size );
            if ( $url ) return $url;
        }

        // Jeśli to URL
        if ( is_string( $val ) && filter_var( $val, FILTER_VALIDATE_URL ) ) {
            return $val;
        }

        // Jeśli to tablica (np. ACF image field zwraca array)
        if ( is_array( $val ) && ! empty( $val['url'] ) ) {
            return $val['url'];
        }
    }

    // Fallback: miniaturka posta
    return (string) get_the_post_thumbnail_url( $post_id, $size );
}

/**
 * Zwraca mapę flagi emoji → język (do wyświetlania ikon języków).
 *
 * @return array<string, string>  Klucz = slug taksonomii lub nazwa, wartość = emoji flagi
 */
function np_get_flag_map(): array {
    return [
        'polski'     => 'pl',
        'angielski'  => 'gb',
        'ukrainski'  => 'ua',
        'rosyjski'   => 'ru',
        'niemiecki'  => 'de',
        'francuski'  => 'fr',
        'hiszpanski' => 'es',
        'wloski'     => 'it',
        'pl'         => 'pl',
        'en'         => 'gb',
        'uk'         => 'ua',
        'ru'         => 'ru',
        'de'         => 'de',
        'fr'         => 'fr',
        'es'         => 'es',
        'it'         => 'it',
    ];
}
