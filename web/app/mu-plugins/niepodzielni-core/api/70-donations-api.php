<?php

/**
 * Donations — bootstrap (Sprint 1, faza B: scaffolding).
 *
 * Tworzy tabelę wp_np_donations, eksponuje stałe i helpery konfiguracyjne.
 * REST endpointy (POST /donations/checkout, /webhook, /pit-pdf) zostaną
 * dodane w fazie C, gdy klucze testowe Stripe będą dostępne.
 *
 * Tabela wp_np_donations:
 *   id BIGINT UNSIGNED AUTO_INCREMENT PK
 *   stripe_event_id   VARCHAR(255) UNIQUE  — idempotency
 *   stripe_payment_intent_id VARCHAR(255)
 *   stripe_subscription_id   VARCHAR(255)
 *   stripe_customer_id VARCHAR(255)
 *   type ENUM('one_off','subscription','pit_15')
 *   amount_cents INT UNSIGNED
 *   currency CHAR(3) DEFAULT 'PLN'
 *   email VARCHAR(255)
 *   name  VARCHAR(255)
 *   status VARCHAR(40) DEFAULT 'pending'
 *   metadata JSON
 *   created_at, updated_at
 */

if (! defined('ABSPATH')) {
    exit;
}

const NP_DONATIONS_DB_VERSION = '1.0';

// ─── DB install / migrate ─────────────────────────────────────────────────────

add_action('plugins_loaded', 'np_donations_maybe_install_db', 5);

function np_donations_maybe_install_db(): void
{
    if (get_option('np_donations_db_version') === NP_DONATIONS_DB_VERSION) {
        return;
    }

    global $wpdb;
    $charsetCollate = $wpdb->get_charset_collate();
    $table          = $wpdb->prefix . 'np_donations';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        stripe_event_id VARCHAR(255) DEFAULT NULL,
        stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
        stripe_subscription_id VARCHAR(255) DEFAULT NULL,
        stripe_customer_id VARCHAR(255) DEFAULT NULL,
        type VARCHAR(20) NOT NULL,
        amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
        currency CHAR(3) NOT NULL DEFAULT 'PLN',
        email VARCHAR(255) DEFAULT NULL,
        name VARCHAR(255) DEFAULT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'pending',
        metadata LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_stripe_event_id (stripe_event_id),
        KEY idx_status (status),
        KEY idx_email (email),
        KEY idx_created (created_at)
    ) {$charsetCollate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('np_donations_db_version', NP_DONATIONS_DB_VERSION, false);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Zwraca KRS Fundacji z env (priorytet) lub wp_option (fallback).
 */
function np_donations_krs(): string
{
    $env = getenv('NP_FUNDACJA_KRS');
    if ($env !== false && $env !== '') {
        return (string) $env;
    }
    return (string) get_option('np_fundacja_krs', '');
}

function np_donations_foundation_name(): string
{
    return (string) get_option('np_fundacja_name', 'Fundacja Niepodzielni');
}

function np_donations_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'np_donations';
}

/**
 * @return array{configured: bool, missing: string[]}
 */
function np_donations_config_status(): array
{
    $missing = [];

    if (\Niepodzielni\Donations\StripeClient::resolveSecretKey() === '') {
        $missing[] = 'NP_STRIPE_SECRET_KEY';
    }
    if (\Niepodzielni\Donations\StripeClient::resolvePublishableKey() === '') {
        $missing[] = 'NP_STRIPE_PUBLISHABLE_KEY';
    }
    if (\Niepodzielni\Donations\StripeClient::resolveWebhookSecret() === '') {
        $missing[] = 'NP_STRIPE_WEBHOOK_SECRET';
    }
    if (np_donations_krs() === '') {
        $missing[] = 'NP_FUNDACJA_KRS (lub wp_option np_fundacja_krs)';
    }

    return [
        'configured' => count($missing) === 0,
        'missing'    => $missing,
    ];
}

// ─── REST: POST /donations/pit-pdf ─────────────────────────────────────────────
// Generator PDF nie wymaga konfiguracji Stripe — działa od razu po composer install.

add_action('rest_api_init', function (): void {
    register_rest_route('niepodzielni/v1', '/donations/pit-pdf', [
        'methods'             => 'POST',
        'callback'            => 'np_donations_pit_pdf',
        'permission_callback' => '__return_true',
        'args'                => [
            'donor_name' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'cf-turnstile-response' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);
});

function np_donations_pit_pdf(\WP_REST_Request $request): \WP_REST_Response
{
    $donorName = (string) $request->get_param('donor_name');
    $turnstile = (string) $request->get_param('cf-turnstile-response');
    $remoteIp  = function_exists('np_get_client_ip') ? np_get_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    // Audit security #1 — fail-closed: brak helpera lub błąd weryfikacji = blokuj.
    // (W dev pomijanie obsługuje sam helper przez WP_ENV !== production.)
    if (! function_exists('np_cf_turnstile_verify') || ! np_cf_turnstile_verify($turnstile, $remoteIp)) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Weryfikacja anty-spam nie powiodła się.',
        ], 400);
    }

    $krs = np_donations_krs();
    if ($krs === '') {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Brak KRS Fundacji w konfiguracji.',
        ], 503);
    }

    try {
        $generator = new \Niepodzielni\Donations\PdfGenerator();
        $binary    = $generator->renderPitInstruction([
            'krs'             => $krs,
            'foundation_name' => np_donations_foundation_name(),
            'donor_name'      => $donorName,
            'amount'          => null,
        ]);
    } catch (\Niepodzielni\Donations\DonationsApiException $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Donations PIT PDF] ' . $e->getMessage() . ' (context=' . $e->context . ')');
        }
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Nie udało się wygenerować PDF. Spróbuj ponownie później.',
        ], 500);
    }

    $filename = 'instrukcja-1-5-procent-pit-niepodzielni-' . date('Y-m-d') . '.pdf';

    // Zwracamy binary bezpośrednio — pomija WP_REST_Response serializację.
    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($binary));
    echo $binary;
    exit;
}

// ─── Admin notice gdy brak konfiguracji ───────────────────────────────────────

add_action('admin_notices', 'np_donations_admin_notice');

function np_donations_admin_notice(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    // Tylko na ekranie ustawień Niepodzielni — żeby nie spamować admina.
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (! $screen || ! str_contains((string) $screen->id, 'niepodzielni-settings')) {
        return;
    }

    $status = np_donations_config_status();
    if ($status['configured']) {
        return;
    }

    $missing = implode(', ', array_map('esc_html', $status['missing']));
    echo '<div class="notice notice-warning"><p><strong>Donations:</strong> brak konfiguracji — '
        . wp_kses_post($missing) . '. Sekcja Stripe poniżej.</p></div>';
}
