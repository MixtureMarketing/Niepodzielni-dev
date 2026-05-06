@php
/**
 * Taksonomia: rodzaj-pomocy
 * Przekierowuje do Psychomapy z pre-wybranym filtrem rodzaju pomocy.
 * Frontend obsługuje filtrowanie po term_id przez URL hash (future feature).
 */
$psychomapa = get_posts([
    'post_type'      => 'page',
    'posts_per_page' => 1,
    'meta_key'       => '_wp_page_template',
    'meta_value'     => 'template-psychomapa',
]);
$term   = get_queried_object();
$base   = $psychomapa ? get_permalink($psychomapa[0]->ID) : home_url('/psychomapa/');
$target = $base . '#rodzaj-' . ($term->term_id ?? '');
wp_redirect($target, 302);
exit;
@endphp
