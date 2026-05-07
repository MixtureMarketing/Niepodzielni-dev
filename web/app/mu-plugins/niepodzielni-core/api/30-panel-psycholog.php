<?php

/**
 * Panel psychologa — AJAX endpoints.
 *
 * Wszystkie endpointy wymagają:
 *   - is_user_logged_in()
 *   - rola psycholog (lub admin do testów)
 *   - nonce np_panel_nonce
 *   - własność edytowanego posta przez post_author
 *
 * Endpointy zarejestrowane przez np_ajax_endpoint() (api/0-ajax-endpoint-wrapper.php).
 * Wrapper obsługuje nonce i auth_callback (sprawdza ownership po post_author).
 *
 * Endpoints:
 *   - np_panel_save_profile     — biogram + tryb_konsultacji_info
 *   - np_panel_save_taxonomies  — 4× taksonomie (filtrowane przez slug__in)
 *   - np_panel_upload_photo     — featured image
 *   - np_panel_get_reviews      — lista opinii
 *   - np_panel_reply_review     — odpowiedź psychologa na opinię
 */

if (! defined('ABSPATH')) {
    exit;
}

// ─── Helper: znajdź post psychologa powiązany z aktualnym userem ─────────────

/**
 * Cached per request.
 */
function np_panel_get_user_psycholog_post(int $user_id = 0): ?WP_Post
{
    static $cache = [];
    $user_id = $user_id ?: get_current_user_id();
    if ($user_id === 0) {
        return null;
    }
    if (array_key_exists($user_id, $cache)) {
        return $cache[$user_id];
    }

    $posts = get_posts([
        'post_type'      => 'psycholog',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'author'         => $user_id,
    ]);

    return $cache[$user_id] = ($posts[0] ?? null);
}

/**
 * Guard: czy user może edytować dany post psychologa?
 * Admin może wszystko; psycholog tylko swój post (post_author).
 */
function np_panel_can_edit_post(int $post_id): bool
{
    if (! is_user_logged_in()) {
        return false;
    }
    $user_id = get_current_user_id();

    if (current_user_can('manage_options')) {
        return true;
    }

    $user = wp_get_current_user();
    if (! in_array(NP_PSYCHOLOG_ROLE, (array) $user->roles, true)) {
        return false;
    }

    $post = get_post($post_id);
    return $post && $post->post_type === 'psycholog' && (int) $post->post_author === $user_id;
}

/**
 * Auth callback dla wrappera — czyta post_id z requestu i sprawdza ownership.
 * Wywoływany ZA nonce-checkiem przez np_ajax_endpoint().
 */
function np_panel_auth_callback(array $req): bool
{
    $post_id = (int) ($req['post_id'] ?? 0);
    return np_panel_can_edit_post($post_id);
}

// ─── Endpoint: zapisz pola tekstowe (biogram + tryb_konsultacji_info) ───────

np_ajax_endpoint('np_panel_save_profile', [
    'nonce_action'  => 'np_panel_nonce',
    'auth_callback' => 'np_panel_auth_callback',
], function (array $req): array {
    $post_id = (int) $req['post_id'];

    $biogram = isset($req['biogram']) ? wp_kses_post(wp_unslash((string) $req['biogram'])) : null;
    $tryb    = isset($req['tryb_konsultacji_info'])
        ? sanitize_textarea_field(wp_unslash((string) $req['tryb_konsultacji_info']))
        : null;

    if ($biogram !== null) {
        update_post_meta($post_id, 'biogram', $biogram);
        // Carbon Fields zapisuje też pod prefixem `_` — utrzymaj spójność
        update_post_meta($post_id, '_biogram', 'field_complex');
    }
    if ($tryb !== null) {
        update_post_meta($post_id, 'tryb_konsultacji_info', $tryb);
        update_post_meta($post_id, '_tryb_konsultacji_info', 'field_complex');
    }

    np_clear_psy_listing_cache();

    return ['message' => 'Profil zapisany.'];
});

// ─── Endpoint: zapisz taksonomie (4× multiselect) ────────────────────────────

np_ajax_endpoint('np_panel_save_taxonomies', [
    'nonce_action'  => 'np_panel_nonce',
    'auth_callback' => 'np_panel_auth_callback',
], function (array $req): array {
    $post_id = (int) $req['post_id'];

    // Mapa: parametr POST → (taxonomy slug, Carbon Fields meta key)
    $map = [
        'specjalizacje'   => ['specjalizacja', 'cf_specjalizacje'],
        'nurty'           => ['nurt', 'cf_nurty'],
        'obszary_pomocy'  => ['obszar-pomocy', 'cf_obszary_pomocy'],
        'jezyki'          => ['jezyk', 'cf_jezyki'],
    ];

    foreach ($map as $param => [$taxonomy, $cf_meta_key]) {
        if (! isset($req[$param])) {
            continue; // nie wysłano tego pola — nie zmieniaj
        }

        $input = (array) wp_unslash($req[$param]);
        $input = array_map('sanitize_title', $input);
        $input = array_filter($input, static fn($s) => $s !== '');

        // KRYTYCZNE: filtruj tylko po slugach które ISTNIEJĄ w tej taksonomii.
        $valid_slugs = empty($input)
            ? []
            : (array) get_terms([
                'taxonomy'   => $taxonomy,
                'slug'       => $input,
                'fields'     => 'slugs',
                'hide_empty' => false,
            ]);

        update_post_meta($post_id, $cf_meta_key, $valid_slugs);
        wp_set_object_terms($post_id, $valid_slugs, $taxonomy, false);
    }

    np_clear_psy_listing_cache();

    return ['message' => 'Taksonomie zapisane.'];
});

