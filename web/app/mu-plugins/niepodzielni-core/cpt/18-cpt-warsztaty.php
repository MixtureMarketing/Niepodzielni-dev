<?php

/**
 * CPT: Warsztaty
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('init', 'np_register_cpt_warsztaty');

function np_register_cpt_warsztaty(): void
{
    register_post_type('warsztaty', [
        'labels' => [
            'name'          => 'Warsztaty',
            'singular_name' => 'Warsztat',
            'add_new_item'  => 'Dodaj warsztat',
            'edit_item'     => 'Edytuj warsztat',
        ],
        'public'        => true,
        'show_in_rest'  => true,
        'has_archive'   => false,
        'rewrite'       => [ 'slug' => 'warsztat' ],
        'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
        'menu_icon'     => 'dashicons-groups',
        'menu_position' => 8,
    ]);
}
