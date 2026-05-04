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
 * Endpoints:
 *   - np_panel_save_profile     — biogram + tryb_konsultacji_info
 *   - np_panel_save_taxonomies  — 4× taksonomie (filtrowane przez slug__in)
 *   - np_panel_upload_photo     — featured image
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
        return $cache[ $user_id ];
    }

    $posts = get_posts([
        'post_type'      => 'psycholog',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'author'         => $user_id,
    ]);

    return $cache[ $user_id ] = ($posts[0] ?? null);
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
 * Wspólna walidacja na początku każdego endpointu.
 * Zwraca post_id lub kończy request błędem.
 */
function np_panel_validate_request(): int
{
    if (! check_ajax_referer('np_panel_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Sesja wygasła. Odśwież stronę.'], 403);
    }
    $post_id = (int) ($_POST['post_id'] ?? 0);
    if (! np_panel_can_edit_post($post_id)) {
        wp_send_json_error(['message' => 'Brak uprawnień do edycji tego profilu.'], 403);
    }
    return $post_id;
}

// ─── Endpoint: zapisz pola tekstowe (biogram + tryb_konsultacji_info) ───────

add_action('wp_ajax_np_panel_save_profile', 'np_ajax_panel_save_profile');

function np_ajax_panel_save_profile(): void
{
    $post_id = np_panel_validate_request();

    $biogram = isset($_POST['biogram']) ? wp_kses_post(wp_unslash((string) $_POST['biogram'])) : null;
    $tryb    = isset($_POST['tryb_konsultacji_info'])
        ? sanitize_textarea_field(wp_unslash((string) $_POST['tryb_konsultacji_info']))
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

    // Inwaliduj cache listingu psychologów (jeśli istnieje)
    if (class_exists('App\\Services\\PsychologistListingService')) {
        \App\Services\PsychologistListingService::clearCache();
    }

    wp_send_json_success([
        'message' => 'Profil zapisany.',
    ]);
}

// ─── Endpoint: zapisz taksonomie (4× multiselect) ────────────────────────────

add_action('wp_ajax_np_panel_save_taxonomies', 'np_ajax_panel_save_taxonomies');

function np_ajax_panel_save_taxonomies(): void
{
    $post_id = np_panel_validate_request();

    // Mapa: parametr POST → (taxonomy slug, Carbon Fields meta key)
    $map = [
        'specjalizacje'   => ['specjalizacja', 'cf_specjalizacje'],
        'nurty'           => ['nurt', 'cf_nurty'],
        'obszary_pomocy'  => ['obszar-pomocy', 'cf_obszary_pomocy'],
        'jezyki'          => ['jezyk', 'cf_jezyki'],
    ];

    foreach ($map as $param => [$taxonomy, $cf_meta_key]) {
        if (! isset($_POST[ $param ])) {
            continue; // nie wysłano tego pola — nie zmieniaj
        }

        $input = (array) wp_unslash($_POST[ $param ]);
        $input = array_map('sanitize_title', $input);
        $input = array_filter($input, static fn($s) => $s !== '');

        // KRYTYCZNE: filtruj tylko po slugach które ISTNIEJĄ w tej taksonomii.
        // get_terms zwróci pustą tablicę jeśli żaden slug nie pasuje — usunie próby wstrzyknięcia.
        $valid_slugs = empty($input)
            ? []
            : (array) get_terms([
                'taxonomy'   => $taxonomy,
                'slug'       => $input,
                'fields'     => 'slugs',
                'hide_empty' => false,
            ]);

        // Aktualizuj Carbon Fields meta (slugi w tablicy)
        // Carbon Fields zapisuje te wartości jako serializowaną tablicę pod prefixem `_`
        update_post_meta($post_id, $cf_meta_key, $valid_slugs);

        // Zsynchronizuj natywne term_relationships, by getterm() działał spójnie
        wp_set_object_terms($post_id, $valid_slugs, $taxonomy, false);
    }

    if (class_exists('App\\Services\\PsychologistListingService')) {
        \App\Services\PsychologistListingService::clearCache();
    }

    wp_send_json_success([
        'message' => 'Taksonomie zapisane.',
    ]);
}

// ─── Endpoint: upload zdjęcia profilowego ────────────────────────────────────

add_action('wp_ajax_np_panel_upload_photo', 'np_ajax_panel_upload_photo');

function np_ajax_panel_upload_photo(): void
{
    $post_id = np_panel_validate_request();

    if (empty($_FILES['photo']) || ! is_array($_FILES['photo'])) {
        wp_send_json_error(['message' => 'Nie wgrano pliku.'], 400);
    }

    // Walidacja typu — tylko obrazki
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $type    = (string) ($_FILES['photo']['type'] ?? '');
    if (! in_array($type, $allowed, true)) {
        wp_send_json_error(['message' => 'Dozwolone formaty: JPG, PNG, WebP.'], 400);
    }

    // Walidacja rozmiaru — max 5 MB
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

    // Ustaw jako featured image
    set_post_thumbnail($post_id, $attachment_id);

    if (class_exists('App\\Services\\PsychologistListingService')) {
        \App\Services\PsychologistListingService::clearCache();
    }

    $url = wp_get_attachment_image_url($attachment_id, 'medium_large');

    wp_send_json_success([
        'message'       => 'Zdjęcie zapisane.',
        'attachment_id' => $attachment_id,
        'url'           => $url,
    ]);
}

// ─── Endpoint: lista opinii dla psychologa ────────────────────────────────────

add_action('wp_ajax_np_panel_get_reviews', 'np_ajax_panel_get_reviews');

function np_ajax_panel_get_reviews(): void
{
    $post_id = np_panel_validate_request();

    $comments = get_comments([
        'post_id' => $post_id,
        'type'    => 'review',
        'status'  => 'approve',
        'parent'  => 0,
        'orderby' => 'comment_date',
        'order'   => 'DESC',
        'number'  => 100,
    ]);

    $data = [];
    foreach ($comments as $c) {
        $id      = (int) $c->comment_ID;
        $rating  = (int) get_comment_meta($id, '_rating', true);
        $verified = (bool) get_comment_meta($id, '_verified_visit', true);

        // Pierwsza odpowiedź psychologa (child comment)
        $replies = get_comments(['parent' => $id, 'status' => 'approve', 'number' => 1]);
        $reply   = $replies[0] ?? null;

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

    wp_send_json_success(['reviews' => $data]);
}

// ─── Endpoint: odpowiedź na opinię ───────────────────────────────────────────

add_action('wp_ajax_np_panel_reply_review', 'np_ajax_panel_reply_review');

function np_ajax_panel_reply_review(): void
{
    $post_id = np_panel_validate_request();

    $comment_id = (int) ($_POST['comment_id'] ?? 0);
    $content    = sanitize_textarea_field((string) wp_unslash($_POST['content'] ?? ''));

    if (! $comment_id || ! $content) {
        wp_send_json_error(['message' => 'Brakuje wymaganych danych.'], 400);
    }

    // Upewnij się, że komentarz dotyczy tego posta
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
}
