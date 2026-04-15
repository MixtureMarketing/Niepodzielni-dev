<?php
/**
 * CPT: Grupy Wsparcia
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', 'np_register_cpt_grupy_wsparcia' );

function np_register_cpt_grupy_wsparcia(): void {
    register_post_type( 'grupy-wsparcia', [
        'labels' => [
            'name'          => 'Grupy wsparcia',
            'singular_name' => 'Grupa wsparcia',
            'add_new_item'  => 'Dodaj grupę wsparcia',
            'edit_item'     => 'Edytuj grupę wsparcia',
        ],
        'public'        => true,
        'show_in_rest'  => true,
        'has_archive'   => false,
        'rewrite'       => [ 'slug' => 'grupa-wsparcia' ],
        'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
        'menu_icon'     => 'dashicons-heart',
        'menu_position' => 9,
    ] );
}
