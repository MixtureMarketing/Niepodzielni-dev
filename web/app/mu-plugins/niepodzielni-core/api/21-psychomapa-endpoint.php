<?php

/**
 * REST API — Endpoint Psychomapa
 *
 * GET /wp-json/niepodzielni/v1/psychomapa
 *
 * Endpoint publiczny (brak autoryzacji) zoptymalizowany pod rendering mapy:
 *  - Dwa zapytania $wpdb zamiast N+1 pętli WP_Query
 *  - Object cache z TTL 6h, inwalidowany przy każdej zmianie osrodek_pomocy
 *  - wp_send_json() zamiast WP_REST_Response (pomija overhead serializacji)
 *
 * Payload (tablica obiektów):
 *   id, title, lat, lng, url, city, phone, logo_url,
 *   terms: { rodzaj_pomocy: [int], grupa_docelowa: [int] }
 */

if (! defined('ABSPATH')) {
    exit;
}

// ─── Rejestracja route ────────────────────────────────────────────────────────

add_action('rest_api_init', 'np_psychomapa_register_route');

function np_psychomapa_register_route(): void
{
    register_rest_route('niepodzielni/v1', '/psychomapa', [
        'methods'             => 'GET',
        'callback'            => 'np_psychomapa_endpoint',
        'permission_callback' => '__return_true',
    ]);
}

// ─── Callback endpointu ───────────────────────────────────────────────────────

function np_psychomapa_endpoint(): void
{
    $cacheKey   = 'np_psychomapa_all';
    $cacheGroup = 'np_psychomapa';

    $cached = wp_cache_get($cacheKey, $cacheGroup);
    if ($cached !== false) {
        header('Cache-Control: public, max-age=3600, s-maxage=21600');
        wp_send_json($cached);
        return;
    }

    global $wpdb;

    // ── Query 1: posty + meta w jednym JOIN ───────────────────────────────────
    // PIVOT przez MAX(CASE...) — jeden wiersz per post zamiast N wierszy.
    $posts = $wpdb->get_results(
        "SELECT
            p.ID,
            p.post_title,
            p.post_name,
            MAX(CASE WHEN pm.meta_key = 'lat'         THEN pm.meta_value END) AS lat,
            MAX(CASE WHEN pm.meta_key = 'lng'         THEN pm.meta_value END) AS lng,
            MAX(CASE WHEN pm.meta_key = 'np_miasto'   THEN pm.meta_value END) AS city,
            MAX(CASE WHEN pm.meta_key = 'np_telefon'  THEN pm.meta_value END) AS phone,
            MAX(CASE WHEN pm.meta_key = 'np_logo_url' THEN pm.meta_value END) AS logo_url
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID
           AND pm.meta_key IN ('lat', 'lng', 'np_miasto', 'np_telefon', 'np_logo_url')
        WHERE p.post_type   = 'osrodek_pomocy'
          AND p.post_status = 'publish'
        GROUP BY p.ID, p.post_title, p.post_name",
        ARRAY_A
    );

    if (empty($posts)) {
        wp_send_json([]);
        return;
    }

    $postIds = array_column($posts, 'ID');

    // ── Query 2: taksonomie dla wszystkich postów naraz ───────────────────────
    $placeholders = implode(',', array_fill(0, count($postIds), '%d'));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $termRows = $wpdb->get_results(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT tr.object_id, tt.taxonomy, t.term_id
            FROM {$wpdb->term_relationships} tr
            JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            JOIN {$wpdb->terms} t           ON t.term_id          = tt.term_id
            WHERE tr.object_id IN ({$placeholders})
              AND tt.taxonomy IN ('rodzaj-pomocy', 'grupa-docelowa')",
            ...$postIds
        ),
        ARRAY_A
    );

    // Indeksuj termy per post_id i taksonomia
    $termMap = [];
    foreach ($termRows as $row) {
        $termMap[(int) $row['object_id']][$row['taxonomy']][] = (int) $row['term_id'];
    }

    // ── Buduj payload ─────────────────────────────────────────────────────────
    $data = [];
    foreach ($posts as $post) {
        $id  = (int) $post['ID'];
        $lat = $post['lat'] !== null ? (float) $post['lat'] : null;
        $lng = $post['lng'] !== null ? (float) $post['lng'] : null;

        // Pomiń ośrodki bez współrzędnych — mapa ich nie wyrenderuje
        if ($lat === null || $lng === null) {
            continue;
        }

        $data[] = [
            'id'       => $id,
            'title'    => $post['post_title'],
            'lat'      => $lat,
            'lng'      => $lng,
            'url'      => get_permalink($id),
            'city'     => (string) ($post['city'] ?? ''),
            'phone'    => (string) ($post['phone'] ?? ''),
            'logo_url' => (string) ($post['logo_url'] ?? ''),
            'terms'    => [
                'rodzaj_pomocy'  => $termMap[$id]['rodzaj-pomocy']  ?? [],
                'grupa_docelowa' => $termMap[$id]['grupa-docelowa'] ?? [],
            ],
        ];
    }

    wp_cache_set($cacheKey, $data, $cacheGroup, 6 * HOUR_IN_SECONDS);

    header('Cache-Control: public, max-age=3600, s-maxage=21600');
    wp_send_json($data);
}

// ─── Inwalidacja cache ────────────────────────────────────────────────────────

add_action('save_post_osrodek_pomocy', 'np_psychomapa_clear_cache');
add_action('delete_post',              'np_psychomapa_maybe_clear_cache');
add_action('trashed_post',             'np_psychomapa_maybe_clear_cache');

function np_psychomapa_clear_cache(): void
{
    wp_cache_delete('np_psychomapa_all', 'np_psychomapa');
}

function np_psychomapa_maybe_clear_cache(int $postId): void
{
    if (get_post_type($postId) === 'osrodek_pomocy') {
        np_psychomapa_clear_cache();
    }
}
