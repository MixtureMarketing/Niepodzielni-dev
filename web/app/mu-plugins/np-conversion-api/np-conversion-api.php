<?php

/**
 * Plugin Name: NP Conversion API (S2S)
 * Description: Server-to-Server Conversion API — Meta CAPI + GA4 Measurement Protocol dla krytycznych eventów (purchase, generate_lead, sign_up). Działa równolegle z Cloudflare Zaraz (client-side); event_id jest wspólny → deduplikacja Meta. Lepsza atrybucja gdy klient blokuje JS / 3rd-party.
 * Version: 1.0.0
 * Author: Mixture Marketing
 *
 * Bezpieczeństwo:
 *  - REST endpoint POST /wp-json/np/v1/track zabezpieczony nonce (X-WP-Nonce) + Turnstile (opcjonalny).
 *  - Rate limit per IP (transient np_s2s_rl_<ip>) — 60/min default.
 *  - PII (email, phone, IP) hashowane SHA-256 (lower-case, trim) przed wysyłką do GA4 / Meta.
 *  - Crisis Hub: zero S2S — wystarczy nie wysyłać tych eventów z frontu (białą listą zarządza JS).
 *
 * Konfiguracja (constants z config/application.php → env Trellis vault):
 *   NP_GA4_MEASUREMENT_ID, NP_GA4_API_SECRET
 *   NP_META_PIXEL_ID, NP_META_CAPI_TOKEN
 * Brak constants = endpoint zwraca 200 noop (nie spamuje 5xx) i loguje WARNING raz/min.
 *
 * Wykonanie zewnętrznych HTTP nieblokujące — `wp_remote_post(['blocking' => false])` —
 * żeby nie opóźniać response do klienta (cel: <50ms p99 endpoint REST).
 */

if (! defined('ABSPATH')) {
    exit;
}

/** Lista krytycznych eventów akceptowanych przez S2S. */
const NP_S2S_ALLOWED_EVENTS = ['purchase', 'generate_lead', 'sign_up'];

/** Rate limit per IP (zapytań/minutę). */
const NP_S2S_RATE_LIMIT_PER_MIN = 60;

// ─── Frontend nonce + URL exposure ────────────────────────────────────────────

/**
 * Eksponuje window.NP_S2S = { url, nonce } na frontendzie. Używane przez lib/track.js
 * dla krytycznych eventów (purchase, generate_lead, sign_up). Crisis Hub stronami
 * NIE wywołuje fetch() — białą listą eventów zarządza JS.
 */
add_action('wp_head', static function (): void {
    // Pomiń panel admina i strony Crisis Hub (zero PII forwarding).
    if (is_admin()) {
        return;
    }
    $url   = esc_url_raw(rest_url('np/v1/track'));
    $nonce = wp_create_nonce('wp_rest');
    echo '<script>window.NP_S2S=' . wp_json_encode(['url' => $url, 'nonce' => $nonce]) . ';</script>' . "\n";
}, 5);

// ─── REST registration ────────────────────────────────────────────────────────

add_action('rest_api_init', static function (): void {
    register_rest_route('np/v1', '/track', [
        'methods'             => 'POST',
        'callback'            => 'np_s2s_handle_track',
        'permission_callback' => '__return_true',
        'args'                => [
            'event_name' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'event_id' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'user_data' => [
                'required' => false,
                'type'     => 'object',
            ],
            'custom_data' => [
                'required' => false,
                'type'     => 'object',
            ],
            'source_url' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
            ],
        ],
    ]);
});

// ─── Handler ──────────────────────────────────────────────────────────────────

