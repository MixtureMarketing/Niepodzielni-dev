<?php

/**
 * CPT: Wydarzenia
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('init', 'np_register_cpt_wydarzenia');

function np_register_cpt_wydarzenia(): void
{
    register_post_type('wydarzenia', [
        'labels' => [
            'name'          => 'Wydarzenia',
            'singular_name' => 'Wydarzenie',
            'add_new_item'  => 'Dodaj wydarzenie',
            'edit_item'     => 'Edytuj wydarzenie',
        ],
        'public'        => true,
        'show_in_rest'  => true,
        'has_archive'   => false,
        'rewrite'       => [ 'slug' => 'wydarzenie' ],
        'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
        'menu_icon'     => 'dashicons-calendar-alt',
        'menu_position' => 7,
    ]);
}
