<?php

/**
 * Retention policy — codzienny cron usuwa stare zgłoszenia formularzy oraz
 * (przez `np_audit_purge_old`) stare wpisy w audit logu.
 *
 * Konfigurowalne przez filtr `np_zgloszenia_retention_days` (default: 90).
 */

if (! defined('ABSPATH')) {
    exit;
}

const NP_RETENTION_HOOK = 'np_purge_old_zgloszenia';

add_action('init', static function (): void {
    if (! wp_next_scheduled(NP_RETENTION_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', NP_RETENTION_HOOK);
    }
});

// Cleanup CPT `zgloszenie` starszych niż N dni.  Trzymamy `delete_post(force=true)`
// (omija kosz) — zgłoszenia przeszły już przez audit log.
add_action(NP_RETENTION_HOOK, static function (): void {
    $days = (int) apply_filters('np_zgloszenia_retention_days', 90);
    if ($days < 1) {
        return;
    }

    $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days") ?: time());

    $query = new \WP_Query([
        'post_type'      => 'zgloszenie',
        'post_status'    => 'any',
        'date_query'     => [['before' => $cutoff, 'inclusive' => true, 'column' => 'post_date_gmt']],
        'fields'         => 'ids',
        'posts_per_page' => 200,                 // batch
        'no_found_rows'  => true,
        'orderby'        => 'date',
        'order'          => 'ASC',
    ]);

    foreach ($query->posts as $postId) {
        wp_delete_post((int) $postId, true);
    }

    // Audit log retention (365 dni — patrz 14-audit-log.php).
    do_action('np_audit_purge_old');

    do_action('np_audit_event', [
        'action' => 'retention_purge',
        'meta'   => ['deleted_count' => count($query->posts), 'cutoff' => $cutoff],
    ]);
});

// Bezpieczne wyczyść harmonogram przy disable mu-plugina (jeśli kiedyś).
register_deactivation_hook(__FILE__, static function (): void {
    $timestamp = wp_next_scheduled(NP_RETENTION_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, NP_RETENTION_HOOK);
    }
});