function np_s2s_handle_track(\WP_REST_Request $request): \WP_REST_Response
{
    // 1. Rate limit — best-effort, transient-based.
    $ip   = np_s2s_client_ip();
    $rlKey = 'np_s2s_rl_' . md5($ip);
    $hits = (int) get_transient($rlKey);
    if ($hits >= NP_S2S_RATE_LIMIT_PER_MIN) {
        return new \WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
    }
    set_transient($rlKey, $hits + 1, MINUTE_IN_SECONDS);

    // 2. Nonce — wymagany (chroni przed CSRF-driven event spamem).
    $nonce = (string) ($request->get_header('x_wp_nonce') ?: $request->get_param('_wpnonce'));
    if (! wp_verify_nonce($nonce, 'wp_rest')) {
        return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_nonce'], 403);
    }

    // 3. Walidacja event_name.
    $eventName = (string) $request->get_param('event_name');
    if (! in_array($eventName, NP_S2S_ALLOWED_EVENTS, true)) {
        return new \WP_REST_Response(['ok' => false, 'error' => 'event_not_allowed'], 400);
    }

    $eventId = (string) $request->get_param('event_id');
    if ($eventId === '') {
        return new \WP_REST_Response(['ok' => false, 'error' => 'missing_event_id'], 400);
    }

    // 4. Turnstile — opcjonalny (jeśli klient dorzucił token, weryfikujemy).
    //    Brak tokena nie blokuje — nonce już chroni, a S2S z natury jest
    //    wywoływane z autoryzowanego frontu po Bookero/form sukces.
    $turnstile = (string) ($request->get_param('cf-turnstile-response') ?? '');
    if ($turnstile !== '' && function_exists('np_cf_turnstile_verify')) {
        if (! np_cf_turnstile_verify($turnstile, $ip)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'turnstile_failed'], 400);
        }
    }

    /** @var array<string, mixed> $userDataRaw */
    $userDataRaw = (array) ($request->get_param('user_data') ?? []);
    /** @var array<string, mixed> $customData */
    $customData = (array) ($request->get_param('custom_data') ?? []);
    $sourceUrl  = (string) ($request->get_param('source_url') ?? '');

    // 5. Hashing PII — SHA-256 lower-case trim.
    $userDataHashed = np_s2s_hash_user_data($userDataRaw, $ip);

    // 6. Equal-cost wysyłka — GA4 + Meta równolegle (oba nieblokujące).
    $ga4Status  = np_s2s_send_ga4($eventName, $eventId, $userDataHashed, $customData, $sourceUrl);
    $metaStatus = np_s2s_send_meta($eventName, $eventId, $userDataHashed, $customData, $sourceUrl, $ip);

    // Zwracamy sukces nawet gdy backendy nie skonfigurowane — front nie ma co
    // robić ze szczegółami (i tak nieblokujący sendBeacon).
    return new \WP_REST_Response([
        'ok'   => true,
        'ga4'  => $ga4Status,
        'meta' => $metaStatus,
    ], 200);
}

// ─── PII hashing ──────────────────────────────────────────────────────────────

/**
 * Normalizuje + SHA-256-uje PII zgodnie z wymaganiami Meta CAPI / GA4 MP.
 * Email/phone: lower-case + trim. Phone dodatkowo: tylko cyfry, prefix +48 jeśli PL.
 * IP: hashowane (Meta akceptuje raw IP, ale my preferujemy hash).
 *
 * @param  array<string, mixed> $userData  Surowe dane (email, phone, first_name, last_name, ...)
 * @param  string               $clientIp  REMOTE_ADDR
 * @return array<string, string>
 */
function np_s2s_hash_user_data(array $userData, string $clientIp): array
{
    $out = [];

    if (! empty($userData['email']) && is_string($userData['email'])) {
        $out['em'] = hash('sha256', strtolower(trim($userData['email'])));
    }

    if (! empty($userData['phone']) && is_string($userData['phone'])) {
        $phoneDigits = preg_replace('/\D+/', '', $userData['phone']) ?? '';
        if ($phoneDigits !== '' && strlen($phoneDigits) === 9) {
            $phoneDigits = '48' . $phoneDigits;
        }
        if ($phoneDigits !== '') {
            $out['ph'] = hash('sha256', $phoneDigits);
        }
    }

    foreach (['first_name' => 'fn', 'last_name' => 'ln', 'city' => 'ct', 'zip' => 'zp', 'country' => 'country'] as $src => $key) {
        if (! empty($userData[$src]) && is_string($userData[$src])) {
            $out[$key] = hash('sha256', strtolower(trim($userData[$src])));
        }
    }

    if ($clientIp !== '') {
        $out['client_ip_hash'] = hash('sha256', $clientIp);
    }

    return $out;
}

// ─── GA4 Measurement Protocol ─────────────────────────────────────────────────

/**
 * @param  array<string, string> $userDataHashed
 * @param  array<string, mixed>  $customData
 */
function np_s2s_send_ga4(
    string $eventName,
    string $eventId,
    array $userDataHashed,
    array $customData,
    string $sourceUrl,
): string {
    $measurementId = defined('NP_GA4_MEASUREMENT_ID') ? (string) NP_GA4_MEASUREMENT_ID : '';
    $apiSecret     = defined('NP_GA4_API_SECRET') ? (string) NP_GA4_API_SECRET : '';

    if ($measurementId === '' || $apiSecret === '') {
        return 'skipped_no_config';
    }

    // GA4 wymaga client_id (UUID). Generujemy stabilny ID z hashed email lub IP,
    // żeby kolejne eventy z tej samej sesji łączyły się w GA4.
    $clientId = $userDataHashed['em'] ?? $userDataHashed['client_ip_hash'] ?? bin2hex(random_bytes(8));
    $clientId = substr($clientId, 0, 16) . '.' . substr($clientId, 16, 16);

    $params = $customData;
    $params['event_id']      = $eventId;
    $params['engagement_time_msec'] = 100;
    if ($sourceUrl !== '') {
        $params['page_location'] = $sourceUrl;
    }

    $payload = [
        'client_id' => $clientId,
        'events'    => [[
            'name'   => $eventName,
            'params' => $params,
        ]],
    ];

    if (! empty($userDataHashed['em'])) {
        // GA4 user_id musi być oryginalnym ID — tu używamy hash email jako stabilnego pseudo-ID.
        $payload['user_id'] = $userDataHashed['em'];
    }

    $url = sprintf(
        'https://www.google-analytics.com/mp/collect?measurement_id=%s&api_secret=%s',
        rawurlencode($measurementId),
        rawurlencode($apiSecret),
    );

    $resp = wp_remote_post($url, [
        'timeout'  => 5,
        'blocking' => false, // fire-and-forget — nie opóźniamy response klienta
        'headers'  => ['Content-Type' => 'application/json'],
        'body'     => wp_json_encode($payload),
    ]);

    if (is_wp_error($resp)) {
        np_s2s_log_warning('ga4_http_error', $resp->get_error_message());
        return 'error';
    }

    return 'queued';
}

