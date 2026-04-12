<?php
/**
 * Shortcodes: UI, Formatting and Sliders
 *
 * @package Niepodzielni
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * [opis_psychologa word_limit="60"] / [opis_produktu]
 * Skraca opis i dodaje przycisk "Pokaż więcej".
 */
add_shortcode( 'opis_produktu', 'niepodzielni_opis_psychologa_shortcode' );
add_shortcode( 'opis_psychologa', 'niepodzielni_opis_psychologa_shortcode' );
function niepodzielni_opis_psychologa_shortcode( $atts ) {
    $content = get_post_field( 'post_content', get_the_ID() );
    if ( empty( $content ) ) return '';

    $atts       = shortcode_atts( array( 'word_limit' => 50 ), $atts, 'opis_psychologa' );
    $word_limit = intval( $atts['word_limit'] );
    $is_short   = str_word_count( strip_tags( $content ) ) <= $word_limit;

    return \Roots\view( 'partials.shortcodes.opis-psychologa', [
        'content'           => wp_kses_post( $content ),
        'is_short'          => $is_short,
        'short_description' => $is_short ? '' : wp_kses_post( wp_trim_words( $content, $word_limit, '...' ) ),
    ] )->render();
}

/**
 * [tytul_wyrozniony]
 * Formatuje tytuł psychologa (imię cienkie, NAZWISKO pogrubione).
 */
add_shortcode( 'tytul_wyrozniony', function () {
    $t = get_the_title();
    $h = get_post_meta( get_the_ID(), 'wyrozniona_czesc', true );
    if ( empty( $h ) ) return "<h1 class='psy-name-h1'>" . esc_html( $t ) . '</h1>';
    $p = explode( $h, $t, 2 );
    return sprintf(
        '<h1 class="psy-name-h1" style="text-transform:uppercase;"><span style="font-weight:400;">%s</span><span style="font-weight:600;">%s</span><span style="font-weight:400;">%s</span></h1>',
        esc_html( $p[0] ),
        esc_html( $h ),
        esc_html( $p[1] ?? '' )
    );
} );

/**
 * [specjalisci_slider limit="12" rodzaj=""]
 * Renderuje Swiper slider ze specjalistami.
 */
add_shortcode( 'specjalisci_slider', 'niepodzielni_specialists_slider_shortcode' );
function niepodzielni_specialists_slider_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'limit'         => 12,
        'rodzaj'        => '',
        'specjalizacja' => '',
    ), $atts );

    $cache_key = 'np_slider_' . md5( serialize( $atts ) );
    $slides    = get_transient( $cache_key );

    if ( $slides === false ) {
        $limit = max( 1, (int) $atts['limit'] );

        $args = array(
            'post_type'      => 'psycholog',
            'posts_per_page' => $limit * 3, // margines na wpisy bez terminu
            'post_status'    => 'publish',
            'no_found_rows'  => true,
            // orderby: rand usunięte — shuffle() w PHP po cachowaniu
        );

        // Filtruj po taksonomii specjalizacja (np. diagnoza-adhd)
        if ( ! empty( $atts['specjalizacja'] ) ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'specjalizacja',
                    'field'    => 'slug',
                    'terms'    => array_map( 'trim', explode( ',', $atts['specjalizacja'] ) ),
                    'operator' => 'IN',
                ),
            );
        }

        // Filtruj po dostępnym ID Bookero
        if ( $atts['rodzaj'] === 'nisko' || $atts['rodzaj'] === 'konsultacje-niskoplatne' ) {
            $args['meta_query'][] = array(
                'key'     => 'bookero_id_niski',
                'value'   => '',
                'compare' => '!=',
            );
        } elseif ( $atts['rodzaj'] === 'pelno' || $atts['rodzaj'] === 'konsultacje-pelnoplatne' ) {
            $args['meta_query'][] = array(
                'key'     => 'bookero_id_pelny',
                'value'   => '',
                'compare' => '!=',
            );
        }

        $query = new WP_Query( $args );
        $pool  = $query->posts;
        shuffle( $pool ); // losowość PHP-side (nie blokuje cache MySQL)

        // Prefetch meta dla wszystkich pobranych postów — eliminuje N+1
        update_postmeta_cache( wp_list_pluck( $pool, 'ID' ) );

        $slides = [];
        foreach ( $pool as $post ) {
            setup_postdata( $post );
            $pid = $post->ID;

            $termin_pelno = get_post_meta( $pid, np_bk_meta_key( 'pelnoplatny' ), true );
            $termin_nisko = get_post_meta( $pid, np_bk_meta_key( 'niskoplatny' ), true );

            if ( $atts['rodzaj'] === 'konsultacje-niskoplatne' || $atts['rodzaj'] === 'nisko' ) {
                $termin = $termin_nisko;
            } elseif ( $atts['rodzaj'] === 'konsultacje-pelnoplatne' || $atts['rodzaj'] === 'pelno' ) {
                $termin = $termin_pelno;
            } else {
                $termin = ( ! empty( $termin_pelno ) && strpos( $termin_pelno, 'Brak' ) === false && strpos( $termin_pelno, 'Błąd' ) === false )
                    ? $termin_pelno
                    : $termin_nisko;
            }

            if ( empty( $termin ) || strpos( $termin, 'Brak' ) !== false || strpos( $termin, 'Błąd' ) !== false ) {
                continue;
            }

            $slides[] = [
                'title'              => get_the_title(),
                'link'               => get_permalink(),
                'thumb'              => get_the_post_thumbnail( $pid, 'large', [ 'class' => 'specialist-image' ] ),
                'termin'             => $termin,
                'rodzaj_wizyty_html' => niepodzielni_rodzaj_wizyty_shortcode( [] ),
                'specjalizacje_html' => niepodzielni_specjalizacje_shortcode( [] ),
            ];

            if ( count( $slides ) >= $limit ) {
                break;
            }
        }
        wp_reset_postdata();

        set_transient( $cache_key, $slides, 30 * MINUTE_IN_SECONDS );
    }

    return \Roots\view( 'partials.shortcodes.specjalisci-slider', compact( 'slides' ) )->render();
}

