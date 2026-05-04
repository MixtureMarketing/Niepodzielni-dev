<?php

/**
 * Niepodzielni Forms — REST API
 *
 * POST /wp-json/niepodzielni/v1/forms/{form_id}/submit
 * POST /wp-json/niepodzielni/v1/forms/{form_id}/verify
 */

if (! defined('ABSPATH')) {
    exit;
}

// ─── Rejestr formularzy ───────────────────────────────────────────────────────

/**
 * Zwraca mapę form_id => FQCN handlera.
 * Inne wtyczki/motyw mogą dodawać własne handlery przez filtr `np_form_handlers`.
 *
 * @return array<string, class-string<\Niepodzielni\Forms\BaseFormHandler>>
 */
function np_get_form_handlers(): array
{
    return apply_filters('np_form_handlers', [
        'contact' => \Niepodzielni\Forms\ContactForm::class,
    ]);
}

/**
 * Zwraca instancję handlera dla podanego form_id lub null jeśli nie istnieje.
 */
function np_resolve_form_handler(string $formId): ?\Niepodzielni\Forms\BaseFormHandler
{
    $handlers = np_get_form_handlers();
    if (! isset($handlers[$formId])) {
        return null;
    }
    return new $handlers[$formId]();
}

// ─── Weryfikacja CF Turnstile ─────────────────────────────────────────────────

function np_verify_turnstile(string $token, string $remoteIp = ''): bool
{
    $secret = defined('NP_CF_TURNSTILE_SECRET')
        ? (string) NP_CF_TURNSTILE_SECRET
        : (string) get_option('np_cf_turnstile_secret', '');

    // Jeśli sekret nie jest skonfigurowany — przepuść (tryb deweloperski)
    if (! $secret) {
        return true;
    }

    $body = ['secret' => $secret, 'response' => $token];
    if ($remoteIp) {
        $body['remoteip'] = $remoteIp;
    }

    $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 10,
        'body'    => $body,
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    return (bool) ($data['success'] ?? false);
}

// ─── Rejestracja endpointów ───────────────────────────────────────────────────

add_action('rest_api_init', function (): void {

    // POST /wp-json/niepodzielni/v1/forms/{form_id}/submit
    register_rest_route('niepodzielni/v1', '/forms/(?P<form_id>[a-z0-9_-]+)/submit', [
        'methods'             => 'POST',
        'callback'            => 'np_forms_handle_submit',
        'permission_callback' => '__return_true',
        'args'                => [
            'form_id' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ],
        ],
    ]);

    // POST /wp-json/niepodzielni/v1/forms/{form_id}/verify
    register_rest_route('niepodzielni/v1', '/forms/(?P<form_id>[a-z0-9_-]+)/verify', [
        'methods'             => 'POST',
        'callback'            => 'np_forms_handle_verify',
        'permission_callback' => '__return_true',
        'args'                => [
            'form_id' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ],
        ],
    ]);
});

// ─── Callback: submit ─────────────────────────────────────────────────────────

