<?php
/**
 * Bookero Enqueue — ładowanie skryptów JS/CSS Bookero
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_enqueue_scripts', 'np_bookero_enqueue' );

function np_bookero_enqueue(): void {
    // Skrypt Bookero ładowany tylko na stronach z widgetem kalendarza
    if ( ! is_singular( [ 'psycholog', 'warsztaty', 'wydarzenia' ] ) ) {
        return;
    }

    wp_enqueue_script(
        'bookero-widget',
        'https://app.bookero.pl/widget/bookero-widget.js',
        [],
        null,
        true
    );

    wp_localize_script( 'bookero-widget', 'npBookero', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'np_bookero_nonce' ),
    ] );
}
