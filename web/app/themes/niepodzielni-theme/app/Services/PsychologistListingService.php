<?php

namespace App\Services;

/**
 * Serwis danych listingu psychologów.
 *
 * Enkapsuluje zapytanie WP_Query + prefetch taksonomii + logikę cache transientów.
 * Poprzednio proceduralna funkcja get_psy_listing_json_data() w app/psy-listing.php.
 *
 * Wspólne mechanizmy (cache L0/L1, WP_Query pipeline) w {@see AbstractListingService}.
 *
 * Rejestruje własne hooki czyszczenia cache — nie wymaga osobnego pliku.
 */
class PsychologistListingService extends AbstractListingService
{
    private const CACHE_GROUP = 'np_psy_listing';
    private const TTL         = 15 * MINUTE_IN_SECONDS;

    /**
     * Zwraca listę psychologów dla danego typu konta (nisko|pelno).
     *
     * Cache wersjonowany przez NP_PSY_LISTING_VERSION (invalidation hooks z PR #17).
     *
     * @param  string $rodzaj  'nisko' | 'pelno'
     * @return array<int, array<string, mixed>>
     */
    public function getData(string $rodzaj = 'nisko'): array
    {
        $version      = self::version();
        $cacheKey     = 'psy_listing_' . $rodzaj . '_' . $version;
        $transientKey = 'psy_listing_data_' . $rodzaj . '_' . $version;

        return $this->withCache(
            $cacheKey,
            $transientKey,
            self::CACHE_GROUP,
            self::TTL,
            fn(): array => $this->fetchAll($rodzaj),
        );
    }

    /**
     * Buduje surową listę psychologów (bez cache) dla danego typu konta.
     *
     * Brak meta_query celowy: Carbon_Fields\Service\Meta_Query_Service przechwytuje WP_Query
     * i zamienia klucze CF (bookero_id_niski / bookero_id_pelny) na format _klucz,
     * co powoduje 0 wyników. Filtrowanie po pustym workerId wykonywane jest w pętli PHP
     * (update_post_meta_cache ładuje ALL meta w jednym IN() — zero dodatkowych SQL).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAll(string $rodzaj): array
    {
        $metaIdKey  = np_bk_id_meta_key($rodzaj);
        $metaTermin = np_bk_meta_key($rodzaj);
        $metaStawka = ($rodzaj === 'nisko') ? 'stawka_niskoplatna' : 'stawka_wysokoplatna';
        $flagMap    = np_get_flag_map();

        $today = date('Ymd');

        return array_values(array_filter($this->buildList(
            [
                'post_type'              => 'psycholog',
                'update_post_term_cache' => true,
            ],
            function (\WP_Post $post) use ($rodzaj, $metaIdKey, $metaTermin, $metaStawka, $flagMap, $today): ?array {
                $pid = $post->ID;

                if (empty(get_post_meta($pid, $metaIdKey, true))) {
                    return null;
                }

                $termin     = get_post_meta($pid, $metaTermin, true);
                $cleanDate  = bookero_sanitize_date($termin);
                $hasTermin  = $cleanDate && np_get_sortable_date($cleanDate) >= $today;
                $sortDate   = np_get_sortable_date($hasTermin ? $cleanDate : '');

                $obszarTerms = get_the_terms($pid, 'obszar-pomocy') ?: [];
                $specTerms   = get_the_terms($pid, 'specjalizacja') ?: [];
                $jezykTerms  = get_the_terms($pid, 'jezyk') ?: [];
                $nurtTerms   = get_the_terms($pid, 'nurt') ?: [];

                $jezykiData = [];
                foreach ($jezykTerms as $jt) {
                    $jezykiData[] = [
                        'slug' => $jt->slug,
                        'name' => $jt->name,
                        'flag' => $flagMap[$jt->slug] ?? '',
                    ];
                }

                return [
                    'id'         => $pid,
                    'title'      => $post->post_title,
                    'link'       => get_the_permalink($pid) . '?konsultacje=' . $rodzaj,
                    'thumb'      => get_the_post_thumbnail_url($pid, 'medium_large'),
                    'termin'     => $termin ?: 'Brak wolnych terminów',
                    'sort_date'  => $sortDate,
                    'has_termin' => $hasTermin,
                    'stawka'     => get_post_meta($pid, $metaStawka, true)
                        ?: get_option(
                            ($rodzaj === 'nisko') ? 'np_domyslna_stawka_nisko' : 'np_domyslna_stawka_pelno',
                            ($rodzaj === 'nisko') ? '55 zł' : '145 zł',
                        ),
                    'wizyta'     => get_post_meta($pid, 'rodzaj_wizyty', true),
                    'has_pelno'  => ! empty(get_post_meta($pid, 'bookero_id_pelny', true)),
                    'has_nisko'  => ! empty(get_post_meta($pid, 'bookero_id_niski', true)),
                    'bio'        => get_post_meta($pid, 'biogram', true),
                    'obszary'    => wp_list_pluck($obszarTerms, 'slug'),
                    'obszary_n'  => wp_list_pluck($obszarTerms, 'name'),
                    'spec'       => wp_list_pluck($specTerms, 'slug'),
                    'nurty'      => wp_list_pluck($nurtTerms, 'slug'),
                    'jezyki'     => $jezykiData,
                    'rola'       => $specTerms ? $specTerms[0]->name : 'Psycholog',
                ];
            },
        )));
    }

    /**
     * Czyści oba transienty i Object Cache listingu (nisko + pelno).
     * Wywoływana przez hooki WP zarejestrowane poniżej.
     */
    public static function clearCache(): void
    {
        $version = self::version();

        foreach (['nisko', 'pelno'] as $rodzaj) {
            delete_transient('psy_listing_data_' . $rodzaj . '_' . $version);
            wp_cache_delete('psy_listing_' . $rodzaj . '_' . $version, self::CACHE_GROUP);
        }
    }

    private static function version(): string
    {
        return defined('NP_PSY_LISTING_VERSION') ? NP_PSY_LISTING_VERSION : '1.0.2';
    }
}

// ─── Hooki czyszczenia cache ─────────────────────────────────────────────────

add_action('save_post_psycholog', [PsychologistListingService::class, 'clearCache']);
add_action('niepodzielni_bookero_batch_synced', [PsychologistListingService::class, 'clearCache']);
