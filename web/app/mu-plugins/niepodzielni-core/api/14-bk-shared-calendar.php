<?php

/**
 * Bookero Shared Calendar — wspólny kalendarz dla wielu psychologów
 * Shortcode: [bookero_wspolny_kalendarz ids="123,456"]
 */

if (! defined('ABSPATH')) {
    exit;
}

add_shortcode('bookero_wspolny_kalendarz', 'np_shortcode_shared_calendar');

function np_shortcode_shared_calendar(array $atts): string
{
    $atts = shortcode_atts([
        'ids'   => '',
        'color' => '#6a3d9a',
    ], $atts);

    if (empty($atts['ids'])) {
        return '';
    }

    $ids = array_filter(array_map('trim', explode(',', $atts['ids'])));
    if (empty($ids)) {
        return '';
    }

    $ids_json = wp_json_encode($ids);

    return sprintf(
        '<div class="bookero-shared-calendar" data-ids=\'%s\' data-color="%s"></div>',
        esc_attr($ids_json),
        esc_attr($atts['color']),
    );
}
