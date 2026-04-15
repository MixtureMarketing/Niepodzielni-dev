<?php

/**
 * CPT: Aktualności
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('init', 'np_register_cpt_aktualnosci');

function np_register_cpt_aktualnosci(): void
{
    register_post_type('aktualnosci', [
        'labels' => [
            'name'          => 'Aktualności',
            'singular_name' => 'Aktualność',
            'add_new_item'  => 'Dodaj aktualność',
            'edit_item'     => 'Edytuj aktualność',
        ],
        'public'       => true,
        'show_in_rest' => true,
        'has_archive'  => false,
        'rewrite'      => [ 'slug' => 'aktualnosci' ],
        'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
        'menu_icon'    => 'dashicons-megaphone',
        'menu_position' => 6,
    ]);

    register_taxonomy('temat', 'aktualnosci', [
        'labels'       => [ 'name' => 'Tematy', 'singular_name' => 'Temat' ],
        'hierarchical' => true,
        'show_in_rest' => true,
        'rewrite'      => [ 'slug' => 'temat' ],
    ]);
}
