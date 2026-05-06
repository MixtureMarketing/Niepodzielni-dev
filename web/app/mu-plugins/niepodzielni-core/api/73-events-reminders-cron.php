<?php

/**
 * Calendar — cron rejestracja + unsubscribe handler.
 *
 * Cron `np_event_reminders_cron` (hourly) wywołuje EventReminderService.
 * Unsubscribe: `?np_unsubscribe={token}&event={id}` — usuwa wpis z DB
 * (token = md5(email + event_id + AUTH_KEY) — bez ujawnienia emaila).
 */

if (! defined('ABSPATH')) {
    exit;
}

const NP_EVENT_REMINDERS_CRON_HOOK = 'np_event_reminders_cron';

// ─── Cron schedule ────────────────────────────────────────────────────────────

add_action('wp', 'np_event_reminders_schedule_cron');

function np_event_reminders_schedule_cron(): void
{
    if (! wp_next_scheduled(NP_EVENT_REMINDERS_CRON_HOOK)) {
        // Hourly built-in interval — nie tworzymy nowego.
        wp_schedule_event(time() + 60, 'hourly', NP_EVENT_REMINDERS_CRON_HOOK);
    }
}

add_action(NP_EVENT_REMINDERS_CRON_HOOK, 'np_event_reminders_run');

function np_event_reminders_run(): void
{
    if (! class_exists(\Niepodzielni\Calendar\EventReminderService::class)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[EventReminders] EventReminderService class not found — composer dump-autoload?');
        }
        return;
    }

    $service = new \Niepodzielni\Calendar\EventReminderService();
    $sent    = $service->sendDueReminders();

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[EventReminders] sent {$sent} reminder(s)");
    }
}

// Cleanup przy deaktywacji mu-plugina (najczęściej never — mu-plugins nie deaktywuje się standardowo).
register_deactivation_hook(__FILE__, 'np_event_reminders_unschedule');

function np_event_reminders_unschedule(): void
{
    $next = wp_next_scheduled(NP_EVENT_REMINDERS_CRON_HOOK);
    if ($next) {
        wp_unschedule_event($next, NP_EVENT_REMINDERS_CRON_HOOK);
    }
}

// ─── Unsubscribe handler ──────────────────────────────────────────────────────

add_action('init', 'np_event_reminders_handle_unsubscribe');

function np_event_reminders_handle_unsubscribe(): void
{
    if (empty($_GET['np_unsubscribe']) || empty($_GET['event'])) {
        return;
    }

    $token   = sanitize_text_field((string) $_GET['np_unsubscribe']);
    $eventId = absint($_GET['event']);

    if ($token === '' || $eventId <= 0) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'np_event_reminders';

    // Pobierz wszystkie subskrypcje dla tego wydarzenia, znajdź matching email po tokenie.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, email FROM {$table} WHERE event_post_id = %d",
            $eventId,
        )
    );

    $service = new \Niepodzielni\Calendar\EventReminderService();
    $matchedId = null;
    foreach ($rows as $row) {
        if (hash_equals($service->unsubscribeToken((string) $row->email, $eventId), $token)) {
            $matchedId = (int) $row->id;
            break;
        }
    }

    if ($matchedId !== null) {
        $wpdb->delete($table, ['id' => $matchedId], ['%d']);
        wp_die(
            '<p>Twoje przypomnienie zostało anulowane. Nie wyślemy już maila o tym wydarzeniu.</p>'
            . '<p><a href="' . esc_url(home_url('/')) . '">Wróć na stronę główną</a></p>',
            'Wypisano',
            ['response' => 200],
        );
    } else {
        wp_die(
            '<p>Link wypisu jest nieprawidłowy lub wygasł.</p>',
            'Błąd',
            ['response' => 400],
        );
    }
}