add_action( 'save_post_psycholog', 'np_clear_slider_cache' );
add_action( 'niepodzielni_bookero_batch_synced', 'np_clear_slider_cache' );
function np_clear_slider_cache(): void {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_np_slider_%'
            OR option_name LIKE '_transient_timeout_np_slider_%'"
    );
}

/**
 * [widget_umow_sie]
 * Renderuje interaktywny przycisk "UMÓW SIĘ" z listą rozwijaną.
 */
add_shortcode( 'widget_umow_sie', function () {
    return \Roots\view( 'partials.shortcodes.widget-umow-sie' )->render();
} );

/**
 * [godziny_wydarzenia]
 * Wyświetla zakres godzin (np. 10:00–12:00) na podstawie pól meta.
 */
add_shortcode( 'godziny_wydarzenia', function () {
    $post_id = get_the_ID();
    $start   = get_post_meta( $post_id, 'godzina_rozpoczecia', true );
    $koniec  = get_post_meta( $post_id, 'godzina_zakonczenia', true );

    if ( empty( $start ) ) return '';

    $start_format = date( 'H:i', strtotime( $start ) );
    if ( empty( $koniec ) ) return 'START: ' . $start_format;

    return $start_format . '–' . date( 'H:i', strtotime( $koniec ) );
} );

/**
 * [moj_start_wydarzenia]
 * Pełna data i godziny (np. START: 15 MARCA 10:00–12:00).
 */
add_shortcode( 'moj_start_wydarzenia', function () {
    $post_id       = get_the_ID();
    $data_surowa   = get_post_meta( $post_id, 'data', true );
    $godzina_start = get_post_meta( $post_id, 'godzina', true );
    $godzina_koniec = get_post_meta( $post_id, 'godzina_zakonczenia', true );

    if ( ! $data_surowa ) return '';

    $miesiace = [ '01' => 'STYCZNIA', '02' => 'LUTEGO', '03' => 'MARCA', '04' => 'KWIETNIA', '05' => 'MAJA', '06' => 'CZERWCA', '07' => 'LIPCA', '08' => 'SIERPNIA', '09' => 'WRZEŚNIA', '10' => 'PAŹDZIERNIKA', '11' => 'LISTOPADA', '12' => 'GRUDNIA' ];

    $time           = strtotime( $data_surowa );
    $dzien          = date( 'j', $time );
    $nazwa_miesiaca = $miesiace[ date( 'm', $time ) ];

    return 'START: ' . $dzien . ' ' . $nazwa_miesiaca . ' ' . $godzina_start . '–' . $godzina_koniec;
} );

/**
 * [burger_menu]
 * Renderuje ikonę burgera dla menu mobilnego.
 */
