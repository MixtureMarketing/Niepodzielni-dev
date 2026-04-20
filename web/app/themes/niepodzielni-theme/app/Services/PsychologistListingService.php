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
        $version     = defined('NP_PSY_LISTING_VERSION') ? NP_PSY_LISTING_VERSION : '1.0.2';
        $cache_key   = 'psy_listing_' . $rodzaj . '_' . $version;
        $cache_group = 'np_psy_listing';

        // L0: WP Object Cache (Redis) — zero SQL przy trafieniu
        $cached = wp_cache_get($cache_key, $cache_group);
        if (is_array($cached)) {
            return $cached;
        }

        // L1: transient (fallback dla środowisk bez Redis — cache w DB)
        $transient_key = 'psy_listing_data_' . $rodzaj . '_' . $version;
        $cached        = get_transient($transient_key);
        if (is_array($cached)) {
            // Przepisz do Object Cache żeby kolejne requesty nie trafiały do DB
            wp_cache_set($cache_key, $cached, $cache_group, 15 * MINUTE_IN_SECONDS);
            return $cached;
        }

        $all_psy_data = [];
        $meta_id_key  = ($rodzaj === 'nisko') ? 'bookero_id_niski' : 'bookero_id_pelny';
        $db_type      = ($rodzaj === 'nisko') ? 'niskoplatny' : 'pelnoplatny';
        $meta_termin  = np_bk_meta_key($db_type);
        $meta_stawka  = ($rodzaj === 'nisko') ? 'stawka_niskoplatna' : 'stawka_wysokoplatna';

        $flagMap = np_get_flag_map();

        // update_post_meta_cache + update_post_term_cache eliminują N+1:
        //   SQL 1: SELECT ID FROM posts WHERE ... (WP_Query)
        //   SQL 2: SELECT meta_key,meta_value FROM postmeta WHERE post_id IN (...)
        //   SQL 3: SELECT t.*,tt.* FROM terms JOIN term_taxonomy JOIN term_relationships WHERE object_id IN (...)
        // Bez tych flag każde get_post_meta() i get_the_terms() generowałoby osobne zapytanie SQL.
        //
        // Brak meta_query celowy: Carbon_Fields\Service\Meta_Query_Service przechwytuje WP_Query
        // i zamienia klucze CF (bookero_id_niski / bookero_id_pelny) na format _klucz,
        // co powoduje 0 wyników. Filtrowanie po pustym workerId wykonywane jest w pętli PHP
        // (update_post_meta_cache ładuje ALL meta w jednym IN() — zero dodatkowych SQL).
        $query = new \WP_Query([
            'post_type'              => 'psycholog',
            'posts_per_page'         => -1,
            'post_status'            => 'publish',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        ]);

        foreach ($query->posts as $post) {
            $pid = $post->ID;

            if (empty(get_post_meta($pid, $meta_id_key, true))) {
                continue;
            }

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
        wp_cache_set($cache_key, $all_psy_data, $cache_group, 15 * MINUTE_IN_SECONDS);

        return $all_psy_data;
    }

    /**
     * Czyści oba transienty i Object Cache listingu (nisko + pelno).
     * Wywoływana przez hooki WP zarejestrowane poniżej.
     */
    public static function clearCache(): void
    {
        $v     = defined('NP_PSY_LISTING_VERSION') ? NP_PSY_LISTING_VERSION : '1.0.2';
        $group = 'np_psy_listing';

        foreach (['nisko', 'pelno'] as $rodzaj) {
            delete_transient('psy_listing_data_' . $rodzaj . '_' . $v);
            wp_cache_delete('psy_listing_' . $rodzaj . '_' . $v, $group);
        }
    }
}

// ─── Hooki czyszczenia cache ─────────────────────────────────────────────────

add_action('save_post_psycholog', [PsychologistListingService::class, 'clearCache']);
add_action('niepodzielni_bookero_batch_synced', [PsychologistListingService::class, 'clearCache']);
