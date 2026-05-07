<?php

/**
 * Helper rejestracji CPT typu "wydarzenie".
 *
 * Trzy CPT (wydarzenia, warsztaty, grupy-wsparcia) mają niemal identyczne
 * `register_post_type` argumenty.  Ten plik trzyma jeden punkt prawdy.
 *
 * Uwaga: numer `15-` zapewnia, że plik jest require'owany PRZED 17/18/19,
 * a niepodzielni-core.php ładuje pliki w narzuconej kolejności.
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @param string $postType    np. 'warsztaty'
 * @param string $singular    np. 'Warsztat'
 * @param string $plural      np. 'Warsztaty'
 * @param string $rewriteSlug np. 'warsztat'
 * @param string $menuIcon    Dashicons identifier
 * @param int    $menuPosition
 * @param array<int, string> $supports  override domyślnych
 */
function np_register_event_cpt(
    string $postType,
    string $singular,
    string $plural,
    string $rewriteSlug,
    string $menuIcon,
    int $menuPosition,
    array $supports = ['title', 'editor', 'thumbnail', 'excerpt'],
): void {
    register_post_type($postType, [
        'labels' => [
            'name'          => $plural,
            'singular_name' => $singular,
            'add_new_item'  => 'Dodaj ' . mb_strtolower($singular),
            'edit_item'     => 'Edytuj ' . mb_strtolower($singular),
        ],
        'public'        => true,
        'show_in_rest'  => true,
        'has_archive'   => false,
        'rewrite'       => ['slug' => $rewriteSlug],
        'supports'      => $supports,
        'menu_icon'     => $menuIcon,
        'menu_position' => $menuPosition,
    ]);
}
