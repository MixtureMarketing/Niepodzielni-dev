<?php

/**
 * Bookero Worker Sync — paginacja crona, 5 psychologów na minutę
 *
 * Podpięty do BOOKERO_CRON_HOOK (wywoływanego co minutę).
 * Każdy przebieg przetwarza kolejnych 5 psychologów wg offsetu.
 * Po przejściu przez wszystkich — reset offsetu do 0.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action(BOOKERO_CRON_HOOK, 'np_bookero_worker_sync', 10);

function np_bookero_worker_sync(): void
{
    update_option('np_bookero_last_cron_run', time(), false);

    $offset  = (int) get_option(BOOKERO_OFFSET_KEY, 0);
    $per_run = 3;

    $psycholodzy = get_posts([
        'post_type'      => 'psycholog',
        'posts_per_page' => $per_run,
        'offset'         => $offset,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    if (empty($psycholodzy)) {
        // Koniec listy — zacznij od nowa
        update_option(BOOKERO_OFFSET_KEY, 0);
        return;
    }

    // Zapisz nowy offset PRZED przetwarzaniem — jeśli PHP timeout przerwie pętlę,
    // następny cron zacznie od kolejnej paczki a nie znów od tej samej.
    update_option(BOOKERO_OFFSET_KEY, $offset + $per_run);

    foreach ($psycholodzy as $id) {
        // Krótka pauza między psychologami — zapobiega throttlingowi Bookero (HTTP 429)
        usleep(300000); // 0.3s

        $bk_pelny = get_post_meta($id, 'bookero_id_pelny', true);
        $bk_niski = get_post_meta($id, 'bookero_id_niski', true);

        $has_id = false;
        if ($bk_pelny) {
            $has_id = true;
            // Jeden zestaw 3 HTTP calls (getMonth × 3 miesiące) zamiast 3+3
            $avail  = np_bookero_get_availability((string) $bk_pelny, 'pelnoplatny');
            if ($avail['nearest'] !== '') {
                update_post_meta($id, 'najblizszy_termin_pelnoplatny', $avail['nearest']);
            } else {
                // Brak terminów — usuń stary wpis żeby nie wyświetlać nieaktualnych danych
                delete_post_meta($id, 'najblizszy_termin_pelnoplatny');
            }
            update_post_meta($id, 'bookero_slots_pelno', wp_json_encode($avail['dates']));

            // Pre-warm godzin dla najbliższej daty — tylko gdy nie ma ich jeszcze w DB
            if (! empty($avail['dates'])) {
                $nearest_date = $avail['dates'][0];
                if (np_bookero_get_cached_hours((int) $id, 'pelnoplatny', $nearest_date) === null) {
                    $hours = np_bookero_get_month_day((string) $bk_pelny, 'pelnoplatny', $nearest_date);
                    np_bookero_cache_hours((int) $id, 'pelnoplatny', $nearest_date, $hours);
                }
            }
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

            if (! empty($avail['dates'])) {
                $nearest_date = $avail['dates'][0];
                if (np_bookero_get_cached_hours((int) $id, 'nisko', $nearest_date) === null) {
                    $hours = np_bookero_get_month_day((string) $bk_niski, 'nisko', $nearest_date);
                    np_bookero_cache_hours((int) $id, 'nisko', $nearest_date, $hours);
                }
            }
        }
        // Zawsze zapisz timestamp synchronizacji jeśli psycholog ma worker ID
        // (nawet gdy brak wolnych terminów — odróżnia "zsynchronizowany" od "jeszcze nie")
        if ($has_id) {
            update_post_meta($id, 'np_termin_updated_at', time());
        }
    }
}
