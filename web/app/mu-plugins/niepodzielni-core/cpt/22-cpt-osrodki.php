<?php

/**
 * CPT: Ośrodek Pomocy (Psychomapa)
 * Rejestruje typ postu i taksonomie dla mapy ośrodków pomocy.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('init', 'np_register_cpt_osrodki');

function np_register_cpt_osrodki(): void
{
    register_post_type('osrodek_pomocy', [
        'labels' => [
            'name'          => 'Ośrodki pomocy',
            'singular_name' => 'Ośrodek pomocy',
            'add_new'       => 'Dodaj nowy',
            'add_new_item'  => 'Dodaj nowy ośrodek',
            'edit_item'     => 'Edytuj ośrodek',
            'view_item'     => 'Zobacz ośrodek',
            'search_items'  => 'Szukaj ośrodków',
            'not_found'     => 'Nie znaleziono ośrodków',
        ],
        'public'        => true,
        'show_in_rest'  => true,
        'has_archive'   => true,
        'rewrite'       => ['slug' => 'osrodek-pomocy'],
        'supports'      => ['title', 'editor', 'thumbnail'],
        'menu_icon'     => 'dashicons-location-alt',
        'menu_position' => 10,
    ]);

    register_taxonomy('rodzaj-pomocy', 'osrodek_pomocy', [
        'labels'       => ['name' => 'Rodzaje pomocy', 'singular_name' => 'Rodzaj pomocy'],
        'hierarchical' => true,
        'show_in_rest' => true,
        'rewrite'      => ['slug' => 'rodzaj-pomocy'],
    ]);

    register_taxonomy('grupa-docelowa', 'osrodek_pomocy', [
        'labels'       => ['name' => 'Grupy docelowe', 'singular_name' => 'Grupa docelowa'],
        'hierarchical' => true,
        'show_in_rest' => true,
        'rewrite'      => ['slug' => 'grupa-docelowa'],
    ]);
}
