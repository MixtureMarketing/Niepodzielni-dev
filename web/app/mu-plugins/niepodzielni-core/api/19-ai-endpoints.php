<?php

/**
 * AI REST Endpoints — dostępność terminów dla chatbota.
 *
 * GET /wp-json/niepodzielni/v1/bot-availability
 *   Parametry: consult_type (pelno|nisko), days (1-30, domyślnie 14)
 *   Auth: nagłówek X-API-Key = NP_AI_BOT_TOKEN
 *   Zwraca: { slots: [{ date, count, psychologist_ids[] }] }
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function (): void {
    register_rest_route('niepodzielni/v1', '/bookero-status', [
        'methods'             => 'GET',
        'callback'            => 'np_ai_rest_bookero_status',
        'permission_callback' => 'np_ai_rest_verify_token',
    ]);

    register_rest_route('niepodzielni/v1', '/bookero-clear-cb', [
        'methods'             => 'POST',
        'callback'            => 'np_ai_rest_clear_cb',
        'permission_callback' => 'np_ai_rest_verify_token',
    ]);

    register_rest_route('niepodzielni/v1', '/bot-availability', [
        'methods'             => 'GET',
        'callback'            => 'np_ai_rest_bot_availability',
        'permission_callback' => 'np_ai_rest_verify_token',
        'args'                => [
            'consult_type' => [
                'type'              => 'string',
                'default'           => 'pelno',
                'enum'              => ['pelno', 'nisko'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'days' => [
                'type'              => 'integer',
                'default'           => 14,
                'minimum'           => 1,
                'maximum'           => 30,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
});

function np_ai_rest_verify_token(\WP_REST_Request $request): bool|\WP_Error
{
    $token = defined('NP_AI_BOT_TOKEN') && NP_AI_BOT_TOKEN
        ? (string) NP_AI_BOT_TOKEN
        : (string) get_option('np_ai_bot_token', '');

    if (! $token) {
        return new \WP_Error('no_token', 'Bot token not configured', ['status' => 500]);
    }

    $provided = $request->get_header('X-API-Key');
    if (! hash_equals($token, (string) $provided)) {
        return new \WP_Error('unauthorized', 'Invalid token', ['status' => 401]);
    }

    return true;
}

function np_ai_rest_bot_availability(\WP_REST_Request $request): \WP_REST_Response
{
    $typ  = $request->get_param('consult_type');
    $days = (int) $request->get_param('days');

    // Pobierz daty z zakresu today → today+days
    $today    = current_time('Y-m-d');
    $end_date = gmdate('Y-m-d', strtotime("+{$days} days", strtotime($today)));

    $slots_key = $typ === 'nisko' ? 'bookero_slots_nisko' : 'bookero_slots_pelno';
    $id_key    = $typ === 'nisko' ? 'bookero_id_niski' : 'bookero_id_pelny';

    // Zbierz wszystkich psychologów z tym typem konta
    $psycholodzy = get_posts([
        'post_type'      => 'psycholog',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [[
            'key'     => $id_key,
            'compare' => 'EXISTS',
        ]],
    ]);

    // Zagreguj dostępność per data
    $availability = [];
    $psy_meta     = [];

    foreach ($psycholodzy as $post_id) {
        $slots_json = get_post_meta((int) $post_id, $slots_key, true);
        if (! $slots_json) {
            continue;
        }

        $slots = json_decode($slots_json, true);
        if (! is_array($slots)) {
            continue;
        }

        foreach ($slots as $date) {
            if ($date < $today || $date > $end_date) {
                continue;
            }
            if (! isset($availability[$date])) {
                $availability[$date] = ['count' => 0, 'psychologist_ids' => []];
            }
            $availability[$date]['count']++;
            $availability[$date]['psychologist_ids'][] = $post_id;
        }

        // Zbierz metadane — raz per psycholog (użyte przy budowaniu psychologists[])
        if (! isset($psy_meta[$post_id])) {
            $spec_terms = get_the_terms($post_id, 'specjalizacja') ?: [];
            $area_terms = get_the_terms($post_id, 'obszar-pomocy') ?: [];
            $all_terms  = array_merge($spec_terms, $area_terms);
            $psy_meta[$post_id] = [
                'id'              => $post_id,
                'title'           => get_the_title($post_id),
                'url'             => get_permalink($post_id),
                'photo_url'       => get_the_post_thumbnail_url($post_id, 'medium_large') ?: '',
                'specializations' => implode(', ', array_map(fn($t) => $t->name, $all_terms)),
            ];
        }
    }

    // Posortuj po dacie i zbuduj response z metadanymi psychologów
    ksort($availability);
    $result = [];
    foreach ($availability as $date => $data) {
        $result[] = [
            'date'             => $date,
            'count'            => $data['count'],
            'psychologist_ids' => $data['psychologist_ids'],
            'psychologists'    => array_values(array_map(
                fn($id) => $psy_meta[$id],
                $data['psychologist_ids'],
            )),
        ];
    }

    return new \WP_REST_Response(['slots' => $result], 200);
}

/**
 * GET /wp-json/niepodzielni/v1/bookero-status
 *
 * Diagnostyka: stan circuit breaker + lista nisko-psychologów z ich terminem i timestampem synchrnoizacji.
 * Pomaga diagnozować dlaczego daty nie pojawiają się na listingu.
 */
