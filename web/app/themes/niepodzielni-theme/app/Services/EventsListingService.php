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
 * Każda metoda zachowuje oryginalny TTL transientu i logikę prefetch N+1.
 *
 * Poprawka błędu: np_get_post_image_url() zwraca URL, a nie ID attachmentu —
 * usunięto pośrednie wywołanie wp_get_attachment_image_url($zdj_id, ...).
 */
class EventsListingService
{
    // ─── 1. Warsztaty + Grupy wsparcia ──────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWorkshopsData(): array
    {
        $transient_key = 'np_workshops_listing';
        $cached        = get_transient($transient_key);
        if ($cached !== false) {
            return $cached;
        }

        $today = current_time('Y-m-d');
        $query = new \WP_Query([
            'post_type'      => [ 'warsztaty', 'grupy-wsparcia' ],
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
        ]);

        update_postmeta_cache(wp_list_pluck($query->posts, 'ID'));

        $data = [];
        foreach ($query->posts as $post) {
            $pid  = $post->ID;
            $date = get_post_meta($pid, 'data', true);

            // Poprawka: np_get_post_image_url() zwraca URL (nie ID) — używamy bezpośrednio
            $title   = get_post_meta($pid, 'temat', true) ?: $post->post_title;
            $zdj_url = np_get_post_image_url($pid, [ 'zdjecie_glowne', 'zdjecie' ], 'medium_large');

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
                'cena_rodzaj' => get_post_meta($pid, 'cena_-_rodzaj', true),
                'prowadzacy'  => np_get_event_leader_name($pid),
                'stanowisko'  => get_post_meta($pid, 'stanowisko', true),
                'thumb'       => $zdj_url,
                'thumb_tag'   => np_get_post_image_tag($pid, [ 'zdjecie_glowne', 'zdjecie' ], 'medium_large', [ 'alt' => $title ]),
                'link'        => get_the_permalink($pid),
                'excerpt'     => $post->post_excerpt ?: wp_trim_words($post->post_content, 20),
                'is_active'   => $date && $date >= $today,
                'sort_date'   => $date ?: '9999-12-31',
            ];
        }

        usort($data, fn($a, $b) => strcmp($a['sort_date'], $b['sort_date']));

        set_transient($transient_key, $data, HOUR_IN_SECONDS);

        return $data;
    }

    // ─── 2. Wydarzenia ──────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWydarzeniaData(): array
    {
        $transient_key = 'np_wydarzenia_listing';
        $cached        = get_transient($transient_key);
        if ($cached !== false) {
            return $cached;
        }

        $today = current_time('Y-m-d');
        $query = new \WP_Query([
            'post_type'      => 'wydarzenia',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
        ]);

        update_postmeta_cache(wp_list_pluck($query->posts, 'ID'));

        $data = [];
        foreach ($query->posts as $post) {
            $pid  = $post->ID;
            $date = get_post_meta($pid, 'data', true);

            // Poprawka: np_get_post_image_url() zwraca URL — fallback do thumbnail gdy pusty
            $zdj_url = np_get_post_image_url($pid, [ 'zdjecie', 'zdjecie_tla' ], 'medium_large')
                ?: (string) get_the_post_thumbnail_url($pid, 'medium_large');

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
                'thumb'       => $zdj_url,
                'thumb_tag'   => np_get_post_image_tag($pid, [ 'zdjecie', 'zdjecie_tla' ], 'medium_large', [ 'alt' => $post->post_title ]),
                'link'        => get_the_permalink($pid),
                'is_upcoming' => $date && $date >= $today,
                'sort_date'   => $date ?: '9999-12-31',
            ];
        }

        usort($data, fn($a, $b) => strcmp($a['sort_date'], $b['sort_date']));

        set_transient($transient_key, $data, HOUR_IN_SECONDS);

        return $data;
    }

    // ─── 3. Aktualności ─────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAktualnosciData(): array
    {
        $transient_key = 'np_aktualnosci_listing';
        $cached        = get_transient($transient_key);
        if ($cached !== false) {
            return $cached;
        }

        $query = new \WP_Query([
            'post_type'      => 'aktualnosci',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);

        update_postmeta_cache(wp_list_pluck($query->posts, 'ID'));

        $data = [];
        foreach ($query->posts as $post) {
            $pid = $post->ID;

            // Poprawka: np_get_post_image_url() zwraca URL — fallback do thumbnail gdy pusty
            $zdj_url = np_get_post_image_url($pid, [ 'zdjecie_glowne' ], 'medium_large')
                ?: (string) get_the_post_thumbnail_url($pid, 'medium_large');

            $data[] = [
                'id'        => $pid,
                'title'     => $post->post_title,
                'date'      => get_post_meta($pid, 'data_wydarzenia', true) ?: get_the_date('Y-m-d', $post),
                'miejsce'   => get_post_meta($pid, 'miejsce', true),
                'excerpt'   => $post->post_excerpt ?: wp_trim_words($post->post_content, 20),
                'thumb'     => $zdj_url,
                'thumb_tag' => np_get_post_image_tag($pid, [ 'zdjecie_glowne' ], 'medium_large', [ 'alt' => $post->post_title ]),
                'link'    => get_the_permalink($pid),
            ];
        }

        set_transient($transient_key, $data, HOUR_IN_SECONDS);

        return $data;
    }

    // ─── 4. Psychoedukacja ──────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPsychoedukacjaData(): array
    {
        $transient_key = 'np_psychoedukacja_listing';
        $cached        = get_transient($transient_key);
        if ($cached !== false) {
            return $cached;
        }

        $query = new \WP_Query([
            'post_type'      => 'post',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
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
                'link'    => get_the_permalink($pid),
                'tags'    => array_map(fn($t) => $t->slug, $tags),
            ];
        }

        set_transient($transient_key, $data, HOUR_IN_SECONDS);

        return $data;
    }

    /**
     * Zwraca tagi do zakładek na stronie Psychoedukacja (tylko niepuste).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getPsychoedukacjaTags(): array
    {
        $transient_key = 'np_psychoedukacja_tags';
        $cached        = get_transient($transient_key);
        if ($cached !== false) {
            return $cached;
        }

        $tags   = get_tags([ 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC' ]);
        $result = array_map(fn($t) => [ 'value' => $t->slug, 'label' => $t->name ], $tags);

        set_transient($transient_key, $result, HOUR_IN_SECONDS);

        return $result;
    }

    // ─── Cache invalidation ─────────────────────────────────────────────────────

    /**
     * Czyści transient odpowiedni dla typu posta który został zapisany.
     * Wywoływana przez hook save_post zarejestrowany poniżej.
     */
    public static function clearCache(int $post_id): void
    {
        if (wp_is_post_revision($post_id)) {
            return;
        }

        switch (get_post_type($post_id)) {
            case 'warsztaty':
            case 'grupy-wsparcia':
                delete_transient('np_workshops_listing');
                break;
            case 'wydarzenia':
                delete_transient('np_wydarzenia_listing');
                break;
            case 'aktualnosci':
                delete_transient('np_aktualnosci_listing');
                break;
            case 'post':
                delete_transient('np_psychoedukacja_listing');
                delete_transient('np_psychoedukacja_tags');
                break;
        }
    }
}

// ─── Hooki czyszczenia cache ─────────────────────────────────────────────────

add_action('save_post', [EventsListingService::class, 'clearCache']);
