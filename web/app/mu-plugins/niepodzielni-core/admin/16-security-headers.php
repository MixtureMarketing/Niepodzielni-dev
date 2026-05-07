<?php

/**
 * Globalne nagłówki bezpieczeństwa wystawiane przez PHP/WP runtime.
 *
 * Trellis nginx już wystawia: HSTS, X-Frame-Options, X-Content-Type-Options,
 * X-XSS-Protection (per `wordpress-site.conf.j2`).  Tu uzupełniamy:
 *   - Referrer-Policy
 *   - Permissions-Policy
 *   - Content-Security-Policy-Report-Only (do wystrojenia listy domen,
 *     potem przepiąć na enforce).
 *
 * Filtr `np_security_headers` pozwala motywom/innym mu-pluginom dorzucić
 * lub zmienić nagłówki bez modyfikacji tego pliku.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('send_headers', static function (): void {
    // Pomijamy wp-cli i nie-HTTP kontekst.
    if (defined('DOING_CRON') || (defined('WP_CLI') && WP_CLI) || headers_sent()) {
        return;
    }

    $defaults = [
        'Referrer-Policy'    => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
        // Lista domen dobrana pod aktualne integracje:
        //  - Cloudflare Turnstile (https://challenges.cloudflare.com)
        //  - Bookero widget   (https://*.bookero.pl)
        //  - jsdelivr (flag-icons)
        //  - Worker AI agent (env-specific URL — frontend wstawia go via NpAiBot)
        // Tryb Report-Only: na początku obserwujemy raporty, dopiero potem
        // przełączamy na Content-Security-Policy (enforce).
        'Content-Security-Policy-Report-Only' => implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com",
            "img-src 'self' data: https: blob:",
            "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com",
            "connect-src 'self' https://challenges.cloudflare.com https://*.workers.dev https://*.cloudflare.com",
            "frame-src https://challenges.cloudflare.com https://*.bookero.pl",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]),
    ];

    /** @var array<string, string> $headers */
    $headers = (array) apply_filters('np_security_headers', $defaults);

    foreach ($headers as $name => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        // `false` w drugim argumencie nie nadpisuje istniejącego nagłówka tego
        // samego typu — jeśli nginx coś już wystawił, my nie kolidujemy.
        header(sprintf('%s: %s', $name, $value), false);
    }
});