add_shortcode( 'burger_menu', function () {
    return '<label class="burger" for="burger"><input type="checkbox" id="burger"><span></span><span></span><span></span></label>';
} );

/**
 * [faq_adhd_schema]
 * Wstrzykuje dane strukturalne JSON-LD dla strony ADHD.
 */
add_shortcode( 'faq_adhd_schema', function () {
    return '
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "Jakie są objawy ADHD u dorosłych?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Objawy ADHD u dorosłych mogą być różne i obejmować: Trudności z koncentracją i uwagą – łatwe rozpraszanie się, zapominanie o obowiązkach. Impulsywność – pochopne podejmowanie decyzji, przerywanie rozmów. Problemy z organizacją czasu i planowaniem. Nadmierna aktywność lub uczucie wewnętrznego niepokoju. Jeśli zauważasz u siebie powyższe symptomy, warto rozważyć diagnozowanie ADHD u dorosłych."
      }
    },
    {
      "@type": "Question",
      "name": "Jak zdiagnozować ADHD u dorosłych?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Diagnoza ADHD u dorosłych wymaga wizyty u psychiatry lub psychologa specjalizującego się w ADHD. Proces obejmuje: 1. Szczegółowy wywiad kliniczny i ocena objawów. 2. Analizę historii życia i funkcjonowania w dzieciństwie. 3. Testy psychologiczne oceniające koncentrację, impulsywność i pamięć. 4. W razie potrzeby konsultacje dodatkowe (np. neurologiczne, endokrynologiczne)."
      }
    },
    {
      "@type": "Question",
      "name": "Ile kosztuje diagnoza ADHD u dorosłych?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Cena diagnozy ADHD u dorosłych zależy od placówki i zakresu badań. Koszt diagnozy ADHD w prywatnych klinikach waha się zazwyczaj od 300 do 2000 zł, w zależności od liczby konsultacji i badań."
      }
    },
    {
      "@type": "Question",
      "name": "Jakie leki na ADHD są stosowane u dorosłych?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Leki stosowane w leczeniu ADHD u dorosłych to: Stymulanty (np. metylofenidat, amfetaminy) – poprawiają koncentrację i redukują impulsywność. Niestymulujące leki (np. atomoksetyna) – pomagają w stabilizacji objawów ADHD. Leczenie farmakologiczne powinno być prowadzone pod nadzorem specjalisty."
      }
    },
    {
      "@type": "Question",
      "name": "Czy ADHD u dorosłych może wpływać na zdrowie psychiczne?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Tak, ADHD u dorosłych często współwystępuje z innymi zaburzeniami, takimi jak: Depresja, zaburzenia nastroju, zaburzenia lękowe. Zaburzenia snu. Problemy z regulacją emocji. Dlatego diagnoza ADHD u dorosłych powinna uwzględniać całościową ocenę stanu psychicznego."
      }
    },
    {
      "@type": "Question",
      "name": "Jak sprawdzić, czy ma się ADHD?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Aby sprawdzić, czy masz ADHD, możesz: 1. Zaobserwować czy występuju u Ciebie typowe objawy dla ADHD. 2. Skonsultować się z lekarzem psychiatrą lub psychologiem. 3. Poddać się profesjonalnej diagnozie ADHD u dorosłych. Jeśli objawy wpływają na Twoje codzienne funkcjonowanie, warto skonsultować się ze specjalistą."
      }
    },
    {
      "@type": "Question",
      "name": "Czy ADHD może wpływać na pracę zawodową?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Tak, ADHD u dorosłych może powodować trudności w miejscu pracy, w tym: Problemy z organizacją i zarządzaniem czasem. Trudności w wykonywaniu zadań wymagających długotrwałej koncentracji. Impulsywne podejmowanie decyzji. Jednak odpowiednia diagnoza ADHD u dorosłych oraz terapia mogą pomóc poprawić funkcjonowanie zawodowe."
      }
    },
    {
      "@type": "Question",
      "name": "Czy ADHD można leczyć terapią bez leków?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Tak, skuteczne metody leczenia ADHD u dorosłych obejmują: Terapię poznawczo-behawioralną (CBT), która pomaga w zarządzaniu objawami. Coaching ADHD – techniki organizacyjne i strategie radzenia sobie. Regularną aktywność fizyczną i zdrową dietę. Techniki relaksacyjne i medytację. Terapia może być skuteczną alternatywą lub uzupełnieniem farmakoterapii."
      }
    }
  ]
}
</script>';
} );
