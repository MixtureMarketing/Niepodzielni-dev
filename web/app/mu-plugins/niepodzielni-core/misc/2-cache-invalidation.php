<?php

/**
 * Cache invalidation — hooki czyszczące transienty/cache po zmianie treści.
 *
 * Pokrywa transienty cytowane w widokach motywu i shortcodach, które bez tego
 * pozostawały „stale" do końca TTL (komentarz w header.blade.php obiecywał
 * invalidację „przy save_post", ale hooków nie było).
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Czyści transienty mega-menu (lista wydarzeń + lista postów w nagłówku).
 *
 * @param string $which 'events' | 'posts' | 'all'
 */
function np_mega_menu_purge(string $which = 'all'): void
{
    if ($which === 'events' || $which === 'all') {
        delete_transient('np_mega_menu_events');
    }
    if ($which === 'posts' || $which === 'all') {
        delete_transient('np_mega_menu_posts');
    }
}

// Wydarzenia w mega-menu — warsztaty + grupy wsparcia
add_action('save_post_warsztaty', static fn() => np_mega_menu_purge('events'));
add_action('save_post_grupy_wsparcia', static fn() => np_mega_menu_purge('events'));

// Posty w mega-menu — standardowy CPT post
add_action('save_post_post', static fn() => np_mega_menu_purge('posts'));

// Usunięcie / przeniesienie do kosza dowolnego z powyższych typów → na pewno czyść.
// Hook `deleted_post` nie zna typu (post jest już usunięty), więc czyścimy oba.
add_action('deleted_post', static fn() => np_mega_menu_purge('all'));
add_action('trashed_post', static fn() => np_mega_menu_purge('all'));
add_action('untrashed_post', static fn() => np_mega_menu_purge('all'));

// Edycja taksonomii (zmiana terminów może wpływać na widoczność postów) — minimalnie inwazyjne.
add_action('edited_term', static fn() => np_mega_menu_purge('all'));
