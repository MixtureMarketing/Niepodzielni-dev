<?php
/**
 * Shortcodes: Psychologist Profile Attributes (Taxonomies and Meta)
 *
 * @package Niepodzielni
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * [lista_obszarow_pomocy limit="10"]
 */
add_shortcode( 'lista_obszarow_pomocy', 'niepodzielni_lista_obszarow_pomocy_shortcode' );
function niepodzielni_lista_obszarow_pomocy_shortcode( $atts ) {
    $post_id  = get_the_ID();
    $taxonomy = 'obszar-pomocy';
    $atts     = shortcode_atts( array( 'limit' => 10 ), $atts );

    $assigned_terms = get_the_terms( $post_id, $taxonomy );
    if ( empty( $assigned_terms ) || is_wp_error( $assigned_terms ) ) return '';

    $child_terms = array();
    foreach ( $assigned_terms as $term ) {
        if ( $term->parent != 0 ) $child_terms[] = $term;
    }
    if ( empty( $child_terms ) ) $child_terms = $assigned_terms;

    usort( $child_terms, function ( $a, $b ) { return strcmp( $a->name, $b->name ); } );

    $limit = intval( $atts['limit'] );
    $total = count( $child_terms );

    return \Roots\view( 'partials.shortcodes.obszary-pomocy', [
        'terms' => array_values( $child_terms ),
        'limit' => $limit,
        'total' => $total,
    ] )->render();
}

/**
 * [specjalizacje_produktu]
 */
add_shortcode( 'specjalizacje_produktu', 'niepodzielni_specjalizacje_shortcode' );
function niepodzielni_specjalizacje_shortcode( $atts ) {
    $names = np_get_post_terms( get_the_ID(), 'specjalizacja', 4 );
    if ( empty( $names ) ) return '';

    return \Roots\view( 'partials.shortcodes.specjalizacje', compact( 'names' ) )->render();
}

/**
 * [rodzaj_wizyty_produktu]
 */
add_shortcode( 'rodzaj_wizyty_produktu', 'niepodzielni_rodzaj_wizyty_shortcode' );
function niepodzielni_rodzaj_wizyty_shortcode( $atts ) {
    $info = get_post_meta( get_the_ID(), 'rodzaj_wizyty', true );
    if ( empty( $info ) ) return '';

    $parts = explode( ', ', $info );

    return \Roots\view( 'partials.shortcodes.rodzaj-wizyty', compact( 'parts' ) )->render();
}

/**
 * [jezyki_profil_psychologa]
 */
add_shortcode( 'jezyki_profil_psychologa', 'niepodzielni_jezyki_shortcode' );
function niepodzielni_jezyki_shortcode( $atts ) {
    $terms = get_the_terms( get_the_ID(), 'jezyk' );
    if ( empty( $terms ) || is_wp_error( $terms ) ) return '';

    return \Roots\view( 'partials.shortcodes.jezyki', [
        'terms'   => $terms,
        'flagMap' => np_get_flag_map(),
    ] )->render();
}

/**
 * [dynamiczna_stawka_konsultacji]
 * Wyświetla stawkę odpowiednią do typu konsultacji (?konsultacje=nisko|pelno).
 */
add_shortcode( 'dynamiczna_stawka_konsultacji', 'np_shortcode_dynamiczna_stawka' );
function np_shortcode_dynamiczna_stawka(): string {
    $post_id   = get_the_ID();
    $kons_type = sanitize_key( $_GET['konsultacje'] ?? '' );
    $meta_key  = ( $kons_type === 'nisko' ) ? 'stawka_niskoplatna' : 'stawka_wysokoplatna';
    $stawka    = get_post_meta( $post_id, $meta_key, true );
    if ( ! $stawka ) {
        $stawka = ( $kons_type === 'nisko' ) ? '55 zł' : '145 zł';
    }
    return '<span class="dynamic-rate-price">' . esc_html( $stawka ) . '</span>'
         . '<span class="dynamic-rate-unit"> / 50 min</span>';
}

/**
 * [termin_z_bazy typ="pelnoplatny|niskoplatny"]
 * Wyświetla najbliższy termin pobrany z postmeta.
 */
add_shortcode( 'termin_z_bazy', 'np_shortcode_termin_z_bazy' );
function np_shortcode_termin_z_bazy( $atts ): string {
    $atts    = shortcode_atts( [ 'typ' => 'pelnoplatny' ], $atts );
    $meta_key = np_bk_meta_key( $atts['typ'] );
    $termin   = get_post_meta( get_the_ID(), $meta_key, true );
    if ( ! $termin ) return '';
    return 'Najbliższy termin: <span class="bookero-date-value">' . esc_html( $termin ) . '</span>';
}

/**
 * [nurty_produktu]
 */
add_shortcode( 'nurty_produktu', 'niepodzielni_nurty_shortcode' );
function niepodzielni_nurty_shortcode( $atts ) {
    $terms = get_the_terms( get_the_ID(), 'nurt' );
    if ( empty( $terms ) || is_wp_error( $terms ) ) return '';

    return \Roots\view( 'partials.shortcodes.nurty', compact( 'terms' ) )->render();
}
