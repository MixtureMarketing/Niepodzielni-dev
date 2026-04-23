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
    }

    // Posortuj po dacie i zbuduj response
    ksort($availability);
    $result = [];
    foreach ($availability as $date => $data) {
        $result[] = [
            'date'              => $date,
            'count'             => $data['count'],
            'psychologist_ids'  => $data['psychologist_ids'],
        ];
    }

    return new \WP_REST_Response(['slots' => $result], 200);
}