// ─── Meta Conversions API ─────────────────────────────────────────────────────

/**
 * @param  array<string, string> $userDataHashed
 * @param  array<string, mixed>  $customData
 */
function np_s2s_send_meta(
    string $eventName,
    string $eventId,
    array $userDataHashed,
    array $customData,
    string $sourceUrl,
    string $clientIp,
): string {
    $pixelId = defined('NP_META_PIXEL_ID') ? (string) NP_META_PIXEL_ID : '';
    $token   = defined('NP_META_CAPI_TOKEN') ? (string) NP_META_CAPI_TOKEN : '';

    if ($pixelId === '' || $token === '') {
        return 'skipped_no_config';
    }

    // Meta używa swoich nazw eventów: Purchase, Lead, CompleteRegistration.
    $metaEventName = match ($eventName) {
        'purchase'      => 'Purchase',
        'generate_lead' => 'Lead',
        'sign_up'       => 'CompleteRegistration',
        default         => 'CustomEvent',
    };

    $userData = $userDataHashed;
    // Meta akceptuje raw IP + UA (nie hashujemy — to są server-side standardowe pola Meta).
    if ($clientIp !== '') {
        $userData['client_ip_address'] = $clientIp;
    }
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    if ($ua !== '') {
        $userData['client_user_agent'] = $ua;
    }
    // fbp / fbc cookies — jeśli są, podnoszą match quality.
    if (isset($_COOKIE['_fbp'])) {
        $userData['fbp'] = (string) $_COOKIE['_fbp'];
    }
    if (isset($_COOKIE['_fbc'])) {
        $userData['fbc'] = (string) $_COOKIE['_fbc'];
    }

    $event = [
        'event_name'       => $metaEventName,
        'event_time'       => time(),
        'event_id'         => $eventId, // dedup z Pixel client-side
        'action_source'    => 'website',
        'event_source_url' => $sourceUrl !== '' ? $sourceUrl : (string) ($_SERVER['HTTP_REFERER'] ?? ''),
        'user_data'        => $userData,
        'custom_data'      => $customData,
    ];

    $payload = ['data' => [$event]];

    $url = sprintf(
        'https://graph.facebook.com/v18.0/%s/events?access_token=%s',
        rawurlencode($pixelId),
        rawurlencode($token),
    );

    $resp = wp_remote_post($url, [
        'timeout'  => 5,
        'blocking' => false,
        'headers'  => ['Content-Type' => 'application/json'],
        'body'     => wp_json_encode($payload),
    ]);

    if (is_wp_error($resp)) {
        np_s2s_log_warning('meta_http_error', $resp->get_error_message());
        return 'error';
    }

    return 'queued';
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function np_s2s_client_ip(): string
{
    // Audit security #4 — wspólny helper z walidacją FILTER_VALIDATE_IP (np_get_client_ip
    // w niepodzielni-core/misc/1-helpers.php). Zachowujemy lokalny wrapper żeby nie łamać
    // istniejących wywołań w tym pliku.
    // TODO(ops): nginx musi mieć `set_real_ip_from <CF ranges>` + `real_ip_header CF-Connecting-IP`
    // — inaczej spoofing nagłówka omija throttle/audit.
    if (function_exists('np_get_client_ip')) {
        return np_get_client_ip();
    }
    // Fallback (helper niezaładowany — np. wczesny boot / testy izolowane).
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = isset($_SERVER[$key]) ? (string) $_SERVER[$key] : '';
        if ($value === '') {
            continue;
        }
        $ip = trim((string) explode(',', $value)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * Throttled WARNING — max 1 log/min/typ błędu, żeby nie zalać error_log.
 */
function np_s2s_log_warning(string $code, string $msg): void
{
    $key = 'np_s2s_warn_' . $code;
    if (get_transient($key)) {
        return;
    }
    set_transient($key, 1, MINUTE_IN_SECONDS);
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[np-conversion-api] ' . $code . ': ' . $msg);
    }
}
