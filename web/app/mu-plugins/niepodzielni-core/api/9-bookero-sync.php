<?php

/**
 * Bookero Sync — harmonogram crona i pełna synchronizacja terminów
 */

if (! defined('ABSPATH')) {
    exit;
}

// --- Rejestracja niestandardowego interwału: co minutę ---
add_filter('cron_schedules', 'np_add_cron_schedules');

function np_add_cron_schedules(array $schedules): array
{
    $schedules['np_every_minute'] = [
        'interval' => 60,
        'display'  => 'Co minutę (Niepodzielni)',
    ];
    return $schedules;
}

// --- Planowanie crona: wymuszenie interwału co minutę ---
// Działa na wp (frontend) i admin_init (panel) — żeby pewniej złapać pierwsze żądanie.
add_action('wp', 'np_bookero_schedule_cron');
add_action('admin_init', 'np_bookero_schedule_cron');

function np_bookero_schedule_cron(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $crons            = _get_cron_array() ?: [];
    $current_interval = 0;
    $current_ts       = 0;

    foreach ($crons as $ts => $hooks) {
        if (isset($hooks[ BOOKERO_CRON_HOOK ])) {
            $event            = reset($hooks[ BOOKERO_CRON_HOOK ]);
            $current_interval = (int) ($event['interval'] ?? 0);
            $current_ts       = (int) $ts;
            break;
        }
    }

    // Poprawny harmonogram: interwał=60s i następne odpalenie w ciągu najbliższych 90s — nic nie rób.
    // Jeśli current_ts jest w przeszłości (zaległy), reschedule żeby nie odpalało się na każdym żądaniu.
    if ($current_interval === 60 && $current_ts >= time() - 90) {
        return;
    }

    // Usuń wszystkie stare/zaległe instancje i zaplanuj od teraz
    wp_clear_scheduled_hook(BOOKERO_CRON_HOOK);
    wp_schedule_event(time() + 60, 'np_every_minute', BOOKERO_CRON_HOOK);
}
