@php
/**
 * Taksonomia: grupa-docelowa
 * Przekierowuje do Psychomapy z pre-wybranym filtrem grupy docelowej.
 */
$psychomapa = get_posts([
    'post_type'      => 'page',
    'posts_per_page' => 1,
    'meta_key'       => '_wp_page_template',
    'meta_value'     => 'template-psychomapa',
]);
$term   = get_queried_object();
$base   = $psychomapa ? get_permalink($psychomapa[0]->ID) : home_url('/psychomapa/');
$target = $base . '#grupa-' . ($term->term_id ?? '');
wp_redirect($target, 302);
exit;
@endphp