// ─── Endpoint: upload zdjęcia profilowego ────────────────────────────────────

np_ajax_endpoint('np_panel_upload_photo', [
    'nonce_action'  => 'np_panel_nonce',
    'auth_callback' => 'np_panel_auth_callback',
], function (array $req): void {
    $post_id = (int) $req['post_id'];

    if (empty($_FILES['photo']) || ! is_array($_FILES['photo'])) {
        wp_send_json_error(['message' => 'Nie wgrano pliku.'], 400);
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $type    = (string) ($_FILES['photo']['type'] ?? '');
    if (! in_array($type, $allowed, true)) {
        wp_send_json_error(['message' => 'Dozwolone formaty: JPG, PNG, WebP.'], 400);
    }

    $size = (int) ($_FILES['photo']['size'] ?? 0);
    if ($size > 5 * 1024 * 1024) {
        wp_send_json_error(['message' => 'Plik za duży. Max 5 MB.'], 400);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $attachment_id = media_handle_upload('photo', $post_id);
    if (is_wp_error($attachment_id)) {
        wp_send_json_error(['message' => 'Błąd uploadu: ' . $attachment_id->get_error_message()], 500);
    }

    set_post_thumbnail($post_id, $attachment_id);
    np_clear_psy_listing_cache();

    $url = wp_get_attachment_image_url($attachment_id, 'medium_large');

    wp_send_json_success([
        'message'       => 'Zdjęcie zapisane.',
        'attachment_id' => $attachment_id,
        'url'           => $url,
    ]);
});

// ─── Endpoint: lista opinii dla psychologa ────────────────────────────────────

np_ajax_endpoint('np_panel_get_reviews', [
    'nonce_action'  => 'np_panel_nonce',
    'auth_callback' => 'np_panel_auth_callback',
], function (array $req): array {
    $post_id = (int) $req['post_id'];

    $comments = get_comments([
        'post_id' => $post_id,
        'type'    => 'review',
        'status'  => 'approve',
        'parent'  => 0,
        'orderby' => 'comment_date',
        'order'   => 'DESC',
        'number'  => 100,
    ]);

    // Wstępnie załaduj meta wszystkich komentarzy jednym zapytaniem (eliminuje N+1)
    $comment_ids = array_map(fn($c) => (int) $c->comment_ID, $comments);
    if ($comment_ids) {
        update_comment_meta_cache($comment_ids);
    }

    // Pobierz WSZYSTKIE odpowiedzi jednym zapytaniem zamiast 1 per komentarz
    $replies_map = [];
    if ($comment_ids) {
        $all_replies = get_comments([
            'parent__in' => $comment_ids,
            'status'     => 'approve',
            'number'     => 500,
            'orderby'    => 'comment_date',
            'order'      => 'ASC',
        ]);
        foreach ($all_replies as $reply) {
            $parent_id = (int) $reply->comment_parent;
            if (! isset($replies_map[$parent_id])) {
                $replies_map[$parent_id] = $reply; // tylko pierwsza odpowiedź
            }
        }
    }

    $data = [];
    foreach ($comments as $c) {
        $id       = (int) $c->comment_ID;
        $rating   = (int) get_comment_meta($id, '_rating', true);
        $verified = (bool) get_comment_meta($id, '_verified_visit', true);
        $reply    = $replies_map[$id] ?? null;

        $data[] = [
            'id'             => $id,
            'author'         => $c->comment_author,
            'date'           => get_comment_date('j F Y', $id),
            'rating'         => $rating,
            'verified_visit' => $verified,
            'content'        => $c->comment_content,
            'reply'          => $reply ? [
                'id'      => (int) $reply->comment_ID,
                'content' => $reply->comment_content,
                'date'    => get_comment_date('j F Y', (int) $reply->comment_ID),
            ] : null,
        ];
    }

    return ['reviews' => $data];
});

// ─── Endpoint: odpowiedź na opinię ───────────────────────────────────────────

np_ajax_endpoint('np_panel_reply_review', [
    'nonce_action'  => 'np_panel_nonce',
    'auth_callback' => 'np_panel_auth_callback',
], function (array $req): void {
    $post_id    = (int) $req['post_id'];
    $comment_id = (int) ($req['comment_id'] ?? 0);
    $content    = sanitize_textarea_field((string) wp_unslash($req['content'] ?? ''));

    if (! $comment_id || ! $content) {
        wp_send_json_error(['message' => 'Brakuje wymaganych danych.'], 400);
    }

    $parent = get_comment($comment_id);
    if (! $parent || (int) $parent->comment_post_ID !== $post_id || $parent->comment_type !== 'review') {
        wp_send_json_error(['message' => 'Nieprawidłowy komentarz.'], 403);
    }

    // Usuń poprzednią odpowiedź, jeśli istnieje
    $existing = get_comments(['parent' => $comment_id, 'status' => 'approve', 'number' => 1]);
    foreach ($existing as $old) {
        wp_delete_comment((int) $old->comment_ID, true);
    }

    $current_user = wp_get_current_user();

    $reply_id = wp_insert_comment([
        'comment_post_ID'      => $post_id,
        'comment_parent'       => $comment_id,
        'comment_author'       => $current_user->display_name,
        'comment_author_email' => $current_user->user_email,
        'comment_content'      => $content,
        'comment_type'         => 'review',
        'comment_approved'     => 1,
        'user_id'              => $current_user->ID,
    ]);

    if (! $reply_id) {
        wp_send_json_error(['message' => 'Błąd zapisu odpowiedzi.'], 500);
    }

    wp_send_json_success([
        'message'  => 'Odpowiedź zapisana.',
        'reply_id' => $reply_id,
        'date'     => get_comment_date('j F Y', (int) $reply_id),
    ]);
});
