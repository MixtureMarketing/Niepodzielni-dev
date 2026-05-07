<?php

/**
 * Bookero Worker Sync — OOP, Circuit Breaker, Smart Polling
 *
 * Podpięty do BOOKERO_CRON_HOOK (co minutę).
 *
 * Strategia kolejkowania:
 *   Każdy przebieg synchronizuje 5 psychologów z NAJSTARSZĄ datą
 *   np_termin_updated_at. Po synchronizacji timestamp jest aktualizowany
 *   przez BookeroSyncService::syncSingleWorker() → psycholog spada na
 *   koniec kolejki. System jest samobalansujący bez żadnego offsetu.
 *
 *   Psycholodzy bez np_termin_updated_at (nigdy nie synchronizowani)
 *   mają absolutny priorytet — przetwarzani jako pierwsi.
 *
 * Circuit Breaker:
 *   Przy HTTP 429 lub Timeout BookeroApiClient rzuca BookeroRateLimitException.
 *   Pętla natychmiast się zatrzymuje i ustawia transient BOOKERO_LOCKOUT_KEY
 *   na BOOKERO_LOCKOUT_TTL minut. Kolejne wywołania crona sprawdzają lockout
 *   na początku i rezygnują, dając Bookero czas na reset.
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Prefiks klucza transienta blokady circuit breaker (per-request).
 *
 * Klucz pełny: BOOKERO_LOCKOUT_PREFIX . $postId
 * Blokuje konkretny request (worker), nie cały cron globalnie.
 * Przy HTTP 429 (globalny rate-limit) używamy klucza z postId=0.
 */
define('BOOKERO_LOCKOUT_PREFIX', 'bookero_lockout_');

/** TTL blokady — 5 minut po otrzymaniu HTTP 429 lub Timeout (per-request). */
define('BOOKERO_LOCKOUT_TTL', 5 * MINUTE_IN_SECONDS);

/** Liczba psychologów przetwarzanych w jednym przebiegu crona. */
define('BOOKERO_SYNC_PER_RUN', 5);

/** Opóźnienie między psychologami (mikrosekundy) — zapobiega throttlingowi. */
define('BOOKERO_SYNC_DELAY_US', 300_000); // 0.3s

// ─── Rejestracja hooka ────────────────────────────────────────────────────────

add_action(BOOKERO_CRON_HOOK, 'np_bookero_worker_sync_oop', 10);

/**
 * Główna funkcja crona — OOP z Circuit Breaker i Smart Polling.
 *
 * Zastępuje proceduralne np_bookero_worker_sync() z offsetem.
 * Stara funkcja pozostaje w pliku ale nie jest podpięta pod hook.
 */
