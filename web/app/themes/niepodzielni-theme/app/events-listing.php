<?php

/**
 * Events Listing — PHP data functions for all 4 listing pages.
 * Returns structured arrays for window.npListingConfig injection.
 * Analogous to psy-listing.php for psychologist listing pages.
 *
 * @package Niepodzielni
 */

if (! defined('ABSPATH')) {
    exit;
}

// ============================================================
// 1. WARSZTATY + GRUPY WSPARCIA
// ============================================================

/** Zwraca imię i nazwisko prowadzącego dla danego posta warsztatu/grupy. */
function np_get_event_leader_name(int $pid): string
{
    $fac_id = (int) get_post_meta($pid, 'prowadzacy_id', true);
    if ($fac_id) {
        return get_post_meta($fac_id, 'imie_i_nazwisko', true) ?: get_the_title($fac_id);
    }
    return get_post_meta($pid, 'imie_i_nazwisko', true);
}

function get_workshops_listing_data(): array
{
    $transient_key = 'np_workshops_listing';
    $cached        = get_transient($transient_key);
    if ($cached !== false) {
        return $cached;
    }

    $today = current_time('Y-m-d');
    $query = new WP_Query([
        'post_type'      => [ 'warsztaty', 'grupy-wsparcia' ],
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'no_found_rows'  => true,
    ]);

    // Prefetch post meta — eliminuje N+1 przy get_post_meta() w pętli
    update_postmeta_cache(wp_list_pluck($query->posts, 'ID'));

    $data = [];
    foreach ($query->posts as $post) {
        $pid  = $post->ID;
        $date = get_post_meta($pid, 'data', true);

        $zdj_id  = np_get_post_image_url($pid, [ 'zdjecie_glowne', 'zdjecie' ]);
        $zdj_url = $zdj_id ? wp_get_attachment_image_url($zdj_id, 'medium_large') : '';

        $data[] = [
            'id'          => $pid,
            'post_type'   => $post->post_type,
            'title'       => get_post_meta($pid, 'temat', true) ?: $post->post_title,
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

// ============================================================
// 2. WYDARZENIA
// ============================================================

function get_wydarzenia_listing_data(): array
{
    $transient_key = 'np_wydarzenia_listing';
    $cached        = get_transient($transient_key);
    if ($cached !== false) {
        return $cached;
    }

    $today = current_time('Y-m-d');
    $query = new WP_Query([
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

        $zdj_id  = np_get_post_image_url($pid, [ 'zdjecie', 'zdjecie_tla' ]);
        $zdj_url = $zdj_id ? wp_get_attachment_image_url($zdj_id, 'medium_large') : get_the_post_thumbnail_url($pid, 'medium_large');

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
            'link'        => get_the_permalink($pid),
            'is_upcoming' => $date && $date >= $today,
            'sort_date'   => $date ?: '9999-12-31',
        ];
    }

    // Nadchodzące rosnąco, archiwalne malejąco — JS dostosuje per tab
    usort($data, fn($a, $b) => strcmp($a['sort_date'], $b['sort_date']));

    set_transient($transient_key, $data, HOUR_IN_SECONDS);
    return $data;
}

// ============================================================
// 3. AKTUALNOŚCI
// ============================================================

function get_aktualnosci_listing_data(): array
{
    $transient_key = 'np_aktualnosci_listing';
    $cached        = get_transient($transient_key);
    if ($cached !== false) {
        return $cached;
    }

    $query = new WP_Query([
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
        $pid     = $post->ID;
        $zdj_id  = np_get_post_image_url($pid, [ 'zdjecie_glowne' ]);
        $zdj_url = $zdj_id
            ? wp_get_attachment_image_url($zdj_id, 'medium_large')
            : get_the_post_thumbnail_url($pid, 'medium_large');

        $data[] = [
            'id'      => $pid,
            'title'   => $post->post_title,
            'date'    => get_post_meta($pid, 'data_wydarzenia', true) ?: get_the_date('Y-m-d', $post),
            'miejsce' => get_post_meta($pid, 'miejsce', true),
            'excerpt' => $post->post_excerpt ?: wp_trim_words($post->post_content, 20),
            'thumb'   => $zdj_url,
            'link'    => get_the_permalink($pid),
        ];
    }

    set_transient($transient_key, $data, HOUR_IN_SECONDS);
    return $data;
}

// ============================================================
// 4. PSYCHOEDUKACJA (natywny WP post)
// ============================================================

function get_psychoedukacja_listing_data(): array
{
    $transient_key = 'np_psychoedukacja_listing';
    $cached        = get_transient($transient_key);
    if ($cached !== false) {
        return $cached;
    }

    $query = new WP_Query([
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
            'id'      => $pid,
            'title'   => $post->post_title,
            'date'    => get_the_date('Y-m-d', $post),
            'excerpt' => $post->post_excerpt ?: wp_trim_words($post->post_content, 20),
            'thumb'   => get_the_post_thumbnail_url($pid, 'medium_large'),
            'link'    => get_the_permalink($pid),
            'tags'    => array_map(fn($t) => $t->slug, $tags),
        ];
    }

    set_transient($transient_key, $data, HOUR_IN_SECONDS);
    return $data;
}

/**
 * Returns tag tabs for the Psychoedukacja page (only tags with posts).
 *
 * @return array[] [['value' => slug, 'label' => name], ...]
 */
function get_psychoedukacja_tags(): array
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

// ============================================================
// 5. CZYSZCZENIE CACHE
// ============================================================

add_action('save_post', 'np_clear_events_listing_cache');
function np_clear_events_listing_cache(int $post_id): void
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
