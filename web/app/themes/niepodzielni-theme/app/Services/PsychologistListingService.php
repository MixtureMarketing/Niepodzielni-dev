<?php

namespace App\Services;

/**
 * Serwis danych listingu psychologów.
 *
 * Enkapsuluje zapytanie WP_Query + prefetch taksonomii + logikę cache transientów.
 * Poprzednio proceduralna funkcja get_psy_listing_json_data() w app/psy-listing.php.
 *
 * Rejestruje własne hooki czyszczenia cache — nie wymaga osobnego pliku.
 */
class PsychologistListingService
{
    /**
     * Zwraca listę psychologów dla danego typu konta (nisko|pelno).
     *
     * L1: transient cache (15 min) — wersjonowany przez NP_PSY_LISTING_VERSION.
     *
     * @param  string $rodzaj  'nisko' | 'pelno'
     * @return array<int, array<string, mixed>>
     */
    public function getData(string $rodzaj = 'nisko'): array
    {
        $version       = defined('NP_PSY_LISTING_VERSION') ? NP_PSY_LISTING_VERSION : '1.0.2';
        $transient_key = 'psy_listing_data_' . $rodzaj . '_' . $version;

        $cached = get_transient($transient_key);
        if ($cached !== false) {
            return $cached;
        }

        $all_psy_data = [];
        $meta_id_key  = ($rodzaj === 'nisko') ? 'bookero_id_niski' : 'bookero_id_pelny';
        $db_type      = ($rodzaj === 'nisko') ? 'niskoplatny' : 'pelnoplatny';
        $meta_termin  = np_bk_meta_key($db_type);
        $meta_stawka  = ($rodzaj === 'nisko') ? 'stawka_niskoplatna' : 'stawka_wysokoplatna';

        $flagMap = np_get_flag_map();

        $query = new \WP_Query([
            'post_type'      => 'psycholog',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => $meta_id_key,
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => $meta_id_key,
                    'value'   => '',
                    'compare' => '!=',
                ],
            ],
        ]);

        foreach ($query->posts as $post) {
            $pid    = $post->ID;
            $termin = get_post_meta($pid, $meta_termin, true);

            $clean_date = bookero_sanitize_date($termin);
            $has_termin = $clean_date && np_get_sortable_date($clean_date) >= date('Ymd');
            $sort_date  = np_get_sortable_date($has_termin ? $clean_date : '');

            $obszar_terms = get_the_terms($pid, 'obszar-pomocy') ?: [];
            $spec_terms   = get_the_terms($pid, 'specjalizacja') ?: [];
            $jezyk_terms  = get_the_terms($pid, 'jezyk') ?: [];
            $nurt_terms   = get_the_terms($pid, 'nurt') ?: [];

            $jezyki_data = [];
            foreach ($jezyk_terms as $jt) {
                $jezyki_data[] = [
                    'slug' => $jt->slug,
                    'name' => $jt->name,
                    'flag' => $flagMap[$jt->slug] ?? '',
                ];
            }

            $all_psy_data[] = [
                'id'         => $pid,
                'title'      => $post->post_title,
                'link'       => get_the_permalink($pid) . '?konsultacje=' . $rodzaj,
                'thumb'      => get_the_post_thumbnail_url($pid, 'medium_large'),
                'termin'     => $termin ?: 'Brak wolnych terminów',
                'sort_date'  => $sort_date,
                'has_termin' => $has_termin,
                'stawka'     => get_post_meta($pid, $meta_stawka, true)
                    ?: get_option(
                        ($rodzaj === 'nisko') ? 'np_domyslna_stawka_nisko' : 'np_domyslna_stawka_pelno',
                        ($rodzaj === 'nisko') ? '55 zł' : '145 zł',
                    ),
                'wizyta'     => get_post_meta($pid, 'rodzaj_wizyty', true),
                'has_pelno'  => ! empty(get_post_meta($pid, 'bookero_id_pelny', true)),
                'has_nisko'  => ! empty(get_post_meta($pid, 'bookero_id_niski', true)),
                'bio'        => get_post_meta($pid, 'biogram', true),
                'obszary'    => wp_list_pluck($obszar_terms, 'slug'),
                'obszary_n'  => wp_list_pluck($obszar_terms, 'name'),
                'spec'       => wp_list_pluck($spec_terms, 'slug'),
                'nurty'      => wp_list_pluck($nurt_terms, 'slug'),
                'jezyki'     => $jezyki_data,
                'rola'       => $spec_terms ? $spec_terms[0]->name : 'Psycholog',
            ];
        }

        set_transient($transient_key, $all_psy_data, 15 * MINUTE_IN_SECONDS);

        return $all_psy_data;
    }

    /**
     * Czyści oba transienty listingu (nisko + pelno).
     * Wywoływana przez hooki WP zarejestrowane poniżej.
     */
    public static function clearCache(): void
    {
        $v = defined('NP_PSY_LISTING_VERSION') ? NP_PSY_LISTING_VERSION : '1.0.2';
        delete_transient('psy_listing_data_nisko_' . $v);
        delete_transient('psy_listing_data_pelno_' . $v);
    }
}

// ─── Hooki czyszczenia cache ─────────────────────────────────────────────────

add_action('save_post_psycholog', [PsychologistListingService::class, 'clearCache']);
add_action('niepodzielni_bookero_batch_synced', [PsychologistListingService::class, 'clearCache']);