function np_bookero_worker_sync_oop(): void
{
    // ── Circuit Breaker: sprawdź globalny lockout (HTTP 429) ─────────────────
    // Globalny lockout (klucz postId=0) ustawiany przy HTTP 429 — blokuje cały cron.
    $global_lockout_key = BOOKERO_LOCKOUT_PREFIX . '0';
    if (get_transient($global_lockout_key)) {
        // Czas wygaśnięcia: przy Redis transiencie _transient_timeout_ nie istnieje w DB,
        // więc liczymy od czasu ustawienia lockoutu przechowywanego w osobnej opcji.
        $lockout_since = (int) get_option('np_bookero_lockout_since', 0);
        $lockout_until = $lockout_since ? $lockout_since + BOOKERO_LOCKOUT_TTL : 0;
        $remaining     = $lockout_until > time() ? gmdate('i:s', $lockout_until - time()) . ' min' : 'wygasa wkrótce';
        np_bookero_log_error(
            'cron',
            'Circuit breaker aktywny (HTTP 429) — pozostało ~' . $remaining . '. Pominięto przebieg.',
        );
        return;
    }

    update_option('np_bookero_last_cron_run', time(), false);

    // ── Smart Polling — krok 1: nigdy nie synchronizowani (priorytet absolutny) ─
    // Psycholodzy bez np_termin_updated_at trafili do bazy ale jeszcze nie przeszli
    // przez cron. Muszą zostać zsynchronizowani zanim wejdą do normalnej rotacji.
    $never_synced = get_posts([
        'post_type'              => 'psycholog',
        'posts_per_page'         => BOOKERO_SYNC_PER_RUN,
        'post_status'            => 'publish',
        'fields'                 => 'ids',
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query'             => [
            [ 'key' => 'np_termin_updated_at', 'compare' => 'NOT EXISTS' ],
        ],
    ]);

    $to_sync   = array_slice($never_synced, 0, BOOKERO_SYNC_PER_RUN);
    $remaining = BOOKERO_SYNC_PER_RUN - count($to_sync);

    // ── Smart Polling — krok 2: najstarsze synchronizacje (wypełnij pozostałe sloty) ─
    // Sortowanie ASC po np_termin_updated_at → zawsze odświeżamy tych, którzy czekali
    // najdłużej. Po synchronizacji ich timestamp wzrośnie → spadną na koniec kolejki.
    if ($remaining > 0) {
        $oldest_synced = get_posts([
            'post_type'              => 'psycholog',
            'posts_per_page'         => $remaining,
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'meta_key'               => 'np_termin_updated_at',
            'orderby'                => 'meta_value_num',
            'order'                  => 'ASC',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $to_sync = array_merge($to_sync, $oldest_synced);
    }

    if (empty($to_sync)) {
        return;
    }

    // ── Inicjalizacja serwisu ─────────────────────────────────────────────────
    $client  = new \Niepodzielni\Bookero\BookeroApiClient();
    $repo    = new \Niepodzielni\Bookero\PsychologistRepository();
    $service = new \Niepodzielni\Bookero\BookeroSyncService($client, $repo);

    // ── Pętla synchronizacji z circuit breaker ────────────────────────────────
    $synced_count = 0;

    foreach ($to_sync as $postId) {
        // Per-request lockout: pomiń workera zablokowanego przez poprzedni timeout.
        // Klucz: BOOKERO_LOCKOUT_PREFIX . $postId — nie blokuje innych workerów.
        $worker_lockout_key = BOOKERO_LOCKOUT_PREFIX . (int) $postId;
        if (get_transient($worker_lockout_key)) {
            np_bookero_log_error(
                'cron',
                'Per-request lockout aktywny — pomijam postId=' . $postId . '.',
            );
            continue;
        }

        usleep(BOOKERO_SYNC_DELAY_US); // 0.3s — ochrona przed throttlingiem Bookero

        try {
            $service->syncSingleWorker((int) $postId);
            $synced_count++;
        } catch (\Niepodzielni\Bookero\BookeroRateLimitException $e) {
            $isHttp429 = str_contains($e->getMessage(), 'HTTP 429');
            error_log('[Bookero] RateLimit cron postId=' . $postId . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

            if ($isHttp429) {
                // HTTP 429 — globalny rate-limit: zatrzymaj cały cron na TTL.
                np_bookero_log_error(
                    'cron',
                    'Circuit breaker (HTTP 429) — globalny lockout na ' . (BOOKERO_LOCKOUT_TTL / 60) . ' min. Przyczyna: ' . $e->getMessage(),
                );
                set_transient(BOOKERO_LOCKOUT_PREFIX . '0', 1, BOOKERO_LOCKOUT_TTL);
                update_option('np_bookero_lockout_since', time(), false);

                // Zainwaliduj listing cache nawet przy przerwaniu — część batcha mogła zmienić daty
                if ($synced_count > 0) {
                    do_action('niepodzielni_bookero_batch_synced');
                }
                return;
            }

            // Timeout — per-request lockout: zablokuj tylko tego workera, kontynuuj z kolejnymi.
            np_bookero_log_error(
                'cron',
                'Timeout — per-request lockout postId=' . $postId . ' na ' . (BOOKERO_LOCKOUT_TTL / 60) . ' min. Przyczyna: ' . $e->getMessage(),
            );
            set_transient($worker_lockout_key, 1, BOOKERO_LOCKOUT_TTL);
            continue;
        } catch (\Exception $e) {
            // Nieoczekiwany błąd — izoluj do pojedynczego psychologa.
            // Loguj z pełnym stack trace i kontynuuj z kolejnym.
            np_bookero_log_error(
                'cron',
                'Błąd syncSingleWorker postId=' . $postId . ': ' . $e->getMessage(),
            );
            error_log('[Bookero] Exception cron postId=' . $postId . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            continue;
        }
    }

    // Inwalidacja cache listingu — wywołuje PsychologistListingService::clearCache()
    // oraz np_bookero_invalidate_workers_cache() i np_clear_slider_cache().
    // Bez tego listing serwuje stare dane przez pełne 15 minut niezależnie od synchrnoizacji.
    if ($synced_count > 0) {
        do_action('niepodzielni_bookero_batch_synced');
    }
}
