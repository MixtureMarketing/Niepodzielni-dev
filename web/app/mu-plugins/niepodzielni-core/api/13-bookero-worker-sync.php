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

/** Klucz transienta blokady circuit breaker (globalny — blokuje cały cron). */
define('BOOKERO_LOCKOUT_KEY', 'bookero_api_lockout');

/** TTL blokady — 15 minut po otrzymaniu HTTP 429 lub Timeout. */
define('BOOKERO_LOCKOUT_TTL', 15 * MINUTE_IN_SECONDS);

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
    // ── Circuit Breaker: sprawdź lockout ──────────────────────────────────────
    // Jeśli poprzedni przebieg otrzymał HTTP 429 lub Timeout, daj Bookero odpocząć.
    if (get_transient(BOOKERO_LOCKOUT_KEY)) {
        np_bookero_log_error(
            'cron',
            'Circuit breaker aktywny — lockout do ' . date('H:i:s', time() + (int) get_option('_transient_timeout_' . BOOKERO_LOCKOUT_KEY, 0) - time()) . '. Pominięto przebieg.',
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
    foreach ($to_sync as $postId) {
        usleep(BOOKERO_SYNC_DELAY_US); // 0.3s — ochrona przed throttlingiem Bookero

        try {
            $service->syncSingleWorker((int) $postId);
        } catch (\Niepodzielni\Bookero\BookeroRateLimitException $e) {
            // HTTP 429 lub Timeout — Bookero prosi o backoff.
            // Zatrzymaj pętlę i zablokuj cron na BOOKERO_LOCKOUT_TTL minut.
            np_bookero_log_error(
                'cron',
                'Circuit breaker wyzwolony — ustawiam lockout na ' . (BOOKERO_LOCKOUT_TTL / 60) . ' min. Przyczyna: ' . $e->getMessage(),
            );
            set_transient(BOOKERO_LOCKOUT_KEY, 1, BOOKERO_LOCKOUT_TTL);
            return; // Natychmiastowe wyjście — nie przetwarzaj kolejnych psychologów
        }
    }
}
