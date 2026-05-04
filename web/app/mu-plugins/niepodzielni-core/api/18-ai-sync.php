<?php

/**
 * AI Sync — wysyła dane psychologów i FAQ do Cloudflare Worker (Vectorize).
 *
 * Używa wp_remote_post z blocking=false (fire-and-forget przez shutdown),
 * aby nie blokować zapisu posta w panelu admina.
 */

if (! defined('ABSPATH')) {
    exit;
}

// ─── Konfiguracja ─────────────────────────────────────────────────────────────

function np_ai_worker_url(): string
{
    return defined('NP_AI_WORKER_URL') && NP_AI_WORKER_URL
        ? (string) NP_AI_WORKER_URL
        : (string) get_option('np_ai_worker_url', '');
}

function np_ai_worker_secret(): string
{
    return defined('NP_AI_WORKER_SECRET') && NP_AI_WORKER_SECRET
        ? (string) NP_AI_WORKER_SECRET
        : (string) get_option('np_ai_worker_secret', '');
}

// ─── Ekstrakcja tekstu posta ──────────────────────────────────────────────────

function np_ai_build_psycholog_payload(int $post_id): ?array
{
    $post = get_post($post_id);
    if (! $post || $post->post_status !== 'publish') {
        return null;
    }

    $meta = [
        'specjalizacje' => (array) get_post_meta($post_id, 'specjalizacje', true),
        'nurty'         => (array) get_post_meta($post_id, 'nurty_terapeutyczne', true),
        'obszary'       => (array) get_post_meta($post_id, 'obszary_pracy', true),
        'jezyki'        => (array) get_post_meta($post_id, 'jezyki', true),
    ];

    // Usuń puste tablice z meta
    $meta = array_filter($meta, fn($v) => ! empty($v));

    return [
        'id'       => $post_id,
        'type'     => 'psycholog',
        'title'    => $post->post_title,
        'content'  => wp_strip_all_tags($post->post_content),
        'url'      => get_permalink($post_id),
        'photo_url' => get_the_post_thumbnail_url($post_id, 'medium') ?: '',
        'meta'    => $meta,
    ];
}

function np_ai_build_faq_payload(int $post_id): ?array
{
    $post = get_post($post_id);
    if (! $post || $post->post_status !== 'publish') {
        return null;
    }

    return [
        'id'      => $post_id,
        'type'    => 'faq',
        'title'   => $post->post_title,
        'content' => wp_strip_all_tags($post->post_content),
        'url'     => get_permalink($post_id),
        'status'  => 'active',
    ];
}

function np_ai_build_article_payload(int $post_id): ?array
{
    $post = get_post($post_id);
    if (! $post || $post->post_status !== 'publish') {
        return null;
    }

    $tags = wp_get_post_terms($post_id, 'temat-artykulu', ['fields' => 'names']);
    if (is_wp_error($tags)) {
        $tags = [];
    }

    return [
        'id'      => $post_id,
        'type'    => 'article',
        'title'   => $post->post_title,
        'content' => wp_strip_all_tags($post->post_content),
        'url'     => get_permalink($post_id),
        'tags'    => array_values(array_filter($tags)),
        'status'  => 'active',
    ];
}

function np_ai_build_blog_payload(int $post_id): ?array
{
    $post = get_post($post_id);
    if (! $post || $post->post_status !== 'publish') {
        return null;
    }

    $cats = wp_get_post_terms($post_id, 'category', ['fields' => 'names']);
    $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
    if (is_wp_error($cats)) {
        $cats = [];
    }
    if (is_wp_error($tags)) {
        $tags = [];
    }
    $all_tags = array_values(array_unique(array_merge($cats, $tags)));

    return [
        'id'      => $post_id,
        'type'    => 'article',
        'title'   => $post->post_title,
        'content' => wp_strip_all_tags($post->post_content),
        'url'     => get_permalink($post_id),
        'tags'    => $all_tags,
        'status'  => 'active',
    ];
}

function np_ai_build_workshop_payload(int $post_id): ?array
{
    $post = get_post($post_id);
    if (! $post || $post->post_status !== 'publish') {
        return null;
    }

    $tags       = wp_get_post_terms($post_id, 'temat', ['fields' => 'names']);
    $event_date = get_post_meta($post_id, 'event_date', true) ?: '';

    if (is_wp_error($tags)) {
        $tags = [];
    }

    return [
        'id'         => $post_id,
        'type'       => 'workshop',
        'title'      => $post->post_title,
        'content'    => wp_strip_all_tags($post->post_content),
        'url'        => get_permalink($post_id),
        'photo_url'  => get_the_post_thumbnail_url($post_id, 'medium') ?: '',
        'tags'       => array_values(array_filter($tags)),
        'event_date' => $event_date ? (string) $event_date : null,
        'status'     => 'active',
    ];
}

function np_ai_build_group_payload(int $post_id): ?array
{
    $post = get_post($post_id);
    if (! $post || $post->post_status !== 'publish') {
        return null;
    }

    $tags = wp_get_post_terms($post_id, 'temat', ['fields' => 'names']);
    if (is_wp_error($tags)) {
        $tags = [];
    }

    return [
        'id'      => $post_id,
        'type'    => 'group',
        'title'   => $post->post_title,
        'content' => wp_strip_all_tags($post->post_content),
        'url'     => get_permalink($post_id),
        'tags'    => array_values(array_filter($tags)),
        'status'  => 'active',
    ];
}