function np_forms_handle_submit(\WP_REST_Request $request): \WP_REST_Response
{
    $formId  = sanitize_key($request->get_param('form_id'));
    $handler = np_resolve_form_handler($formId);

    if (! $handler) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Nieznany formularz.',
        ], 404);
    }

    // Weryfikacja CF Turnstile
    $turnstileToken = (string) $request->get_param('cf-turnstile-response');
    $remoteIp       = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (! np_verify_turnstile($turnstileToken, $remoteIp)) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Weryfikacja anty-spam nie powiodła się. Odśwież stronę i spróbuj ponownie.',
        ], 400);
    }

    // Pobierz i zwaliduj dane formularza
    $body = $request->get_json_params();
    if (! is_array($body)) {
        $body = (array) $request->get_params();
    }

    $result = $handler->validate($body);

    if (! empty($result['errors'])) {
        return new \WP_REST_Response([
            'status' => 'error',
            'errors' => $result['errors'],
        ], 422);
    }

    // Zapis do bazy
    $submissionId = 0;
    if ($handler->shouldSaveToDb()) {
        $sourceUrl    = sanitize_url((string) ($body['_source_url'] ?? wp_get_referer()));
        $submissionId = $handler->saveSubmission($result['sanitized'], $sourceUrl);

        if (! $submissionId) {
            return new \WP_REST_Response([
                'status'  => 'error',
                'message' => 'Wystąpił błąd podczas zapisu. Spróbuj ponownie.',
            ], 500);
        }
    }

    // Weryfikacja e-mail (OTP)?
    if ($handler->requiresVerification() && $submissionId) {
        $sent = $handler->generateAndSendOTP($submissionId);

        if (! $sent) {
            return new \WP_REST_Response([
                'status'  => 'error',
                'message' => 'Nie udało się wysłać kodu weryfikacyjnego. Sprawdź adres e-mail.',
            ], 500);
        }

        return new \WP_REST_Response([
            'status'        => 'requires_verification',
            'submission_id' => $submissionId,
            'message'       => 'Na Twój adres e-mail wysłaliśmy kod weryfikacyjny.',
        ], 200);
    }

    // Wyślij maile od razu (brak OTP)
    if ($submissionId) {
        $handler->sendEmails($submissionId);
    }

    return new \WP_REST_Response([
        'status'  => 'success',
        'message' => 'Dziękujemy! Twoje zgłoszenie zostało przyjęte.',
    ], 200);
}

// ─── Callback: verify OTP ────────────────────────────────────────────────────

function np_forms_handle_verify(\WP_REST_Request $request): \WP_REST_Response
{
    $formId  = sanitize_key($request->get_param('form_id'));
    $handler = np_resolve_form_handler($formId);

    if (! $handler) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Nieznany formularz.',
        ], 404);
    }

    $body         = $request->get_json_params() ?: (array) $request->get_params();
    $submissionId = (int) ($body['submission_id'] ?? 0);
    $otpCode      = sanitize_text_field((string) ($body['otp_code'] ?? ''));

    if (! $submissionId || ! $otpCode) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Brakuje wymaganych danych.',
        ], 400);
    }

    // Sprawdź, czy zgłoszenie należy do tego formularza
    $storedFormId = get_post_meta($submissionId, '_form_id', true);
    if ($storedFormId !== $formId) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Nieprawidłowe zgłoszenie.',
        ], 400);
    }

    $verified = $handler->verifyOTP($submissionId, $otpCode);

    if (! $verified) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Kod jest nieprawidłowy lub wygasł. Spróbuj ponownie.',
        ], 400);
    }

    return new \WP_REST_Response([
        'status'  => 'success',
        'message' => 'Weryfikacja zakończona! Twoje zgłoszenie zostało przyjęte.',
    ], 200);
}

// ─── Enqueue: skrypt frontendowy ──────────────────────────────────────────────

add_action('wp_enqueue_scripts', 'np_forms_enqueue_assets');

function np_forms_enqueue_assets(): void
{
    $jsPath = get_template_directory() . '/resources/js/NiepodzielniForms.js';
    $jsUrl  = get_template_directory_uri() . '/resources/js/NiepodzielniForms.js';

    if (! file_exists($jsPath)) {
        return;
    }

    wp_enqueue_script(
        'niepodzielni-forms',
        $jsUrl,
        [],
        (string) filemtime($jsPath),
        true,
    );

    $siteKey = defined('NP_CF_TURNSTILE_SITE_KEY')
        ? (string) NP_CF_TURNSTILE_SITE_KEY
        : (string) get_option('np_cf_turnstile_site_key', '');

    wp_localize_script('niepodzielni-forms', 'NpFormsConfig', [
        'apiBase'       => esc_url_raw(rest_url('niepodzielni/v1/forms')),
        'turnstileSiteKey' => $siteKey,
    ]);
}
