@php
/**
 * Archiwum CPT: osrodek_pomocy
 * Przekierowuje do Psychomapy — kanonicznej strony przeglądania ośrodków.
 */
$psychomapa = get_posts([
    'post_type'      => 'page',
    'posts_per_page' => 1,
    'meta_key'       => '_wp_page_template',
    'meta_value'     => 'template-psychomapa',
]);
$target = $psychomapa ? get_permalink($psychomapa[0]->ID) : home_url('/psychomapa/');
wp_redirect($target, 301);
exit;
@endphp
