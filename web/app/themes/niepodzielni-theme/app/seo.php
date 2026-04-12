<?php
/**
 * SEO: Structured Data (schema.org JSON-LD)
 *
 * @package Niepodzielni
 */

namespace App;

/**
 * Schema.org Person dla profilu psychologa.
 *
 * Generuje JSON-LD z danymi specjalisty: imię, specjalizacje, obszary pomocy,
 * języki, biogram i link do rezerwacji. Renderowany w <head> tylko na pojedynczych
 * stronach CPT psycholog.
 */
add_action('wp_head', function () {
    if (! is_singular('psycholog')) {
        return;
    }

    $post_id = get_the_ID();

    // Taksonomie
    $specs   = \np_get_post_terms( $post_id, 'specjalizacja' );
    $obszary = \np_get_post_terms( $post_id, 'obszar-pomocy' );
    $jezyki  = \np_get_post_terms( $post_id, 'jezyk' ) ?: [ 'Polski' ];

    $biogram = wp_strip_all_tags(get_post_meta($post_id, 'biogram', true));
    $image   = get_the_post_thumbnail_url($post_id, 'large');

    $schema = [
        '@context'      => 'https://schema.org',
        '@type'         => 'Person',
        'name'          => get_the_title(),
        'url'           => get_permalink(),
        'jobTitle'      => ! empty($specs) ? $specs[0] : 'Psycholog',
        'worksFor'      => [
            '@type' => 'Organization',
            'name'  => 'Fundacja Niepodzielni',
            'url'   => home_url(),
        ],
        'knowsAbout'    => array_values(array_unique(array_merge($specs, $obszary))),
        'knowsLanguage' => $jezyki,
    ];

    if ($image) {
        $schema['image'] = $image;
    }

    if ($biogram) {
        $schema['description'] = $biogram;
    }

    // Jeśli psycholog ma wolny termin → dodaj ReserveAction
    $termin_pelno = get_post_meta($post_id, np_bk_meta_key( 'pelnoplatny' ), true);
    $termin_nisko = get_post_meta($post_id, np_bk_meta_key( 'niskoplatny' ), true);
    $has_booking  = ! empty(bookero_sanitize_date((string) $termin_pelno))
                 || ! empty(bookero_sanitize_date((string) $termin_nisko));

    if ($has_booking) {
        $schema['potentialAction'] = [
            '@type'  => 'ReserveAction',
            'target' => get_permalink(),
            'result' => ['@type' => 'Reservation'],
        ];
    }

    echo '<script type="application/ld+json">'
        . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>' . "\n";
}, 5);
