<?php

/**
 * Audit digest — codzienny raport bezpieczeństwa.
 *
 * O 7:00 czasu serwera czyta z tabeli `wp_np_audit` (rejestrowanej przez
 * 14-audit-log.php) zdarzenia z ostatnich 24h, formatuje krótki raport,
 * wysyła:
 *   1. Email do `admin_email` (backup, archiwum).
 *   2. Discord webhook (`NP_DISCORD_WEBHOOK_URL`) — jeśli skonfigurowany.
 *
 * Discord oczekuje payloadu `{"username": "...", "content": "..."}`.
 * Treść owija się w trzy backticki dla monospace formatowania.
 *
 * Powiązania:
 *   - Tabela `wp_np_audit` z `14-audit-log.php` (PR #7).
 *   - Discord webhook „WordPress Audit" — sekcja 2 `docs/monitoring-runbook.md`.
 */

if (! defined('ABSPATH')) {
    exit;
}

const NP_AUDIT_DIGEST_HOOK = 'np_audit_digest_daily';

add_action('init', static function (): void {
    if (! wp_next_scheduled(NP_AUDIT_DIGEST_HOOK)) {
        // Codziennie o 7:00 czasu serwera (UTC w produkcji, raport mówi o "wczoraj").
        $first = strtotime('tomorrow 07:00') ?: time() + DAY_IN_SECONDS;
        wp_schedule_event($first, 'daily', NP_AUDIT_DIGEST_HOOK);
    }
});

add_action(NP_AUDIT_DIGEST_HOOK, static function (): void {
    global $wpdb;
    $table = function_exists('np_audit_table_name')
        ? np_audit_table_name()
        : $wpdb->prefix . 'np_audit';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT action, COUNT(*) AS c
         FROM {$table}
         WHERE ts >= DATE_SUB(NOW(), INTERVAL %d HOUR)
         GROUP BY action",
        24,
    ));

    $counts = [];
    foreach ($rows as $row) {
        $counts[(string) $row->action] = (int) $row->c;
    }

    $topIps = $wpdb->get_results($wpdb->prepare(
        "SELECT ip, COUNT(*) AS c
         FROM {$table}
         WHERE action = 'login_failed' AND ts >= DATE_SUB(NOW(), INTERVAL %d HOUR)
         GROUP BY ip
         ORDER BY c DESC
         LIMIT 5",
        24,
    ));

    $report = "Niepodzielni — bezpieczeństwo wczoraj\n\n"
        . sprintf("Udane logowania:     %d\n", $counts['login_success']   ?? 0)
        . sprintf("Nieudane próby:      %d\n", $counts['login_failed']    ?? 0)
        . sprintf("Zablokowane IP:      %d\n", $counts['login_lockout']   ?? 0)
        . sprintf("Reset hasła:         %d\n", $counts['password_reset']  ?? 0)
        . sprintf("Nowi użytkownicy:    %d\n", $counts['user_registered'] ?? 0);

    if (! empty($topIps)) {
        $report .= "\nTop IP nieudane (24h):\n";
        foreach ($topIps as $row) {
            $report .= sprintf("  %s — %d prób\n", (string) $row->ip, (int) $row->c);
        }
    }

    // ── Email backup ────────────────────────────────────────────────────────────
    wp_mail(
        (string) get_option('admin_email'),
        'Audit digest — ' . wp_date('Y-m-d'),
        $report,
    );

    // ── Discord webhook (jeśli skonfigurowany) ──────────────────────────────────
    $webhook = defined('NP_DISCORD_WEBHOOK_URL') ? (string) NP_DISCORD_WEBHOOK_URL : '';
    if ($webhook !== '') {
        // Discord ma limit 2000 znaków na content — `wp_np_audit` z digest mieści
        // się w ~500, ale zostawiamy zapas i twardy slice na wypadek edge-case.
        $body = "```\n" . substr($report, 0, 1900) . "```";

        wp_remote_post($webhook, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'username' => 'WordPress Audit',
                'content'  => $body,
            ]),
        ]);
    }

    // Wpisanie do audit log że digest poszedł — przyda się w kolejnym digest do weryfikacji
    do_action('np_audit_event', [
        'action' => 'audit_digest_sent',
        'meta'   => $counts,
    ]);
});

// Bezpieczne odpięcie crona przy disable mu-plugina
register_deactivation_hook(__FILE__, static function (): void {
    $timestamp = wp_next_scheduled(NP_AUDIT_DIGEST_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, NP_AUDIT_DIGEST_HOOK);
    }
});
