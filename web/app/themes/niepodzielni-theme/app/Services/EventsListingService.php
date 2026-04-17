<?php

namespace App\Services;

/**
 * Serwis danych listingów wydarzeń.
 *
 * Enkapsuluje logikę czterech typów listingów:
 *   - Warsztaty + Grupy wsparcia
 *   - Wydarzenia
 *   - Aktualności
 *   - Psychoedukacja (WP Posts + tagi)
 *
 * Poprzednio proceduralne funkcje w app/events-listing.php.
 *
 * Cache:
 *   L0: WP Object Cache (Redis) — wp_cache_get/set, zero SQL przy trafieniu
 *   L1: Transient (DB fallback) — 1h TTL
 *
 * N+1 elimination:
 *   update_post_meta_cache => true  (batch postmeta w WP_Query)
 *   update_post_term_cache => true  (batch term_relationships w WP_Query)
 *
 * Poprawka błędu: np_get_post_image_url() zwraca URL, a nie ID attachmentu —
 * usunięto pośrednie wywołanie wp_get_attachment_image_url($zdj_id, ...).
 */
class EventsListingService
{
    private const CACHE_GROUP = 'np_events_listing';

    // ─── 1. Warsztaty + Grupy wsparcia ──────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWorkshopsData(): array
    {
        $cache_key     = 'workshops';
        $transient_key = 'np_workshops_listing';

        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        $cached = get_transient($transient_key);
        if (is_array($cached)) {
            wp_cache_set($cache_key, $cached, self::CACHE_GROUP, HOUR_IN_SECONDS);
            return $cached;
        }

        $today = current_time('Y-m-d');
        $query = new \WP_Query([
            'post_type'              => [ 'warsztaty', 'grupy-wsparcia' ],
            'posts_per_page'         => -1,
            'post_status'            => 'publish',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        $data = [];
        foreach ($query->posts as $post) {
            $pid   = $post->ID;
            $date  = get_post_meta($pid, 'data', true);
            $title = get_post_meta($pid, 'temat', true) ?: $post->post_title;

            $data[] = [
                'id'          => $pid,
                'post_type'   => $post->post_type,
                'title'       => $title,
                'date'        => $date,
                'time'        => get_post_meta($pid, 'godzina', true),
                'time_end'    => get_post_meta($pid, 'godzina_zakonczenia', true),
                'lokalizacja' => get_post_meta($pid, 'lokalizacja', true),
                'status'      => get_post_meta($pid, 'status', true),
                'cena'        => get_post_meta($pid, 'cena', true),
                'cena_rodzaj' => get_post_meta($pid, 'cena_rodzaj', true),
                'prowadzacy'  => np_get_event_leader_name($pid),
                'stanowisko'  => get_post_meta($pid, 'stanowisko', true),
                'thumb'       => np_get_post_image_url($pid, [ 'zdjecie_glowne', 'zdjecie' ], 'medium_large'),
                'thumb_tag'   => np_get_post_image_tag($pid, [ 'zdjecie_glowne', 'zdjecie' ], 'medium_large', [ 'alt' => $title ]),
                'link'        => get_the_permalink($pid),
                'excerpt'     => $post->post_excerpt ?: wp_trim_words($post->post_content, 20),
                'is_active'   => $date && $date >= $today,
                'sort_date'   => $date ?: '9999-12-31',
            ];
        }

        usort($data, fn($a, $b) => strcmp($a['sort_date'], $b['sort_date']));

        set_transient($transient_key, $data, HOUR_IN_SECONDS);
        wp_cache_set($cache_key, $data, self::CACHE_GROUP, HOUR_IN_SECONDS);

        return $data;
    }

    // ─── 2. Wydarzenia ──────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWydarzeniaData(): array
    {
        $cache_key     = 'wydarzenia';
        $transient_key = 'np_wydarzenia_listing';

        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        $cached = get_transient($transient_key);
        if (is_array($cached)) {
            wp_cache_set($cache_key, $cached, self::CACHE_GROUP, HOUR_IN_SECONDS);
            return $cached;
        }

        $today = current_time('Y-m-d');
        $query = new \WP_Query([
            'post_type'              => 'wydarzenia',
            'posts_per_page'         => -1,
            'post_status'            => 'publish',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        $data = [];
        foreach ($query->posts as $post) {
            $pid  = $post->ID;
            $date = get_post_meta($pid, 'data', true);

            $data[] = [
                'id'          => $pid,
                'title'       => $post->post_title,
                'date'        => $date,
                'time_start'  => get_post_meta($pid, 'godzina_rozpoczecia', true),
                'time_end'    => get_post_meta($pid, 'godzina_zakonczenia', true),
                'miasto'      => get_post_meta($pid, 'miasto', true),
                'lokalizacja' => get_post_meta($pid, 'lokalizacja', true),
                'koszt'       => get_post_meta($pid, 'koszt', true),
                'opis'        => wp_trim_words(get_post_meta($pid, 'opis', true) ?: $post->post_excerpt, 20),
                'thumb'       => np_get_post_image_url($pid, [ 'zdjecie', 'zdjecie_tla' ], 'medium_large')
                    ?: (string) get_the_post_thumbnail_url($pid, 'medium_large'),
                'thumb_tag'   => np_get_post_image_tag($pid, [ 'zdjecie', 'zdjecie_tla' ], 'medium_large', [ 'alt' => $post->post_title ]),
                'link'        => get_the_permalink($pid),
                'is_upcoming' => $date && $date >= $today,
                'sort_date'   => $date ?: '9999-12-31',
            ];
        }

        usort($data, fn($a, $b) => strcmp($a['sort_date'], $b['sort_date']));

        set_transient($transient_key, $data, HOUR_IN_SECONDS);
        wp_cache_set($cache_key, $data, self::CACHE_GROUP, HOUR_IN_SECONDS);

        return $data;
    }

    // ─── 3. Aktualności ─────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAktualnosciData(): array
    {
        $cache_key     = 'aktualnosci';
        $transient_key = 'np_aktualnosci_listing';

        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        $cached = get_transient($transient_key);
        if (is_array($cached)) {
            wp_cache_set($cache_key, $cached, self::CACHE_GROUP, HOUR_IN_SECONDS);
            return $cached;
        }

        $query = new \WP_Query([
            'post_type'              => 'aktualnosci',
            'posts_per_page'         => -1,
            'post_status'            => 'publish',
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        $data = [];
        foreach ($query->posts as $post) {
            $pid = $post->ID;

            $data[] = [
                'id'        => $pid,
                'title'     => $post->post_title,
                'date'      => get_post_meta($pid, 'data_wydarzenia', true) ?: get_the_date('Y-m-d', $post),
                'miejsce'   => get_post_meta($pid, 'miejsce', true),
                'excerpt'   => $post->post_excerpt ?: wp_trim_words($post->post_content, 20),
                'thumb'     => np_get_post_image_url($pid, [ 'zdjecie_glowne' ], 'medium_large')
                    ?: (string) get_the_post_thumbnail_url($pid, 'medium_large'),
                'thumb_tag' => np_get_post_image_tag($pid, [ 'zdjecie_glowne' ], 'medium_large', [ 'alt' => $post->post_title ]),
                'link'      => get_the_permalink($pid),
            ];
        }

        set_transient($transient_key, $data, HOUR_IN_SECONDS);
        wp_cache_set($cache_key, $data, self::CACHE_GROUP, HOUR_IN_SECONDS);

        return $data;
    }

    // ─── 4. Psychoedukacja ──────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPsychoedukacjaData(): array
    {
        $cache_key     = 'psychoedukacja';
        $transient_key = 'np_psychoedukacja_listing';

        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        $cached = get_transient($transient_key);
        if (is_array($cached)) {
            wp_cache_set($cache_key, $cached, self::CACHE_GROUP, HOUR_IN_SECONDS);
            return $cached;
        }

        // update_post_term_cache: batch fetch WP tagów (get_the_tags eliminuje N+1)
        $query = new \WP_Query([
            'post_type'              => 'post',
            'posts_per_page'         => -1,
            'post_status'            => 'publish',
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => true,
        ]);

        $data = [];
        foreach ($query->posts as $post) {
            $pid  = $post->ID;
            $tags = get_the_tags($pid) ?: [];

            $data[] = [
                'id'        => $pid,
                'title'     => $post->post_title,
                'date'      => get_the_date('Y-m-d', $post),
                'excerpt'   => $post->post_excerpt ?: wp_trim_words($post->post_content, 20),
                'thumb'     => get_the_post_thumbnail_url($pid, 'medium_large'),
                'thumb_tag' => np_get_post_image_tag($pid, [], 'medium_large', [ 'alt' => $post->post_title ]),
                'link'      => get_the_permalink($pid),
                'tags'      => array_map(fn($t) => $t->slug, $tags),
            ];
        }

        set_transient($transient_key, $data, HOUR_IN_SECONDS);
        wp_cache_set($cache_key, $data, self::CACHE_GROUP, HOUR_IN_SECONDS);

        return $data;
    }

    /**
     * Zwraca tagi do zakładek na stronie Psychoedukacja (tylko niepuste).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getPsychoedukacjaTags(): array
    {
        $cache_key     = 'psychoedukacja_tags';
        $transient_key = 'np_psychoedukacja_tags';

        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        $cached = get_transient($transient_key);
        if (is_array($cached)) {
            wp_cache_set($cache_key, $cached, self::CACHE_GROUP, HOUR_IN_SECONDS);
            return $cached;
        }

        $tags   = get_tags([ 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC' ]);
        $result = array_map(fn($t) => [ 'value' => $t->slug, 'label' => $t->name ], $tags);

        set_transient($transient_key, $result, HOUR_IN_SECONDS);
        wp_cache_set($cache_key, $result, self::CACHE_GROUP, HOUR_IN_SECONDS);

        return $result;
    }

    // ─── Cache invalidation ─────────────────────────────────────────────────────

    /**
     * Czyści transient i Object Cache dla typu posta który został zapisany.
     * Wywoływana przez hook save_post zarejestrowany poniżej.
     */
    public static function clearCache(int $post_id): void
    {
        if (wp_is_post_revision($post_id)) {
            return;
        }

        $group = self::CACHE_GROUP;

        switch (get_post_type($post_id)) {
            case 'warsztaty':
            case 'grupy-wsparcia':
                delete_transient('np_workshops_listing');
                wp_cache_delete('workshops', $group);
                break;
            case 'wydarzenia':
                delete_transient('np_wydarzenia_listing');
                wp_cache_delete('wydarzenia', $group);
                break;
            case 'aktualnosci':
                delete_transient('np_aktualnosci_listing');
                wp_cache_delete('aktualnosci', $group);
                break;
            case 'post':
                delete_transient('np_psychoedukacja_listing');
                delete_transient('np_psychoedukacja_tags');
                wp_cache_delete('psychoedukacja', $group);
                wp_cache_delete('psychoedukacja_tags', $group);
                break;
        }
    }
}

// ─── Hooki czyszczenia cache ─────────────────────────────────────────────────

add_action('save_post', [EventsListingService::class, 'clearCache']);
