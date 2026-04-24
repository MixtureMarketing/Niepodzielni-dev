<?php

/**
 * AI Chatbot — zapis ocen rozmów i widget na dashboardzie WP.
 *
 * REST: POST /wp-json/niepodzielni/v1/bot-feedback   (X-API-Key)
 * Dane: wp_options → np_ai_chat_ratings  (max 2000 wpisów)
 */

if (! defined('ABSPATH')) {
    exit;
}

// ─── REST endpoint ─────────────────────────────────────────────────────────────

add_action('rest_api_init', function (): void {
    register_rest_route('niepodzielni/v1', '/bot-feedback', [
        'methods'             => 'POST',
        'callback'            => 'np_ai_rest_save_feedback',
        'permission_callback' => 'np_ai_rest_verify_token', // reuse z 19-ai-endpoints.php
    ]);
});

function np_ai_rest_save_feedback(\WP_REST_Request $request): \WP_REST_Response
{
    $params = $request->get_json_params();
    $value  = isset($params['value']) ? (int) $params['value'] : 0;

    if ($value < 1 || $value > 5) {
        return new \WP_REST_Response(['error' => 'Nieprawidłowa ocena (1–5)'], 400);
    }

    $ratings   = get_option('np_ai_chat_ratings', []);
    $ratings[] = [
        'value' => $value,
        'ts'    => time(),
        'date'  => gmdate('Y-m-d'),
    ];

    // Trzymaj max 2000 ostatnich ocen
    if (count($ratings) > 2000) {
        $ratings = array_slice($ratings, -2000);
    }

    update_option('np_ai_chat_ratings', $ratings, false);

    return new \WP_REST_Response(['ok' => true], 200);
}

// ─── Dashboard widget ──────────────────────────────────────────────────────────

add_action('wp_dashboard_setup', 'np_ai_register_dashboard_widget');

function np_ai_register_dashboard_widget(): void
{
    wp_add_dashboard_widget(
        'np_ai_chat_ratings_widget',
        '🤖 AI Chatbot — Oceny rozmów',
        'np_ai_render_dashboard_widget'
    );
}

function np_ai_render_dashboard_widget(): void
{
    $ratings = get_option('np_ai_chat_ratings', []);

    if (empty($ratings)) {
        echo '<p style="color:#666">Brak ocen — widget uzupełni się po pierwszych rozmowach.</p>';
        return;
    }

    $total  = count($ratings);
    $sum    = array_sum(array_column($ratings, 'value'));
    $avg    = round($sum / $total, 2);
    $stars  = str_repeat('★', (int) round($avg)) . str_repeat('☆', 5 - (int) round($avg));

    // Ostatnie 30 dni
    $cutoff  = time() - 30 * DAY_IN_SECONDS;
    $recent  = array_filter($ratings, fn($r) => $r['ts'] >= $cutoff);
    $r_count = count($recent);
    $r_avg   = $r_count
        ? round(array_sum(array_column($recent, 'value')) / $r_count, 2)
        : 0;

    // Rozkład 1–5
    $dist = array_fill(1, 5, 0);
    foreach ($ratings as $r) {
        $dist[(int) $r['value']]++;
    }

    echo '<div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px">';
    echo '  <div style="text-align:center">';
    echo '    <div style="font-size:28px;color:#f5a623">' . esc_html($stars) . '</div>';
    echo '    <div style="font-size:22px;font-weight:700">' . esc_html($avg) . '</div>';
    echo '    <div style="font-size:11px;color:#666">Średnia (wszystkie)</div>';
    echo '  </div>';
    echo '  <div style="text-align:center">';
    echo '    <div style="font-size:22px;font-weight:700">' . esc_html($total) . '</div>';
    echo '    <div style="font-size:11px;color:#666">Wszystkich ocen</div>';
    echo '  </div>';
    echo '  <div style="text-align:center">';
    echo '    <div style="font-size:22px;font-weight:700">' . esc_html($r_avg ?: '—') . '</div>';
    echo '    <div style="font-size:11px;color:#666">Śr. ostatnie 30 dni (' . esc_html($r_count) . ')</div>';
    echo '  </div>';
    echo '</div>';

    // Wykres słupkowy rozkładu
    echo '<table style="width:100%;border-collapse:collapse;font-size:12px">';
    for ($i = 5; $i >= 1; $i--) {
        $count = $dist[$i];
        $pct   = $total ? round($count / $total * 100) : 0;
        $bar   = $total ? max(2, (int) ($pct * 1.2)) : 2; // px szerokości słupka (max ~120)
        echo '<tr>';
        echo '  <td style="padding:2px 6px 2px 0;color:#f5a623;white-space:nowrap">' . str_repeat('★', $i) . '</td>';
        echo '  <td style="padding:2px 4px"><div style="height:10px;width:' . $bar . 'px;background:#f5a623;border-radius:3px;min-width:2px"></div></td>';
        echo '  <td style="padding:2px 6px;color:#444">' . esc_html($count) . '</td>';
        echo '  <td style="padding:2px 0;color:#888">' . esc_html($pct) . '%</td>';
        echo '</tr>';
    }
    echo '</table>';

    // Ostatnie 5 ocen
    $last5 = array_slice(array_reverse($ratings), 0, 5);
    if ($last5) {
        $items = array_map(function ($r) {
            return '<span style="color:#f5a623">' . str_repeat('★', (int) $r['value']) . '</span>'
                . '<span style="color:#bbb">' . str_repeat('★', 5 - (int) $r['value']) . '</span>'
                . ' <span style="color:#888;font-size:10px">' . esc_html(gmdate('d.m', $r['ts'])) . '</span>';
        }, $last5);
        echo '<p style="margin:10px 0 0;font-size:11px;color:#666">Ostatnie: ' . implode(' &nbsp; ', $items) . '</p>';
    }
}
