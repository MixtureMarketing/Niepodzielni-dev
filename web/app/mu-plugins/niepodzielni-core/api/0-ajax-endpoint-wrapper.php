<?php

/**
 * Wrapper dla AJAX endpointów — eliminuje boilerplate (nonce/cap/JSON envelope/rate-limit).
 *
 * Decyzja: handler dostaje już zwalidowane wejście i może po prostu zwrócić tablicę
 * (→ wp_send_json_success) albo rzucić wyjątek (→ wp_send_json_error). Może też
 * nadal wywoływać wp_send_json_* ręcznie — wrapper tego nie blokuje, bo migrowane
 * endpointy używają wielu ścieżek wyjścia (try/catch z różnymi kodami HTTP).
 *
 * Cel: jedno miejsce na nonce/cap/rate-limit, przy zachowaniu autonomii handlerów
 * w warstwie odpowiedzi. Wrapper jest AJAX-only (admin-ajax.php) — REST endpointy
 * (`register_rest_route`) mają własną walidację permission_callback i nie są
 * objęte tym refaktorem.
 *
 * Konfiguracja:
 *   - public        bool    (false)  → rejestruje też wp_ajax_nopriv_*
 *   - nonce_action  ?string (null)   → null = brak weryfikacji nonce (read-only public)
 *   - nonce_field   string  ('nonce')→ nazwa pola w $_POST/$_REQUEST z nonce
 *   - capability    ?string (null)   → null = brak check, np. 'manage_options'
 *   - auth_callback ?callable (null) → custom guard; dostaje request, zwraca bool
 *   - rate_limit    ?int    (null)   → wywołań/min/IP (transient np_rl_<action>_<ip>)
 *
 * Output JSON:
 *   - Success:        { success: true, data: ... }                  (200)
 *   - invalid_nonce:  { success: false, data: { error: ... } }     (403)
 *   - forbidden:      { success: false, data: { error: ... } }     (403)
 *   - rate_limited:   { success: false, data: { error, retry_after } } (429)
 *   - exception:      { success: false, data: { error: <msg> } }   (500)
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! function_exists('np_ajax_endpoint')) {
    /**
     * @param string                $action  Nazwa akcji AJAX (bez prefiksu wp_ajax_).
     * @param array<string, mixed>  $config  Konfiguracja (patrz docblock pliku).
     * @param callable              $handler function(array $request): mixed — return array → success.
     */
    function np_ajax_endpoint(string $action, array $config, callable $handler): void
    {
        $public        = (bool) ($config['public']       ?? false);
        $nonce_action  = $config['nonce_action']         ?? null;
        $nonce_field   = (string) ($config['nonce_field'] ?? 'nonce');
        $capability    = $config['capability']           ?? null;
        $auth_callback = $config['auth_callback']        ?? null;
        $rate_limit    = $config['rate_limit']           ?? null;

        $callback = static function () use ($action, $nonce_action, $nonce_field, $capability, $auth_callback, $rate_limit, $handler): void {
            // 1. Nonce — opcjonalny (część endpointów to read-only z page-cache).
            if ($nonce_action !== null) {
                if (! check_ajax_referer((string) $nonce_action, $nonce_field, false)) {
                    status_header(403);
                    wp_send_json_error(['error' => 'invalid_nonce'], 403);
                }
            }

            // 2. Capability check.
            if ($capability !== null && ! current_user_can((string) $capability)) {
                status_header(403);
                wp_send_json_error(['error' => 'forbidden'], 403);
            }

            // 3. Custom auth callback (np. ownership psychologa po post_author).
            if (is_callable($auth_callback) && ! $auth_callback($_REQUEST)) {
                status_header(403);
                wp_send_json_error(['error' => 'forbidden'], 403);
            }

            // 4. Rate limit per IP (best-effort, transient-based).
            if ($rate_limit !== null && (int) $rate_limit > 0) {
                $ip  = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
                $key = 'np_rl_' . $action . '_' . md5($ip);
                $hits = (int) get_transient($key);
                if ($hits >= (int) $rate_limit) {
                    status_header(429);
                    wp_send_json_error(['error' => 'rate_limited', 'retry_after' => 60], 429);
                }
                set_transient($key, $hits + 1, MINUTE_IN_SECONDS);
            }

            // 5. Handler — może rzucić wyjątek lub zwrócić tablicę.
            try {
                $result = $handler($_REQUEST);
            } catch (\Throwable $e) {
                status_header(500);
                wp_send_json_error(['error' => $e->getMessage()], 500);
            }

            // Handler mógł sam wywołać wp_send_json_* (legacy ścieżki) → tu już nie dojdziemy.
            if (is_array($result) || is_object($result)) {
                wp_send_json_success($result);
            }

            // null / brak return → handler obsłużył response samodzielnie; nic nie robimy.
        };

        add_action('wp_ajax_' . $action, $callback);
        if ($public) {
            add_action('wp_ajax_nopriv_' . $action, $callback);
        }
    }
}
