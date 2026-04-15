<?php

/**
 * Shortcodes Bookero
 * [bookero_kalendarz]  — osadza widget kalendarza Bookero
 * [bookero_przycisk]   — przycisk "Umów się" linkujący do Bookero
 */

if (! defined('ABSPATH')) {
    exit;
}

add_shortcode('bookero_kalendarz', 'np_shortcode_bookero_kalendarz');
add_shortcode('bookero_przycisk', 'np_shortcode_bookero_przycisk');

function np_shortcode_bookero_kalendarz(array $atts): string
{
    $post_id  = get_the_ID();
    $id_pelny = get_post_meta($post_id, 'bookero_id_pelny', true);
    $id_niski = get_post_meta($post_id, 'bookero_id_niski', true);

    if (! $id_pelny && ! $id_niski) {
        return '<p class="bookero-error">Brak ID kalendarza Bookero dla tego psychologa.</p>';
    }

    $kons_type = sanitize_key($_GET['konsultacje'] ?? '');

    // Brak parametru + oba kalendarze dostępne → pokaż selektor
    if (! $kons_type && $id_pelny && $id_niski) {
        $url_pelno = esc_url(add_query_arg('konsultacje', 'pelno'));
        $url_nisko = esc_url(add_query_arg('konsultacje', 'nisko'));
        return '<div class="bookero-typ-wybor">'
             . '<p class="bookero-typ-wybor__label">Wybierz typ konsultacji:</p>'
             . '<div class="bookero-typ-wybor__btns">'
             . '<a href="' . $url_pelno . '" class="bookero-typ-btn bookero-typ-btn--pelno">Pełnopłatna</a>'
             . '<a href="' . $url_nisko . '" class="bookero-typ-btn bookero-typ-btn--nisko">Niskopłatna</a>'
             . '</div></div>';
    }

    return sprintf(
        '<div id="bookero_wrapper" class="bookero-calendar-wrapper"'
        . ' data-calendar-type="product"'
        . ' data-id-pelno="%s"'
        . ' data-id-nisko="%s">'
        . '<div class="bookero-preloader-wrapper"></div>'
        . '</div>'
        . '<div id="bookero_render_target"></div>'
        . '<div id="what_calendar"></div>',
        esc_attr($id_pelny),
        esc_attr($id_niski),
    );
}

function np_shortcode_bookero_przycisk(array $atts): string
{
    $atts = shortcode_atts([
        'id'    => '',
        'tekst' => 'Umów się',
        'class' => 'btn btn-primary',
    ], $atts);

    $bookero_id = $atts['id'] ?: get_post_meta(get_the_ID(), 'bookero_id_pelny', true);
    if (! $bookero_id) {
        return '';
    }

    $url = 'https://app.bookero.pl/calendar/' . urlencode($bookero_id);
    return sprintf(
        '<a href="%s" target="_blank" rel="noopener" class="%s">%s</a>',
        esc_url($url),
        esc_attr($atts['class']),
        esc_html($atts['tekst']),
    );
}
