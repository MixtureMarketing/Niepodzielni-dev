<?php

/**
 * Brute-force burst alert — natychmiastowe powiadomienie gdy w ciągu 1h
 * przekroczono próg lockoutów loginu (zwykle oznacza atak słownikowy).
 *
 * Hook `np_security_lockout` jest emitowany przez `13-login-throttle.php`
 * po każdym 5/15min lockout per IP. Tutaj liczymy je w sliding window
 * (transient) i wysyłamy alert do Discord raz na okno (transient flag
 * blokuje powtórzenia).
 *
 * Pełny digest leci codziennie z `17-audit-digest.php` — ten plik łapie
 * tylko sytuację „dzieje się coś niepokojącego TERAZ".
 */

if (! defined('ABSPATH')) {
    exit;
}

const NP_LOCKOUT_BURST_THRESHOLD = 20;
const NP_LOCKOUT_BURST_WINDOW    = HOUR_IN_SECONDS;

add_action('np_security_lockout', static function (array $payload): void {
    $countKey = 'np_lockout_burst_count';
    $sentKey  = 'np_lockout_burst_sent';

    $count = (int) get_transient($countKey);
    $count++;

    set_transient($countKey, $count, NP_LOCKOUT_BURST_WINDOW);

    // Próg przekroczony i jeszcze nie alertowano w tym oknie
    if ($count < NP_LOCKOUT_BURST_THRESHOLD) {
        return;
    }
    if (get_transient($sentKey)) {
        return;
    }

    set_transient($sentKey, 1, NP_LOCKOUT_BURST_WINDOW);

    $webhook = defined('NP_DISCORD_WEBHOOK_URL') ? (string) NP_DISCORD_WEBHOOK_URL : '';
    if ($webhook === '') {
        return;
    }

    $ip       = isset($payload['ip']) ? (string) $payload['ip'] : '?';
    $username = isset($payload['username']) ? (string) $payload['username'] : '?';

    wp_remote_post($webhook, [
        'timeout' => 5,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode([
            'username' => 'WordPress Audit',
            'content'  => sprintf(
                "🚨 **Brute-force alert** — %d+ lockoutów loginu w %d min.\nOstatnie IP: `%s`, login: `%s`.",
                NP_LOCKOUT_BURST_THRESHOLD,
                (int) (NP_LOCKOUT_BURST_WINDOW / 60),
                $ip,
                $username,
            ),
        ]),
    ]);

    do_action('np_audit_event', [
        'action' => 'lockout_burst_alert',
        'meta'   => [
            'count'    => $count,
            'ip'       => $ip,
            'username' => $username,
        ],
    ]);
});
