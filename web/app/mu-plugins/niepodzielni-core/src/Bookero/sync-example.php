<?php

/**
 * Przykład integracji — jak zastąpić starą logikę pętli w 13-bookero-worker-sync.php
 * ─────────────────────────────────────────────────────────────────────────────────────
 *
 * TEN PLIK JEST DOKUMENTACJĄ — nie jest ładowany przez WordPress.
 * Pokauje w jaki sposób zrefaktoryzować np_bookero_worker_sync() krok po kroku.
 *
 * Migracja jest celowo addytywna:
 *   - Stare funkcje proceduralne (np_bookero_get_terminy, np_bookero_cache_hours, ...)
 *     POZOSTAJĄ niezmienione i działają nadal — nie ma breaking change.
 *   - Klasy z src/Bookero/ są nowym, równoległym interfejsem.
 *   - Można je wdrażać plik po pliku, funkcja po funkcji.
 *
 * ─── KROK 1: Zaktualizuj composer.json (autoloading PSR-4) ───────────────────────────
 *
 * Dodaj do głównego composer.json (root projektu):
 *
 *   "autoload": {
 *       "psr-4": {
 *           "Niepodzielni\\Bookero\\": "web/app/mu-plugins/niepodzielni-core/src/Bookero/"
 *       }
 *   }
 *
 * Następnie wygeneruj autoloader:
 *   composer dump-autoload
 *
 * vendor/autoload.php jest ładowany w web/wp-config.php przed wp-settings.php,
 * więc klasy będą dostępne w mu-plugins bez dodatkowych require.
 *
 * ─── KROK 2: Zastąp pętlę w 13-bookero-worker-sync.php ──────────────────────────────
 *
 * PRZED (proceduralne, ~50 linii):
 *
 *   foreach ( $psycholodzy as $id ) {
 *       usleep( 300000 );
 *
 *       $bk_pelny = get_post_meta( $id, 'bookero_id_pelny', true );
 *       $bk_niski = get_post_meta( $id, 'bookero_id_niski', true );
 *
 *       $has_id = false;
 *       if ( $bk_pelny ) {
 *           $has_id = true;
 *           $avail  = np_bookero_get_availability( (string) $bk_pelny, 'pelnoplatny' );
 *           if ( $avail['nearest'] !== '' ) {
 *               update_post_meta( $id, 'najblizszy_termin_pelnoplatny', $avail['nearest'] );
 *           } else {
 *               delete_post_meta( $id, 'najblizszy_termin_pelnoplatny' );
 *           }
 *           update_post_meta( $id, 'bookero_slots_pelno', wp_json_encode( $avail['dates'] ) );
 *           if ( ! empty( $avail['dates'] ) ) {
 *               $nearest_date = $avail['dates'][0];
 *               if ( np_bookero_get_cached_hours( (int) $id, 'pelnoplatny', $nearest_date ) === null ) {
 *                   $hours = np_bookero_get_month_day( (string) $bk_pelny, 'pelnoplatny', $nearest_date );
 *                   np_bookero_cache_hours( (int) $id, 'pelnoplatny', $nearest_date, $hours );
 *               }
 *           }
 *       }
 *       if ( $bk_niski ) {
 *           // ... identyczna struktura dla 'nisko' ...
 *       }
 *       if ( $has_id ) {
 *           update_post_meta( $id, 'np_termin_updated_at', time() );
 *       }
 *   }
 *
 * PO (OOP, ~8 linii + inicjalizacja serwisu):
 */

// Normalnie use-statements na początku pliku (przed namespace/deklaracjami)
// Tutaj poniżej przykładu — celowo w bloku komentarza dla czytelności.

/*
use Niepodzielni\Bookero\BookeroApiClient;
use Niepodzielni\Bookero\PsychologistRepository;
use Niepodzielni\Bookero\BookeroSyncService;
*/

/**
 * Refaktoryzacja np_bookero_worker_sync() z użyciem nowych klas.
 *
 * Jedyna zmiana semantyczna: serwis zwraca SyncResult — można logować
 * wyniki bez dodatkowych get_post_meta.
 */
function np_bookero_worker_sync_v2(): void {
    update_option( 'np_bookero_last_cron_run', time(), false );

    $offset  = (int) get_option( BOOKERO_OFFSET_KEY, 0 );
    $perRun  = 3;

    $psycholodzy = get_posts( [
        'post_type'      => 'psycholog',
        'posts_per_page' => $perRun,
        'offset'         => $offset,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ] );

    if ( empty( $psycholodzy ) ) {
        update_option( BOOKERO_OFFSET_KEY, 0 );
        return;
    }

    // Nowy offset PRZED przetwarzaniem — PHP timeout nie cofa postępu
    update_option( BOOKERO_OFFSET_KEY, $offset + $perRun );

    // ─── Inicjalizacja serwisu ─────────────────────────────────────────────────
    // W docelowej architekturze można to wyciągnąć do prostego kontenera DI
    // lub statycznej fabryki BookeroSyncService::create().
    $service = new \Niepodzielni\Bookero\BookeroSyncService(
        new \Niepodzielni\Bookero\BookeroApiClient(),
        new \Niepodzielni\Bookero\PsychologistRepository(),
    );

    // ─── Pętla synchronizacji — 50 linii zastąpione 3 ────────────────────────
    foreach ( $psycholodzy as $postId ) {
        usleep( 300_000 ); // 0.3s — zapobiega throttlingowi Bookero (HTTP 429)
        $service->syncSingleWorker( (int) $postId );
    }
}

/**
 * ─── KROK 3: Zamiana hooka ────────────────────────────────────────────────────────
 *
 * W 13-bookero-worker-sync.php zmień:
 *
 *   add_action( BOOKERO_CRON_HOOK, 'np_bookero_worker_sync', 10 );
 *
 * na:
 *
 *   add_action( BOOKERO_CRON_HOOK, 'np_bookero_worker_sync_v2', 10 );
 *
 * (lub usuń starą funkcję i zmień nazwę v2 → bez suffixu).
 *
 * ─── KROK 4 (opcjonalny): np_bookero_sync_all() w 9-bookero-sync.php ──────────────
 *
 * Analogiczna zamiana — użyj $service->syncSingleWorker() w pętli foreach.
 *
 * ─── KROK 5 (opcjonalny): AJAX handlers w 10-ajax-handlers.php ────────────────────
 *
 * Zamiast np_bookero_get_availability():
 *   $avail = $service->getAvailability( $workerId, $typ );
 *
 * Zamiast np_bookero_get_account_config():
 *   $config = $service->getAccountConfig( $typ );
 *   $serviceId = $config->serviceId;
 *
 * ─── Pełna kompatybilność kluczy cache ────────────────────────────────────────────
 *
 * PsychologistRepository::monthCacheKey()  generuje identyczny hash co np_bookero_get_terminy().
 * PsychologistRepository::configCacheKey() generuje identyczny klucz co np_bookero_get_account_config().
 *
 * Migracja nie unieważnia istniejącego cache — transienty działają bez przerwy.
 */
