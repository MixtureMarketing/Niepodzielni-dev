<?php

/**
 * CPT: Psycholog
 * Rejestruje typ postu i taksonomie dla psychologów.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('init', 'np_register_cpt_psycholog');

function np_register_cpt_psycholog(): void
{
    register_post_type('psycholog', [
        'labels' => [
            'name'               => 'Psycholodzy',
            'singular_name'      => 'Psycholog',
            'add_new'            => 'Dodaj nowego',
            'add_new_item'       => 'Dodaj nowego psychologa',
            'edit_item'          => 'Edytuj psychologa',
            'view_item'          => 'Zobacz psychologa',
            'search_items'       => 'Szukaj psychologów',
            'not_found'          => 'Nie znaleziono psychologów',
        ],
        'public'             => true,
        'show_in_rest'       => true,
        'has_archive'        => false,
        'rewrite'            => [ 'slug' => 'psycholog' ],
        'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'comments' ],
        'menu_icon'          => 'dashicons-businessperson',
        'menu_position'      => 5,
    ]);

    // Taksonomie
    $taxonomies = [
        'specjalizacja' => [ 'Specjalizacje',  'Specjalizacja'  ],
        'nurt'          => [ 'Nurty',           'Nurt'           ],
        'obszar-pomocy' => [ 'Obszary pomocy',  'Obszar pomocy'  ],
        'jezyk'         => [ 'Języki',          'Język'          ],
        'rodzaj-konsultacji' => [ 'Rodzaj konsultacji', 'Rodzaj konsultacji' ],
    ];

    foreach ($taxonomies as $slug => [ $plural, $singular ]) {
        register_taxonomy($slug, 'psycholog', [
            'labels'       => [ 'name' => $plural, 'singular_name' => $singular ],
            'hierarchical' => false,
            'show_in_rest' => true,
            'rewrite'      => [ 'slug' => $slug ],
        ]);
    }
}
