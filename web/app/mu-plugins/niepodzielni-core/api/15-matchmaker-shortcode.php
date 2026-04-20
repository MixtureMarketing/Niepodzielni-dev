<?php
/**
 * Matchmaker Shortcode — dopasowanie psychologa do potrzeb użytkownika
 * Shortcode: [np_matchmaker] | [matchmaker]
 *
 * Renderuje <div id="np-matchmaker"> jako mount point dla matchmaker.js (v4).
 * Wstrzykuje window.NP_MATCHMAKER z danymi psychologów, taksonomii i konfiguracji.
 *
 * window.NP_MATCHMAKER:
 *   psychologists[]  — pełna lista z danymi scoringowymi
 *   obszary[]        — {slug, name}
 *   nurty[]          — {slug, name}
 *   jezyki_list[]    — {slug, name}
 *   curated[]        — slugi obszarów do głównej siatki (krok 2)
 *   areaClusters     — {slug → cluster_id} (fuzzy matching obszarów)
 *   nurtFamilies     — {slug → family_id} (fuzzy matching nurtów)
 *   specBonuses      — {} (zarezerwowane, na razie puste)
 *   pelnoPluginId    — Bookero hash konta pełnopłatnego
 *   niskoPluginId    — Bookero hash konta niskopłatnego
 *   telefon          — numer telefonu do kontaktu
 */

if (! defined('ABSPATH')) {
    exit;
}

add_shortcode('np_matchmaker', 'np_shortcode_matchmaker');
add_shortcode('matchmaker', 'np_shortcode_matchmaker');

function np_shortcode_matchmaker(array $atts): string
{
    static $rendered = false;
    if ($rendered) {
        return '<!-- matchmaker: drugi shortcode na stronie zignorowany -->';
    }
    $rendered = true;

    $data = np_matchmaker_build_data();

    ob_start();
    echo '<div id="np-matchmaker" aria-live="polite"></div>';
    echo '<script>window.NP_MATCHMAKER = ' . wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';

    return ob_get_clean();
}

/**
 * Buduje tablicę danych window.NP_MATCHMAKER.
 *
 * @return array<string, mixed>
 */