// ─── Wysyłka do Workera (fire-and-forget) ────────────────────────────────────

function np_ai_sync_dispatch(array $payload): void
{
    $worker_url = np_ai_worker_url();
    $secret     = np_ai_worker_secret();

    if (! $worker_url || ! $secret) {
        return;
    }

    $url    = rtrim($worker_url, '/') . '/sync';
    $body   = wp_json_encode($payload);
    $secret = $secret;

    // Uruchom po zakończeniu bieżącego żądania HTTP — nie blokuje zapisu posta
    add_action('shutdown', static function () use ($url, $body, $secret): void {
        wp_remote_post($url, [
            'blocking' => false,
            'timeout'  => 5,
            'headers'  => [
                'Content-Type'    => 'application/json',
                'X-Worker-Secret' => $secret,
            ],
            'body' => $body,
        ]);
    });
}

// ─── Hooki ────────────────────────────────────────────────────────────────────

add_action('save_post_psycholog', function (int $post_id, \WP_Post $post): void {
    if ($post->post_status !== 'publish' || wp_is_post_revision($post_id)) {
        return;
    }

    $payload = np_ai_build_psycholog_payload($post_id);
    if ($payload) {
        np_ai_sync_dispatch($payload);
    }
}, 10, 2);

add_action('save_post_faq', function (int $post_id, \WP_Post $post): void {
    if ($post->post_status !== 'publish' || wp_is_post_revision($post_id)) {
        return;
    }
    $payload = np_ai_build_faq_payload($post_id);
    if ($payload) {
        np_ai_sync_dispatch($payload);
    }
}, 10, 2);

add_action('save_post', function (int $post_id, \WP_Post $post): void {
    if ($post->post_type !== 'post' || $post->post_status !== 'publish' || wp_is_post_revision($post_id)) {
        return;
    }
    $payload = np_ai_build_blog_payload($post_id);
    if ($payload) {
        np_ai_sync_dispatch($payload);
    }
}, 10, 2);

add_action('save_post_aktualnosci', function (int $post_id, \WP_Post $post): void {
    if ($post->post_status !== 'publish' || wp_is_post_revision($post_id)) {
        return;
    }
    $payload = np_ai_build_article_payload($post_id);
    if ($payload) {
        np_ai_sync_dispatch($payload);
    }
}, 10, 2);

add_action('save_post_warsztaty', function (int $post_id, \WP_Post $post): void {
    if ($post->post_status !== 'publish' || wp_is_post_revision($post_id)) {
        return;
    }
    $payload = np_ai_build_workshop_payload($post_id);
    if ($payload) {
        np_ai_sync_dispatch($payload);
    }
}, 10, 2);

add_action('save_post_grupy-wsparcia', function (int $post_id, \WP_Post $post): void {
    if ($post->post_status !== 'publish' || wp_is_post_revision($post_id)) {
        return;
    }
    $payload = np_ai_build_group_payload($post_id);
    if ($payload) {
        np_ai_sync_dispatch($payload);
    }
}, 10, 2);

// ─── Bulk sync — wszystkie typy do KNOWLEDGE_BASE ────────────────────────────

/**
 * Synckuje jeden typ CPT do Vectorize.
 *
 * Użycie z WP-CLI:
 *   wp eval 'np_ai_bulk_sync("psycholog");'
 *   wp eval 'np_ai_bulk_sync_all();'
 */
function np_ai_bulk_sync(string $post_type = 'psycholog'): void
{
    $builders = [
        'psycholog'      => 'np_ai_build_psycholog_payload',
        'faq'            => 'np_ai_build_faq_payload',
        'aktualnosci'    => 'np_ai_build_article_payload',
        'post'           => 'np_ai_build_blog_payload',
        'warsztaty'      => 'np_ai_build_workshop_payload',
        'grupy-wsparcia' => 'np_ai_build_group_payload',
    ];

    $builder = $builders[$post_type] ?? 'np_ai_build_psycholog_payload';

    $ids = get_posts([
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    $worker_url = np_ai_worker_url();
    $secret     = np_ai_worker_secret();

    if (! $worker_url || ! $secret) {
        error_log('[NP AI] Brak NP_AI_WORKER_URL lub NP_AI_WORKER_SECRET — bulk sync pominięty.');
        return;
    }

    $url = rtrim($worker_url, '/') . '/sync';
    $ok  = 0;

    foreach ($ids as $id) {
        $payload = $builder((int) $id);
        if (! $payload) {
            continue;
        }

        $res = wp_remote_post($url, [
            'blocking' => true,
            'timeout'  => 15,
            'headers'  => [
                'Content-Type'    => 'application/json',
                'X-Worker-Secret' => $secret,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (! is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            $ok++;
        }

        usleep(150000); // 150ms między requestami
    }

    error_log(sprintf('[NP AI] Bulk sync %s: %d/%d', $post_type, $ok, count($ids)));
}

function np_ai_bulk_sync_all(): void
{
    foreach (['psycholog', 'faq', 'aktualnosci', 'post', 'warsztaty', 'grupy-wsparcia'] as $type) {
        np_ai_bulk_sync($type);
    }
}