function np_ai_rest_bookero_status(): \WP_REST_Response
{
    $cb_active      = (bool) get_transient(BOOKERO_LOCKOUT_KEY);
    $lockout_since  = (int) get_option('np_bookero_lockout_since', 0);
    $last_cron      = (int) get_option('np_bookero_last_cron_run', 0);

    $psycholodzy = get_posts([
        'post_type'      => 'psycholog',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [[
            'key'     => 'bookero_id_niski',
            'compare' => 'EXISTS',
        ]],
    ]);

    $workers = [];
    foreach ($psycholodzy as $post_id) {
        $pid         = (int) $post_id;
        $updated_at  = (int) get_post_meta($pid, 'np_termin_updated_at', true);
        $termin      = (string) get_post_meta($pid, 'najblizszy_termin_niskoplatny', true);
        $slots_json  = (string) get_post_meta($pid, 'bookero_slots_nisko', true);
        $slots       = $slots_json ? json_decode($slots_json, true) : [];
        $worker_id   = (string) get_post_meta($pid, 'bookero_id_niski', true);

        $workers[] = [
            'post_id'     => $pid,
            'name'        => get_the_title($pid),
            'worker_id'   => $worker_id,
            'termin'      => $termin ?: null,
            'slots_count' => is_array($slots) ? count($slots) : 0,
            'synced_at'   => $updated_at ? gmdate('Y-m-d H:i:s', $updated_at) . ' UTC' : null,
            'synced_ago'  => $updated_at ? human_time_diff($updated_at) . ' ago' : 'never',
        ];
    }

    usort($workers, fn($a, $b) => ($a['synced_at'] ?? '') <=> ($b['synced_at'] ?? ''));

    return new \WP_REST_Response([
        'circuit_breaker' => [
            'active'       => $cb_active,
            'locked_since' => $lockout_since ? gmdate('Y-m-d H:i:s', $lockout_since) . ' UTC' : null,
        ],
        'last_cron_run'   => $last_cron ? gmdate('Y-m-d H:i:s', $last_cron) . ' UTC' : null,
        'nisko_workers'   => $workers,
        'listing_cache'   => [
            'note' => 'Use POST /bookero-clear-cb to clear CB and listing cache',
        ],
    ], 200);
}

/**
 * POST /wp-json/niepodzielni/v1/bookero-clear-cb
 *
 * Awaryjne czyszczenie circuit breakera i cache listingu.
 * Usuwa lockout transient żeby następny cron mógł normalnie zadziałać.
 * Opcjonalnie: force_sync=true wymusza synchronizację pierwszych 5 nisko-psychologów natychmiast.
 */
function np_ai_rest_clear_cb(\WP_REST_Request $request): \WP_REST_Response
{
    $was_active = (bool) get_transient(BOOKERO_LOCKOUT_KEY);

    delete_transient(BOOKERO_LOCKOUT_KEY);
    delete_option('np_bookero_lockout_since');

    // Wyczyść cache konfiguracji konta (24h TTL) — wymusza ponowne pobranie /init z mapą workerów
    $repo = new \Niepodzielni\Bookero\PsychologistRepository();
    delete_transient($repo->configCacheKey('nisko'));
    delete_transient($repo->configCacheKey('pelnoplatny'));

    // Wyczyść listing cache żeby pokazać aktualne dane
    if (class_exists('App\Services\PsychologistListingService')) {
        \App\Services\PsychologistListingService::clearCache();
    }

    // Wyczyść shared calendar month transients (5 min TTL) — zawierają stary service_id per worker
    foreach (['nisko', 'pelnoplatny'] as $t) {
        for ($i = 0; $i <= 2; $i++) {
            delete_transient($repo->sharedMonthCacheKey($t, $i));
        }
    }

    $synced = [];
    $errors = [];

    if ($request->get_param('force_sync')) {
        $explicit_ids = $request->get_param('post_ids');
        if (! empty($explicit_ids) && is_array($explicit_ids)) {
            $to_sync = array_map('absint', $explicit_ids);
        } else {
            $to_sync = get_posts([
                'post_type'              => 'psycholog',
                'post_status'            => 'publish',
                'posts_per_page'         => 5,
                'fields'                 => 'ids',
                'meta_key'               => 'np_termin_updated_at',
                'orderby'                => 'meta_value_num',
                'order'                  => 'ASC',
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => [[
                    'key'     => 'bookero_id_niski',
                    'compare' => 'EXISTS',
                ]],
            ]);
        }

        $client  = new \Niepodzielni\Bookero\BookeroApiClient();
        $repo_s  = new \Niepodzielni\Bookero\PsychologistRepository();
        $service = new \Niepodzielni\Bookero\BookeroSyncService($client, $repo_s);

        foreach ($to_sync as $pid) {
            try {
                $result   = $service->syncSingleWorker((int) $pid);
                $synced[] = [
                    'post_id'      => (int) $pid,
                    'nearest_nisko' => $result->nearestNisko,
                ];
            } catch (\Exception $e) {
                $errors[] = ['post_id' => (int) $pid, 'error' => $e->getMessage()];
            }
        }

        if (! empty($synced)) {
            do_action('niepodzielni_bookero_batch_synced');
        }
    }

    return new \WP_REST_Response([
        'ok'                => true,
        'cb_was_active'     => $was_active,
        'cb_cleared'        => true,
        'listing_cleared'   => true,
        'synced'            => $synced,
        'errors'            => $errors,
    ], 200);
}