function np_matchmaker_build_data(): array
{
    // ── Psycholodzy ────────────────────────────────────────────────────────────
    // Jeden WP_Query dla wszystkich + bulk meta cache → zero N+1.
    $query = new WP_Query([
        'post_type'              => 'psycholog',
        'posts_per_page'         => 500,
        'post_status'            => 'publish',
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => true,
        // Brak meta_query: CF Meta_Query_Service zamieniałoby bookero_id_* na _bookero_id_*
    ]);

    $defaultStawkaNisko = get_option('np_domyslna_stawka_nisko', '55 zł');
    $defaultStawkaPelno = get_option('np_domyslna_stawka_pelno', '145 zł');
    $today              = current_time('Ymd');

    $psychologists = [];

    foreach ($query->posts as $post) {
        $pid = (int) $post->ID;

        $bkNisko = (string) get_post_meta($pid, 'bookero_id_niski', true);
        $bkPelno = (string) get_post_meta($pid, 'bookero_id_pelny', true);

        // Pomijamy psychologów bez żadnego konta Bookero
        if ($bkNisko === '' && $bkPelno === '') {
            continue;
        }

        // Termin — format Ymd potrzebny do scoringu dostępności w JS
        $terminNisko = (string) get_post_meta($pid, 'najblizszy_termin_niskoplatny', true);
        $terminPelno = (string) get_post_meta($pid, 'najblizszy_termin_pelnoplatny', true);
        $sortDateNisko = np_matchmaker_to_ymd($terminNisko);
        $sortDatePelno = np_matchmaker_to_ymd($terminPelno);

        // sort_date: wybieramy najbliższy termin, ale tylko przyszły
        $sortDate = '';
        foreach ([$sortDateNisko, $sortDatePelno] as $sd) {
            if ($sd && $sd >= $today) {
                if ($sortDate === '' || $sd < $sortDate) {
                    $sortDate = $sd;
                }
            }
        }

        // Taksonomie (bulk-loaded przez update_post_term_cache)
        $obszaryTerms    = get_the_terms($pid, 'obszar-pomocy') ?: [];
        $nurtyTerms      = get_the_terms($pid, 'nurt') ?: [];
        $jezykiTerms     = get_the_terms($pid, 'jezyk') ?: [];
        $specTerms       = get_the_terms($pid, 'specjalizacja') ?: [];

        $psychologists[] = [
            'id'          => $pid,
            'title'       => $post->post_title,
            'link'        => (string) get_the_permalink($pid),
            'thumb'       => (string) get_the_post_thumbnail_url($pid, 'medium'),
            'rola'        => $specTerms ? $specTerms[0]->name : 'Psycholog',
            'wizyta'      => (string) get_post_meta($pid, 'rodzaj_wizyty', true),
            'has_pelno'   => $bkPelno !== '',
            'has_nisko'   => $bkNisko !== '',
            'bk_id_pelno' => (int) $bkPelno,
            'bk_id_nisko' => (int) $bkNisko,
            'stawka_pelno' => (string) (get_post_meta($pid, 'stawka_wysokoplatna', true) ?: $defaultStawkaPelno),
            'stawka_nisko' => (string) (get_post_meta($pid, 'stawka_niskoplatna', true) ?: $defaultStawkaNisko),
            'sort_date'   => $sortDate,
            'obszary'     => wp_list_pluck($obszaryTerms, 'slug'),
            'nurty'       => wp_list_pluck($nurtyTerms, 'slug'),
            'jezyki'      => wp_list_pluck($jezykiTerms, 'slug'),
            'spec'        => wp_list_pluck($specTerms, 'slug'),
        ];
    }

    // ── Taksonomie ─────────────────────────────────────────────────────────────

    $obszaryAll = get_terms(['taxonomy' => 'obszar-pomocy', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC']);
    $nurtyAll   = get_terms(['taxonomy' => 'nurt',          'hide_empty' => true, 'orderby' => 'name',  'order' => 'ASC']);
    $jezykiAll  = get_terms(['taxonomy' => 'jezyk',         'hide_empty' => true, 'orderby' => 'name',  'order' => 'ASC']);

    $obszaryList = is_wp_error($obszaryAll) ? [] : array_map(
        fn($t) => ['slug' => $t->slug, 'name' => $t->name],
        (array) $obszaryAll
    );

    $nurtyList = is_wp_error($nurtyAll) ? [] : array_map(
        fn($t) => ['slug' => $t->slug, 'name' => $t->name],
        (array) $nurtyAll
    );

    $jezykiList = is_wp_error($jezykiAll) ? [] : array_map(
        fn($t) => ['slug' => $t->slug, 'name' => $t->name],
        (array) $jezykiAll
    );

    // Curated: top 12 obszarów wg liczby psychologów → główna siatka w kroku 2
    $curated = array_slice(array_column($obszaryList, 'slug'), 0, 12);

    // ── Konfiguracja Bookero ────────────────────────────────────────────────────

    return [
        'psychologists' => $psychologists,
        'obszary'       => $obszaryList,
        'nurty'         => $nurtyList,
        'jezyki_list'   => $jezykiList,
        'curated'       => $curated,
        'areaClusters'  => (object) [],
        'nurtFamilies'  => (object) [],
        'specBonuses'   => (object) [],
        'pelnoPluginId' => np_bookero_cal_id_for('pelnoplatny'),
        'niskoPluginId' => np_bookero_cal_id_for('niskoplatny'),
        'telefon'       => (string) get_option('np_telefon_kontakt', ''),
    ];
}

/**
 * Konwertuje sformatowaną datę Bookero do formatu Ymd (np. "20260420").
 * Zwraca pusty string gdy data jest nieprawidłowa lub przeterminowana.
 *
 * @param string $date  Dowolny format daty (np. "15 maja 2025", "2025-05-15")
 * @return string  Format Ymd lub '' gdy nieprawidłowa
 */
function np_matchmaker_to_ymd(string $date): string
{
    if (empty($date)) {
        return '';
    }

    // Jeśli już Ymd (8 cyfr) — zwróć wprost
    if (preg_match('/^\d{8}$/', $date)) {
        return $date;
    }

    // Format ISO
    $ts = strtotime($date);
    if ($ts && $ts > 0) {
        return date('Ymd', $ts);
    }

    // Polski format — deleguj do bookero_sanitize_date + strtotime
    $sanitized = bookero_sanitize_date($date);
    if ($sanitized) {
        $ts = strtotime($sanitized);
        if ($ts && $ts > 0) {
            return date('Ymd', $ts);
        }
    }

    return '';
}
