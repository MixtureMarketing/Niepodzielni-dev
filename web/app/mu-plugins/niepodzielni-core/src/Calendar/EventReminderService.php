<?php

declare(strict_types=1);

namespace Niepodzielni\Calendar;

/**
 * Event reminder service — wysyłka emaili T-24h.
 *
 * Wywoływany przez cron (api/73-events-reminders-cron.php) co godzinę.
 * Idempotency: `sent_at IS NULL` filter + UPDATE po wysłaniu.
 *
 * Stałe założenia:
 *   - Reminder odpala się dla wydarzeń, których `data` = jutro (Y-m-d).
 *   - Nie zapisuje "delivered" — `wp_mail` może zwrócić true zanim SMTP
 *     potwierdzi; sukces oznacza "spróbowałem". Lepiej raz wysłać
 *     i zostawić niż wysyłać 24× po retry.
 */
class EventReminderService
{
    /**
     * Skanuje pending reminders dla wydarzeń jutro i wysyła emaile.
     *
     * @return int  liczba wysłanych emaili
     */
    public function sendDueReminders(): int
    {
        global $wpdb;
        $table  = $wpdb->prefix . 'np_event_reminders';
        $tomorrow = date('Y-m-d', strtotime('+1 day', current_time('timestamp')));

        // SELECT pending reminders dla wydarzeń jutro.
        // Łączymy z postmeta, żeby zfiltrować po `data` jednym query.
        $sql = $wpdb->prepare(
            "SELECT r.id, r.email, r.event_post_id
             FROM {$table} r
             INNER JOIN {$wpdb->postmeta} pm
                 ON pm.post_id = r.event_post_id
                 AND pm.meta_key = 'data'
                 AND pm.meta_value = %s
             INNER JOIN {$wpdb->posts} p
                 ON p.ID = r.event_post_id
                 AND p.post_status = 'publish'
             WHERE r.sent_at IS NULL
             ORDER BY r.created_at ASC
             LIMIT 500",
            $tomorrow,
        );

        $pending = $wpdb->get_results($sql);
        if (empty($pending)) {
            return 0;
        }

        $sent = 0;
        foreach ($pending as $row) {
            $eventId = (int) $row->event_post_id;
            $email   = (string) $row->email;
            $reminderId = (int) $row->id;

            // Sprawdź czy event nie został odwołany w międzyczasie.
            $status = (string) get_post_meta($eventId, 'status', true);
            if ($status === 'Odwołane') {
                // Oznacz jako "wysłany" żeby nie próbować ponownie.
                $wpdb->update(
                    $table,
                    ['sent_at' => current_time('mysql')],
                    ['id' => $reminderId],
                    ['%s'],
                    ['%d'],
                );
                continue;
            }

            $delivered = $this->sendOne($email, $eventId);
            if ($delivered) {
                $wpdb->update(
                    $table,
                    ['sent_at' => current_time('mysql')],
                    ['id' => $reminderId],
                    ['%s'],
                    ['%d'],
                );
                $sent++;
            }
        }

        return $sent;
    }

    private function sendOne(string $email, int $eventId): bool
    {
        $post = get_post($eventId);
        if (! $post) return false;

        $title    = $post->post_title;
        $url      = (string) get_permalink($eventId);
        $date     = (string) get_post_meta($eventId, 'data', true);
        $timeStart = $post->post_type === 'wydarzenia'
            ? (string) get_post_meta($eventId, 'godzina_rozpoczecia', true)
            : (string) get_post_meta($eventId, 'godzina', true);
        $location = (string) get_post_meta($eventId, 'lokalizacja', true);
        if ($post->post_type === 'wydarzenia') {
            $miasto = (string) get_post_meta($eventId, 'miasto', true);
            if ($miasto !== '' && $location !== '') {
                $location = $miasto . ', ' . $location;
            } elseif ($miasto !== '') {
                $location = $miasto;
            }
        }

        $unsubscribeUrl = add_query_arg([
            'np_unsubscribe' => $this->unsubscribeToken($email, $eventId),
            'event'          => $eventId,
        ], home_url('/'));

        $siteName = (string) get_bloginfo('name');
        $subject  = sprintf('[%s] Przypomnienie: jutro %s', $siteName, $title);

        $html = $this->renderTemplate([
            'title'         => $title,
            'date'          => $this->formatDatePl($date),
            'time'          => $timeStart,
            'location'      => $location,
            'url'           => $url,
            'unsubscribeUrl' => $unsubscribeUrl,
            'siteName'      => $siteName,
        ]);

        $htmlFilter = static fn(): string => 'text/html';
        add_filter('wp_mail_content_type', $htmlFilter);

        $result = wp_mail($email, $subject, $html);

        remove_filter('wp_mail_content_type', $htmlFilter);

        if (! $result && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[EventReminder] wp_mail failed for {$email} (event {$eventId})");
        }

        return (bool) $result;
    }

    private function renderTemplate(array $data): string
    {
        $tpl = __DIR__ . '/templates/reminder-email.php';
        if (! file_exists($tpl)) {
            return $data['title'];
        }
        ob_start();
        extract($data, EXTR_SKIP);
        require $tpl;
        return (string) ob_get_clean();
    }

    /**
     * Token unsubscribe — md5(email + post_id + AUTH_KEY).
     * Endpoint /np_unsubscribe weryfikuje i usuwa wpis z DB.
     */
    public function unsubscribeToken(string $email, int $eventId): string
    {
        $secret = defined('AUTH_KEY') ? AUTH_KEY : 'fallback';
        return md5($email . '|' . $eventId . '|' . $secret);
    }

    private function formatDatePl(string $date): string
    {
        if ($date === '') return '';
        return date_i18n('j F Y', strtotime($date) ?: time());
    }
}
