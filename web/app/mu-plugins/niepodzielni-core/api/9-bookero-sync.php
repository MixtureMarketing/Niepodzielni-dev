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

/**
 * Synchronizuje WSZYSTKICH psychologów w jednym przebiegu.
 * Używana do ręcznego odświeżenia (AJAX) — nie podpięta pod cron.
 * Cron używa np_bookero_worker_sync() z offsetem (5 na raz).
 */
function np_bookero_sync_all(): void
{
    update_option('np_bookero_last_cron_run', time(), false);

    $psycholodzy = get_posts([
        'post_type'      => 'psycholog',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ]);

    foreach ($psycholodzy as $id) {
        $bk_pelny = get_post_meta($id, 'bookero_id_pelny', true);
        $bk_niski = get_post_meta($id, 'bookero_id_niski', true);

        $has_id = false;
        if ($bk_pelny) {
            $has_id = true;
            $avail  = np_bookero_get_availability((string) $bk_pelny, 'pelnoplatny');
            if ($avail['nearest'] !== '') {
                update_post_meta($id, 'najblizszy_termin_pelnoplatny', $avail['nearest']);
            } else {
                delete_post_meta($id, 'najblizszy_termin_pelnoplatny');
            }
            update_post_meta($id, 'bookero_slots_pelno', wp_json_encode($avail['dates']));
        }
        if ($bk_niski) {
            $has_id = true;
            $avail  = np_bookero_get_availability((string) $bk_niski, 'nisko');
            if ($avail['nearest'] !== '') {
                update_post_meta($id, 'najblizszy_termin_niskoplatny', $avail['nearest']);
            } else {
                delete_post_meta($id, 'najblizszy_termin_niskoplatny');
            }
            update_post_meta($id, 'bookero_slots_nisko', wp_json_encode($avail['dates']));
        }
        if ($has_id) {
            update_post_meta($id, 'np_termin_updated_at', time());
        }
    }
}
